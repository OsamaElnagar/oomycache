<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Cache;

use Closure;
use Countable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Oomycache\Oomycache\Builder\QueryCacheQueryBuilder;
use Oomycache\Oomycache\Concerns\HasQueryCache;
use Oomycache\Oomycache\Contracts\PayloadCodec;
use Oomycache\Oomycache\Contracts\QueryCacheable;
use Oomycache\Oomycache\Contracts\TenantResolver;
use Oomycache\Oomycache\Contracts\VersionStore;
use Oomycache\Oomycache\Events\QueryCacheBypassed;
use Oomycache\Oomycache\Events\QueryCacheCleared;
use Oomycache\Oomycache\Events\QueryCacheHit;
use Oomycache\Oomycache\Events\QueryCacheInvalidated;
use Oomycache\Oomycache\Events\QueryCacheMissed;
use Oomycache\Oomycache\Payload\ChunkedPayloadStore;
use Oomycache\Oomycache\Payload\PayloadCompressor;
use Oomycache\Oomycache\Query\BaseQueryContext;
use Oomycache\Oomycache\Query\CacheDecision;
use Oomycache\Oomycache\Query\Fingerprint;
use Oomycache\Oomycache\Query\QueryContext;
use Oomycache\Oomycache\Stats\CacheStats;
use Oomycache\Oomycache\Tenant\TenantContext;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Central read/write orchestrator: classification, key composition, payload
 * storage and version-namespace invalidation.
 */
final class QueryCacheEngine
{
    private int $bypassDepth = 0;

    private ?Repository $repository = null;

    /** @var array<string, array<string, mixed>> */
    private array $optionsMemo = [];

    /** @var array<string, string> */
    private array $versionMemo = [];

    private ?string $globalToken = null;

    /** @var array<string, mixed> */
    private array $requestMemo = [];

    public function __construct(
        private readonly CacheManager $cache,
        private readonly ConfigRepository $config,
        private readonly VersionStore $versions,
        private readonly PayloadCodec $codec,
        private readonly PayloadCompressor $compressor,
        private readonly ChunkedPayloadStore $chunks,
        private readonly Container $container,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('oomycache.enabled', true);
    }

    public function isCacheableModel(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        if (in_array($class, (array) $this->config->get('oomycache.cache.skip_models', []), true)) {
            return false;
        }

        return is_subclass_of($class, QueryCacheable::class)
            || in_array(HasQueryCache::class, class_uses_recursive($class), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsFor(string $modelClass): array
    {
        if (isset($this->optionsMemo[$modelClass])) {
            return $this->optionsMemo[$modelClass];
        }

        $defaults = [
            'enabled' => (bool) $this->config->get('oomycache.enabled', true),
            'ttl' => (int) $this->config->get('oomycache.ttl', 300),
            'ttl_jitter' => (float) $this->config->get('oomycache.ttl_jitter', 0.1),
            'cache_reads' => (bool) $this->config->get('oomycache.cache.reads', true),
            'cache_aggregates' => (bool) $this->config->get('oomycache.cache.aggregates', true),
            'cache_pagination' => (bool) $this->config->get('oomycache.cache.pagination', true),
            'cache_single_records' => (bool) $this->config->get('oomycache.cache.single_records', true),
            'cache_value_and_pluck' => (bool) $this->config->get('oomycache.cache.value_and_pluck', true),
            'negative' => (bool) $this->config->get('oomycache.cache.negative', false),
            'dependencies' => [],
            'skip_query_patterns' => (array) $this->config->get('oomycache.cache.skip_query_patterns', []),
        ];

        /** @var array<string, mixed> $preset */
        $preset = [];
        $modelPresets = (array) $this->config->get('oomycache.model_presets', []);
        $presetName = $modelPresets[$modelClass] ?? null;

        if (is_string($presetName)) {
            $preset = (array) $this->config->get("oomycache.presets.{$presetName}", []);
        }

        try {
            /** @var Model $instance */
            $instance = new $modelClass;

            if (method_exists($instance, 'queryCacheOptions')) {
                $overrides = $instance->queryCacheOptions();
                $preset = array_merge($preset, is_array($overrides) ? $overrides : []);
            }
        } catch (Throwable) {
            // Model could not be instantiated (abstract/constructor deps): use config.
        }

        /** @var array<string, mixed> $options */
        $options = array_merge($defaults, $preset);

        return $this->optionsMemo[$modelClass] = $options;
    }

    /**
     * Forget memoized per-model options.
     *
     * Call this after changing `oomycache.*` configuration at runtime (tests,
     * long-running workers that reload config) so subsequent queries observe
     * the new options.
     */
    public function forgetOptions(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            $this->optionsMemo = [];

            return;
        }

        unset($this->optionsMemo[$modelClass]);
    }

    public function decide(QueryContext $context): CacheDecision
    {
        if (! $this->enabled()) {
            return CacheDecision::deny('globally-disabled');
        }

        if ($this->bypassDepth > 0) {
            return CacheDecision::deny('explicit-bypass');
        }

        $tenant = $this->tenantContext();

        if ($tenant->failClosed) {
            return CacheDecision::deny('tenant-unresolved');
        }

        if ($context->streaming) {
            return CacheDecision::deny('streaming');
        }

        if ($context->pessimisticLock) {
            return CacheDecision::deny('pessimistic-lock');
        }

        if ($context->insideTransaction && (bool) $this->config->get('oomycache.safety.skip_reads_in_transactions', true)) {
            return CacheDecision::deny('transaction');
        }

        if ((bool) $this->config->get('oomycache.safety.skip_in_console', false) && running_in_console() && ! app()->runningUnitTests()) {
            return CacheDecision::deny('console');
        }

        if (! ($context->options['enabled'] ?? true)) {
            return CacheDecision::deny('model-disabled');
        }

        $reason = $this->intentBypassReason($context);

        if ($reason !== null) {
            return CacheDecision::deny($reason);
        }

        if (! (bool) $this->config->get('oomycache.cache.search_terms', false) && $this->isSearchQuery($context->sql)) {
            return CacheDecision::deny('search-query');
        }

        foreach ((array) $context->options['skip_query_patterns'] as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $context->sql) === 1) {
                return CacheDecision::deny('excluded-pattern');
            }
        }

