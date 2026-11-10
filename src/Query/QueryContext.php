<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Oomycache\Oomycache\Builder\QueryCacheBuilder;
use Oomycache\Oomycache\Builder\QueryCacheQueryBuilder;
use RuntimeException;

/**
 * Immutable snapshot of everything needed to decide, key and store a query
 * result. Captured at read time from the scoped Eloquent builder.
 */
final class QueryContext
{
    /**
     * @param  array<int, mixed>  $bindings
     * @param  array<int, string>  $columns
     * @param  array<string, string|null>  $eagerLoadSignatures
     * @param  list<string>  $dependencyTables
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $pagination
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly ?string $connectionName,
        public readonly string $sql,
        public readonly array $bindings,
        public readonly array $columns,
        public readonly string $intent,
        public readonly array $eagerLoadSignatures,
        public readonly array $dependencyTables,
        public readonly bool $streaming,
        public readonly bool $pessimisticLock,
        public readonly bool $insideTransaction,
        public readonly array $options,
        public readonly ?int $ttlOverride,
        public readonly ?string $keySuffix,
        public readonly mixed $cacheIf,
        public readonly array $pagination = [],
    ) {}

    /**
     * @param  array<int, mixed>|string  $columns
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $extra
     */
    public static function capture(
        QueryCacheBuilder $builder,
        string $intent,
        array|string $columns,
        array $options,
        array $extra = [],
    ): self {
        $scoped = $builder->applyScopes();
        $base = $scoped->getQuery();
        $model = $builder->getModel();

        if (! $base instanceof QueryCacheQueryBuilder) {
            throw new RuntimeException('Query cache builder has an unexpected base query builder.');
        }

        $eagerSignatures = [];
        $tables = [$model->getTable()];

        foreach ($scoped->getEagerLoads() as $name => $constraint) {
            [$relationTables, $signature] = EagerLoadResolver::resolve($model, $name, $constraint);

            if ($signature !== null) {
                $eagerSignatures[$name] = $signature;
            }

            foreach ($relationTables as $table) {
                $tables[] = $table;
            }
        }

        foreach ($base->tableRecorder()->tables() as $table) {
            $tables[] = $table;
        }

        foreach ($builder->dependencyRelations() as $relation) {
            [$relationTables] = EagerLoadResolver::resolve($model, $relation, null);

            foreach ($relationTables as $table) {
                $tables[] = $table;
            }
        }

        foreach ($options['dependencies'] ?? [] as $dependency) {
            if (is_string($dependency) && class_exists($dependency) && is_subclass_of($dependency, EloquentBuilder::class)) {
                continue; // not a model class
            }

            if (is_string($dependency) && class_exists($dependency) && is_subclass_of($dependency, Model::class)) {
                /** @var Model $instance */
                $instance = new $dependency;
                $tables[] = $instance->getTable();
            } elseif (is_string($dependency)) {
                $tables[] = $dependency;
            }
        }

        $dependencyTables = array_values(array_unique(array_filter($tables, static fn (mixed $table): bool => $table !== '')));

        return new self(
            modelClass: $model::class,
            table: $model->getTable(),
            connectionName: $model->getConnection()->getName(),
            sql: $base->toSql(),
            bindings: Fingerprint::canonicalizeBindings($base->getBindings()),
            columns: array_values(array_map(
                static fn (mixed $column): string => is_string($column) ? $column : (string) $column,
                is_array($columns) ? $columns : [$columns],
            )),
            intent: $intent,
            eagerLoadSignatures: $eagerSignatures,
            dependencyTables: $dependencyTables,
            streaming: (bool) ($extra['streaming'] ?? false),
            pessimisticLock: $base->lock !== null,
            insideTransaction: $model->getConnection()->transactionLevel() > 0,
            options: $options,
            ttlOverride: $extra['ttl'] ?? null,
            keySuffix: $extra['key_suffix'] ?? null,
            cacheIf: $extra['cache_if'] ?? null,
            pagination: $extra['pagination'] ?? [],
        );
    }
}
