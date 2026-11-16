<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

/**
 * Count application queries issued by a callback; the cache uses the array
 * store in this suite, so only database statements are counted.
 */
function cursorPageQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function cursorPageSeedProducts(int $count): void
{
    $category = Category::query()->create(['name' => 'Cursor category', 'is_active' => true]);

    for ($i = 1; $i <= $count; $i++) {
        Product::query()->create([
            'category_id' => $category->getKey(),
            'sku' => 'CURSOR-'.$i,
            'name' => 'Cursor product '.$i,
            'price' => $i * 2,
            'is_active' => true,
            'stock_quantity' => $i,
        ]);
    }
}

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'oomycache.store' => null,
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.safety.skip_reads_in_transactions' => false,
        'oomycache.safety.invalidate_after_commit' => false,
    ]);

    cursorPageSeedProducts(5);
});

it('caches the first cursor page and rebuilds an identical paginator on a hit', function (): void {
    $first = Product::query()->orderBy('id')->cursorPaginate(2);

    expect($first->items())->toHaveCount(2)
        ->and($first->hasMorePages())->toBeTrue()
        ->and($first->nextCursor())->not->toBeNull();

    $queries = cursorPageQueryCount(function () use ($first): void {
        $cached = Product::query()->orderBy('id')->cursorPaginate(2);

        expect($cached->items())->toHaveCount(2)
            ->and($cached->hasMorePages())->toBe($first->hasMorePages())
            ->and($cached->nextCursor()?->encode())->toBe($first->nextCursor()?->encode())
            ->and($cached->path())->toBe($first->path())
            ->and($cached->getCursorName())->toBe($first->getCursorName());
    });

    expect($queries)->toBe(0);
});

it('keys cursor pages by the effective cursor', function (): void {
    $first = Product::query()->orderBy('id')->cursorPaginate(2);
    $cursor = $first->nextCursor()?->encode();

    expect($cursor)->not->toBeNull();

    $second = Product::query()->orderBy('id')->cursorPaginate(2, cursor: $cursor);

    $queries = cursorPageQueryCount(function () use ($cursor, $second): void {
        $cached = Product::query()->orderBy('id')->cursorPaginate(2, cursor: $cursor);

        expect($cached->items())->toHaveCount(2)
            ->and($cached->items()[0]->getKey())->toBe($second->items()[0]->getKey());
    });

    expect($queries)->toBe(0)
        ->and($second->items()[0]->getKey())->not->toBe($first->items()[0]->getKey());
});

it('round-trips hasMore and a null next cursor on the last page', function (): void {
    $first = Product::query()->orderBy('id')->cursorPaginate(2);
    $second = Product::query()->orderBy('id')->cursorPaginate(2, cursor: $first->nextCursor()?->encode());
    $lastCursor = $second->nextCursor()?->encode();

    expect($lastCursor)->not->toBeNull();

    $last = Product::query()->orderBy('id')->cursorPaginate(2, cursor: $lastCursor);

    expect($last->items())->toHaveCount(1)
        ->and($last->hasMorePages())->toBeFalse()
        ->and($last->nextCursor())->toBeNull();

    $queries = cursorPageQueryCount(function () use ($lastCursor): void {
        $cached = Product::query()->orderBy('id')->cursorPaginate(2, cursor: $lastCursor);

        expect($cached->hasMorePages())->toBeFalse()
            ->and($cached->nextCursor())->toBeNull();
    });

    expect($queries)->toBe(0);
});

it('invalidates a cached cursor page when a model is written', function (): void {
    Product::query()->orderBy('id')->cursorPaginate(2);

    expect(cursorPageQueryCount(fn (): mixed => Product::query()->orderBy('id')->cursorPaginate(2)))->toBe(0);

    Product::query()->create([
        'sku' => 'CURSOR-NEW',
        'name' => 'Cursor new product',
        'price' => 99,
        'is_active' => true,
        'stock_quantity' => 1,
    ]);

    $queries = cursorPageQueryCount(function (): void {
        Product::query()->orderBy('id')->cursorPaginate(2);
    });

    expect($queries)->toBeGreaterThan(0);
});

it('does not cache cursor pages when cache_pagination is disabled', function (): void {
    config(['oomycache.cache.pagination' => false]);
    app(QueryCacheEngine::class)->forgetOptions();

    Product::query()->orderBy('id')->cursorPaginate(2);

    $queries = cursorPageQueryCount(fn (): mixed => Product::query()->orderBy('id')->cursorPaginate(2));

    expect($queries)->toBeGreaterThan(0);
});
