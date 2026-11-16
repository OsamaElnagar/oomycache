<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Oomycache\Oomycache\Contracts\VersionStore;
use RuntimeException;

/**
 * Simulates a cache-store outage during a version bump.
 */
final class ThrowingVersionStore implements VersionStore
{
    public function get(string $table, ?string $connection = null): string
    {
        return 'v-fixture';
    }

    public function bump(string $table, ?string $connection = null): string
    {
        throw new RuntimeException('version store unavailable');
    }

    public function global(): string
    {
        return 'g-fixture';
    }

    public function bumpGlobal(): string
    {
        throw new RuntimeException('version store unavailable');
    }
}
