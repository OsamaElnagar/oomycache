<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Stats;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Lightweight cache-backed counters for the studio/status screens.
 *
 * Stats are off by default; event listeners are always registered but each
 * record() is a no-op unless `oomycache.stats.enabled` is true.
 *
 * Counters use the store's atomic increment so concurrent requests cannot lose
 * an observation, and a shared `window_start` key describes the real age of the
 * current counter window. A fixed window expires with `oomycache.stats.ttl`;
 * reset() starts a fresh one immediately.
 */
final class CacheStats
{
    private const array METRICS = ['hits', 'misses', 'bypasses', 'empty_bypasses', 'invalidations', 'clears'];

    private const string WINDOW_START = 'window_start';

    public function __construct(
        private readonly CacheManager $cache,
        private readonly ConfigRepository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('oomycache.stats.enabled', false);
    }

    public function record(string $metric): void
    {
        if (! $this->enabled() || ! in_array($metric, self::METRICS, true)) {
            return;
        }

        $sampleRate = (float) $this->config->get('oomycache.stats.sample_rate', 1.0);

        if ($sampleRate < 1.0 && random_int(1, 1_000_000) > (int) ($sampleRate * 1_000_000)) {
            return;
        }

        try {
            $repository = $this->repository();
            $key = $this->key($metric);

            // Seeding with add() is required by drivers whose increment() does
            // not create a missing key (array, database, memcached). add() is
            // also atomic and never fires cache events, so it cannot double
            // count through an event listener.
            $repository->add($this->key(self::WINDOW_START), time(), $this->ttl());
            $repository->add($key, 0, $this->ttl());
            $repository->increment($key);
        } catch (Throwable) {
            // Metrics must never break a request.
        }
    }

    /**
     * Start a fresh counter window: zero every metric and reset the clock.
     *
     * This forgets only `oomycache.prefix.:stats:*` keys; it never touches the
     * application cache or OoMyCache version tokens.
     */
    public function reset(): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $repository = $this->repository();

            foreach ([...self::METRICS, self::WINDOW_START] as $name) {
                $repository->forget($this->key($name));
            }

            $repository->add($this->key(self::WINDOW_START), time(), $this->ttl());
        } catch (Throwable) {
            // Metrics must never break a request.
        }
    }

    /**
     * @return array{enabled: bool, window_seconds: int, hits: int, misses: int, bypasses: int, empty_bypasses: int, invalidations: int, clears: int}
     */
    public function snapshot(): array
    {
        $snapshot = [
            'enabled' => $this->enabled(),
            'window_seconds' => 0,
            'hits' => 0,
            'misses' => 0,
            'bypasses' => 0,
            'empty_bypasses' => 0,
            'invalidations' => 0,
            'clears' => 0,
        ];

        if (! $this->enabled()) {
            return $snapshot;
        }

        try {
            $repository = $this->repository();
            $windowStart = (int) $repository->get($this->key(self::WINDOW_START), 0);

            if ($windowStart > 0) {
                $ttl = $this->ttl();
                $snapshot['window_seconds'] = max(0, min($ttl, time() - $windowStart));
            }

            foreach (self::METRICS as $metric) {
                $snapshot[$metric] = (int) $repository->get($this->key($metric), 0);
            }
        } catch (Throwable) {
            // Return zeros when the stats store is unavailable.
        }

        return $snapshot;
    }

    private function key(string $metric): string
    {
        return (string) $this->config->get('oomycache.prefix', 'oomycache').':stats:'.$metric;
    }

    private function ttl(): int
    {
        return max(60, (int) $this->config->get('oomycache.stats.ttl', 3600));
    }

    private function repository(): Repository
    {
        $store = $this->config->get('oomycache.stats.store')
            ?? $this->config->get('oomycache.store');

        return $this->cache->store($store);
    }
}
