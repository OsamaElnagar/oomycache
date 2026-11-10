<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository;
use Oomycache\Oomycache\Contracts\VersionStore as VersionStoreContract;

/**
 * Version tokens live in the shared cache store under forever keys, so
 * invalidation is a single O(1) write on every driver (no tags, no scans).
 */
final class CacheVersionStore implements VersionStoreContract
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly Repository $config,
    ) {}

    public function get(string $table, ?string $connection = null): string
    {
        $key = $this->tableKey($table, $connection);
        $token = $this->repository()->get($key);

        if (! is_string($token) || $token === '') {
            $token = $this->newToken();
            $this->repository()->forever($key, $token);
        }

        return $token;
    }

    public function bump(string $table, ?string $connection = null): string
    {
        $token = $this->newToken();
        $this->repository()->forever($this->tableKey($table, $connection), $token);

        return $token;
    }

    public function global(): string
    {
        $token = $this->repository()->get($this->globalKey());

        if (! is_string($token) || $token === '') {
            $token = $this->newToken();
            $this->repository()->forever($this->globalKey(), $token);
        }

        return $token;
    }

    public function bumpGlobal(): string
    {
        $token = $this->newToken();
        $this->repository()->forever($this->globalKey(), $token);

        return $token;
    }

    private function tableKey(string $table, ?string $connection): string
    {
        return $this->prefix().':version:'.hash('xxh128', ($connection ?? 'default').'|'.$table);
    }

    private function globalKey(): string
    {
        return $this->prefix().':version:global';
    }

    private function prefix(): string
    {
        return (string) $this->config->get('oomycache.prefix', 'oomycache');
    }

    private function repository(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->cache->store($this->config->get('oomycache.store'));
    }

    private function newToken(): string
    {
        return 'v'.bin2hex(random_bytes(8));
    }
}
