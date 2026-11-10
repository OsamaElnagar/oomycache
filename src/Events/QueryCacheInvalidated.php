<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a table's version namespace is bumped.
 */
final class QueryCacheInvalidated
{
    use Dispatchable;

    public function __construct(
        public readonly string $table,
        public readonly ?string $connection = null,
        public readonly string $reason = 'write',
    ) {}
}
