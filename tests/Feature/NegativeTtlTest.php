<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Events\QueryCacheMissed;
use Workbench\App\Models\Product;

/**
 * Count cache misses (i.e. executions of the DB callback) for a callback.
 */
function negativeTtlMissCount(Closure $callback): int
{
    $misses = 0;
    $listener = function (QueryCacheMissed $event) use (&$misses): void {
        $misses++;
    };

    Event::listen(QueryCacheMissed::class, $listener);

    $callback();

    Event::forget(QueryCacheMissed::class, $listener);

    return $misses;
}

/**
 * Read the expiry timestamp of a cached entry captured from a miss event.
 *
 * @return array{expiresAt: float}
 */
function negativeTtlEntryFor(?string $key): array
{
    $entries = Cache::store()->getStore()->all();

    return $key === null ? [] : ($entries[$key] ?? []);
}

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'oomycache.store' => null,
        'oomycache.ttl' => 300,
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.cache.negative' => false,
        'oomycache.cache.negative_ttl' => 10,
        'oomycache.safety.skip_reads_in_transactions' => false,
    ]);
});

it('caches a zero count with a per-call negative TTL', function (): void {
    $read = fn (): int => Product::query()->cacheNegativeFor(30)->where('sku', 'NEG-ZERO')->count();

    $misses = negativeTtlMissCount(function () use ($read): void {
        expect($read())->toBe(0)
            ->and($read())->toBe(0);
    });

    expect($misses)->toBe(1);
});

it('accepts the negative TTL as a cacheFor named argument', function (): void {
    $read = fn (): int => Product::query()->cacheFor(300, negativeTtl: 30)->where('sku', 'NEG-NAMED')->count();

    $misses = negativeTtlMissCount(function () use ($read): void {
        expect($read())->toBe(0)
            ->and($read())->toBe(0);
    });

    expect($misses)->toBe(1);
});

it('honors the supplied negative TTL for an empty aggregate', function (): void {
    $key = null;

    Event::listen(QueryCacheMissed::class, function (QueryCacheMissed $event) use (&$key): void {
        $key = $event->key;
    });

    expect(Product::query()->cacheNegativeFor(30)->where('sku', 'NEG-TTL')->count())->toBe(0)
        ->and($key)->not->toBeNull();

    $entry = negativeTtlEntryFor($key);

    expect($entry)->not->toBeEmpty();

    $remaining = $entry['expiresAt'] - microtime(true);

    expect($remaining)->toBeGreaterThan(25)
        ->and($remaining)->toBeLessThanOrEqual(31);
});

it('caches a false exists result with a per-call negative TTL', function (): void {
    $read = fn (): bool => Product::query()->cacheNegativeFor(20)->where('sku', 'NEG-EXISTS')->exists();

    $misses = negativeTtlMissCount(function () use ($read): void {
        expect($read())->toBeFalse()
            ->and($read())->toBeFalse();
    });

    expect($misses)->toBe(1);
});

it('caches an empty result collection with a per-call negative TTL', function (): void {
    $read = fn () => Product::query()->cacheNegativeFor(30)->where('sku', 'NEG-EMPTY')->get();

    $misses = negativeTtlMissCount(function () use ($read): void {
        expect($read())->toHaveCount(0)
            ->and($read())->toHaveCount(0);
    });

    expect($misses)->toBe(1);
});

it('keeps the default full TTL for a zero count without a negative TTL', function (): void {
    $key = null;

    Event::listen(QueryCacheMissed::class, function (QueryCacheMissed $event) use (&$key): void {
        $key = $event->key;
    });

    expect(Product::query()->where('sku', 'NEG-DEFAULT')->count())->toBe(0)
        ->and($key)->not->toBeNull();

    $entry = negativeTtlEntryFor($key);

    expect($entry)->not->toBeEmpty();

    $remaining = $entry['expiresAt'] - microtime(true);

    expect($remaining)->toBeGreaterThan(290)
        ->and($remaining)->toBeLessThanOrEqual(301);
});

it('leaves empty collections uncached by default', function (): void {
    $read = fn () => Product::query()->where('sku', 'NEG-NOCACHE')->get();

    $misses = negativeTtlMissCount(function () use ($read): void {
        expect($read())->toHaveCount(0)
            ->and($read())->toHaveCount(0);
    });

    expect($misses)->toBe(2);
});

it('refreshes the negative TTL default when options are forgotten', function (): void {
    $engine = app(QueryCacheEngine::class);
    $engine->optionsFor(Product::class); // memoize the original 10s default

    config([
        'oomycache.cache.negative' => true,
        'oomycache.cache.negative_ttl' => 22,
    ]);
    $engine->forgetOptions(Product::class);

    $key = null;

    Event::listen(QueryCacheMissed::class, function (QueryCacheMissed $event) use (&$key): void {
        $key = $event->key;
    });

    expect(Product::query()->where('sku', 'NEG-FORGET')->get())->toHaveCount(0)
        ->and($key)->not->toBeNull();

    $entry = negativeTtlEntryFor($key);

    expect($entry)->not->toBeEmpty();

    $remaining = $entry['expiresAt'] - microtime(true);

    expect($remaining)->toBeGreaterThan(18)
        ->and($remaining)->toBeLessThanOrEqual(23);
});
