<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class SoftDeleteRecord extends Model
{
    use HasQueryCache, SoftDeletes;

    public $timestamps = false;

    protected $table = 'soft_delete_records';

    protected $guarded = [];
}
