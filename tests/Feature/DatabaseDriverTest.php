<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

/**
 * Real database-driver lane for the package's Eloquent path.
 *
 * Skipped by default; run locally/CI with:
 *
 *   OOMYCACHE_TEST_DB=mysql  DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=oomycache_smoke DB_USERNAME=root DB_PASSWORD= \
 *     vendor/bin/pest tests/Feature/DatabaseDriverTest.php
 *
 *   OOMYCACHE_TEST_DB=pgsql  DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=oomycache_smoke DB_USERNAME=postgres DB_PASSWORD=postgres \
 *     vendor/bin/pest tests/Feature/DatabaseDriverTest.php
 */
beforeEach(function (): void {
    $driver = getenv('OOMYCACHE_TEST_DB');

    if (! is_string($driver) || ! in_array($driver, ['mysql', 'pgsql'], true)) {
        $this->markTestSkipped('Set OOMYCACHE_TEST_DB=mysql|pgsql to run the real database-driver lane.');
    }

    $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432));
    $database = (string) (getenv('DB_DATABASE') ?: 'oomycache_smoke');
    $username = (string) (getenv('DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'));
    $password = (string) (getenv('DB_PASSWORD') ?: '');

    config([
        'database.default' => $driver,
        "database.connections.{$driver}" => array_filter([
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '',
            'schema' => $driver === 'pgsql' ? 'public' : null,
            'sslmode' => $driver === 'pgsql' ? 'prefer' : null,
        ], static fn (mixed $value): bool => $value !== null),
        'cache.default' => 'database',
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => $driver,
            'table' => 'cache',
            'prefix' => '',
        ],
        'oomycache.store' => 'database',
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.safety.skip_reads_in_transactions' => false,
        'oomycache.safety.invalidate_after_commit' => false,
    ]);

    try {
        DB::connection($driver)->getPdo();
    } catch (Throwable $exception) {
        $this->markTestSkipped("Database [{$driver}] is not reachable: ".$exception->getMessage());
    }

    Schema::connection($driver)->dropIfExists('products');
    Schema::connection($driver)->dropIfExists('categories');

    Schema::connection($driver)->create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::connection($driver)->create('products', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('category_id');
        $table->string('sku')->unique();
        $table->string('name');
        $table->decimal('price', 10, 2)->default(0);
        $table->boolean('is_active')->default(true);
        $table->integer('stock_quantity')->default(0);
        $table->timestamps();
    });

    Schema::connection($driver)->dropIfExists('cache');

    Schema::connection($driver)->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->integer('expiration');
    });
});

function driverTestProductQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = 0;

    foreach (DB::getQueryLog() as $entry) {
        if (str_contains((string) $entry['query'], 'products')) {
            $count++;
        }
    }

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

it('caches and invalidates Eloquent queries on the real database driver', function (): void {
    $driver = (string) getenv('OOMYCACHE_TEST_DB');

    $category = Category::query()->create(['name' => 'Driver category '.$driver, 'is_active' => true]);

    $product = Product::query()->create([
        'category_id' => $category->getKey(),
        'sku' => 'DRIVER-'.$driver,
        'name' => 'Driver product',
        'price' => 10,
        'is_active' => true,
        'stock_quantity' => 3,
    ]);

    app(QueryCacheEngine::class)->clear();

    Product::query()->with('category')->orderBy('id')->get();

    expect(driverTestProductQueries(
        fn () => Product::query()->with('category')->orderBy('id')->get(),
    ))->toBe(0);

    $product->update(['name' => 'Driver product renamed']);

    $fresh = Product::query()->with('category')->orderBy('id')->get();

    expect($fresh->firstWhere('id', $product->getKey())?->name)->toBe('Driver product renamed')
        ->and(driverTestProductQueries(
            fn () => Product::query()->with('category')->orderBy('id')->get(),
        ))->toBe(0);
});
