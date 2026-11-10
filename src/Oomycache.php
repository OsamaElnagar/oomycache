<?php

declare(strict_types=1);

namespace Oomycache\Oomycache;

use Closure;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

/**
 * Public facade target for operator/developer actions.
 */
final class Oomycache
{
    public function __construct(private readonly QueryCacheEngine $engine) {}

    /**
     * Invalidate one model, several models, or the whole cache namespace.
     *
     * @param  class-string|list<class-string>|null  $models
     */
    public function flush(string|array|null $models = null): void
    {
        if ($models === null) {
            $this->clear();

            return;
        }

        foreach ((array) $models as $model) {
            $this->engine->flushModel($model, 'manual');
        }
    }

    public function clear(): void
    {
        $this->engine->clear();
    }

    /**
     * Cache a raw-query result with explicit dependency tables.
     *
     * Use this for DB::table() report/dashboard reads that cannot go through
     * the Eloquent builder. The caller owns the cache key and must include every
     * filter that changes the result; the table list controls invalidation.
     *
     * @template TReturn
     *
     * @param  list<string>  $tables
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function rememberQuery(array $tables, string $key, int $ttl, Closure $callback, ?string $connection = null): mixed
    {
        return $this->engine->rememberRaw($tables, $key, $ttl, $callback, $connection);
    }

    /**
     * Invalidate explicit tables, including rememberQuery() entries.
     *
     * @param  list<string>  $tables
     */
    public function flushTables(array $tables, ?string $connection = null): void
    {
        $this->engine->flushTables($tables, $connection, 'manual');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->engine->status();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutQueryCache(callable $callback): mixed
    {
        return $this->engine->withoutQueryCache($callback);
    }

    public function engine(): QueryCacheEngine
    {
        return $this->engine;
    }
}
