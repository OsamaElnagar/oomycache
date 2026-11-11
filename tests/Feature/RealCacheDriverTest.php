<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

/**
 * Real Redis / Memcached driver lane. The normal suite is service-independent;
 * run this file with:
 *
 *   OOMYCACHE_TEST_DRIVER=redis     vendor/bin/pest tests/Feature/RealCacheDriverTest.php
 *   OOMYCACHE_TEST_DRIVER=memcached vendor/bin/pest tests/Feature/RealCacheDriverTest.php
 */
function realCacheDriverProductQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = 0;

    foreach (DB::getQueryLog() as $entry) {
        if (str_contains((string) $entry['query'], '"products"')) {
            $count++;
        }
    }

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function configureRealCacheDriver(string $driver): void
{
    config([
        'oomycache.store' => $driver,
        'oomycache.prefix' => 'oomycache-driver-'.bin2hex(random_bytes(6)),
        'oomycache.ttl_jitter' => 0.0,
        'oomycache.safety.skip_reads_in_transactions' => false,
        'oomycache.safety.invalidate_after_commit' => false,
        'oomycache.payload.memoize_per_request' => false,
        'cache.default' => $driver,
    ]);

    if ($driver === 'redis') {
        $connection = [
            'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => (int) (getenv('REDIS_DB') ?: 0),
            'timeout' => 2,
        ];

        config([
            'redis.client' => 'phpredis',
            'database.redis.default' => $connection,
            'database.redis.cache' => $connection,
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        ]);
    }

    if ($driver === 'memcached') {
        config([
            'cache.stores.memcached' => [
                'driver' => 'memcached',
                'servers' => [
                    [
                        'host' => (string) (getenv('MEMCACHED_HOST') ?: '127.0.0.1'),
                        'port' => (int) (getenv('MEMCACHED_PORT') ?: 11211),
                        'weight' => 100,
                    ],
                ],
            ],
        ]);
    }
}

it('caches and invalidates on a real Redis store', function (): void {
    if (getenv('OOMYCACHE_TEST_DRIVER') !== 'redis') {
        $this->markTestSkipped('Set OOMYCACHE_TEST_DRIVER=redis to run the real Redis lane.');
    }

    configureRealCacheDriver('redis');

    try {
        Cache::store('redis')->put('oomycache-driver-probe', 'ok', 10);
    } catch (Throwable $exception) {
        $this->markTestSkipped('Redis is not reachable: '.$exception->getMessage());
    }

    $category = Category::query()->create(['name' => 'Redis driver category', 'is_active' => true]);
    $product = Product::query()->create([
        'category_id' => $category->getKey(),
        'sku' => 'REDIS-DRIVER-1',
        'name' => 'Redis driver product',
        'is_active' => true,
    ]);

    Product::query()->with('category')->orderBy('id')->get();

    expect(realCacheDriverProductQueries(
        fn () => Product::query()->with('category')->orderBy('id')->get(),
    ))->toBe(0);

    $product->update(['name' => 'Redis driver renamed']);

    expect(Product::query()->orderBy('id')->firstOrFail()->name)->toBe('Redis driver renamed')
        ->and(app(QueryCacheEngine::class)->status()['store'])->toBe('redis');
});

it('caches and invalidates on a real Memcached store', function (): void {
    if (getenv('OOMYCACHE_TEST_DRIVER') !== 'memcached') {
        $this->markTestSkipped('Set OOMYCACHE_TEST_DRIVER=memcached to run the real Memcached lane.');
    }

    configureRealCacheDriver('memcached');

    try {
        Cache::store('memcached')->put('oomycache-driver-probe', 'ok', 10);
    } catch (Throwable $exception) {
        $this->markTestSkipped('Memcached is not reachable: '.$exception->getMessage());
    }

    $category = Category::query()->create(['name' => 'Memcached driver category', 'is_active' => true]);
    $product = Product::query()->create([
        'category_id' => $category->getKey(),
        'sku' => 'MEMCACHED-DRIVER-1',
        'name' => 'Memcached driver product',
        'is_active' => true,
    ]);

    Product::query()->with('category')->orderBy('id')->get();

    expect(realCacheDriverProductQueries(
        fn () => Product::query()->with('category')->orderBy('id')->get(),
    ))->toBe(0);

    $product->update(['name' => 'Memcached driver renamed']);

    expect(Product::query()->orderBy('id')->firstOrFail()->name)->toBe('Memcached driver renamed')
        ->and(app(QueryCacheEngine::class)->status()['store'])->toBe('memcached');
});
