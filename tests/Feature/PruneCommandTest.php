<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insert a raw database cache row (the test bypasses the cache repository on
 * purpose, so it can control the `expiration` column directly).
 */
if (! function_exists('pruneTestInsertCacheRow')) {
    function pruneTestInsertCacheRow(string $key, int $expiration, string $value = 'payload'): void
    {
        DB::table('cache')->insert([
            'key' => $key,
            'value' => $value,
            'expiration' => $expiration,
        ]);
    }
}

beforeEach(function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'cache',
            'prefix' => '',
        ],
        'oomycache.store' => null,
        'oomycache.maintenance.prune.chunk' => 100,
        'oomycache.maintenance.prune.max_seconds' => 5,
        'oomycache.maintenance.prune.sleep_us' => 0,
    ]);

    Schema::dropIfExists('cache_locks');
    Schema::dropIfExists('cache');

    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->integer('expiration');
    });

    Schema::create('cache_locks', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
});

it('prunes only expired rows from the database cache store', function (): void {
    $now = time();
    pruneTestInsertCacheRow('stale-a', $now - 120);
    pruneTestInsertCacheRow('stale-b', $now - 60);
    pruneTestInsertCacheRow('fresh-a', $now + 3600);
    pruneTestInsertCacheRow('fresh-b', $now + 60);

    $this->artisan('oomycache:prune', ['--chunk' => 2])
        ->expectsOutputToContain('Expired: 2')
        ->expectsOutputToContain('Deleted: 2')
        ->assertSuccessful();

    expect(DB::table('cache')->whereIn('key', ['stale-a', 'stale-b'])->count())->toBe(0)
        ->and(DB::table('cache')->whereIn('key', ['fresh-a', 'fresh-b'])->count())->toBe(2);
});

it('counts expired rows in dry-run mode without deleting them', function (): void {
    $now = time();
    pruneTestInsertCacheRow('stale-a', $now - 10);
    pruneTestInsertCacheRow('fresh-a', $now + 3600);

    $this->artisan('oomycache:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: yes')
        ->expectsOutputToContain('Expired: 1')
        ->expectsOutputToContain('Deleted: 0 (dry run)')
        ->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(2);
});

it('never deletes a key that was refreshed after a scan', function (): void {
    $now = time();
    pruneTestInsertCacheRow('refreshed', $now - 30);

    $this->artisan('oomycache:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Expired: 1')
        ->assertSuccessful();

    DB::table('cache')->where('key', 'refreshed')->update(['expiration' => $now + 3600]);

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('Deleted: 0')
        ->assertSuccessful();

    expect(DB::table('cache')->where('key', 'refreshed')->exists())->toBeTrue();
});

it('respects the store prefix and never touches cache_locks', function (): void {
    config([
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'cache',
            'prefix' => 'oomycache-',
        ],
    ]);

    $now = time();
    pruneTestInsertCacheRow('oomycache-stale', $now - 10);
    pruneTestInsertCacheRow('oomycache-fresh', $now + 3600);
    pruneTestInsertCacheRow('foreign-stale', $now - 10);

    DB::table('cache_locks')->insert([
        'key' => 'oomycache-lock',
        'owner' => 'other-process',
        'expiration' => $now - 10,
    ]);

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('Deleted: 1')
        ->assertSuccessful();

    expect(DB::table('cache')->where('key', 'oomycache-stale')->exists())->toBeFalse()
        ->and(DB::table('cache')->where('key', 'oomycache-fresh')->exists())->toBeTrue()
        ->and(DB::table('cache')->where('key', 'foreign-stale')->exists())->toBeTrue()
        ->and(DB::table('cache_locks')->count())->toBe(1);
});

it('prunes the configured OoMyCache store rather than cache.default', function (): void {
    config([
        'cache.stores.backup_db' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'cache',
            'prefix' => 'backup-',
        ],
        'oomycache.store' => 'backup_db',
    ]);

    $now = time();
    pruneTestInsertCacheRow('backup-stale', $now - 10);
    pruneTestInsertCacheRow('backup-fresh', $now + 3600);
    pruneTestInsertCacheRow('database-stale', $now - 10);

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('backup_db')
        ->expectsOutputToContain('Deleted: 1')
        ->assertSuccessful();

    expect(DB::table('cache')->where('key', 'backup-stale')->exists())->toBeFalse()
        ->and(DB::table('cache')->where('key', 'database-stale')->exists())->toBeTrue();
});

it('stops when the time budget is exhausted', function (): void {
    $now = time();

    foreach (range(1, 5) as $index) {
        pruneTestInsertCacheRow('stale-'.$index, $now - 10);
    }

    $this->artisan('oomycache:prune', ['--chunk' => 1, '--max-seconds' => '0.000001'])
        ->expectsOutputToContain('budget was exhausted')
        ->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(4);
});

it('is idempotent', function (): void {
    pruneTestInsertCacheRow('stale', time() - 10);

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('Deleted: 1')
        ->assertSuccessful();

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('Deleted: 0')
        ->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(0);
});

it('refuses non-database stores with a typed message', function (): void {
    config([
        'cache.default' => 'array',
        'oomycache.store' => null,
    ]);

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('only the [database] cache store can be pruned')
        ->assertExitCode(1);
});

it('recommends laravel-cache-evict when refusing the file store', function (): void {
    config([
        'cache.default' => 'file',
        'cache.stores.file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
        'oomycache.store' => null,
    ]);

    $this->artisan('oomycache:prune')
        ->expectsOutputToContain('laravel-cache-evict')
        ->assertExitCode(1);
});
