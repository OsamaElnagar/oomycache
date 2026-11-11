<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Builder\QueryCacheBuilder;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

class BenchCommand extends Command
{
    protected $signature = 'oomycache:bench
                            {model? : Fully-qualified cacheable model class}
                            {--all : Benchmark every discovered cacheable model}
                            {--limit=100 : Rows fetched per query}
                            {--samples=5 : Warm samples}
                            {--clear : Bump the global cache token first (destructive; normally not needed)}
                            {--json : Output JSON instead of a table}';

    protected $description = 'Benchmark cold vs warm Eloquent read queries for cacheable model(s).';

    public function handle(QueryCacheEngine $engine): int
    {
        $all = (bool) $this->option('all');
        $clear = (bool) $this->option('clear');
        $limit = max(1, (int) $this->option('limit'));
        $samples = max(1, (int) $this->option('samples'));

        if ($all) {
            $models = $engine->cacheableModels();

            if ($models === []) {
                $this->components->error('No cacheable models were discovered in app/Models.');

                return self::FAILURE;
            }
        } else {
            $modelArgument = $this->argument('model');
            $model = is_string($modelArgument) ? $modelArgument : '';

            if ($model === '' || ! class_exists($model)) {
                $this->components->error("Model [{$model}] does not exist. Pass a model or --all.");

                return self::FAILURE;
            }

            if (! $engine->isCacheableModel($model)) {
                $this->components->error("Model [{$model}] is not cacheable (missing HasQueryCache).");

                return self::FAILURE;
            }

            $models = [$model];
        }

        if ($clear) {
            $engine->clear();
        }

        $results = [];

        foreach ($models as $model) {
            $results[] = $this->benchmarkModel($engine, $model, $limit, $samples, $clear);
        }

        if ((bool) $this->option('json')) {
            $payload = $all ? $results : $results[0];
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($all) {
            $this->components->info('OoMyCache bench: '.count($results).' cacheable models');

            $this->table(
                ['Model', 'Warm app', 'Warm store', 'Warm total', 'Warm ms'],
                array_map(static fn (array $result): array => [
                    $result['model'],
                    $result['warm_avg_app_queries'],
                    $result['warm_avg_store_queries'],
                    $result['warm_avg_total_queries'],
                    $result['warm_avg_ms'],
                ], $results),
            );

            return self::SUCCESS;
        }

        $result = $results[0];
        $this->components->info('OoMyCache bench: '.$result['model']);

        if (! $clear) {
            $this->components->warn('Existing application cache was not cleared; only benchmark-isolated keys were used. Pass --clear to reset the global token (destructive).');
        }

        $this->table(
            ['Run', 'App queries', 'Store queries', 'Total', 'Time (ms)'],
            [
                ['cold', $result['cold']['app_queries'], $result['cold']['store_queries'], $result['cold']['queries'], $result['cold']['ms']],
                ['warm avg ('.$samples.' samples)', $result['warm_avg_app_queries'], $result['warm_avg_store_queries'], $result['warm_avg_total_queries'], $result['warm_avg_ms']],
            ],
        );

        if ($result['warm_avg_store_queries'] > 0) {
            $this->components->warn('Warm reads hit the cache-store table too; "0 app queries" is not "0 SQL statements" on the database store.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{model: string, limit: int, cache_cleared: bool, memo_reset_per_sample: bool, cold: array<string, mixed>, warm_samples: int, warm_avg_ms: float, warm_avg_app_queries: float, warm_avg_store_queries: float, warm_avg_total_queries: float, warm: list<array<string, mixed>>}
     */
    private function benchmarkModel(QueryCacheEngine $engine, string $model, int $limit, int $samples, bool $clear): array
    {
        $suffix = 'bench:'.bin2hex(random_bytes(8));

        $engine->resetRuntimeState();
        $cold = $this->measure($model, $limit, $suffix);
        $warm = [];

        for ($i = 0; $i < $samples; $i++) {
            $engine->resetRuntimeState();
            $warm[] = $this->measure($model, $limit, $suffix);
        }

        $warmQueries = array_sum(array_column($warm, 'app_queries')) / count($warm);
        $warmStoreQueries = array_sum(array_column($warm, 'store_queries')) / count($warm);
        $warmTotalQueries = array_sum(array_column($warm, 'queries')) / count($warm);
        $warmMs = array_sum(array_column($warm, 'ms')) / count($warm);

        return [
            'model' => $model,
            'limit' => $limit,
            'cache_cleared' => $clear,
            'memo_reset_per_sample' => true,
            'cold' => $cold,
            'warm_samples' => count($warm),
            'warm_avg_ms' => round($warmMs, 3),
            'warm_avg_app_queries' => round($warmQueries, 2),
            'warm_avg_store_queries' => round($warmStoreQueries, 2),
            'warm_avg_total_queries' => round($warmTotalQueries, 2),
            'warm' => $warm,
        ];
    }

    /**
     * @return array{queries: int, app_queries: int, store_queries: int, ms: float}
     */
    private function measure(string $model, int $limit, string $suffix): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);

        /** @var Model $modelInstance */
        $modelInstance = new $model;
        $query = $modelInstance->newQuery();

        if ($query instanceof QueryCacheBuilder) {
            $query = $query->cacheKey($suffix);
        }

        $query->limit($limit)->get();

        $ms = (hrtime(true) - $start) / 1_000_000;
        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        $appQueries = 0;
        $storeQueries = 0;
        $cacheTable = strtolower((string) config('cache.stores.database.table', 'cache'));
        $cacheLockTable = strtolower((string) config('cache.stores.database.lock_table', 'cache_locks'));

        foreach ($log as $entry) {
            $sql = strtolower((string) $entry['query']);

            if (str_contains($sql, '"'.$cacheTable.'"') || str_contains($sql, '`'.$cacheTable.'`')
                || str_contains($sql, '"'.$cacheLockTable.'"') || str_contains($sql, '`'.$cacheLockTable.'`')) {
                $storeQueries++;

                continue;
            }

            $appQueries++;
        }

        return [
            'queries' => count($log),
            'app_queries' => $appQueries,
            'store_queries' => $storeQueries,
            'ms' => round($ms, 3),
        ];
    }
}
