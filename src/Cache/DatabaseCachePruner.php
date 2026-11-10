<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Cache;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Expiry-guarded, keyset-ordered garbage collection for Laravel's `database`
 * cache store.
 *
 * Only rows whose `expiration` timestamp has already passed are removed. The
 * DELETE re-checks `expiration <= now` in the same statement, so an entry that
 * is refreshed between the scan and the delete (for example by a concurrent
 * Livewire request) is never deleted. Rows are read with keyset pagination on
 * `key` and a mandatory `orderBy('key')` (PostgreSQL may otherwise return heap
 * order and skip rows — see research/01 §5.2). The `cache_locks` table is never
 * touched, other prefixes in a shared table are ignored, and the whole sweep is
 * cheap to re-run: pruning is idempotent.
 */
final class DatabaseCachePruner
{
    private const int MAX_CHUNK = 10_000;

    private const int MAX_SLEEP_MICROSECONDS = 1_000_000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Resolve the requested cache store and decide whether it can be pruned.
     *
     * @return array{
     *     store: string,
     *     driver: string|null,
     *     table: string|null,
     *     connection: string|null,
     *     prefix: string,
     *     supported: bool,
     *     refusal: string|null,
     *     hint: string|null
     * }
     */
    public function inspect(?string $store = null): array
    {
        $storeName = $this->storeName($store);
        $storeConfig = $this->config->get("cache.stores.{$storeName}");

        if (! is_array($storeConfig)) {
            return $this->unsupported(
                $storeName,
                null,
                "Cache store [{$storeName}] is not configured under [cache.stores].",
                'Set OOMYCACHE_STORE (or cache.default) to a configured database cache store, or pass the store name as the first argument.',
            );
        }

        $driver = is_string($storeConfig['driver'] ?? null) ? $storeConfig['driver'] : null;
        $prefix = is_string($storeConfig['prefix'] ?? null)
            ? $storeConfig['prefix']
            : (string) $this->config->get('cache.prefix', '');

        if ($driver !== 'database') {
            return $this->unsupported(
                $storeName,
                $driver,
                "OoMyCache prune refuses cache store [{$storeName}] using driver [".($driver ?? 'unknown').']: only the [database] cache store can be pruned.',
                $this->hintFor($driver),
            );
        }

        $table = is_string($storeConfig['table'] ?? null) ? $storeConfig['table'] : null;
        $connection = is_string($storeConfig['connection'] ?? null) ? $storeConfig['connection'] : null;

        $fallback = $this->config->get('cache.database');

        if (is_array($fallback)) {
            $table ??= is_string($fallback['table'] ?? null) ? $fallback['table'] : null;
            $connection ??= is_string($fallback['connection'] ?? null) ? $fallback['connection'] : null;
        }

        if ($table === null || $table === '') {
            return $this->unsupported(
                $storeName,
                $driver,
                "Cache store [{$storeName}] uses the [database] driver but has no [table] configured.",
                "Add cache.stores.{$storeName}.table (Laravel's default is \"cache\").",
            );
        }

        return [
            'store' => $storeName,
            'driver' => $driver,
            'table' => $table,
            'connection' => $connection,
            'prefix' => $prefix,
            'supported' => true,
            'refusal' => null,
            'hint' => null,
        ];
    }

    /**
     * Default rows per keyset page, from `oomycache.maintenance.prune.chunk`.
     */
    public function defaultChunkSize(): int
    {
        $chunk = (int) $this->config->get('oomycache.maintenance.prune.chunk', 100);

        return max(1, min($chunk, self::MAX_CHUNK));
    }

    /**
     * Default soft time budget, from `oomycache.maintenance.prune.max_seconds`.
     */
    public function defaultMaxSeconds(): float
    {
        return max(0.0, (float) $this->config->get('oomycache.maintenance.prune.max_seconds', 5.0));
    }

