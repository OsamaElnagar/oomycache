<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Oomycache\Oomycache\Cache\DatabaseCachePruner;

class PruneCommand extends Command
{
    protected $signature = 'oomycache:prune
                            {store? : Cache store name to prune; defaults to the OoMyCache store, then cache.default}
                            {--dry-run : Count expired entries without deleting them}
                            {--chunk= : Rows scanned per keyset page (default: oomycache.maintenance.prune.chunk)}
                            {--max-seconds= : Soft time budget in seconds, 0 disables the budget (default: oomycache.maintenance.prune.max_seconds)}';

    protected $description = 'Delete expired rows from a database cache store (expiry-guarded, keyset-ordered, never touches cache_locks).';

    public function handle(DatabaseCachePruner $pruner): int
    {
        $store = $this->argument('store');
        $inspection = $pruner->inspect(is_string($store) && $store !== '' ? $store : null);

        if (! $inspection['supported']) {
            $this->components->error((string) $inspection['refusal']);

            if (is_string($inspection['hint']) && $inspection['hint'] !== '') {
                $this->components->warn($inspection['hint']);
            }

            return self::FAILURE;
        }

        $chunk = $this->resolveChunk($pruner);

        if ($chunk === null) {
            return self::INVALID;
        }

        $maxSeconds = $this->resolveMaxSeconds($pruner);

        if ($maxSeconds === null) {
            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $table = (string) $inspection['table'];

        $result = $pruner->prune(
            $table,
            $inspection['connection'],
            (string) $inspection['prefix'],
            $chunk,
            $maxSeconds,
            $dryRun,
        );

        $this->components->info($dryRun ? 'OoMyCache prune (dry run)' : 'OoMyCache prune');

        $this->table(['Store', 'Driver', 'Table', 'Connection', 'Prefix'], [
            [
                $inspection['store'],
                (string) $inspection['driver'],
                $table,
                $inspection['connection'] ?? 'default',
                $inspection['prefix'] === '' ? '(none)' : $inspection['prefix'],
            ],
        ]);

        // Plain line output (not the Termwind console components) so schedulers and
        // tests can rely on one statistic per line.
        $this->line(sprintf('Store: %s', $inspection['store']));
        $this->line(sprintf('Dry run: %s', $dryRun ? 'yes' : 'no'));
        $this->line(sprintf('Scanned: %d row(s)', $result['scanned']));
        $this->line(sprintf('Expired: %d', $result['expired']));
        $this->line(sprintf('Deleted: %s', $dryRun ? '0 (dry run)' : (string) $result['deleted']));
        $this->line(sprintf('Elapsed: %d ms', $result['elapsed_ms']));

        if ($result['budget_exhausted']) {
            $this->warn('The time budget was exhausted; re-run oomycache:prune to continue. Deleted rows are gone and the next run restarts from the store prefix.');
        }

        return self::SUCCESS;
    }

    private function resolveChunk(DatabaseCachePruner $pruner): ?int
    {
        $option = $this->option('chunk');

        if ($option === null) {
            return $pruner->defaultChunkSize();
        }

        if (! is_numeric($option)) {
            $this->components->error('The --chunk option must be a positive integer.');

            return null;
        }

        $chunk = (int) $option;

        $chunk = (int) $option;

        if ($chunk < 1 || $chunk > 10_000) {
            $this->components->error('The --chunk option must be between 1 and 10000.');

            return null;
        }

        return $chunk;
    }

    private function resolveMaxSeconds(DatabaseCachePruner $pruner): ?float
    {
        $option = $this->option('max-seconds');

        if ($option === null) {
            return $pruner->defaultMaxSeconds();
        }

        if (! is_numeric($option)) {
            $this->components->error('The --max-seconds option must be a number (0 disables the budget).');

            return null;
        }

        $maxSeconds = (float) $option;

        if ($maxSeconds < 0.0) {
            $this->components->error('The --max-seconds option cannot be negative (use 0 to disable the budget).');

            return null;
        }

        return $maxSeconds;
    }
}
