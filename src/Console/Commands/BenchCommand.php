<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

class BenchCommand extends Command
{
    protected $signature = 'oomycache:bench
                            {model : Fully-qualified cacheable model class}
                            {--limit=100 : Rows fetched per query}
                            {--samples=5 : Warm samples}
                            {--json : Output JSON instead of a table}';

    protected $description = 'Benchmark cold vs warm Eloquent read queries for a cacheable model.';

    public function handle(QueryCacheEngine $engine): int
    {
        /** @var string $model */
        $model = $this->argument('model');

        if (! class_exists($model)) {
            $this->components->error("Model [{$model}] does not exist.");

            return self::FAILURE;
        }

        if (! $engine->isCacheableModel($model)) {
            $this->components->error("Model [{$model}] is not cacheable (missing HasQueryCache).");

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $samples = max(1, (int) $this->option('samples'));

        $engine->clear();

        $cold = $this->measure($model, $limit);
        $warm = [];

        for ($i = 0; $i < $samples; $i++) {
            $warm[] = $this->measure($model, $limit);
        }

        $warmQueries = array_sum(array_column($warm, 'app_queries')) / count($warm);
        $warmMs = array_sum(array_column($warm, 'ms')) / count($warm);

        $result = [
            'model' => $model,
            'limit' => $limit,
            'cold' => $cold,
            'warm_samples' => count($warm),
            'warm_avg_ms' => round($warmMs, 3),
            'warm_avg_app_queries' => round($warmQueries, 2),
            'warm' => $warm,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('OoMyCache bench: '.$model);
        $this->table(
            ['Run', 'App queries', 'Total queries', 'Time (ms)'],
            [
                ['cold', $cold['app_queries'], $cold['queries'], $cold['ms']],
                ['warm avg ('.$samples.' samples)', round($warmQueries, 2), '—', round($warmMs, 3)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array{queries: int, app_queries: int, ms: float}
     */
    private function measure(string $model, int $limit): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);

        $query = static fn (): mixed => $model::query()->limit($limit)->get();
        $query();

        $ms = (hrtime(true) - $start) / 1_000_000;
        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        $appQueries = 0;

        foreach ($log as $entry) {
            $sql = strtolower((string) $entry['query']);

            if (str_contains($sql, '"cache"') || str_contains($sql, '"cache_locks"')) {
                continue;
            }

            $appQueries++;
        }

        return [
            'queries' => count($log),
            'app_queries' => $appQueries,
            'ms' => round($ms, 3),
        ];
    }
}
