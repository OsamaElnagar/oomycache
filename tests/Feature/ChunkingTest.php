<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Oomycache\Oomycache\Events\QueryCacheBypassed;
use Oomycache\Oomycache\Payload\ChunkedPayloadStore;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'oomycache.store' => null,
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.safety.skip_reads_in_transactions' => false,
        'oomycache.safety.invalidate_after_commit' => false,
        'oomycache.safety.max_payload_bytes' => 1_048_576,
        'oomycache.payload.memoize_per_request' => false,
        'oomycache.payload.compression.enabled' => false,
        'oomycache.cache.negative' => false,
        'oomycache.payload.chunking.enabled' => true,
        'oomycache.payload.chunking.chunk_bytes' => 512,
        'oomycache.payload.chunking.max_chunks' => 64,
    ]);
});

/**
 * Raw ArrayStore entries for OoMyCache data (version tokens excluded).
 *
 * @return array<string, mixed>
 */
function chunkRawEntries(): array
{
    /** @var array<string, mixed> $all */
    $all = Cache::store()->getStore()->all();

    return array_filter(
        $all,
        static fn (string $key): bool => str_starts_with($key, 'oomycache:') && ! str_contains($key, ':version:'),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * @param  array<string, mixed>  $entries
 * @return list<string>
 */
function chunkStoredKeys(array $entries): array
{
    return array_values(array_filter(
        array_keys($entries),
        static fn (string $key): bool => str_contains($key, ':c:'),
    ));
}

/**
 * @param  array<string, mixed>  $entries
 */
function chunkManifestKey(array $entries): ?string
{
    foreach ($entries as $key => $entry) {
        $value = chunkEntryValue($entry);

        if (is_string($value) && str_starts_with($value, ChunkedPayloadStore::MANIFEST_PREFIX)) {
            return (string) $key;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $entries
 * @return list<string>
 */
function chunkKeysFor(array $entries, string $manifestKey): array
{
    return array_values(array_filter(
        array_keys($entries),
        static fn (string $key): bool => str_starts_with($key, $manifestKey.':c:'),
    ));
}

/**
 * @param  array<string, mixed>  $entries
 * @return list<string>
 */
function chunkManifestKeys(array $entries): array
{
    $keys = [];

    foreach ($entries as $key => $entry) {
        $value = chunkEntryValue($entry);

        if (is_string($value) && str_starts_with($value, ChunkedPayloadStore::MANIFEST_PREFIX)) {
            $keys[] = (string) $key;
        }
    }

    return $keys;
}

function chunkEntryValue(mixed $entry): mixed
{
    return is_array($entry) ? ($entry['value'] ?? null) : $entry;
}

function chunkEntryExpiry(mixed $entry): float
{
    return is_array($entry) ? (float) ($entry['expiresAt'] ?? 0.0) : 0.0;
}

function chunkStore(): CacheRepository
{
    return Cache::store();
}

function chunkSeed(int $count = 10, int $nameLength = 200, bool $randomNames = false): void
{
    $category = Category::query()->create(['name' => 'Chunk category', 'is_active' => true]);

    for ($i = 1; $i <= $count; $i++) {
        $name = $randomNames
            ? substr(str_repeat(bin2hex(random_bytes(64)).'-', 5), 0, $nameLength)
            : str_pad('product-'.$i.'-', $nameLength, 'x');

        Product::query()->create([
            'category_id' => $category->getKey(),
            'sku' => 'CHUNK-'.$i,
            'name' => $name,
            'price' => $i,
            'is_active' => true,
            'stock_quantity' => $i,
        ]);
    }
}

function chunkQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

it('splits a large payload across multiple keys and reconstructs it identically', function (): void {
    chunkSeed(10);

    $first = Product::query()->with('category')->orderBy('id')->get();
    $entries = chunkRawEntries();
    $manifestKey = chunkManifestKey($entries);

    expect($manifestKey)->toBeString();

    $chunkKeys = chunkKeysFor($entries, (string) $manifestKey);

    expect($chunkKeys)->toHaveCount(chunkCountFor($entries))
        ->and(count($chunkKeys))->toBeGreaterThanOrEqual(2);

    $manifest = chunkEntryValue($entries[$manifestKey]);

    expect($manifest)->toBeString()
        ->and($manifest)->toStartWith(ChunkedPayloadStore::MANIFEST_PREFIX);

    $chunkSize = config('oomycache.payload.chunking.chunk_bytes');
    $expiries = [];

    foreach ($chunkKeys as $index => $chunkKey) {
        $value = chunkEntryValue($entries[$chunkKey]);

        expect($chunkKey)->toBe($manifestKey.':c:'.$index)
            ->and($value)->toBeString()
            ->and(strlen((string) $value))->toBeLessThanOrEqual($chunkSize);

        $expiries[] = chunkEntryExpiry($entries[$chunkKey]);
    }

    // Chunks and manifest are written together and share the same TTL.
    $expiries[] = chunkEntryExpiry($entries[$manifestKey]);
    expect(max($expiries) - min($expiries))->toBeLessThan(1.0);

    $queries = chunkQueryCount(fn () => Product::query()->with('category')->orderBy('id')->get());
    $warm = Product::query()->with('category')->orderBy('id')->get();

    expect($queries)->toBe(0)
        ->and($warm)->toHaveCount(10)
        ->and($warm->pluck('id')->all())->toBe($first->pluck('id')->all())
        ->and($warm->first())->toBeInstanceOf(Product::class)
        ->and($warm->first()->relationLoaded('category'))->toBeTrue()
        ->and($warm->first()->category?->name)->toBe('Chunk category');
});

it('keeps a payload under the chunk threshold in a single cache key', function (): void {
    config(['oomycache.payload.chunking.chunk_bytes' => 1_048_576]);

    chunkSeed(5);

    Product::query()->orderBy('id')->get();

    $entries = chunkRawEntries();

    expect($entries)->toHaveCount(1)
        ->and(chunkManifestKeys($entries))->toBe([])
        ->and(chunkEntryValue(array_values($entries)[0]))->toStartWith('OOMYCACHE1:');

    expect(chunkQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);
});

it('bypasses payloads above max_payload_bytes when chunking is disabled', function (): void {
    config([
        'oomycache.payload.chunking.enabled' => false,
        'oomycache.safety.max_payload_bytes' => 200,
    ]);

    $reasons = [];

    Event::listen(QueryCacheBypassed::class, static function (QueryCacheBypassed $event) use (&$reasons): void {
        $reasons[] = $event->reason;
    });

    chunkSeed(10);

    Product::query()->orderBy('id')->get();

    expect($reasons)->toContain('payload-too-large')
        ->and(chunkRawEntries())->toBe([])
        ->and(chunkQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBeGreaterThan(0);
});

it('bypasses payloads that would need more chunks than max_chunks', function (): void {
    config([
        'oomycache.payload.chunking.chunk_bytes' => 256,
        'oomycache.payload.chunking.max_chunks' => 2,
    ]);

    $reasons = [];

    Event::listen(QueryCacheBypassed::class, static function (QueryCacheBypassed $event) use (&$reasons): void {
        $reasons[] = $event->reason;
    });

    chunkSeed(10);

    Product::query()->orderBy('id')->get();

    expect($reasons)->toContain('payload-too-large')
        ->and(chunkRawEntries())->toBe([])
        ->and(chunkQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBeGreaterThan(0);
});

it('combines compression and chunking', function (): void {
    config([
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
        'oomycache.payload.compression.algorithm' => 'gzip',
        'oomycache.payload.chunking.chunk_bytes' => 64,
        'oomycache.payload.chunking.max_chunks' => 256,
    ]);

    chunkSeed(5, 240, true);

    $first = Product::query()->orderBy('id')->get();
    $entries = chunkRawEntries();
    $manifestKey = chunkManifestKey($entries);

    expect($manifestKey)->toBeString();

    $chunkKeys = chunkKeysFor($entries, (string) $manifestKey);

    expect(count($chunkKeys))->toBeGreaterThanOrEqual(2)
        ->and(chunkEntryValue($entries[(string) $manifestKey]))->toStartWith(ChunkedPayloadStore::MANIFEST_PREFIX);

    $compressed = implode('', array_map(
        static fn (string $key): string => (string) chunkEntryValue($entries[$key]),
        $chunkKeys,
    ));

    // The first chunk starts with the compressor envelope: encode → compress → chunk.
    expect($compressed)->toStartWith('OOMYCACHE1C');

    $queries = chunkQueryCount(fn () => Product::query()->orderBy('id')->get());
    $warm = Product::query()->orderBy('id')->get();

    expect($queries)->toBe(0)
        ->and($warm->pluck('id')->all())->toBe($first->pluck('id')->all());
});

it('treats a corrupt chunk as a miss, cleans the entry and never serves stale data', function (): void {
    chunkSeed(10);

    Product::query()->orderBy('id')->get();

    $entries = chunkRawEntries();
    $manifestKey = chunkManifestKey($entries);

    expect($manifestKey)->toBeString();

    $chunkKeys = chunkKeysFor($entries, (string) $manifestKey);

    expect($chunkKeys)->not->toBe([]);

    chunkStore()->put($chunkKeys[1], 'corrupted-chunk-value', 300);

    // Delete rows without touching the version tokens so the same logical key
    // is still read: the only reason for a DB hit is the corrupt payload.
    DB::table('products')->delete();

    $queries = chunkQueryCount(fn () => Product::query()->orderBy('id')->get());
    $result = Product::query()->orderBy('id')->get();

    expect($queries)->toBeGreaterThan(0)
        ->and($result)->toHaveCount(0)
        ->and(chunkRawEntries())->toBe([]);
});

it('treats a missing chunk as a miss and deletes the remaining chunks', function (): void {
    chunkSeed(10);

    Product::query()->orderBy('id')->get();

    $entries = chunkRawEntries();
    $manifestKey = chunkManifestKey($entries);

    expect($manifestKey)->toBeString();

    $chunkKeys = chunkKeysFor($entries, (string) $manifestKey);

    expect($chunkKeys)->not->toBe([]);

    chunkStore()->forget($chunkKeys[0]);

    DB::table('products')->delete();

    $queries = chunkQueryCount(fn () => Product::query()->orderBy('id')->get());
    $result = Product::query()->orderBy('id')->get();

    expect($queries)->toBeGreaterThan(0)
        ->and($result)->toHaveCount(0)
        ->and(chunkRawEntries())->toBe([]);
});

it('treats a corrupt manifest as a miss and never serves stale data', function (): void {
    chunkSeed(10);

    Product::query()->orderBy('id')->get();

    $entries = chunkRawEntries();
    $manifestKey = chunkManifestKey($entries);

    expect($manifestKey)->toBeString();

    chunkStore()->put($manifestKey, ChunkedPayloadStore::MANIFEST_PREFIX.'{"v":1', 300);

    DB::table('products')->delete();

    $queries = chunkQueryCount(fn () => Product::query()->orderBy('id')->get());
    $result = Product::query()->orderBy('id')->get();

    expect($queries)->toBeGreaterThan(0)
        ->and($result)->toHaveCount(0)
        // Corrupt manifests cannot enumerate their chunks; best-effort cleanup
        // removes the manifest and orphaned chunks expire by TTL.
        ->and(chunkManifestKey(chunkRawEntries()))->toBeNull();
});

it('makes chunked entries a miss after version invalidation', function (): void {
    chunkSeed(10);

    Product::query()->with('category')->orderBy('id')->get();

    $oldEntries = chunkRawEntries();
    $oldManifestKey = chunkManifestKey($oldEntries);

    expect($oldManifestKey)->toBeString();

    $product = Product::query()->firstOrFail();
    $product->update(['name' => 'Renamed chunked product']);

    $queries = chunkQueryCount(fn () => Product::query()->with('category')->orderBy('id')->get());
    $fresh = Product::query()->with('category')->orderBy('id')->get();

    $newEntries = array_diff_key(chunkRawEntries(), $oldEntries);
    $newManifestKey = chunkManifestKey($newEntries);

    expect($queries)->toBeGreaterThan(0)
        ->and($fresh->firstWhere('id', $product->getKey())?->name)->toBe('Renamed chunked product')
        ->and($newManifestKey)->toBeString()
        ->and($newManifestKey)->not->toBe($oldManifestKey);

    // The new generation is warm; old chunks are unreachable and expire by TTL.
    expect(chunkQueryCount(fn () => Product::query()->with('category')->orderBy('id')->get()))->toBe(0);
});

/**
 * Read the chunk count out of the manifest so key-count assertions stay
 * precise without importing private state.
 *
 * @param  array<string, mixed>  $entries
 */
function chunkCountFor(array $entries): int
{
    $key = chunkManifestKey($entries);

    if ($key === null) {
        return 0;
    }

    /** @var ChunkedPayloadStore $store */
    $store = app(ChunkedPayloadStore::class);
    $meta = $store->decodeManifest((string) chunkEntryValue($entries[$key]));

    return $meta['chunks'] ?? 0;
}
