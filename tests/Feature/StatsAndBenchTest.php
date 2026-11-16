<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Contracts\VersionStore;
use Oomycache\Oomycache\Events\QueryCacheInvalidated;
use Oomycache\Oomycache\Stats\CacheStats;
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

it('emits a single invalidation notification for a model update', function (): void {
    Category::query()->create(['name' => 'Dedupe category', 'is_active' => true]);
    $product = Product::query()->create(['sku' => 'DEDUPE-UPDATE', 'name' => 'Dedupe product', 'is_active' => true]);

    Event::fake([QueryCacheInvalidated::class]);

    $product->update(['name' => 'Dedupe renamed']);

    Event::assertDispatchedTimes(QueryCacheInvalidated::class, 1);
});

it('emits a single invalidation notification for a model delete', function (): void {
    Category::query()->create(['name' => 'Dedupe delete category', 'is_active' => true]);
    $product = Product::query()->create(['sku' => 'DEDUPE-DELETE', 'name' => 'Dedupe delete product', 'is_active' => true]);

    Event::fake([QueryCacheInvalidated::class]);

    $product->delete();

    Event::assertDispatchedTimes(QueryCacheInvalidated::class, 1);
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

it('counts atomic increments without losing observations', function (): void {
    $stats = app(CacheStats::class);
    $stats->reset();

    for ($i = 1; $i <= 150; $i++) {
        $stats->record('hits');
    }

    // A second recorder instance shares the same store, like concurrent workers.
    $other = new CacheStats(app('cache'), app('config'));

    for ($i = 1; $i <= 50; $i++) {
        $other->record('hits');
    }

    expect($stats->snapshot()['hits'])->toBe(200);
});

it('reports the real age of the current stats window', function (): void {
    $stats = app(CacheStats::class);
    $stats->reset();
    $stats->record('hits');

    expect($stats->snapshot()['window_seconds'])->toBeLessThan(5);

    $windowKey = (string) config('oomycache.prefix', 'oomycache').':stats:window_start';
    Cache::store()->put($windowKey, time() - 120, 3600);

    expect($stats->snapshot()['window_seconds'])->toBeGreaterThanOrEqual(120);
});

it('resets counters and restarts the stats window', function (): void {
    $stats = app(CacheStats::class);
    $stats->reset();
    $stats->record('hits');
    $stats->record('misses');

    expect($stats->snapshot()['hits'])->toBe(1)
        ->and($stats->snapshot()['misses'])->toBe(1);

    $stats->reset();
    $snapshot = $stats->snapshot();

    expect($snapshot['hits'])->toBe(0)
        ->and($snapshot['misses'])->toBe(0)
        ->and($snapshot['window_seconds'])->toBeLessThan(5);

    $stats->record('hits');

    expect($stats->snapshot()['hits'])->toBe(1);
});

it('returns a zeroed snapshot when stats are disabled', function (): void {
    config(['oomycache.stats.enabled' => false]);

    $snapshot = app(CacheStats::class)->snapshot();

    expect($snapshot['enabled'])->toBeFalse()
        ->and($snapshot['window_seconds'])->toBe(0)
        ->and($snapshot['hits'])->toBe(0)
        ->and($snapshot['misses'])->toBe(0);
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

it('benchmarks every discovered cacheable model with --all', function (): void {
    Category::query()->create(['name' => 'Bench all category', 'is_active' => true]);
    Product::query()->create(['sku' => 'BENCH-ALL', 'name' => 'Bench all product', 'is_active' => true]);

    $this->artisan('oomycache:bench', [
        '--all' => true,
        '--limit' => 1,
        '--samples' => 1,
    ])
        ->expectsOutputToContain('cacheable models')
        ->assertSuccessful();
});
