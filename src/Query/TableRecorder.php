<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

use Oomycache\Oomycache\Builder\QueryCacheQueryBuilder;

/**
 * Collects the database tables a query touches while it is being built.
 *
 * Used by {@see QueryCacheQueryBuilder} for
 * from/join/subquery recording so a write to a joined table can invalidate
 * cached reads of the joined query.
 */
final class TableRecorder
{
    /** @var array<string, true> */
    private array $tables = [];

    public function record(mixed $table): void
    {
        if (is_array($table)) {
            foreach ($table as $item) {
                $this->record($item);
            }

            return;
        }

        if (! is_string($table) || $table === '') {
            return;
        }

        $name = str_contains($table, ' as ') ? strstr($table, ' as ', true) : $table;
        $name = trim((string) $name, '`"[] ');

        if ($name !== '') {
            $this->tables[$name] = true;
        }
    }

    public function recordAlias(string $alias): void
    {
        $this->tables[trim($alias, '`"[] ')] = true;
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->tables);
    }
}
