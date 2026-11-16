<?php

declare(strict_types=1);

use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Contracts\VersionStore;
use Oomycache\Oomycache\Invalidation\InvalidationManager;
use Oomycache\Oomycache\Tests\Fixtures\ThrowingVersionStore;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    config([
        'oomycache.safety.log_invalidation_failures' => false,
        'oomycache.safety.throw_on_invalidation_failure' => true,
        'oomycache.safety.fail_silently' => true,
        'oomycache.safety.invalidate_after_commit' => false,
    ]);

    app()->instance(VersionStore::class, new ThrowingVersionStore);
    app()->forgetInstance(QueryCacheEngine::class);
    app()->forgetInstance(InvalidationManager::class);
});

it('rethrows an engine invalidation failure by default', function (): void {
    expect(fn (): mixed => app(QueryCacheEngine::class)->flushModel(Product::class))
        ->toThrow(RuntimeException::class, 'version store unavailable');
});

it('suppresses an engine invalidation failure only when explicitly disabled', function (): void {
    config(['oomycache.safety.throw_on_invalidation_failure' => false]);

    expect(fn (): mixed => app(QueryCacheEngine::class)->flushModel(Product::class))
        ->not->toThrow(RuntimeException::class);
});

it('rethrows an invalidation-manager failure by default', function (): void {
    expect(fn (): mixed => app(InvalidationManager::class)->flushModel(new Product, 'saved'))
        ->toThrow(RuntimeException::class, 'version store unavailable');
});

it('suppresses an invalidation-manager failure only when explicitly disabled', function (): void {
    config(['oomycache.safety.throw_on_invalidation_failure' => false]);

    expect(fn (): mixed => app(InvalidationManager::class)->flushModel(new Product, 'saved'))
        ->not->toThrow(RuntimeException::class);
});

it('does not let fail_silently suppress an invalidation failure', function (): void {
    config([
        'oomycache.safety.fail_silently' => true,
        'oomycache.safety.throw_on_invalidation_failure' => true,
    ]);

    expect(fn (): mixed => app(QueryCacheEngine::class)->flushModel(Product::class))
        ->toThrow(RuntimeException::class, 'version store unavailable');
});
