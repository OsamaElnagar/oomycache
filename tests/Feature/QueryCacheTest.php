<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Oomycache;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

/**
 * Count the DB queries performed by a callback (cache-store operations do
 * not use the DB in these tests because cache.default = array).
 */
function oomycacheQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function oomycacheSeedProducts(int $count = 10): void
{
    $category = Category::query()->create(['name' => 'General', 'is_active' => true]);

    for ($i = 1; $i <= $count; $i++) {
        Product::query()->create([
            'category_id' => $category->getKey(),
            'sku' => 'SKU-'.$i,
            'name' => 'Product '.$i,
            'price' => $i * 1.5,
            'is_active' => true,
            'stock_quantity' => $i,
        ]);
    }
}

it('merges the package configuration', function (): void {
    expect(config('oomycache.ttl'))->toBe(300)
        ->and(config('oomycache.payload.mode'))->toBe('arrays')
        ->and(app(Oomycache::class))->toBeInstanceOf(Oomycache::class);
});

it('caches repeated identical get queries', function (): void {
    oomycacheSeedProducts(10);

    $first = Product::query()->orderBy('id')->get();
    $queries = oomycacheQueryCount(fn () => Product::query()->orderBy('id')->get());

    expect($queries)->toBe(0);
});

it('invalidates the model namespace on an instance save', function (): void {
    oomycacheSeedProducts(5);

    $product = Product::query()->firstOrFail();
    Product::query()->orderBy('id')->get();

    $product->update(['name' => 'Renamed product']);

    $fresh = Product::query()->orderBy('id')->get();
    $queries = oomycacheQueryCount(fn () => Product::query()->orderBy('id')->get());

    expect($fresh->firstWhere('id', $product->getKey())?->name)->toBe('Renamed product')
        ->and($queries)->toBe(0);
});

it('invalidates the model namespace on a mass update through the builder', function (): void {
    oomycacheSeedProducts(10);

    Product::query()->orderBy('id')->get();

    Product::query()->where('id', '<=', 5)->update(['name' => 'Bulk renamed']);

    $fresh = Product::query()->orderBy('id')->get();

    expect($fresh->where('name', 'Bulk renamed'))->toHaveCount(5);
});

it('invalidates on mass delete through the builder', function (): void {
    oomycacheSeedProducts(10);
    Product::query()->orderBy('id')->get();

    Product::query()->where('id', '<=', 3)->delete();

    $fresh = Product::query()->orderBy('id')->get();

    expect($fresh)->toHaveCount(7);
});

it('caches the base paginator count used by Filament', function (): void {
    oomycacheSeedProducts(30);

    $run = function (): int {
        return Product::query()->where('is_active', true)->toBase()->getCountForPagination();
    };

    $first = $run();
    $queries = oomycacheQueryCount(fn () => $run());

    expect($first)->toBe(30)
        ->and($queries)->toBe(0);
});

it('caches a Filament-style page and its total together', function (): void {
    oomycacheSeedProducts(30);

    $render = function (): array {
        $query = Product::query()->where('is_active', true)->orderBy('id');

        $total = $query->toBase()->getCountForPagination();
        $page = $query->paginate(10, total: $total);

        return [$total, $page->items()];
    };

    [$firstTotal, $firstItems] = $render();
    $queries = oomycacheQueryCount(function () use ($render, $firstTotal, $firstItems): void {
        [$total, $items] = $render();

        expect($total)->toBe($firstTotal)
            ->and($items)->toHaveCount(count($firstItems));
    });

    expect($queries)->toBe(0);
});

it('invalidates eager-loaded relations when the related model is written', function (): void {
    oomycacheSeedProducts(3);

    $product = Product::query()->with('category')->firstOrFail();
    $category = $product->category;
    $category->update(['name' => 'Renamed category']);

    $fresh = Product::query()->with('category')->firstOrFail();

    expect($fresh->category?->name)->toBe('Renamed category');
});

it('caches eager-loaded relations and invalidates them when the related model is written', function (): void {
    oomycacheSeedProducts(3);

    $first = Product::query()->with('category')->orderBy('id')->get();
    $queries = oomycacheQueryCount(fn () => Product::query()->with('category')->orderBy('id')->get());

    Category::query()->firstOrFail()->update(['name' => 'Renamed category']);
    $fresh = Product::query()->with('category')->orderBy('id')->get();

    expect($queries)->toBe(0)
        ->and($first->first()?->relationLoaded('category'))->toBeTrue()
        ->and($fresh->first()?->category?->name)->toBe('Renamed category');
});

it('caches relationship aggregates and invalidates them when a child is written', function (): void {
    oomycacheSeedProducts(2);
    $category = Category::query()->firstOrFail();

    $first = Category::query()->withCount('products')->firstOrFail();
    $queries = oomycacheQueryCount(fn () => Category::query()->withCount('products')->firstOrFail());

    Product::query()->create([
        'category_id' => $category->getKey(),
        'sku' => 'NEW-AGG-1',
        'name' => 'New aggregate product',
        'price' => 10,
        'is_active' => true,
        'stock_quantity' => 1,
    ]);

    $fresh = Category::query()->withCount('products')->firstOrFail();

    expect($queries)->toBe(0)
        ->and($first->products_count)->toBe(2)
        ->and($fresh->products_count)->toBe(3);
});

it('tracks whereHas dependencies for invalidation', function (): void {
    oomycacheSeedProducts(1);
    $category = Category::query()->firstOrFail();

    $first = Category::query()->whereHas('products')->count();
    $queries = oomycacheQueryCount(fn () => Category::query()->whereHas('products')->count());

    $category->products()->create([
        'sku' => 'HAS-1',
        'name' => 'Where-has product',
        'price' => 5,
        'is_active' => true,
        'stock_quantity' => 1,
    ]);

    $fresh = Category::query()->whereHas('products')->count();

    expect($queries)->toBe(0)->and($first)->toBe(1)->and($fresh)->toBe(1);
});

it('bypasses the cache with dontCache()', function (): void {
    oomycacheSeedProducts(5);
    Product::query()->orderBy('id')->get();

    $queries = oomycacheQueryCount(fn () => Product::query()->dontCache()->orderBy('id')->get());

    expect($queries)->toBeGreaterThan(0);
});

it('bypasses cache reads inside a transaction', function (): void {
    oomycacheSeedProducts(5);
    Product::query()->orderBy('id')->get();

    $queries = oomycacheQueryCount(function (): void {
        DB::transaction(function (): void {
            Product::query()->orderBy('id')->get();
        });
    });

    expect($queries)->toBeGreaterThan(0);
});

it('bypasses search-term queries by default', function (): void {
    oomycacheSeedProducts(5);

    $queries = oomycacheQueryCount(function (): void {
        Product::query()->where('name', 'like', '%Product%')->get();
        Product::query()->where('name', 'like', '%Product%')->get();
    });

    expect($queries)->toBeGreaterThan(0);
});

it('clears the cache through the artisan command', function (): void {
    oomycacheSeedProducts(5);
    Product::query()->orderBy('id')->get();

    $this->artisan('oomycache:clear', ['--model' => [Product::class]])->assertSuccessful();

    $queries = oomycacheQueryCount(fn () => Product::query()->orderBy('id')->get());

    expect($queries)->toBeGreaterThan(0);
});

it('runs the status and doctor commands', function (): void {
    $this->artisan('oomycache:status')->assertSuccessful();
    $this->artisan('oomycache:doctor')->assertSuccessful();
});
