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
 */
final class CacheStats
{
    private const array METRICS = ['hits', 'misses', 'bypasses', 'invalidations', 'clears'];

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
            $current = (int) $repository->get($key, 0);
            $repository->put($key, $current + 1, $this->ttl());
        } catch (Throwable) {
            // Metrics must never break a request.
        }
    }

    /**
     * @return array{enabled: bool, window_seconds: int, hits: int, misses: int, bypasses: int, invalidations: int, clears: int}
     */
    public function snapshot(): array
    {
        $snapshot = [
            'enabled' => $this->enabled(),
            'window_seconds' => $this->ttl(),
            'hits' => 0,
            'misses' => 0,
            'bypasses' => 0,
            'invalidations' => 0,
            'clears' => 0,
        ];

        if (! $this->enabled()) {
            return $snapshot;
        }

        try {
            $repository = $this->repository();

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
        return $this->cache->store($this->config->get('oomycache.stats.store'));
    }
}
