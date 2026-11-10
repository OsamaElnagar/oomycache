<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Contracts\TenantResolver;
use Oomycache\Oomycache\Query\CacheDecision;
use Oomycache\Oomycache\Query\QueryContext;
use Oomycache\Oomycache\Tests\Fixtures\MutableTenantResolver;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;

function tenancyQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function tenancySeedProducts(): void
{
    $category = Category::query()->create(['name' => 'Tenant category', 'is_active' => true]);

    for ($i = 1; $i <= 5; $i++) {
        Product::query()->create([
            'category_id' => $category->getKey(),
            'sku' => 'TENANT-'.$i,
            'name' => 'Tenant product '.$i,
            'price' => $i,
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

    app()->instance(TenantResolver::class, new MutableTenantResolver);
});

it('isolates cache entries per tenant', function (): void {
    tenancySeedProducts();

    /** @var MutableTenantResolver $resolver */
    $resolver = app(TenantResolver::class);

    $resolver->tenant('tenant-a');

    Product::query()->orderBy('id')->get();
    expect(tenancyQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);

    $resolver->tenant('tenant-b');
    expect(tenancyQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBeGreaterThan(0);

    $resolver->tenant('tenant-a');
    expect(tenancyQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);
});

it('isolates cache entries per panel', function (): void {
    tenancySeedProducts();

    /** @var MutableTenantResolver $resolver */
    $resolver = app(TenantResolver::class);

    $resolver->panel('admin');
    Product::query()->orderBy('id')->get();
    expect(tenancyQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBe(0);

    $resolver->panel('glass-aurora');
    expect(tenancyQueryCount(fn () => Product::query()->orderBy('id')->get()))->toBeGreaterThan(0);
});

it('fails closed and never caches when the tenant is unresolved', function (): void {
    tenancySeedProducts();

    /** @var MutableTenantResolver $resolver */
    $resolver = app(TenantResolver::class);
    $resolver->failClosed('filament-tenant-unresolved');

    $first = tenancyQueryCount(fn () => Product::query()->orderBy('id')->get());
    $second = tenancyQueryCount(fn () => Product::query()->orderBy('id')->get());

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBeGreaterThan(0);
});

it('reports tenant segments in the engine status', function (): void {
    /** @var MutableTenantResolver $resolver */
    $resolver = app(TenantResolver::class);
    $resolver->tenant('tenant-a', 'glass-lens');

    $status = app(QueryCacheEngine::class)->status();

    expect($status['tenant'])->toBe(['panel' => 'glass-lens', 'tenant' => 'tenant-a']);
});

it('denies caching with a named reason when tenancy is unresolved', function (): void {
    /** @var MutableTenantResolver $resolver */
    $resolver = app(TenantResolver::class);
    $resolver->failClosed('filament-tenant-unresolved');

    tenancySeedProducts();

    $engine = app(QueryCacheEngine::class);
    $context = QueryContext::capture(
        Product::query(),
        'get',
        ['*'],
        $engine->optionsFor(Product::class),
    );

    $decision = $engine->decide($context);

    expect($decision)->toBeInstanceOf(CacheDecision::class)
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe('tenant-unresolved');
});
