<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Contracts;

use Oomycache\Oomycache\Concerns\HasQueryCache;

/**
 * Marks an Eloquent model as query-cacheable.
 *
 * Models using {@see HasQueryCache} implement
 * this contract automatically; implementing it manually has no effect.
 */
interface QueryCacheable
{
    //
}
