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

        if ($total === 0) {
            $this->components->info('No unobservable write paths found under '.(function_exists('app_path') ? app_path() : 'the application path').'.');

            return self::SUCCESS;
        }

        foreach ($results as $category => $findings) {
            if ($findings === []) {
                continue;
            }

            $this->components->warn($category.' ('.count($findings).')');

            foreach ($findings as $finding) {
                $this->line(sprintf('  %s:%d  %s', $finding['path'], $finding['line'], $finding['hint']));
            }
        }

        $this->line(sprintf('Total: %d finding(s). Flush the affected tables explicitly after these writes.', $total));

        return self::SUCCESS;
    }

    /**
     * Scan app_path() for unobservable write patterns. Read-only and bounded.
     *
     * @return array<string, list<array{path: string, line: int, hint: string}>>
     */
    public function audit(): array
    {
        /** @var array<string, list<array{path: string, line: int, hint: string}>> $results */
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

            foreach (self::PATTERNS as $category => $entries) {
                foreach ($entries as $entry) {
                    $matches = [];

                    if (preg_match_all($entry['pattern'], $content, $matches, PREG_OFFSET_CAPTURE) === false) {
                        continue;
                    }

                    foreach ($matches[0] as $match) {
                        $line = substr_count($content, "\n", 0, $match[1]) + 1;
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
                        ];
                    }
                }
            }
        }

        return $results;
    }

    private function relativePath(string $path, string $root): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
