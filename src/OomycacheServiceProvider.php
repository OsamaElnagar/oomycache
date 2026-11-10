<?php

declare(strict_types=1);

namespace Oomycache\Oomycache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Oomycache\Oomycache\Cache\CacheVersionStore;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Console\Commands\BenchCommand;
use Oomycache\Oomycache\Console\Commands\ClearCommand;
use Oomycache\Oomycache\Console\Commands\DoctorCommand;
use Oomycache\Oomycache\Console\Commands\PruneCommand;
use Oomycache\Oomycache\Console\Commands\StatusCommand;
use Oomycache\Oomycache\Contracts\PayloadCodec;
use Oomycache\Oomycache\Contracts\TenantResolver;
use Oomycache\Oomycache\Contracts\VersionStore;
use Oomycache\Oomycache\Events\QueryCacheBypassed;
use Oomycache\Oomycache\Events\QueryCacheCleared;
use Oomycache\Oomycache\Events\QueryCacheHit;
use Oomycache\Oomycache\Events\QueryCacheInvalidated;
use Oomycache\Oomycache\Events\QueryCacheMissed;
use Oomycache\Oomycache\Invalidation\InvalidationManager;
use Oomycache\Oomycache\Payload\ArrayPayloadCodec;
use Oomycache\Oomycache\Payload\ChunkedPayloadStore;
use Oomycache\Oomycache\Payload\PayloadCompressor;
use Oomycache\Oomycache\Stats\CacheStats;
use Oomycache\Oomycache\Tenant\NullTenantResolver;

class OomycacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/oomycache.php', 'oomycache');

        $this->app->singleton(VersionStore::class, CacheVersionStore::class);
        $this->app->singleton(PayloadCodec::class, ArrayPayloadCodec::class);
        $this->app->singleton(PayloadCompressor::class);
        $this->app->singleton(ChunkedPayloadStore::class);

        $this->app->bind(TenantResolver::class, function (Application $app): TenantResolver {
            $resolver = config('oomycache.tenant.resolver');

            if (is_string($resolver) && class_exists($resolver) && is_subclass_of($resolver, TenantResolver::class)) {
                /** @var TenantResolver */
                return $app->make($resolver);
            }

            return new NullTenantResolver;
        });

        $this->app->singleton(QueryCacheEngine::class, function (Application $app): QueryCacheEngine {
            return new QueryCacheEngine(
                $app->make('cache'),
                $app->make('config'),
                $app->make(VersionStore::class),
                $app->make(PayloadCodec::class),
                $app->make(PayloadCompressor::class),
                $app->make(ChunkedPayloadStore::class),
                $app,
            );
        });

        $this->app->singleton(InvalidationManager::class, function (Application $app): InvalidationManager {
            return new InvalidationManager(
                $app->make(QueryCacheEngine::class),
                $app->make('events'),
            );
        });

        $this->app->singleton(Oomycache::class, function (Application $app): Oomycache {
            return new Oomycache($app->make(QueryCacheEngine::class));
        });

        $this->app->singleton(CacheStats::class);
    }

    public function boot(): void
    {
        $this->app->make(InvalidationManager::class)->register();
        $this->registerStatsListeners();
        $this->registerRuntimeStateResetters();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/oomycache.php' => config_path('oomycache.php'),
        ], ['oomycache', 'oomycache-config']);

        if (! (bool) config('oomycache.commands.register', true)) {
            return;
        }

        $this->commands([
            StatusCommand::class,
            ClearCommand::class,
            DoctorCommand::class,
            BenchCommand::class,
            PruneCommand::class,
        ]);
    }

    private function registerStatsListeners(): void
    {
        $stats = $this->app->make(CacheStats::class);
        $events = $this->app->make('events');

        $events->listen(QueryCacheHit::class, static function () use ($stats): void {
            $stats->record('hits');
        });
        $events->listen(QueryCacheMissed::class, static function () use ($stats): void {
            $stats->record('misses');
        });
        $events->listen(QueryCacheBypassed::class, static function () use ($stats): void {
            $stats->record('bypasses');
        });
        $events->listen(QueryCacheInvalidated::class, static function () use ($stats): void {
            $stats->record('invalidations');
        });
        $events->listen(QueryCacheCleared::class, static function () use ($stats): void {
            $stats->record('clears');
        });
    }

    private function registerRuntimeStateResetters(): void
    {
        $events = $this->app->make('events');
        $app = $this->app;

        $reset = static function () use ($app): void {
            $app->make(QueryCacheEngine::class)->resetRuntimeState();
        };

        $events->listen(JobProcessing::class, $reset);

        // Octane is optional; listening by class name is harmless when the
        // package is not installed because those events are never dispatched.
        $events->listen('Laravel\Octane\Events\RequestReceived', $reset);
        $events->listen('Laravel\Octane\Events\RequestTerminated', $reset);
    }
}
