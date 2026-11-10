<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

class ClearCommand extends Command
{
    protected $signature = 'oomycache:clear
                            {--model=* : Model classes (or table names) to invalidate; omit to clear everything}';

    protected $description = 'Invalidate OoMyCache entries (O(1) version bumps; never touches other cache keys).';

    public function handle(QueryCacheEngine $engine): int
    {
        /** @var list<string> $models */
        $models = (array) $this->option('model');

        if ($models === []) {
            $engine->clear();
            $this->components->info('Cleared the global OoMyCache namespace.');

            return self::SUCCESS;
        }

        foreach ($models as $model) {
            if (class_exists($model) && $engine->isCacheableModel($model)) {
                $engine->flushModel($model, 'command');
                $this->components->info("Invalidated {$model}.");

                continue;
            }

            $engine->flushTable($model, null, 'command');
            $this->components->info("Invalidated table {$model}.");
        }

        return self::SUCCESS;
    }
}
