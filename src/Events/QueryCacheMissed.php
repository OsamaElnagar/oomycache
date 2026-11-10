<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a cacheable query missed and was executed against the DB.
 */
final class QueryCacheMissed
{
    use Dispatchable;

    public function __construct(
        public readonly string $model,
        public readonly string $intent,
        public readonly string $key,
        public readonly string $reason = 'miss',
    ) {}
}
