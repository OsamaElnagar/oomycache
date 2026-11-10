<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a query that could have been cached was intentionally
 * executed against the DB. The reason is machine-readable so operators can
 * answer "why is this page not cached?".
 */
final class QueryCacheBypassed
{
    use Dispatchable;

    public function __construct(
        public readonly string $model,
        public readonly string $intent,
        public readonly string $reason,
    ) {}
}
