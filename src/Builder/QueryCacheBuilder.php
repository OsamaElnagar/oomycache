<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Builder;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Concerns\HasQueryCache;
use Oomycache\Oomycache\Query\BaseQueryContext;
use Oomycache\Oomycache\Query\DependencyRecorder;
use Oomycache\Oomycache\Query\QueryContext;
use Oomycache\Oomycache\Query\TableRecorder;
use Throwable;

/**
 * The transparent caching Eloquent builder returned for models using
 * {@see HasQueryCache}.
 *
 * All read methods execute through the engine (classify → key → cache →
 * hydrate); write methods execute normally and bump the table's version
 * namespace. Streaming APIs set a bypass marker so their internal get()
 * pages are never cached.
 */
/**
 * @extends Builder<Model>
 */
class QueryCacheBuilder extends Builder
{
    private bool $dontCache = false;

    private bool $streaming = false;

    private ?int $ttlOverride = null;

    private bool $forever = false;

    private ?string $keySuffix = null;

    private mixed $cacheIf = null;

    private DependencyRecorder $dependencyRecorder;

    public function __construct(QueryBuilder $query, private readonly QueryCacheEngine $engine)
    {
        parent::__construct($query);

        $this->dependencyRecorder = new DependencyRecorder;
    }

    public function setModel(Model $model): static
    {
        parent::setModel($model);
        $this->syncBaseContext();

        return $this;
    }

    public function toBase(): QueryBuilder
    {
        $base = parent::toBase();

        if ($base instanceof QueryCacheQueryBuilder) {
            $base->setCacheContext($this->baseContext($base->tableRecorder()));
        }

        return $base;
    }

    /* ---------------------------------------------------------------------
     * Fluent per-query API
     * ------------------------------------------------------------------ */

    public function cacheFor(int|DateInterval $ttl): static
    {
        $this->ttlOverride = $ttl instanceof DateInterval ? $this->dateIntervalToSeconds($ttl) : $ttl;

        return $this;
    }

    public function cacheForForever(): static
    {
        $this->forever = true;

        return $this;
    }

    public function dontCache(): static
    {
        $this->dontCache = true;

        return $this;
    }

    public function cacheKey(string $suffix): static
    {
        $this->keySuffix = $suffix;

        return $this;
    }

    public function cacheIf(bool|Closure $condition): static
    {
        $this->cacheIf = $condition;

        return $this;
    }

    /* ---------------------------------------------------------------------
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int, mixed>|string  $columns
     * @return Collection<int, Model>
     */
    public function get($columns = ['*']): Collection
    {
        return $this->cacheRead('get', $columns, fn (): mixed => $this->getFromDatabase($columns));
    }

    /**
     * @param  array<int, mixed>|string  $columns
     */
    public function first($columns = ['*']): ?Model
    {
        $query = clone $this;
        $query->take(1);

        return $query->cacheRead(
            'first',
            $columns,
            fn (): mixed => $query->getFromDatabase($columns)->first(),
        );
    }

    /**
     * @param  array<int, mixed>|string  $columns
     */
    public function count($columns = ['*']): int
    {
        $column = is_array($columns) ? ($columns[0] ?? '*') : $columns;

        return $this->cacheRead(
            'count',
            [$column],
            fn (): mixed => parent::count($column),
        );
    }

    public function exists(): bool
    {
        return $this->cacheRead('exists', ['*'], fn (): mixed => parent::exists());
    }

    public function doesntExist(): bool
    {
        return $this->cacheRead('doesntExist', ['*'], fn (): mixed => parent::doesntExist());
    }

    /**
     * @param  string  $column
     */
    public function sum($column): mixed
    {
        return $this->cacheRead('sum', [$column], fn (): mixed => parent::sum($column));
    }

    /**
     * @param  string  $column
     */
    public function avg($column): mixed
    {
        return $this->cacheRead('avg', [$column], fn (): mixed => parent::avg($column));
    }

    /**
     * @param  string  $column
     */
    public function min($column): mixed
    {
        return $this->cacheRead('min', [$column], fn (): mixed => parent::min($column));
    }

    /**
     * @param  string  $column
     */
    public function max($column): mixed
    {
        return $this->cacheRead('max', [$column], fn (): mixed => parent::max($column));
    }

    /**
     * @param  string  $column
     * @param  string|null  $key
     */
    public function pluck($column, $key = null): \Illuminate\Support\Collection
    {
        return $this->cacheRead('pluck', [$column, $key], fn (): mixed => parent::pluck($column, $key));
    }

