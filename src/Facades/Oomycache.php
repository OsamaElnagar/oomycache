<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Oomycache\Oomycache\Oomycache
 */
class Oomycache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Oomycache\Oomycache\Oomycache::class;
    }
}
