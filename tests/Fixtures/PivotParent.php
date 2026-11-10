<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class PivotParent extends Model
{
    use HasQueryCache;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<PivotChild, $this>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotChild::class,
            'pivot_parent_pivot_child',
            'parent_id',
            'child_id',
        );
    }
}
