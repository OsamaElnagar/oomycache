<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

class StatusCommand extends Command
{
    protected $signature = 'oomycache:status {--model= : Show resolved options for one model class}';

    protected $description = 'Show OoMyCache configuration, store capabilities and cacheable models.';

    public function handle(QueryCacheEngine $engine): int
    {
        $status = $engine->status();

        $this->components->info('OoMyCache status');

        $this->table(['Key', 'Value'], [
            ['enabled', $status['enabled'] ? 'yes' : 'no'],
            ['store', (string) $status['store']],
            ['prefix', (string) $status['prefix']],
            ['version', (string) $status['version']],
            ['ttl', (string) $status['ttl']],
            ['ttl_jitter', (string) $status['ttl_jitter']],
            ['payload_mode', (string) $status['payload_mode']],
            ['global_token', $status['global_token']],
            ['store_class', $status['capabilities']['store']],
            ['taggable', $status['capabilities']['taggable'] ? 'yes' : 'no'],
            ['lockable', $status['capabilities']['lockable'] ? 'yes' : 'no'],
        ]);

        $models = $engine->cacheableModels();

        $stats = is_array($status['stats'] ?? null) ? $status['stats'] : [];

        if (($stats['enabled'] ?? false) === true) {
            $this->components->info('Stats counters (window: '.((int) ($stats['window_seconds'] ?? 0)).'s)');
            $this->table(['Metric', 'Value'], [
                ['hits', (string) ($stats['hits'] ?? 0)],
                ['misses', (string) ($stats['misses'] ?? 0)],
                ['bypasses', (string) ($stats['bypasses'] ?? 0)],
                ['invalidations', (string) ($stats['invalidations'] ?? 0)],
                ['clears', (string) ($stats['clears'] ?? 0)],
            ]);
        }

        $this->components->info(sprintf('%d cacheable model(s) discovered', count($models)));

        if ($models !== []) {
            $this->table(['Model', 'Table'], array_map(
                static function (string $model): array {
                    /** @var Model $instance */
                    $instance = new $model;

                    return [$model, $instance->getTable()];
                },
                $models,
            ));
        }

        $model = $this->option('model');

        if (is_string($model) && $model !== '') {
            if (! class_exists($model)) {
                $this->components->error("Model [{$model}] does not exist.");

                return self::FAILURE;
            }

            $this->components->info("Resolved options for {$model}");
            $this->table(
                ['Option', 'Value'],
                array_map(
                    static fn (string $key, mixed $value): array => [$key, is_scalar($value) ? (string) $value : json_encode($value)],
                    array_keys($engine->optionsFor($model)),
                    array_values($engine->optionsFor($model)),
                ),
            );
        }

        return self::SUCCESS;
    }
}
