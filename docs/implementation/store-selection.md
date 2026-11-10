# Choosing a cache store (including no-Redis hosts)

OoMyCache can use its own store, independent of Laravel's application cache:

```dotenv
CACHE_STORE=database        # app cache can stay where it is
OOMYCACHE_STORE=file        # query cache gets a non-SQL store
```

This is useful on a single host where Redis/Memcached is unavailable but the filesystem is writable
(for example a Hostinger shared/premium plan). It avoids the cache-table SELECTs that the database
store adds to every warm read.

If stats are enabled, keep them on the same store too (the default follows `OOMYCACHE_STORE`):

```dotenv
OOMYCACHE_STATS=true
OOMYCACHE_STATS_STORE=file
```

Otherwise the stats counters silently keep using Laravel's default store and re-introduce cache-table
SQL on every hit/miss.

## Measure, don't assume

Run the benchmark on the deployment host, on a real cacheable model, with the corrected output:

```bash
OOMYCACHE_STORE=file php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=5 --json
```

Read all three numbers:

- `warm_avg_app_queries` — application queries eliminated (the cache's actual job)
- `warm_avg_store_queries` — cache-store SQL statements (only non-zero for the database store)
- `warm_avg_total_queries` — the honest total

Also run the same command with `OOMYCACHE_STORE=database` for comparison. A query cache is only worth
enabling for reads whose DB work exceeds the store's read cost.

## Trade-offs

| Store | Warm-read cost | Works on shared hosting | Multi-node safe | Notes |
| --- | --- | --- | --- | --- |
| `database` | 1 cache-table SELECT per cache entry + version reads | yes | yes (shared DB) | Every warm read adds SQL; `oomycache:prune` required |
| `file` | filesystem read | yes, if `storage/` is writable | **no** — each node has its own filesystem | Best single-host no-Redis option |
| `redis` / `memcached` | network op | usually needs a managed service | yes | Preferred for multi-node |
| `array` | in-process | n/a | n/a | Tests only; not persistent |
| `octane` | in-process | n/a | n/a | Only with Laravel Octane and a persistent worker |

File store caveats:

- It is per-server. On a load-balanced/multi-node deployment, nodes will have different cache contents;
  use Redis/Memcached or the database store instead.
- Changing `OOMYCACHE_STORE` or `OOMYCACHE_PREFIX` starts a fresh key namespace; clear config caches
  (`php artisan config:clear`) and deploy consistently.
- Laravel's file store handles expiry on read; existing files are reclaimed by the store's own
  garbage collection. `oomycache:prune` only applies to the database store.
- Keep the file store on local disk, not a network mount, for predictable latency.

## Hostinger premium guidance

1. Keep Laravel's app cache as-is (`CACHE_STORE=database`).
2. Set `OOMYCACHE_STORE=file` and a unique `OOMYCACHE_PREFIX` in the production environment.
3. Run `php artisan config:clear` after changing the environment.
4. Enable only a few read-heavy models, then run the app's real page flows and compare
   `app_queries`/`store_queries` before enabling more.
5. If the host ever scales to multiple application nodes, move the store to Redis/Memcached or back to
   the shared database store.
