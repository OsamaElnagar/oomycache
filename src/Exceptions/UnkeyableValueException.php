<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Exceptions;

use RuntimeException;

/**
 * Thrown while fingerprinting a query when a value cannot be represented
 * deterministically. The cache layer must fall back to an uncached query
 * rather than risk sharing rows between two different queries.
 */
final class UnkeyableValueException extends RuntimeException
{
    /**
     * @param  string  $reason  machine-readable bypass reason for observability
     */
    public function __construct(string $message, public readonly string $reason = 'unkeyable-value')
    {
        parent::__construct($message);
    }

    public static function forBinding(mixed $value): self
    {
        return new self(sprintf(
            'Cannot fingerprint a query binding of type [%s]; the query will run uncached.',
            get_debug_type($value),
        ), 'unkeyable-binding');
    }

    public static function forEagerLoad(string $name): self
    {
        return new self(sprintf(
            'Cannot fingerprint eager load [%s]; the query will run uncached.',
            $name,
        ), 'unkeyable-eager-load');
    }

    public static function forRelation(string $relation): self
    {
        return new self(sprintf(
            'Cannot resolve relation [%s] for invalidation; the query will run uncached.',
            $relation,
        ), 'unkeyable-relation');
    }
}
