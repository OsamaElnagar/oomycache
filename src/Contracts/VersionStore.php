<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Contracts;

/**
 * Stores the monotonic version tokens that make cached queries unreachable
 * after a table write.
 */
interface VersionStore
{
    /**
     * Get the current token for a table on a connection, creating one if
     * it does not exist yet.
     */
    public function get(string $table, ?string $connection = null): string;

    /**
     * Give a table a new token, invalidating every cache key that embedded
     * the previous token.
     */
    public function bump(string $table, ?string $connection = null): string;

    /**
     * Global token used by clear(): bumping it orphans every package entry.
     */
    public function global(): string;

    /**
     * Give the global namespace a new token.
     */
    public function bumpGlobal(): string;
}
