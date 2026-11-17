<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Read-only static scan for write paths that bypass OoMyCache invalidation.
 *
 * The package observes Eloquent model events and its own builder writes. Raw
 * DB statements, quiet saves and (outside the caching relations) pivot writes
 * are invisible to it and need an explicit flush. This command reports where
 * those patterns occur so adopters can audit them; it never executes or edits
 * application code.
 */
class AuditCommand extends Command
{
    protected $signature = 'oomycache:audit
                            {--json : Output JSON instead of a grouped report}';

    protected $description = 'Report write paths OoMyCache cannot observe (raw DB, quiet, pivot writes).';

    /** Keep the scan and the output bounded on large applications. */
    private const int MAX_FILES = 2000;

    private const int MAX_FINDINGS_PER_CATEGORY = 100;

    /** A flush call this close to a write is treated as an explicit fix. */
    private const int COVERED_WINDOW_LINES = 6;

    private const string FLUSH_PATTERN = '/Oomycache\s*::\s*(?:flush|flushTables|clear)\s*\(|->\s*flushQueryCache(?:Instance)?\s*\(/';

    private const string CLEAR_PATTERN = '/Oomycache\s*::\s*clear\s*\(/';

    /** Filament form/table columns use ->toggle() for UI state, not pivots. */
    private const string COLUMN_TOGGLE_PATTERN = '/(?:ToggleColumn|Toggle)::make\s*\(/';

    /**
     * Regex → hint per category. Patterns run against whole file contents so
     * multi-line chains (DB::table(...)->...->update()) are detected too.
     *
     * @var array<string, list<array{pattern: string, hint: string}>>
     */
    private const array PATTERNS = [
        'Quiet writes' => [
            [
                'pattern' => '/->\s*saveQuietly\s*\(/',
                'hint' => 'saveQuietly() skips model events; flush the table explicitly after the write.',
            ],
            [
                'pattern' => '/(?:->|::)\s*withoutEvents\s*\(/',
                'hint' => 'withoutEvents() skips model events; flush the table explicitly after the write.',
            ],
        ],
        'Raw DB writes' => [
            [
                'pattern' => '/\bDB\s*::\s*(?:statement|unprepared)\s*\(/',
                'hint' => 'DB::statement()/unprepared() bypass invalidation; flush the affected tables explicitly.',
            ],
            [
                'pattern' => '/\bDB\s*::\s*table\s*\([^;]{0,400}?->\s*(?:insertOrIgnore|update|insert|upsert|delete|truncate)\s*\(/',
                'hint' => 'Raw DB::table() write bypasses invalidation; flush the table explicitly.',
            ],
        ],
        'Pivot writes' => [
            [
                'pattern' => '/->\s*(?:updateExistingPivot|attach|detach|sync|toggle)\s*\(/',
                'hint' => 'Pivot write bypasses invalidation unless the relation is a caching relation; flush both tables explicitly.',
            ],
        ],
    ];

    public function handle(): int
    {
        $results = $this->audit();
        $total = array_sum(array_map(static fn (array $findings): int => count($findings), $results));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('OoMyCache audit: write paths the package cannot observe');

        $uncovered = array_map(
            static fn (array $findings): array => array_values(array_filter($findings, static fn (array $finding): bool => ! $finding['covered'])),
            $results,
        );
        $coveredTotal = $total - array_sum(array_map(static fn (array $findings): int => count($findings), $uncovered));

        if ($total === 0) {
            $this->components->info('No unobservable write paths found under '.(function_exists('app_path') ? app_path() : 'the application path').'.');

            return self::SUCCESS;
        }

        foreach ($uncovered as $category => $findings) {
            if ($findings === []) {
                continue;
            }

            $this->components->warn($category.' ('.count($findings).')');

            foreach ($findings as $finding) {
                $this->line(sprintf('  %s:%d  %s', $finding['path'], $finding['line'], $finding['hint']));
            }
        }

        if ($coveredTotal > 0) {
            $this->components->info('Covered by an explicit flush ('.$coveredTotal.') — no action needed.');
        }

        $open = $total - $coveredTotal;

        $this->line($open === 0
            ? 'All unobservable write paths have an explicit flush nearby.'
            : sprintf('Total: %d open finding(s). Flush the affected tables explicitly after these writes.', $open));

        return self::SUCCESS;
    }

    /**
     * Scan app_path() for unobservable write patterns. Read-only and bounded.
     *
     * @return array<string, list<array{path: string, line: int, hint: string, covered: bool}>>
     */
    public function audit(): array
    {
        /** @var array<string, list<array{path: string, line: int, hint: string, covered: bool}>> $results */
        $results = array_fill_keys(array_keys(self::PATTERNS), []);
        $root = function_exists('app_path') ? app_path() : null;

        if ($root === null || ! is_dir($root)) {
            return $results;
        }

        /** @var array<string, array<string, true>> $seen */
        $seen = array_fill_keys(array_keys(self::PATTERNS), []);

        $finder = Finder::create()
            ->files()
            ->in($root)
            ->name('*.php')
            ->exclude(['vendor', 'storage', 'bootstrap', 'cache'])
            ->ignoreVCS(true)
            ->ignoreDotFiles(true);

        $scanned = 0;

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            if ($scanned++ >= self::MAX_FILES) {
                break;
            }

            $content = $file->getContents();
            $relative = $this->relativePath($file->getPathname(), $root);
            $lines = explode("\n", $content);
            $fileHasClear = preg_match(self::CLEAR_PATTERN, $content) === 1;

            foreach (self::PATTERNS as $category => $entries) {
                foreach ($entries as $entry) {
                    $matches = [];

                    if (preg_match_all($entry['pattern'], $content, $matches, PREG_OFFSET_CAPTURE) === false) {
                        continue;
                    }

                    foreach ($matches[0] as $match) {
                        $line = substr_count($content, "\n", 0, $match[1]) + 1;

                        if ($category === 'Pivot writes'
                            && preg_match('/->\s*toggle\s*\(/', $match[0]) === 1
                            && $this->hasNearbyColumnToggle($lines, $line)) {
                            continue;
                        }

                        $key = $relative.':'.$line.':'.$entry['hint'];

                        if (isset($seen[$category][$key])) {
                            continue;
                        }

                        $seen[$category][$key] = true;

                        if (count($results[$category]) >= self::MAX_FINDINGS_PER_CATEGORY) {
                            continue;
                        }

                        $results[$category][] = [
                            'path' => $relative,
                            'line' => $line,
                            'hint' => $entry['hint'],
                            'covered' => $fileHasClear || $this->hasNearbyFlush($lines, $line),
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasNearbyFlush(array $lines, int $line): bool
    {
        $from = max(1, $line - self::COVERED_WINDOW_LINES);
        $to = min(count($lines), $line + self::COVERED_WINDOW_LINES);

        for ($index = $from; $index <= $to; $index++) {
            if (preg_match(self::FLUSH_PATTERN, $lines[$index - 1]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasNearbyColumnToggle(array $lines, int $line): bool
    {
        $from = max(1, $line - self::COVERED_WINDOW_LINES);
        $to = min(count($lines), $line + self::COVERED_WINDOW_LINES);

        for ($index = $from; $index <= $to; $index++) {
            if (preg_match(self::COLUMN_TOGGLE_PATTERN, $lines[$index - 1]) === 1) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $path, string $root): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
