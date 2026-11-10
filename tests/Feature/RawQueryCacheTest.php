<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Oomycache\Oomycache\Facades\Oomycache;
use Workbench\App\Models\Product;

it('caches a raw query result and invalidates it by dependency table', function (): void {
    Product::query()->create(['sku' => 'RAW-1', 'name' => 'Raw product', 'is_active' => true]);

    $calls = 0;

    $callback = function () use (&$calls): array {
        $calls++;

        return DB::table('products')->orderBy('id')->get()->map(
            static fn (object $row): array => (array) $row,
        )->all();
    };

    $first = Oomycache::rememberQuery(['products'], 'raw:products', 60, $callback);
    $second = Oomycache::rememberQuery(['products'], 'raw:products', 60, $callback);

    expect($calls)->toBe(1)
        ->and($second)->toBe($first);

    Product::query()->create(['sku' => 'RAW-2', 'name' => 'Raw product 2', 'is_active' => true]);

    $third = Oomycache::rememberQuery(['products'], 'raw:products', 60, $callback);

    expect($calls)->toBe(2)
        ->and($third)->toHaveCount(2);
});

it('can invalidate raw query entries explicitly by table', function (): void {
    $calls = 0;

    $callback = function () use (&$calls): int {
        $calls++;

        return Product::query()->count();
    };

    Oomycache::rememberQuery(['products'], 'raw:count', 60, $callback);
    Oomycache::rememberQuery(['products'], 'raw:count', 60, $callback);

    expect($calls)->toBe(1);

    Oomycache::flushTables(['products']);

    Oomycache::rememberQuery(['products'], 'raw:count', 60, $callback);

    expect($calls)->toBe(2);
});

it('does not cache empty raw results when negative caching is disabled', function (): void {
    $calls = 0;

    $callback = function () use (&$calls): array {
        $calls++;

        return [];
    };

    Oomycache::rememberQuery(['products'], 'raw:empty', 60, $callback);
    Oomycache::rememberQuery(['products'], 'raw:empty', 60, $callback);

    expect($calls)->toBe(2);
});
