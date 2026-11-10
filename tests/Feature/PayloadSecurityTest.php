<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Oomycache\Oomycache\Contracts\PayloadCodec;
use Oomycache\Oomycache\Events\QueryCacheBypassed;
use Oomycache\Oomycache\Payload\ChunkedPayloadStore;
use Oomycache\Oomycache\Payload\PayloadCompressor;
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
        'oomycache.payload.chunking.max_total_bytes' => 2_097_152,
    ]);
});

/**
 * Raw ArrayStore entries for OoMyCache data (version tokens excluded).
 *
 * @return array<string, mixed>
 */
function securityRawEntries(): array
{
    /** @var array<string, mixed> $all */
    $all = Cache::store()->getStore()->all();

    return array_filter(
        $all,
        static fn (string $key): bool => str_starts_with($key, 'oomycache:') && ! str_contains($key, ':version:'),
        ARRAY_FILTER_USE_KEY,
    );
}

function securityValue(mixed $entry): mixed
{
    return is_array($entry) ? ($entry['value'] ?? null) : $entry;
}

/**
 * @param  array<string, mixed>  $entries
 */
function securityManifestKey(array $entries): ?string
{
    foreach ($entries as $key => $entry) {
        $value = securityValue($entry);

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
function securityChunkKeys(array $entries, string $manifestKey): array
{
    return array_values(array_filter(
        array_keys($entries),
        static fn (string $key): bool => str_starts_with($key, $manifestKey.':c:'),
    ));
}

function securityQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function securitySeed(int $count = 10, int $nameLength = 200, bool $randomNames = false): void
{
    $category = Category::query()->create(['name' => 'Security category', 'is_active' => true]);

    for ($i = 1; $i <= $count; $i++) {
        $name = $randomNames
            ? substr(str_repeat(bin2hex(random_bytes(64)).'-', 5), 0, $nameLength)
            : str_pad('secure-'.$i.'-', $nameLength, 'x');

        Product::query()->create([
            'category_id' => $category->getKey(),
            'sku' => 'SEC-'.$i,
            'name' => $name,
            'price' => $i,
            'is_active' => true,
            'stock_quantity' => $i,
        ]);
    }
}

/**
 * A class that exists but is not an Eloquent collection: a forged payload must
 * never instantiate it. Counts constructor calls so the test can prove it.
 */
final class PayloadSecurityProbe
{
    public static int $constructed = 0;

    public function __construct(mixed $items = null)
    {
        self::$constructed++;
    }
}

/**
 * A legitimate Eloquent collection subclass: the allow-list must keep
 * instantiating it (so custom collection classes keep working).
 */
final class PayloadSecurityValidCollection extends EloquentCollection {}

it('bypasses chunked payloads above chunking.max_total_bytes', function (): void {
    config([
        'oomycache.payload.chunking.chunk_bytes' => 256,
        'oomycache.payload.chunking.max_chunks' => 1000,
        'oomycache.payload.chunking.max_total_bytes' => 1024,
    ]);

    $reasons = [];

    Event::listen(QueryCacheBypassed::class, static function (QueryCacheBypassed $event) use (&$reasons): void {
        $reasons[] = $event->reason;
    });

    securitySeed(10);

    Product::query()->orderBy('id')->get();

    expect($reasons)->toContain('payload-too-large')
        ->and(securityRawEntries())->toBe([])
        ->and(securityQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBeGreaterThan(0);
});

it('treats a manifest above max_total_bytes as a corrupt miss and cleans it up', function (): void {
    securitySeed(10);

    Product::query()->orderBy('id')->get();

    $entries = securityRawEntries();
    $manifestKey = securityManifestKey($entries);

    expect($manifestKey)->toBeString();

    $manifest = (string) securityValue($entries[(string) $manifestKey]);

    /** @var array<string, mixed> $data */
    $data = json_decode(
        substr($manifest, strlen(ChunkedPayloadStore::MANIFEST_PREFIX)),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );

    $data['bytes'] = 10_000_000;

    Cache::store()->put(
        (string) $manifestKey,
        ChunkedPayloadStore::MANIFEST_PREFIX.json_encode($data, JSON_THROW_ON_ERROR),
        300,
    );

    // Lower the cap below the forged declared size: the read path must reject
    // the manifest before fetching any chunk.
    config(['oomycache.payload.chunking.max_total_bytes' => 1024]);

    DB::table('products')->delete();

    $queries = securityQueryCount(fn () => Product::query()->orderBy('id')->get());
    $result = Product::query()->orderBy('id')->get();

    expect($queries)->toBeGreaterThan(0)
        ->and($result)->toHaveCount(0)
        ->and(securityRawEntries())->toBe([]);
});

it('rejects a compressed payload that decompresses above compression.max_bytes', function (): void {
    config([
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
    ]);

    /** @var PayloadCompressor $compressor */
    $compressor = app(PayloadCompressor::class);

    $bomb = 'OOMYCACHE1CG:'.gzencode(str_repeat('A', 8_000_000), 1);

    expect(strlen($bomb))->toBeLessThan(100_000)
        ->and(fn () => $compressor->decompress($bomb))->toThrow(UnexpectedValueException::class);
});

it('treats a decompression bomb in the cache store as a corrupt miss without a fatal', function (): void {
    config([
        'oomycache.payload.chunking.enabled' => false,
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
    ]);

    securitySeed(10);

    Product::query()->orderBy('id')->get();

    $key = array_key_first(securityRawEntries());

    expect($key)->toBeString();

    Cache::store()->put((string) $key, 'OOMYCACHE1CG:'.gzencode(str_repeat('A', 8_000_000), 1), 300);

    $queries = securityQueryCount(fn () => Product::query()->orderBy('id')->get());
    $result = Product::query()->orderBy('id')->get();

    expect($queries)->toBeGreaterThan(0)
        ->and($result)->toHaveCount(10);
});

it('does not create a compressed envelope above compression.max_bytes', function (): void {
    config([
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
        'oomycache.payload.compression.max_bytes' => 1024,
    ]);

    /** @var PayloadCompressor $compressor */
    $compressor = app(PayloadCompressor::class);

    $payload = str_repeat('x', 2048);

    expect($compressor->compress($payload))->toBe($payload);
});

it('falls back to a safe EloquentCollection for forged collection classes', function (): void {
    PayloadSecurityProbe::$constructed = 0;

    /** @var PayloadCodec $codec */
    $codec = app(PayloadCodec::class);

    $forged = 'OOMYCACHE1:'.json_encode([
        '__eloquent_collection' => PayloadSecurityProbe::class,
        'items' => [],
    ], JSON_THROW_ON_ERROR);

    $decoded = $codec->decode($forged);

    expect($decoded)->toBeInstanceOf(EloquentCollection::class)
        ->and(PayloadSecurityProbe::$constructed)->toBe(0);

    $valid = 'OOMYCACHE1:'.json_encode([
        '__eloquent_collection' => PayloadSecurityValidCollection::class,
        'items' => [],
    ], JSON_THROW_ON_ERROR);

    expect($codec->decode($valid))->toBeInstanceOf(PayloadSecurityValidCollection::class);
});

it('keeps normal compression and chunking working within the caps', function (): void {
    config([
        'oomycache.payload.compression.enabled' => true,
        'oomycache.payload.compression.threshold_bytes' => 1,
        'oomycache.payload.chunking.chunk_bytes' => 64,
        'oomycache.payload.chunking.max_chunks' => 256,
        'oomycache.payload.chunking.max_total_bytes' => 2_097_152,
    ]);

    securitySeed(5, 240, true);

    $first = Product::query()->orderBy('id')->get();
    $entries = securityRawEntries();
    $manifestKey = securityManifestKey($entries);

    expect($manifestKey)->toBeString();

    $chunks = securityChunkKeys($entries, (string) $manifestKey);

    $combined = implode('', array_map(
        static fn (string $key): string => (string) securityValue($entries[$key]),
        $chunks,
    ));

    expect($chunks)->not->toBe([])
        ->and($combined)->toStartWith('OOMYCACHE1C')
        ->and(securityQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);

    $warm = Product::query()->orderBy('id')->get();

    expect($warm->pluck('id')->all())->toBe($first->pluck('id')->all());
});
