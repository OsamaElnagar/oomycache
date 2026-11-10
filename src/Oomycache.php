<?php

declare(strict_types=1);

namespace Oomycache\Oomycache;

use Oomycache\Oomycache\Cache\QueryCacheEngine;

/**
 * Public facade target for operator/developer actions.
 */
final class Oomycache
{
    public function __construct(private readonly QueryCacheEngine $engine) {}

    /**
     * Invalidate one model, several models, or the whole cache namespace.
     *
     * @param  class-string|list<class-string>|null  $models
     */
    public function flush(string|array|null $models = null): void
    {
        if ($models === null) {
            $this->clear();

            return;
        }

        foreach ((array) $models as $model) {
            $this->engine->flushModel($model, 'manual');
        }
    }

    public function clear(): void
    {
        $this->engine->clear();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->engine->status();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutQueryCache(callable $callback): mixed
    {
        return $this->engine->withoutQueryCache($callback);
    }

    public function engine(): QueryCacheEngine
    {
        return $this->engine;
    }
}
