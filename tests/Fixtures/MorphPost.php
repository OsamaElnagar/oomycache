<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class MorphPost extends Model
{
    use HasQueryCache;

    public $timestamps = false;

    protected $table = 'morph_posts';

    protected $guarded = [];

    /**
     * @return MorphToMany<MorphTag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(
            MorphTag::class,
            'taggable',
            'taggables',
            'taggable_id',
            'tag_id',
        );
    }
}
