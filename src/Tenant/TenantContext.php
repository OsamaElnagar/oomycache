<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tenant;

/**
 * Tenant/panel/user segments for one query execution.
 *
 * A context with failClosed=true must not be cached: tenancy is active but the
 * tenant could not be resolved, so sharing a cache entry would risk a leak.
 */
final class TenantContext
{
    /**
     * @param  array<string, scalar>  $segments
     */
    public function __construct(
        public readonly array $segments = [],
        public readonly bool $failClosed = false,
        public readonly string $reason = '',
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<string, scalar>  $segments
     */
    public static function for(array $segments): self
    {
        ksort($segments);

        return new self($segments);
    }

    public static function failClosed(string $reason): self
    {
        return new self([], true, $reason);
    }

    /**
     * @return array<string, scalar>
     */
    public function segments(): array
    {
        return $this->segments;
    }
}
