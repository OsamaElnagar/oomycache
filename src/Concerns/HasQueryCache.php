<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Oomycache\Oomycache\Builder\QueryCacheBuilder;
use Oomycache\Oomycache\Builder\QueryCacheQueryBuilder;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Relations\CachingBelongsToMany;

/**
 * Opt-in trait: make a model's Eloquent reads cacheable.
 *
 * Add to a model and nothing else: Filament resources, tables, widgets,
 * relation managers, route bindings and every plain Eloquent read will flow
 * through the caching builders.
 */
trait HasQueryCache
{
    /**
     * Per-model overrides merged over the package config.
     *
     * @return array<string, mixed>
     */
    public function queryCacheOptions(): array
    {
        return [];
    }

    /**
     * Return the caching base query builder (cached paginator totals +
     * join/subquery table recording).
     */
    protected function newBaseQueryBuilder(): QueryCacheQueryBuilder
    {
        $connection = $this->getConnection();

        return new QueryCacheQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
        );
    }

    public function newEloquentBuilder($query): QueryCacheBuilder
    {
        return new QueryCacheBuilder($query, app(QueryCacheEngine::class));
    }

    /**
     * Wrap many-to-many relations so pivot writes invalidate both table
     * namespaces. Pivot writes do not dispatch Eloquent model events.
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ): CachingBelongsToMany {
        return new CachingBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
        );
    }

    public static function flushQueryCache(): void
    {
        app(QueryCacheEngine::class)->flushModel(static::class);
    }

    public function flushQueryCacheInstance(): void
    {
        app(QueryCacheEngine::class)->flushModel(static::class);
    }

    /**
     * Run a callback with query caching disabled.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutQueryCache(callable $callback): mixed
    {
        return app(QueryCacheEngine::class)->withoutQueryCache($callback);
    }
}
