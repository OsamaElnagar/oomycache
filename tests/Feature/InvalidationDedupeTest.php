<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Oomycache\Oomycache\Events\QueryCacheInvalidated;
use Oomycache\Oomycache\Tests\Fixtures\SoftDeleteRecord;

beforeEach(function (): void {
    Schema::create('soft_delete_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->softDeletes();
    });
});

it('emits one invalidation for an update', function (): void {
    $record = SoftDeleteRecord::query()->create(['name' => 'Update']);

    Event::fake([QueryCacheInvalidated::class]);
    $record->update(['name' => 'Updated']);

    Event::assertDispatchedTimes(QueryCacheInvalidated::class, 1);
});

it('emits one invalidation for a soft delete', function (): void {
    $record = SoftDeleteRecord::query()->create(['name' => 'Soft delete']);

    Event::fake([QueryCacheInvalidated::class]);
    $record->delete();

    Event::assertDispatchedTimes(QueryCacheInvalidated::class, 1);
});

it('emits one invalidation for a restore', function (): void {
    $record = SoftDeleteRecord::query()->create(['name' => 'Restore']);
    $record->delete();

    Event::fake([QueryCacheInvalidated::class]);
    $record->restore();

    Event::assertDispatchedTimes(QueryCacheInvalidated::class, 1);
});

it('emits one invalidation for a force delete', function (): void {
    $record = SoftDeleteRecord::query()->create(['name' => 'Force delete']);
    $record->delete();

    Event::fake([QueryCacheInvalidated::class]);
    $record->forceDelete();

    Event::assertDispatchedTimes(QueryCacheInvalidated::class, 1);
});

it('does not emit invalidation events while the package is disabled', function (): void {
    $record = SoftDeleteRecord::query()->create(['name' => 'Disabled']);

    config(['oomycache.enabled' => false]);
    Event::fake([QueryCacheInvalidated::class]);

    $record->update(['name' => 'Disabled update']);

    Event::assertNotDispatched(QueryCacheInvalidated::class);
});

it('does not recursively notify when an invalidation listener writes a cached model', function (): void {
    $record = SoftDeleteRecord::query()->create(['name' => 'Recursion']);

    $events = 0;

    Event::listen(QueryCacheInvalidated::class, function () use (&$events): void {
        $events++;

        SoftDeleteRecord::query()->create(['name' => 'Nested audit '.$events]);
    });

    $record->update(['name' => 'Recursion update']);

    expect($events)->toBe(1);
});
