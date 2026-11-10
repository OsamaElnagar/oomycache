<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Invalidation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Concerns\HasQueryCache;
use Oomycache\Oomycache\Contracts\QueryCacheable;
use Throwable;

/**
 * Translates Eloquent write events into table version bumps. Invalidations
 * that happen inside a transaction are deferred until commit; a failed flush
 * is a correctness incident, not a silent miss.
 */
final class InvalidationManager
{
    public function __construct(
        private readonly QueryCacheEngine $engine,
        private readonly Dispatcher $events,
    ) {}

    public function register(): void
    {
        $this->events->listen(
            'eloquent.*',
            function (string $eventName, array $payload): void {
                $model = $payload[0] ?? null;

                foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
                    if (str_starts_with($eventName, 'eloquent.'.$event.': ')) {
                        $this->handleModelEvent($model, $event);

                        return;
                    }
                }
            },
        );
    }

    public function handleModelEvent(mixed $model, string $event): void
    {
        if (! $model instanceof Model) {
            return;
        }

        $isCacheable = $model instanceof QueryCacheable
            || in_array(HasQueryCache::class, class_uses_recursive($model), true);

        if (! $isCacheable) {
            return;
        }

        $this->flushModel($model, $event);
    }

    public function flushModel(Model $model, string $reason = 'write'): void
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $connectionName = $connection->getName();

        $flush = function () use ($table, $connectionName, $reason): mixed {
            try {
                return $this->engine->flushTable($table, $connectionName, $reason);
            } catch (Throwable $exception) {
                if ((bool) config('oomycache.safety.log_invalidation_failures', true)) {
                    logger()->critical('OoMyCache invalidation failed.', [
                        'table' => $table,
                        'connection' => $connectionName,
                        'reason' => $reason,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }

                // Invalidation failures are correctness incidents: keep them loud.
                throw $exception;
            }
        };

        if ($this->deferToCommit() && $connection->transactionLevel() > 0) {
            $connection->afterCommit($flush);

            return;
        }

        $flush();
    }

    private function deferToCommit(): bool
    {
        return (bool) config('oomycache.safety.invalidate_after_commit', true);
    }
}
