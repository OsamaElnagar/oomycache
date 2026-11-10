<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Oomycache\Oomycache\Builder\QueryCacheQueryBuilder;
use Oomycache\Oomycache\Cache\QueryCacheEngine;

/**
 * Carries the Eloquent context (model, resolved options, table recorder) onto
 * the base query builder so Filament's
 * `$query->toBase()->getCountForPagination()` can be cached too.
 */
final class BaseQueryContext
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly QueryCacheEngine $engine,
        private readonly Model $model,
        private readonly array $options,
        private readonly TableRecorder $recorder,
    ) {}

    public function engine(): QueryCacheEngine
    {
        return $this->engine;
    }

    public function model(): Model
    {
        return $this->model;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function recorder(): TableRecorder
    {
        return $this->recorder;
    }

    public function rememberCount(QueryCacheQueryBuilder $builder, Closure $execute): mixed
    {
        return $this->engine->rememberBaseCount($this, $builder, $execute);
    }
}
