<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Relations\Concerns;

use Closure;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

/**
 * Shared pivot-write invalidation for BelongsToMany and MorphToMany.
 *
 * Pivot writes do not dispatch Eloquent model events. Each mutating operation
 * flushes both the parent and related table namespaces after the outermost
 * write completes, so nested calls (sync → attach/detach) flush only once.
 */
trait FlushesPivotWrites
{
    private int $pivotWriteDepth = 0;

    /**
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     */
    public function attach($ids, array $attributes = [], $touch = true): void
    {
        $this->runPivotWrite(fn () => parent::attach($ids, $attributes, $touch));
    }

    /**
     * @param  mixed  $ids
     */
    public function detach($ids = null, $touch = true): int
    {
        return $this->runPivotWrite(fn () => parent::detach($ids, $touch));
    }

    /**
     * @param  mixed  $ids
     * @return array<int|string, array<string, mixed>>
     */
    public function sync($ids, $detaching = true): array
    {
        return $this->runPivotWrite(fn () => parent::sync($ids, $detaching));
    }

    /**
     * @param  mixed  $ids
     * @return array<int|string, array<string, mixed>>
     */
    public function syncWithoutDetaching($ids): array
    {
        return $this->runPivotWrite(fn () => parent::syncWithoutDetaching($ids));
    }

    /**
     * @param  mixed  $ids
     * @param  array<string, mixed>  $values
     * @return array<int|string, array<string, mixed>>
     */
    public function syncWithPivotValues($ids, array $values, bool $detaching = true): array
    {
        return $this->runPivotWrite(fn () => parent::syncWithPivotValues($ids, $values, $detaching));
    }

    /**
     * @param  mixed  $ids
     * @return array<int|string, array<string, mixed>>
     */
    public function toggle($ids, $touch = true): array
    {
        return $this->runPivotWrite(fn () => parent::toggle($ids, $touch));
    }

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        return $this->runPivotWrite(fn () => parent::updateExistingPivot($id, $attributes, $touch));
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function runPivotWrite(Closure $callback): mixed
    {
        $this->pivotWriteDepth++;

        try {
            return $callback();
        } finally {
            $this->pivotWriteDepth--;

            if ($this->pivotWriteDepth === 0) {
                $this->flushPivotCache();
            }
        }
    }

    private function flushPivotCache(): void
    {
        if (! (bool) config('oomycache.cache.pivot_invalidation', true)) {
            return;
        }

        $engine = app(QueryCacheEngine::class);

        // Both tables can contribute to a cached query's dependency vector:
        // the parent table and the related table. Flush both, regardless of
        // whether the related model is itself cacheable.
        $engine->flushModelAfterWrite($this->getParent(), 'pivot');
        $engine->flushModelAfterWrite($this->getRelated(), 'pivot');
    }
}
