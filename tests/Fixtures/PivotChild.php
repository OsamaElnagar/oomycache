<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class PivotChild extends Model
{
    use HasQueryCache;

    public $timestamps = false;

    protected $guarded = [];
}
