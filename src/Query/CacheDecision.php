<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

/**
 * The result of query classification: cache it, or run it uncached with a
 * machine-readable reason.
 */
final class CacheDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'allowed');
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
