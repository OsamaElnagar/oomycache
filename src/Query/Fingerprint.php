<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Oomycache\Oomycache\Exceptions\UnkeyableValueException;
use Throwable;
use UnitEnum;

/**
 * Canonicalises query parts and produces the single hash that identifies a
 * cacheable query. One canonical fingerprint beats a hand-written per-clause
 * key encoder (a competitor needed 1,225 lines and still shipped collision
 * fixes); if a value cannot be represented we throw and the builder runs the
 * query uncached instead.
 */
final class Fingerprint
{
    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public static function canonicalizeBindings(array $bindings): array
    {
        return array_map(self::canonicalizeValue(...), array_values($bindings));
    }

    public static function canonicalizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return [
                '__datetime',
                $value->format('Y-m-d H:i:s.uP'),
                $value->getTimezone()->getName(),
            ];
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof BackedEnum
                ? ['__backed_enum', $value::class, $value->value]
                : ['__unit_enum', $value::class, $value->name];
        }

        if ($value instanceof Expression) {
            return ['__expression', self::expressionValue($value)];
        }

        if (is_array($value)) {
            return array_map(self::canonicalizeValue(...), $value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return ['__stringable', $value::class, (string) $value];
        }

        throw UnkeyableValueException::forBinding($value);
    }

    public static function expressionValue(Expression $expression): string
    {
        try {
            return (string) $expression->getValue(app('db')->connection()->getQueryGrammar());
        } catch (Throwable) {
            return $expression::class;
        }
    }

    /**
     * Build a deterministic 32-char hash from a nested fingerprint array.
     *
     * @param  array<string, mixed>  $parts
     */
    public static function hash(array $parts): string
    {
        return hash('xxh128', serialize(self::canonicalizeValue($parts)));
    }
}