    /**
     * Delete already-expired rows from one database cache store.
     *
     * @return array{scanned: int, expired: int, deleted: int, budget_exhausted: bool, elapsed_ms: int}
     */
    public function prune(
        string $table,
        ?string $connection,
        string $prefix,
        int $chunk = 100,
        float $maxSeconds = 5.0,
        bool $dryRun = false,
    ): array {
        $db = $this->db->connection($connection);
        $chunk = max(1, min($chunk, self::MAX_CHUNK));
        $sleepMicroseconds = $this->sleepMicroseconds();
        $startedAt = microtime(true);
        $scanned = 0;
        $expired = 0;
        $deleted = 0;
        $budgetExhausted = false;
        $cursor = null;
        $pages = 0;

        while (true) {
            // Always run at least one page, then honour the soft time budget.
            if ($pages > 0 && $maxSeconds > 0.0 && (microtime(true) - $startedAt) >= $maxSeconds) {
                $budgetExhausted = true;

                break;
            }

            $rows = $this->fetchChunk($db, $table, $prefix, $cursor, $chunk);

            if ($rows->isEmpty()) {
                break;
            }

            $pages++;
            $scanned += $rows->count();
            $now = time();
            $expiredKeys = [];

            foreach ($rows as $row) {
                /** @var mixed $key */
                $key = $row->key;
                $cursor = (string) $key;

                if ((int) $row->expiration <= $now) {
                    $expiredKeys[] = (string) $key;
                }
            }

            $expired += count($expiredKeys);

            if (! $dryRun && $expiredKeys !== []) {
                // Guarded delete: a row refreshed after the scan has a future
                // expiration now and therefore does not match this predicate.
                $deleted += $db->table($table)
                    ->whereIn('key', $expiredKeys)
                    ->where('expiration', '<=', time())
                    ->delete();
            }

            if ($sleepMicroseconds > 0) {
                usleep($sleepMicroseconds);
            }
        }

        return [
            'scanned' => $scanned,
            'expired' => $expired,
            'deleted' => $deleted,
            'budget_exhausted' => $budgetExhausted,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function storeName(?string $store): string
    {
        if (is_string($store) && $store !== '') {
            return $store;
        }

        $configured = $this->config->get('oomycache.store');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) $this->config->get('cache.default', 'default');
    }

    private function sleepMicroseconds(): int
    {
        $sleep = (int) $this->config->get('oomycache.maintenance.prune.sleep_us', 0);

        return max(0, min($sleep, self::MAX_SLEEP_MICROSECONDS));
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function fetchChunk(Connection $connection, string $table, string $prefix, ?string $cursor, int $chunk): Collection
    {
        $query = $connection->table($table)
            ->select(['key', 'expiration'])
            ->orderBy('key')
            ->limit($chunk);

        if ($cursor !== null) {
            $query->where('key', '>', $cursor);
        } elseif ($prefix !== '') {
            // Start at the store's keyspace instead of scanning foreign prefixes.
            $query->where('key', '>=', $prefix);
        }

        if ($prefix !== '') {
            // SUBSTR is available on MySQL, MariaDB, PostgreSQL and SQLite;
            // SQL Server only has SUBSTRING. Match case-sensitively so two
            // stores whose prefixes differ only by case cannot evict each other.
            $wrappedKey = $connection->getQueryGrammar()->wrap('key');
            $function = $connection->getDriverName() === 'sqlsrv' ? 'SUBSTRING' : 'SUBSTR';

            /** @var literal-string $sql */
            $sql = sprintf('%s(%s, 1, %d) = ?', $function, $wrappedKey, strlen($prefix));

            $query->whereRaw($sql, [$prefix]);
        }

        return $query->get();
    }

    /**
     * @return array{
     *     store: string,
     *     driver: string|null,
     *     table: string|null,
     *     connection: string|null,
     *     prefix: string,
     *     supported: bool,
     *     refusal: string|null,
     *     hint: string|null
     * }
     */
    private function unsupported(string $store, ?string $driver, string $refusal, string $hint): array
    {
        return [
            'store' => $store,
            'driver' => $driver,
            'table' => null,
            'connection' => null,
            'prefix' => '',
            'supported' => false,
            'refusal' => $refusal,
            'hint' => $hint,
        ];
    }

    private function hintFor(?string $driver): string
    {
        return match ($driver) {
            'file' => 'For the file store, use vectorial1024/laravel-cache-evict (^2.1.2) and schedule `php artisan cache:evict`; OoMyCache does not parse Laravel\'s internal file-cache format.',
            'redis' => 'Redis expires entries natively via TTL and maxmemory policy; rely on the driver instead of pruning keys by hand.',
            'memcached' => 'Memcached expires entries natively via TTL; no prune is required.',
            'dynamodb' => 'DynamoDB expires items natively via TTL; no prune is required.',
            'array' => 'The array store lives in process memory and disappears with the request; there is nothing durable to prune.',
            'storage' => 'The storage store is not supported by OoMyCache prune; switch to the database/redis store or clean its files with the storage driver\'s tooling.',
            default => 'Only the [database] cache store can be pruned by OoMyCache; switch the store or use the driver\'s own eviction tooling.',
        };
    }
}
