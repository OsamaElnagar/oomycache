<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
    ]);
});

/**
 * Count queries that reference the given table (ignores cache-store I/O).
 */
function cacheStoreMatrixTableQueries(Closure $callback, string $table): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = 0;

    foreach (DB::getQueryLog() as $entry) {
        if (str_contains((string) $entry['query'], '"'.$table.'"')) {
            $count++;
        }
    }

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function cacheStoreMatrixSeed(): Product
{
    $category = Category::query()->create(['name' => 'Matrix category', 'is_active' => true]);

    return Product::query()->create([
        'category_id' => $category->getKey(),
        'sku' => 'MATRIX-1',
        'name' => 'Matrix product',
        'price' => 10,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
}

it('caches and invalidates on the array store', function (): void {
    $product = cacheStoreMatrixSeed();

    Product::query()->with('category')->orderBy('id')->get();

    expect(cacheStoreMatrixTableQueries(
        fn () => Product::query()->with('category')->orderBy('id')->get(),
        'products',
    ))->toBe(0);

    $product->update(['name' => 'Array renamed']);

    expect(Product::query()->orderBy('id')->firstOrFail()->name)->toBe('Array renamed');
});

it('caches and invalidates on the file store', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oomycache-file-'.uniqid('', true);

    config([
        'cache.default' => 'file',
        'cache.stores.file' => [
            'driver' => 'file',
            'path' => $directory,
        ],
    ]);

    try {
        $product = cacheStoreMatrixSeed();

        Product::query()->orderBy('id')->get();

        expect(cacheStoreMatrixTableQueries(
            fn () => Product::query()->orderBy('id')->get(),
            'products',
        ))->toBe(0);

        $product->update(['name' => 'File renamed']);

        expect(Product::query()->orderBy('id')->firstOrFail()->name)->toBe('File renamed');
    } finally {
        Cache::store('file')->flush();
        File::deleteDirectory($directory);
    }
});

it('caches and invalidates on the database store', function (): void {
    Schema::dropIfExists('cache_locks');
    Schema::dropIfExists('cache');

    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->integer('expiration');
    });

    config([
        'cache.default' => 'database',
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'cache',
            'prefix' => '',
        ],
    ]);

    $product = cacheStoreMatrixSeed();

    Product::query()->orderBy('id')->get();

    expect(cacheStoreMatrixTableQueries(
        fn () => Product::query()->orderBy('id')->get(),
        'products',
    ))->toBe(0);

    $product->update(['name' => 'Database renamed']);

    expect(Product::query()->orderBy('id')->firstOrFail()->name)->toBe('Database renamed');
});

it('reports the active store in engine status', function (): void {
    config(['cache.default' => 'array']);

    $status = app(QueryCacheEngine::class)->status();

    expect($status['store'])->toBe('array');
});