    public function value($column): mixed
    {
        $query = clone $this;
        $query->take(1);

        return $query->cacheRead('value', [$column], function () use ($query, $column): mixed {
            $result = $query->getFromDatabase([$column])->first();

            if ($result === null) {
                return null;
            }

            $column = $column instanceof Expression
                ? $column->getValue($query->getQuery()->getGrammar())
                : $column;

            return $result->{Str::afterLast((string) $column, '.')};
        });
    }

    /**
     * @param  array<int, mixed>|string  $columns
     * @param  mixed  $total
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $total ??= (int) $this->toBase()->getCountForPagination();
        $perPage = (int) (value($perPage, $total) ?: $this->getModel()->getPerPage());

        if ((int) $total === 0) {
            return $this->paginator($this->getModel()->newCollection(), 0, $perPage, (int) $page, [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]);
        }

        $pageQuery = clone $this;
        $pageQuery->forPage((int) $page, $perPage);

        $items = $pageQuery->cacheRead(
            'paginate',
            $columns,
            fn (): mixed => $pageQuery->getFromDatabase($columns),
            ['pagination' => ['page' => (int) $page, 'per_page' => $perPage]],
        );

        return $this->paginator($items, (int) $total, $perPage, (int) $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null): Paginator
    {
        $page = (int) ($page ?: Paginator::resolveCurrentPage($pageName));
        $perPage = (int) ($perPage ?: $this->getModel()->getPerPage());

        $pageQuery = clone $this;
        $pageQuery->offset(($page - 1) * $perPage)->limit($perPage + 1);

        $items = $pageQuery->cacheRead(
            'simplePaginate',
            $columns,
            fn (): mixed => $pageQuery->getFromDatabase($columns),
            ['pagination' => ['page' => $page, 'per_page' => $perPage]],
        );

        return $this->simplePaginator($items, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    public function cursorPaginate($perPage = null, $columns = ['*'], $cursorName = 'cursor', $cursor = null): CursorPaginator
    {
        return parent::cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /* ---------------------------------------------------------------------
     * Relationship dependencies (withCount/withSum/…/whereHas/…)
     * ------------------------------------------------------------------ */

    public function withAggregate($relations, $column, $function = null): static
    {
        $this->dependencyRecorder->record($relations);

        return parent::withAggregate($relations, $column, $function);
    }

    public function withCount($relations): static
    {
        $this->dependencyRecorder->record($relations);

        return parent::withCount($relations);
    }

