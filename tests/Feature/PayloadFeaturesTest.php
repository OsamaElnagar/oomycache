<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'oomycache.store' => null,
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.safety.skip_reads_in_transactions' => false,
        'oomycache.safety.invalidate_after_commit' => false,
        'oomycache.payload.memoize_per_request' => false,
        'oomycache.payload.compression.enabled' => false,
        'oomycache.cache.negative' => false,
    ]);

    $category = Category::query()->create(['name' => 'Payload category', 'is_active' => true]);

    for ($i = 1; $i <= 3; $i++) {
        Product::query()->create([
            'category_id' => $category->getKey(),
            'sku' => 'PAYLOAD-'.$i,
            'name' => 'Payload product '.$i,
            'price' => $i,
            'is_active' => true,
            'stock_quantity' => $i,
        ]);
    }
});

/**
 * @return array<string, mixed>
 */
function payloadRawEntries(): array
{
    /** @var array<string, mixed> $all */
    $all = Cache::store()->getStore()->all();

    return array_filter(
        $all,
        static fn (string $key): bool => ! str_contains($key, ':version:'),
        ARRAY_FILTER_USE_KEY,
    );
}

function payloadQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

it('stores compressed payloads and serves them transparently', function (): void {
    config([
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
        'oomycache.payload.compression.algorithm' => 'gzip',
    ]);

    Product::query()->orderBy('id')->get();

    $entries = payloadRawEntries();
    expect($entries)->not->toBeEmpty();

    $compressed = 0;

    foreach ($entries as $entry) {
        $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;

        if (is_string($value) && str_starts_with($value, 'OOMYCACHE1C')) {
            $compressed++;
        }
    }

    expect($compressed)->toBeGreaterThanOrEqual(1);
    expect(payloadQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);
});

it('treats a corrupt compressed payload as a miss', function (): void {
    config([
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
    ]);

    Product::query()->orderBy('id')->get();

    $entries = payloadRawEntries();
    $key = array_key_first($entries);

    expect($key)->toBeString();

    Cache::store()->put($key, 'OOMYCACHE1CG:definitely-not-gzip', 300);

    $queries = payloadQueryCount(fn () => Product::query()->orderBy('id')->get());

    expect($queries)->toBeGreaterThan(0);
});

it('memoizes decoded payloads for the request and resets runtime state', function (): void {
    config(['oomycache.payload.memoize_per_request' => true]);

    Product::query()->orderBy('id')->get();

    foreach (array_keys(payloadRawEntries()) as $key) {
        Cache::store()->forget($key);
    }

    expect(payloadQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);

    app(QueryCacheEngine::class)->resetRuntimeState();

    expect(payloadQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBeGreaterThan(0);
    expect(payloadQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);
});

it('does not cache empty results when negative caching is disabled', function (): void {
    $first = payloadQueryCount(fn () => Product::query()->where('sku', 'NOPE')->get());
    $second = payloadQueryCount(fn () => Product::query()->where('sku', 'NOPE')->get());

    expect($first)->toBeGreaterThan(0)->and($second)->toBeGreaterThan(0);
});

it('caches empty results when negative caching is enabled', function (): void {
    config(['oomycache.cache.negative' => true]);

    app(QueryCacheEngine::class)->forgetOptions(Product::class);

    Product::query()->where('sku', 'NOPE')->get();

    expect(payloadQueryCount(fn () => Product::query()->where('sku', 'NOPE')->get()))->toBe(0);
});
