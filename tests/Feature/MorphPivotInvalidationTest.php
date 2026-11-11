<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Oomycache\Oomycache\Relations\CachingMorphToMany;
use Oomycache\Oomycache\Tests\Fixtures\MorphPost;
use Oomycache\Oomycache\Tests\Fixtures\MorphTag;

it('returns the caching morph-to-many relation', function (): void {
    $post = MorphPost::query()->create(['name' => 'Post']);

    expect($post->tags())->toBeInstanceOf(CachingMorphToMany::class);
});

it('invalidates a cached morph relation after attach and detach', function (): void {
    $post = MorphPost::query()->create(['name' => 'Post']);
    $tag = MorphTag::query()->create(['name' => 'Tag']);

    expect(MorphPost::query()->with('tags')->findOrFail($post->id)->tags)->toHaveCount(0);

    $post->tags()->attach($tag);

    expect(MorphPost::query()->with('tags')->findOrFail($post->id)->tags)->toHaveCount(1);

    $post->tags()->detach($tag);

    expect(MorphPost::query()->with('tags')->findOrFail($post->id)->tags)->toHaveCount(0);
});

it('invalidates a cached inverse morph relation after attach and detach', function (): void {
    $post = MorphPost::query()->create(['name' => 'Inverse post']);
    $tag = MorphTag::query()->create(['name' => 'Inverse tag']);

    expect(MorphTag::query()->with('posts')->findOrFail($tag->id)->posts)->toHaveCount(0);

    $tag->posts()->attach($post);

    expect(MorphTag::query()->with('posts')->findOrFail($tag->id)->posts)->toHaveCount(1);

    $tag->posts()->detach($post);

    expect(MorphTag::query()->with('posts')->findOrFail($tag->id)->posts)->toHaveCount(0);
});

beforeEach(function (): void {
    Schema::create('morph_posts', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('morph_tags', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('taggables', function (Blueprint $table): void {
        $table->foreignId('tag_id');
        $table->morphs('taggable');
    });
});
