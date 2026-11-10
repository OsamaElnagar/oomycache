<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after the global namespace is bumped (oomycache:clear).
 */
final class QueryCacheCleared
{
    use Dispatchable;

    public function __construct(
        public readonly string $scope = 'all',
    ) {}
}
