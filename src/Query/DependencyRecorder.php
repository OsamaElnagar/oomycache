<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

/**
 * Records relation names used by withCount/withSum/…/has/whereHas so the
 * query's dependency table set includes the related (and pivot) tables.
 *
 * Without this, a Filament `counts('stockLevels')` column would stay stale
 * after child rows change — the classic relationship-aggregate cache gap.
 */
final class DependencyRecorder
{
    /** @var array<string, true> */
    private array $relations = [];

    public function record(mixed $relations): void
    {
        if (is_array($relations)) {
            foreach ($relations as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $this->relations[$key] = true;
                }

                if (is_string($value) && $value !== '') {
                    $this->relations[$value] = true;
                }
            }

            return;
        }

        if (is_string($relations) && $relations !== '') {
            $this->relations[$relations] = true;
        }
    }

    /**
     * @return list<string>
     */
    public function relations(): array
    {
        return array_keys($this->relations);
    }
}