        return CacheDecision::allow();
    }

    public function remember(QueryContext $context, Closure $execute): mixed
    {
        $corrupt = false;

        try {
            $key = $this->buildKey($context);

            if ($this->memoEnabled() && array_key_exists($key, $this->requestMemo)) {
                $this->dispatchHit($context, $key);

                return $this->requestMemo[$key];
            }

            $payload = $this->repository()->get($key);

            if (is_string($payload) && $this->chunks->isManifest($payload)) {
                $resolved = $this->chunks->read($this->repository(), $key, $payload);

                $corrupt = $resolved === null;
                $payload = $resolved;
            }
        } catch (Throwable $exception) {
            $this->reportCacheError($exception);

            return $execute();
        }

        if (is_string($payload)) {
            try {
                $value = $this->codec->decode($this->compressor->decompress($payload));

                if ($this->memoEnabled()) {
                    $this->requestMemo[$key] = $value;
                }

                $this->dispatchHit($context, $key);

                return $value;
            } catch (Throwable $exception) {
                $this->forgetQuietly($key);
                $this->reportCacheError($exception);
                $this->dispatchMissed($context, $key, 'corrupt-payload');

                return $execute();
            }
        }

        $this->dispatchMissed($context, $key, $corrupt ? 'corrupt-payload' : 'miss');
        $value = $execute();

        if (! ($context->options['negative'] ?? false) && $this->isEmptyResult($value)) {
            $this->dispatchBypassed($context, 'negative-disabled');

            return $value;
        }

        if (! $this->cacheIfAllows($context->cacheIf, $value)) {
            $this->dispatchBypassed($context, 'cache-if');

            return $value;
        }

        try {
            $encoded = $this->codec->encode($value);
            $max = (int) $this->config->get('oomycache.safety.max_payload_bytes', 1_048_576);
            $chunking = $this->chunks->enabled();

            if (! $chunking && $max > 0 && strlen($encoded) > $max) {
                $this->dispatchBypassed($context, 'payload-too-large');

                return $value;
            }

            $encoded = $this->compressor->compress($encoded);
            $ttl = $this->isEmptyResult($value)
                ? max(1, (int) $this->config->get('oomycache.cache.negative_ttl', 10))
                : $this->resolveTtl($context);

            // M1: hard total-size cap for chunked payloads — bypass even when
            // the chunk count alone would fit inside max_chunks.
            if ($chunking && $this->chunks->exceedsTotalBudget(strlen($encoded))) {
                $this->dispatchBypassed($context, 'payload-too-large');

                return $value;
            }

            if ($chunking && strlen($encoded) > $this->chunks->chunkBytes()) {
                if ($this->chunks->chunkCount(strlen($encoded)) > $this->chunks->maxChunks()) {
                    $this->dispatchBypassed($context, 'payload-too-large');

                    return $value;
                }

                if (! $this->chunks->put($this->repository(), $key, $encoded, $ttl)) {
                    return $value;
                }
            } elseif ($max > 0 && strlen($encoded) > $max) {
                $this->dispatchBypassed($context, 'payload-too-large');

                return $value;
            } elseif ($ttl === 0) {
                $this->repository()->forever($key, $encoded);
            } else {
                $this->repository()->put($key, $encoded, $ttl);
            }

            if ($this->memoEnabled()) {
                $this->requestMemo[$key] = $value;
            }
        } catch (Throwable $exception) {
            $this->reportCacheError($exception);
        }

        return $value;
    }

    /**
     * Cache an explicit raw-query result with an explicit dependency list.
     *
     * Intended for report/dashboard reads that use DB::table() rather than
     * Eloquent. The caller supplies a stable key and the tables whose version
     * tokens must invalidate the entry. Prefer returning arrays/scalars.
     *
     * @template TReturn
     *
     * @param  list<string>  $tables
     * @param  callable(): TReturn  $execute
     * @return TReturn
     */
    public function rememberRaw(array $tables, string $key, int $ttl, callable $execute, ?string $connection = null): mixed
    {
        $tables = array_values(array_unique(array_filter(
            array_map(static fn (string $table): string => trim($table), $tables),
            static fn (string $table): bool => $table !== '',
        )));

        if ($tables === [] || trim($key) === '' || $ttl < 1) {
            throw new InvalidArgumentException('rememberRaw() requires at least one table, a key and a TTL >= 1.');
        }

        if (! $this->enabled()) {
            return $execute();
        }

        $tenant = $this->tenantContext();

        if ($tenant->failClosed) {
            $this->dispatchRawBypassed('tenant-unresolved');

            return $execute();
        }

        $connection = $connection ?? $this->config->get('database.default');
        $connection = is_string($connection) ? $connection : null;
        $cacheKey = $this->buildRawKey($tables, $key, $connection, $tenant);

        if ($this->memoEnabled() && array_key_exists($cacheKey, $this->requestMemo)) {
            $this->dispatchRawHit($cacheKey);

            return $this->requestMemo[$cacheKey];
        }

        $corrupt = false;

        try {
            $payload = $this->repository()->get($cacheKey);

            if (is_string($payload) && $this->chunks->isManifest($payload)) {
                $resolved = $this->chunks->read($this->repository(), $cacheKey, $payload);

                $corrupt = $resolved === null;
                $payload = $resolved;
            }
        } catch (Throwable $exception) {
            $this->reportCacheError($exception);

            return $execute();
        }

        if (is_string($payload)) {
            try {
                $value = $this->codec->decode($this->compressor->decompress($payload));

                if ($this->memoEnabled()) {
                    $this->requestMemo[$cacheKey] = $value;
                }

                $this->dispatchRawHit($cacheKey);

                return $value;
            } catch (Throwable $exception) {
                $this->forgetQuietly($cacheKey);
                $this->reportCacheError($exception);
                $this->dispatchRawMissed($cacheKey, 'corrupt-payload');

                return $execute();
            }
        }

        $this->dispatchRawMissed($cacheKey, $corrupt ? 'corrupt-payload' : 'miss');
        $value = $execute();

        if (! (bool) $this->config->get('oomycache.cache.negative', false) && $this->isEmptyResult($value)) {
            $this->dispatchRawBypassed('negative-disabled');

            return $value;
        }

        try {
            $encoded = $this->codec->encode($value);
            $max = (int) $this->config->get('oomycache.safety.max_payload_bytes', 1_048_576);
            $chunking = $this->chunks->enabled();

            if (! $chunking && $max > 0 && strlen($encoded) > $max) {
                $this->dispatchRawBypassed('payload-too-large');

                return $value;
            }

            $encoded = $this->compressor->compress($encoded);
            $entryTtl = $this->isEmptyResult($value)
                ? max(1, (int) $this->config->get('oomycache.cache.negative_ttl', 10))
                : $this->resolveRawTtl($ttl);

            if ($chunking && $this->chunks->exceedsTotalBudget(strlen($encoded))) {
                $this->dispatchRawBypassed('payload-too-large');

                return $value;
            }

            if ($chunking && $this->chunks->shouldChunk(strlen($encoded))) {
                if ($this->chunks->chunkCount(strlen($encoded)) > $this->chunks->maxChunks()) {
                    $this->dispatchRawBypassed('payload-too-large');

                    return $value;
                }

                if (! $this->chunks->put($this->repository(), $cacheKey, $encoded, $entryTtl)) {
                    return $value;
                }
            } else {
                $this->repository()->put($cacheKey, $encoded, $entryTtl);
            }

            if ($this->memoEnabled()) {
                $this->requestMemo[$cacheKey] = $value;
            }
        } catch (Throwable $exception) {
            $this->reportCacheError($exception);
        }

        return $value;
    }

    /**
     * Invalidate explicit tables, including raw-query cache entries.
     *
     * @param  list<string>  $tables
     */
    public function flushTables(array $tables, ?string $connection = null, string $reason = 'manual'): void
    {
        $connection = $connection ?? $this->config->get('database.default');
        $connection = is_string($connection) ? $connection : null;

        foreach (array_unique($tables) as $table) {
            if ($table === '') {
                continue;
            }

            $this->flushTable($table, $connection, $reason);
        }
    }

    public function rememberBaseCount(BaseQueryContext $context, QueryCacheQueryBuilder $builder, Closure $execute): mixed
    {
        $model = $context->model();
        $options = $context->options();

        if (! $this->enabled() || $this->bypassDepth > 0 || ! ($options['cache_aggregates'] ?? true)) {
            return $execute();
        }

        if (! $this->isCacheableModel($model::class)) {
            return $execute();
        }

        if ((bool) $this->config->get('oomycache.safety.skip_reads_in_transactions', true)
            && $model->getConnection()->transactionLevel() > 0) {
            return $execute();
        }

        $sql = $builder->toSql();

        $tenant = $this->tenantContext();

        if ($tenant->failClosed) {
            return $execute();
        }

        if (! (bool) $this->config->get('oomycache.cache.search_terms', false) && $this->isSearchQuery($sql)) {
            return $execute();
        }

        try {
            $tables = array_values(array_unique(array_merge([$model->getTable()], $context->recorder()->tables())));
            $key = $this->composeKey(Fingerprint::hash([
                'v' => 1,
                'model' => $model::class,
                'connection' => $model->getConnection()->getName(),
                'sql' => $sql,
                'bindings' => Fingerprint::canonicalizeBindings($builder->getBindings()),
                'tables' => $this->versionVector($tables, $model->getConnection()->getName()),
                'global' => $this->globalVersion(),
                'tenant' => $tenant->segments(),
                'intent' => 'base-count',
            ]), $tenant->segments());
            $payload = $this->repository()->get($key);
        } catch (Throwable $exception) {
            $this->reportCacheError($exception);

            return $execute();
        }

        $pseudo = new QueryContext(
            modelClass: $model::class,
            table: $model->getTable(),
            connectionName: $model->getConnection()->getName(),
            sql: $sql,
            bindings: [],
            columns: ['*'],
            intent: 'count',
            eagerLoadSignatures: [],
            dependencyTables: $tables,
            streaming: false,
            pessimisticLock: false,
            insideTransaction: false,
            options: $options,
            ttlOverride: null,
            keySuffix: null,
            cacheIf: null,
        );

        if ($this->memoEnabled() && array_key_exists($key, $this->requestMemo)) {
            $value = $this->requestMemo[$key];
            $this->dispatchHit($pseudo, $key);

            return $value;
        }

        if (is_string($payload)) {
            try {
                $value = $this->codec->decode($this->compressor->decompress($payload));

                if ($this->memoEnabled()) {
                    $this->requestMemo[$key] = $value;
                }

                $this->dispatchHit($pseudo, $key);

                return $value;
            } catch (Throwable $exception) {
                $this->forgetQuietly($key);
                $this->reportCacheError($exception);
            }
        }

        $this->dispatchMissed($pseudo, $key, 'miss');
        $value = $execute();

        try {
            $encoded = $this->compressor->compress($this->codec->encode($value));
            $ttl = $this->resolveTtl($pseudo);

            if ($ttl === 0) {
                $this->repository()->forever($key, $encoded);
            } else {
                $this->repository()->put($key, $encoded, $ttl);
            }

            if ($this->memoEnabled()) {
                $this->requestMemo[$key] = $value;
            }
        } catch (Throwable $exception) {
            $this->reportCacheError($exception);
        }

        return $value;
    }

    public function flushTable(string $table, ?string $connection = null, string $reason = 'write'): string
    {
        $token = $this->versions->bump($table, $connection);

        unset($this->versionMemo[$this->versionCacheKey($table, $connection)]);

        $this->requestMemo = [];

        if ((bool) $this->config->get('oomycache.events.enabled', true)
            && (bool) $this->config->get('oomycache.events.invalidations', true)) {
            $this->dispatcher()->dispatch(new QueryCacheInvalidated($table, $connection, $reason));
        }

        return $token;
    }

    public function flushModel(string $modelClass, string $reason = 'write'): void
    {
        if (! $this->isCacheableModel($modelClass)) {
            return;
        }

        /** @var Model $model */
        $model = new $modelClass;
        $this->flushTable($model->getTable(), $model->getConnection()->getName(), $reason);
    }

    public function flushModelAfterWrite(Model $model, string $reason = 'write'): void
    {
        $flush = fn (): mixed => $this->flushTable($model->getTable(), $model->getConnection()->getName(), $reason);

        if ((bool) $this->config->get('oomycache.safety.invalidate_after_commit', true)
            && $model->getConnection()->transactionLevel() > 0) {
            $model->getConnection()->afterCommit($flush);

            return;
        }

        $flush();
    }

    /**
     * Clear per-process state that must not leak across requests/jobs.
     *
     * Octane workers and queue workers keep the application (and this
     * singleton) alive; registering this on request/job boundaries prevents
     * stale decoded payloads, version tokens and memoized options from
     * crossing tenant or invalidation boundaries.
     */
    public function resetRuntimeState(): void
    {
        $this->requestMemo = [];
        $this->versionMemo = [];
        $this->optionsMemo = [];
        $this->globalToken = null;
    }

    public function clear(): void
    {
        $this->versions->bumpGlobal();
        $this->globalToken = null;
        $this->versionMemo = [];
        $this->requestMemo = [];

        if ((bool) $this->config->get('oomycache.events.enabled', true)
            && (bool) $this->config->get('oomycache.events.invalidations', true)) {
            $this->dispatcher()->dispatch(new QueryCacheCleared);
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutQueryCache(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'store' => $this->config->get('oomycache.store') ?? $this->config->get('cache.default'),
            'prefix' => $this->config->get('oomycache.prefix'),
            'version' => $this->config->get('oomycache.version'),
            'ttl' => $this->config->get('oomycache.ttl'),
            'ttl_jitter' => $this->config->get('oomycache.ttl_jitter'),
            'payload_mode' => $this->config->get('oomycache.payload.mode', 'arrays'),
            'global_token' => $this->globalVersion(),
            'tenant' => $this->tenantContext()->segments(),
            'stats' => $this->container->bound(CacheStats::class)
                ? $this->container->make(CacheStats::class)->snapshot()
                : [],
            'payload_compression' => [
                'enabled' => $this->compressor->enabled(),
                'threshold_bytes' => $this->compressor->threshold(),
                'max_bytes' => $this->compressor->maxBytes(),
                'algorithm' => $this->compressor->algorithm(),
            ],
            'payload_chunking' => [
                'enabled' => $this->chunks->enabled(),
                'chunk_bytes' => $this->chunks->chunkBytes(),
                'max_chunks' => $this->chunks->maxChunks(),
                'max_total_bytes' => $this->chunks->maxTotalBytes(),
            ],
            'memoize_per_request' => $this->memoEnabled(),
            'capabilities' => $this->storeCapabilities(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeCapabilities(): array
    {
        $repository = $this->repository();

        return [
            'taggable' => method_exists($repository, 'supportsTags') ? $repository->supportsTags() : false,
            'lockable' => method_exists($repository->getStore(), 'lock'),
            'store' => $repository->getStore()::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function cacheableModels(): array
    {
        $path = function_exists('app_path') ? app_path('Models') : null;

        if ($path === null || ! is_dir($path)) {
            return [];
        }

        $models = [];

        /** @var iterable<SplFileInfo> $files */
        $files = Finder::create()->files()->in($path)->name('*.php');

        foreach ($files as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '\\'], '\\', substr($file->getRelativePathname(), 0, -4));

            if ($this->isCacheableModel($class)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    public function dispatchBypassed(QueryContext $context, string $reason): void
    {
        if (! (bool) $this->config->get('oomycache.events.enabled', true)) {
            return;
        }

        $this->dispatcher()->dispatch(new QueryCacheBypassed($context->modelClass, $context->intent, $reason));
    }

    private function intentBypassReason(QueryContext $context): ?string
    {
        $single = ['first', 'firstOrFail', 'find', 'findMany', 'sole'];
        $aggregates = ['count', 'exists', 'doesntExist', 'sum', 'avg', 'min', 'max'];
        $pagination = ['paginate', 'simplePaginate', 'cursorPaginate'];
        $valueLike = ['pluck', 'value'];

        return match (true) {
            in_array($context->intent, $single, true) && ! ($context->options['cache_single_records'] ?? true) => 'single-disabled',
            in_array($context->intent, $aggregates, true) && ! ($context->options['cache_aggregates'] ?? true) => 'aggregates-disabled',
            in_array($context->intent, $pagination, true) && ! ($context->options['cache_pagination'] ?? true) => 'pagination-disabled',
            in_array($context->intent, $valueLike, true) && ! ($context->options['cache_value_and_pluck'] ?? true) => 'value-disabled',
            $context->intent === 'get' && ! ($context->options['cache_reads'] ?? true) => 'reads-disabled',
            default => null,
        };
    }

    private function isSearchQuery(string $sql): bool
    {
        $sql = strtolower($sql);

        foreach ([' like ', ' ilike ', ' regexp ', ' match '] as $needle) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantResolver::class)->resolve();
    }

    private function buildKey(QueryContext $context): string
    {
        $global = $this->globalVersion();
        $versions = $this->versionVector($context->dependencyTables, $context->connectionName);
        $tenant = $this->tenantContext();

        return $this->composeKey(Fingerprint::hash([
            'v' => 1,
            'model' => $context->modelClass,
            'connection' => $context->connectionName,
            'sql' => $context->sql,
            'bindings' => $context->bindings,
            'columns' => $context->columns,
            'eager' => $context->eagerLoadSignatures,
            'tables' => $versions,
            'global' => $global,
            'tenant' => $tenant->segments(),
            'intent' => $context->intent,
            'pagination' => $context->pagination,
            'suffix' => $context->keySuffix,
        ]), $tenant->segments());
    }

    /**
     * @param  list<string>  $tables
     */
    private function buildRawKey(array $tables, string $key, ?string $connection, TenantContext $tenant): string
    {
        return $this->composeKey(Fingerprint::hash([
            'v' => 1,
            'kind' => 'raw-query',
            'key' => $key,
            'connection' => $connection,
            'tables' => $this->versionVector($tables, $connection),
            'global' => $this->globalVersion(),
            'tenant' => $tenant->segments(),
        ]), $tenant->segments());
    }

    private function resolveRawTtl(int $ttl): int
    {
        $ttl = max(1, $ttl);
        $jitter = min(0.5, max(0.0, (float) $this->config->get('oomycache.ttl_jitter', 0.0)));

        if ($jitter > 0.0) {
            $maxReduction = (int) floor($ttl * $jitter);

            if ($maxReduction > 0) {
                $ttl -= random_int(0, $maxReduction);
            }
        }

        return max(1, $ttl);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function versionVector(array $tables, ?string $connection): array
    {
        $versions = [];

        foreach (array_unique($tables) as $table) {
            $versions[$table] = $this->tableVersion($table, $connection);
        }

        return $versions;
    }

    private function tableVersion(string $table, ?string $connection): string
    {
        $memoKey = $this->versionCacheKey($table, $connection);

        return $this->versionMemo[$memoKey] ??= $this->versions->get($table, $connection);
    }

    private function versionCacheKey(string $table, ?string $connection): string
    {
        return ($connection ?? 'default').'|'.$table;
    }

    private function globalVersion(): string
    {
        return $this->globalToken ??= $this->versions->global();
    }

    /**
     * @param  array<string, scalar>  $segments
     */
    private function composeKey(string $hash, array $segments = []): string
    {
        $prefix = (string) $this->config->get('oomycache.prefix', 'oomycache');

        if (strlen($prefix) > 24) {
            $prefix = substr($prefix, 0, 24);
        }

        $tenant = $segments === [] ? 'none' : substr(hash('xxh128', serialize($segments)), 0, 8);

        return $prefix.':'.(string) $this->config->get('oomycache.version', '1').':'.$tenant.':'.$hash;
    }

    private function resolveTtl(QueryContext $context): int
    {
        $ttl = $context->ttlOverride ?? (int) ($context->options['ttl'] ?? 300);

        if ($ttl <= 0) {
            return 0;
        }

        $jitter = min(0.5, max(0.0, (float) ($context->options['ttl_jitter'] ?? 0.0)));

        if ($jitter > 0.0) {
            $maxReduction = (int) floor($ttl * $jitter);

            if ($maxReduction > 0) {
                $ttl -= random_int(0, $maxReduction);
            }
        }

        return max(1, $ttl);
    }

    private function cacheIfAllows(mixed $cacheIf, mixed $value): bool
    {
        if ($cacheIf instanceof Closure) {
            return (bool) $cacheIf($value);
        }

        if (is_bool($cacheIf)) {
            return $cacheIf;
        }

        return true;
    }

    private function memoEnabled(): bool
    {
        return (bool) $this->config->get('oomycache.payload.memoize_per_request', true);
    }

    private function isEmptyResult(mixed $value): bool
    {
        return $value === null
            || $value === []
            || ($value instanceof Countable && count($value) === 0);
    }

    private function dispatchRawHit(string $key): void
    {
        if ((bool) $this->config->get('oomycache.events.enabled', true)
            && (bool) $this->config->get('oomycache.events.hits', true)) {
            $this->dispatcher()->dispatch(new QueryCacheHit('raw-query', 'raw', $key));
        }
    }

    private function dispatchRawMissed(string $key, string $reason): void
    {
        if ((bool) $this->config->get('oomycache.events.enabled', true)
            && (bool) $this->config->get('oomycache.events.misses', true)) {
            $this->dispatcher()->dispatch(new QueryCacheMissed('raw-query', 'raw', $key, $reason));
        }
    }

    private function dispatchRawBypassed(string $reason): void
    {
        if ((bool) $this->config->get('oomycache.events.enabled', true) && (bool) $this->config->get('oomycache.events.bypasses', true)) {
            $this->dispatcher()->dispatch(new QueryCacheBypassed('raw-query', 'raw', $reason));
        }
    }

    private function dispatchHit(QueryContext $context, string $key): void
    {
        if ((bool) $this->config->get('oomycache.events.enabled', true)
            && (bool) $this->config->get('oomycache.events.hits', true)) {
            $this->dispatcher()->dispatch(new QueryCacheHit($context->modelClass, $context->intent, $key));
        }
    }

    private function dispatchMissed(QueryContext $context, string $key, string $reason): void
    {
        if ((bool) $this->config->get('oomycache.events.enabled', true)
            && (bool) $this->config->get('oomycache.events.misses', true)) {
            $this->dispatcher()->dispatch(new QueryCacheMissed($context->modelClass, $context->intent, $key, $reason));
        }
    }

    private function dispatcher(): Dispatcher
    {
        // Resolve lazily: Event::fake() and long-running workers can replace the
        // dispatcher after the engine singleton is constructed.
        return $this->container->make(Dispatcher::class);
    }

    private function forgetQuietly(string $key): void
    {
        try {
            $this->chunks->forget($this->repository(), $key);
        } catch (Throwable) {
            // Ignore: a corrupt / unreachable entry is already a miss.
        }
    }

    private function reportCacheError(Throwable $exception): void
    {
        if (! (bool) $this->config->get('oomycache.safety.fail_silently', true)) {
            throw $exception;
        }

        if (function_exists('report')) {
            report($exception);
        } else {
            error_log($exception->getMessage());
        }
    }

    private function repository(): Repository
    {
        return $this->repository ??= $this->cache->store($this->config->get('oomycache.store'));
    }
}

/**
 * Small helper kept in this file to avoid pulling in Foundation helpers on
 * non-web runtimes.
 */
function running_in_console(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}
