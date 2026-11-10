<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a query result is served from the cache.
 */
final class QueryCacheHit
{
    use Dispatchable;

    public function __construct(
        public readonly string $model,
        public readonly string $intent,
        public readonly string $key,
    ) {}
}
