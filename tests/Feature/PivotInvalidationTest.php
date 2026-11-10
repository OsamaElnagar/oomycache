<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Oomycache\Oomycache\Relations\CachingBelongsToMany;
use Oomycache\Oomycache\Tests\Fixtures\PivotChild;
use Oomycache\Oomycache\Tests\Fixtures\PivotParent;

beforeEach(function (): void {
    Schema::create('pivot_parents', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('pivot_children', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('pivot_parent_pivot_child', function (Blueprint $table): void {
        $table->foreignId('parent_id');
        $table->foreignId('child_id');
    });
});

it('returns the caching belongs-to-many relation', function (): void {
    $parent = PivotParent::query()->create(['name' => 'Parent']);

    expect($parent->children())->toBeInstanceOf(CachingBelongsToMany::class);
});

it('invalidates a cached parent relation after attach', function (): void {
    $parent = PivotParent::query()->create(['name' => 'Parent']);
    $child = PivotChild::query()->create(['name' => 'Child']);

    $warm = PivotParent::query()->with('children')->findOrFail($parent->id);

    expect($warm->children)->toHaveCount(0);

    $parent->children()->attach($child);

    $fresh = PivotParent::query()->with('children')->findOrFail($parent->id);

    expect($fresh->children)->toHaveCount(1)
        ->and($fresh->children->first()->is($child))->toBeTrue();
});

it('invalidates a cached parent relation after detach', function (): void {
    $parent = PivotParent::query()->create(['name' => 'Parent']);
    $child = PivotChild::query()->create(['name' => 'Child']);

    $parent->children()->attach($child);

    $warm = PivotParent::query()->with('children')->findOrFail($parent->id);

    expect($warm->children)->toHaveCount(1);

    $parent->children()->detach($child);

    $fresh = PivotParent::query()->with('children')->findOrFail($parent->id);

    expect($fresh->children)->toHaveCount(0);
});

it('invalidates once for nested sync operations', function (): void {
    $parent = PivotParent::query()->create(['name' => 'Parent']);
    $child = PivotChild::query()->create(['name' => 'Child']);

    PivotParent::query()->with('children')->findOrFail($parent->id);

    $parent->children()->sync([$child->id]);

    $fresh = PivotParent::query()->with('children')->findOrFail($parent->id);

    expect($fresh->children)->toHaveCount(1);
});
