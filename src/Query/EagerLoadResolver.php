<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Oomycache\Oomycache\Exceptions\UnkeyableValueException;
use Throwable;

/**
 * Resolves eager-load names to the database tables they read and to a
 * closure fingerprint, without executing any SQL.
 */
final class EagerLoadResolver
{
    /**
     * @return array{0: list<string>, 1: ?string}
     */
    public static function resolve(Model $model, string $name, ?Closure $constraint): array
    {
        $tables = [];
        $signature = null;
        $current = $model;
        $segments = explode('.', $name);

        foreach ($segments as $index => $segment) {
            try {
                $relation = Relation::noConstraints(
                    static fn (): mixed => $current->newInstance()->{$segment}(),
                );
            } catch (Throwable) {
                throw UnkeyableValueException::forRelation($name);
            }

            if (! $relation instanceof Relation) {
                throw UnkeyableValueException::forRelation($name);
            }

            if ($relation instanceof MorphTo || $relation instanceof MorphToMany) {
                throw UnkeyableValueException::forRelation($name);
            }

            $related = $relation->getRelated();
            $tables[] = $related->getTable();

            if ($relation instanceof BelongsToMany) {
                $tables[] = $relation->getTable();
            }

            if ($index === 0) {
                $signature = ClosureFingerprint::of($constraint);
            }

            $current = $related;
        }

        return [$tables, $signature];
    }
}
