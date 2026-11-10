<?php

declare(strict_types=1);

use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Contracts\VersionStore;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'oomycache.store' => null,
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.safety.skip_reads_in_transactions' => false,
        'oomycache.safety.invalidate_after_commit' => false,
        'oomycache.stats.enabled' => true,
        'oomycache.stats.sample_rate' => 1.0,
        'oomycache.stats.ttl' => 3600,
    ]);
});

it('records hit, miss and invalidation counters', function (): void {
    Category::query()->create(['name' => 'Stats category', 'is_active' => true]);
    Product::query()->create(['sku' => 'STAT-1', 'name' => 'Stats product', 'is_active' => true]);

    Product::query()->orderBy('id')->get();
    Product::query()->orderBy('id')->get();

    Product::query()->firstOrFail()->update(['name' => 'Stats renamed']);

    $stats = app(QueryCacheEngine::class)->status()['stats'];

    expect($stats['enabled'])->toBeTrue()
        ->and($stats['hits'])->toBeGreaterThanOrEqual(1)
        ->and($stats['misses'])->toBeGreaterThanOrEqual(1)
        ->and($stats['invalidations'])->toBeGreaterThanOrEqual(1);
});

it('records a clear counter', function (): void {
    app(QueryCacheEngine::class)->clear();

    $stats = app(QueryCacheEngine::class)->status()['stats'];

    expect($stats['clears'])->toBeGreaterThanOrEqual(1);
});

it('records empty-result bypasses when negative caching is disabled', function (): void {
    config(['oomycache.cache.negative' => false]);

    Product::query()->where('sku', 'NOPE-EMPTY')->get();

    $stats = app(QueryCacheEngine::class)->status()['stats'];

    expect($stats['empty_bypasses'])->toBeGreaterThanOrEqual(1);
});

it('runs the bench command for a cacheable model', function (): void {
    Category::query()->create(['name' => 'Bench category', 'is_active' => true]);
    Product::query()->create(['sku' => 'BENCH-1', 'name' => 'Bench product', 'is_active' => true]);

    $this->artisan('oomycache:bench', [
        'model' => Product::class,
        '--limit' => 5,
        '--samples' => 2,
    ])
        ->expectsOutputToContain('OoMyCache bench')
        ->assertSuccessful();
});

it('does not clear the application cache during a bench by default', function (): void {
    Category::query()->create(['name' => 'Bench category 3', 'is_active' => true]);
    Product::query()->create(['sku' => 'BENCH-3', 'name' => 'Bench product 3', 'is_active' => true]);

    $versions = app(VersionStore::class);
    $before = $versions->global();

    $this->artisan('oomycache:bench', [
        'model' => Product::class,
        '--limit' => 2,
        '--samples' => 1,
    ])->assertSuccessful();

    expect($versions->global())->toBe($before);
});

it('clears the global token only when --clear is passed', function (): void {
    Category::query()->create(['name' => 'Bench category 4', 'is_active' => true]);
    Product::query()->create(['sku' => 'BENCH-4', 'name' => 'Bench product 4', 'is_active' => true]);

    $versions = app(VersionStore::class);
    $before = $versions->global();

    $this->artisan('oomycache:bench', [
        'model' => Product::class,
        '--limit' => 2,
        '--samples' => 1,
        '--clear' => true,
    ])->assertSuccessful();

    expect($versions->global())->not->toBe($before);
});

it('returns JSON from the bench command', function (): void {
    Category::query()->create(['name' => 'Bench category 2', 'is_active' => true]);
    Product::query()->create(['sku' => 'BENCH-2', 'name' => 'Bench product 2', 'is_active' => true]);

    $this->artisan('oomycache:bench', [
        'model' => Product::class,
        '--limit' => 2,
        '--samples' => 1,
        '--json' => true,
    ])->assertSuccessful();
});
