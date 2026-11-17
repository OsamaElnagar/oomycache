<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Oomycache\Oomycache\Events\QueryCacheBypassed;
use Oomycache\Oomycache\Relations\CachingMorphToMany;
use Oomycache\Oomycache\Tests\Fixtures\MorphPost;
use Oomycache\Oomycache\Tests\Fixtures\MorphTag;

it('returns the caching morph-to-many relation', function (): void {
    $post = MorphPost::query()->create(['name' => 'Post']);

    expect($post->tags())->toBeInstanceOf(CachingMorphToMany::class);
});

it('dispatches a bypass event and still queries the database for an unkeyable eager load', function (): void {
    Event::fake([QueryCacheBypassed::class]);

    MorphPost::query()->create(['name' => 'Post']);
    MorphTag::query()->create(['name' => 'Tag']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $posts = MorphPost::query()->with('tags')->get();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($posts)->toHaveCount(1)
        ->and($queries)->toBeGreaterThan(0);

    Event::assertDispatched(QueryCacheBypassed::class, function (QueryCacheBypassed $event): bool {
        return $event->model === MorphPost::class
            && $event->intent === 'get'
            && $event->reason === 'unkeyable-relation';
    });
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
