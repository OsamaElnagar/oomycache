<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class MorphTag extends Model
{
    use HasQueryCache;

    public $timestamps = false;

    protected $table = 'morph_tags';

    protected $guarded = [];

    /**
     * @return MorphToMany<MorphPost, $this>
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(
            MorphPost::class,
            'taggable',
            'taggables',
            'tag_id',
            'taggable_id',
        );
    }
}