    /**
     * @param  array<string, mixed>|string  $relation
     * @param  string  $column
     */
    public function withSum($relation, $column): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::withSum($relation, $column);
    }

    /**
     * @param  array<string, mixed>|string  $relation
     * @param  string  $column
     */
    public function withAvg($relation, $column): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::withAvg($relation, $column);
    }

    /**
     * @param  array<string, mixed>|string  $relation
     * @param  string  $column
     */
    public function withMin($relation, $column): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::withMin($relation, $column);
    }

    /**
     * @param  array<string, mixed>|string  $relation
     * @param  string  $column
     */
    public function withMax($relation, $column): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::withMax($relation, $column);
    }

    /**
     * @param  array<string, mixed>|string  $relation
     */
    public function withExists($relation): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::withExists($relation);
    }

    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::has($relation, $operator, $count, $boolean, $callback);
    }

    public function orHas($relation, $operator = '>=', $count = 1): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::orHas($relation, $operator, $count);
    }

    public function doesntHave($relation, $boolean = 'and', ?Closure $callback = null): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::doesntHave($relation, $boolean, $callback);
    }

    public function orDoesntHave($relation): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::orDoesntHave($relation);
    }

    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::whereHas($relation, $callback, $operator, $count);
    }

    public function orWhereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::orWhereHas($relation, $callback, $operator, $count);
    }

    public function whereDoesntHave($relation, ?Closure $callback = null): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::whereDoesntHave($relation, $callback);
    }

    public function whereRelation($relation, $column, $operator = null, $value = null): static
    {
        $this->dependencyRecorder->record($relation);

        return parent::whereRelation($relation, $column, $operator, $value);
    }

    /**
     * @return list<string>
     */
    public function dependencyRelations(): array
    {
        return $this->dependencyRecorder->relations();
    }

    /* ---------------------------------------------------------------------
     * Writes (execute, then invalidate the table namespace)
     * ------------------------------------------------------------------ */

    public function update(array $values): int
    {
        $result = parent::update($values);
        $this->flushAfterWrite('update');

        return $result;
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $result = parent::upsert($values, $uniqueBy, $update);
        $this->flushAfterWrite('upsert');

        return $result;
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        $result = parent::increment($column, $amount, $extra);
        $this->flushAfterWrite('increment');

        return $result;
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $result = parent::decrement($column, $amount, $extra);
        $this->flushAfterWrite('decrement');

        return $result;
    }

    public function delete(): mixed
    {
        $result = parent::delete();
        $this->flushAfterWrite('delete');

        return $result;
    }

    public function forceDelete(): mixed
    {
        $result = parent::forceDelete();
        $this->flushAfterWrite('forceDelete');

        return $result;
    }

    /**
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null): int
    {
        $result = parent::touch($column);
        $this->flushAfterWrite('touch');

        return (int) $result;
    }

    public function truncate(): void
    {
        $this->toBase()->truncate();
        $this->flushAfterWrite('truncate');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $result = $this->toBase()->insert($values);
        $this->flushAfterWrite('insert');

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Streaming APIs must never be cached.
     * ------------------------------------------------------------------ */

    public function chunk($count, callable $callback): bool
    {
        return $this->runWithoutCache(fn (): bool => parent::chunk($count, $callback));
    }

    public function chunkById($count, callable $callback, $column = null, $alias = null): bool
    {
        return $this->runWithoutCache(fn (): bool => parent::chunkById($count, $callback, $column, $alias));
    }

    public function each(callable $callback, $count = 1000): bool
    {
        return $this->runWithoutCache(fn (): bool => parent::each($callback, $count));
    }

    public function lazy($chunkSize = 1000): LazyCollection
    {
        return $this->runWithoutCache(fn (): mixed => parent::lazy($chunkSize));
    }

    public function lazyById($chunkSize = 1000, $column = null, $alias = null): LazyCollection
    {
        return $this->runWithoutCache(fn (): mixed => parent::lazyById($chunkSize, $column, $alias));
    }

    public function cursor(): LazyCollection
    {
        return $this->runWithoutCache(fn (): mixed => parent::cursor());
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int, mixed>|string  $columns
     * @param  array<string, mixed>  $extra
     */
    private function cacheRead(string $intent, array|string $columns, Closure $execute, array $extra = []): mixed
    {
        if ($this->dontCache || $this->streaming) {
            return $execute();
        }

        $model = $this->getModel();

        if (! $this->engine->isCacheableModel($model::class)) {
            return $execute();
        }

        try {
            $context = QueryContext::capture(
                $this,
                $intent,
                $columns,
                $this->engine->optionsFor($model::class),
                [
                    'ttl' => $this->forever ? 0 : $this->ttlOverride,
                    'key_suffix' => $this->keySuffix,
                    'cache_if' => $this->cacheIf,
                ] + $extra,
            );
        } catch (Throwable) {
            return $execute();
        }

        $decision = $this->engine->decide($context);

        if (! $decision->allowed) {
            $this->engine->dispatchBypassed($context, $decision->reason);

            return $execute();
        }

        return $this->engine->remember($context, $execute);
    }

    /**
     * @param  array<int, mixed>|string  $columns
     * @return Collection<int, Model>
     */
    protected function getFromDatabase(array|string $columns = ['*']): Collection
    {
        $builder = $this->applyScopes();

        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->applyAfterQueryCallbacks($builder->getModel()->newCollection($models));
    }

    private function baseContext(TableRecorder $recorder): BaseQueryContext
    {
        return new BaseQueryContext(
            $this->engine,
            $this->getModel(),
            $this->engine->optionsFor($this->getModel()::class),
            $recorder,
        );
    }

    private function syncBaseContext(): void
    {
        $query = $this->getQuery();

        if ($query instanceof QueryCacheQueryBuilder) {
            $query->setCacheContext($this->baseContext($query->tableRecorder()));
        }
    }

    private function flushAfterWrite(string $reason): void
    {
        if (! (bool) config('oomycache.safety.intercept_mass_writes', true)) {
            return;
        }

        $this->engine->flushModelAfterWrite($this->getModel(), $reason);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runWithoutCache(callable $callback): mixed
    {
        $this->streaming = true;

        try {
            return $callback();
        } finally {
            $this->streaming = false;
        }
    }

    private function dateIntervalToSeconds(DateInterval $interval): int
    {
        $now = new DateTimeImmutable;
        $target = $now->add($interval);

        return max(1, $target->getTimestamp() - $now->getTimestamp());
    }
}
