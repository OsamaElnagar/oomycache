# Production runbook

This is the short, opinionated checklist for putting OoMyCache into a real Laravel application.
It assumes the package is installed and at least one model has been adopted only after the
[adoption checklist](adoption-checklist.md) passes.

## 1. Choose the cache store deliberately

| Store | Use it when | Avoid when |
| --- | --- | --- |
| Redis / Memcached | multiple app/queue nodes, predictable latency, no SQL cache reads | no managed service available |
| `file` | single host with a writable `storage/` directory (for example shared hosting) | multiple app nodes without shared storage |
| `database` | only expensive reads, or as a fallback | cheap reads; each warm read adds cache-table statements |
| `array` | tests only | production |

Run `scripts/benchmark-stores.ps1` (Windows) or `scripts/benchmark-stores.sh` against a staging
copy on the deployment host. Compare `app_queries`, `store_queries` and total statements; do not
decide from local laptop latency alone.

For a single-host file-store deployment:

```dotenv
CACHE_STORE=database
OOMYCACHE_ENABLED=true
OOMYCACHE_STORE=file
OOMYCACHE_STATS=true
OOMYCACHE_STATS_STORE=file
OOMYCACHE_PREFIX=myapp
```

`OOMYCACHE_STATS_STORE` matters: without it, stats can use Laravel's default store and quietly add
cache-table or network traffic.

## 2. Test with isolated configuration

When `config:cache` is active, `phpunit.xml` env values are not reliable. The deployment gate is:

```bash
php artisan config:clear
php artisan test --compact
php artisan config:cache
```

Keep the normal suite cache-inert (`OOMYCACHE_ENABLED=false`, `OOMYCACHE_STORE=array`) and opt in
only in dedicated cache tests.

## 3. Deploy order

1. Deploy code + package versions.
2. Set the `OOMYCACHE_*` environment.
3. Ensure the cache directory or service is reachable.
4. `php artisan config:clear && php artisan config:cache`.
5. Restart PHP-FPM/CGI workers — long-lived workers may otherwise keep the old config.
6. `php artisan queue:restart` for queue workers.
7. Run `php artisan oomycache:doctor`.
8. Run `php artisan oomycache:bench "App\\Models\\YourModel" --limit=50 --samples=5 --json` on the host.
9. Smoke-test one write/invalidate/read cycle through the real UI.
10. Watch hit/miss/bypass/invalidation counters for the first 24–72 hours.

## 4. File-store maintenance

File stores do not garbage-collect versioned keys that are never read again. If the `file` store is
dedicated to OoMyCache, schedule a low-traffic clear:

```bash
php artisan cache:clear file
```

Weekly is a reasonable starting point. Monitor `storage/framework/cache/data` disk usage. Never clear
a file store that other parts of the app also use without accounting for that.

## 5. Monitoring

- `oomycache:status` for store/model/version state and stats.
- `oomycache:doctor` for configuration and dependency warnings.
- `oomycache:bench` after store, host or package changes.
- Application logs for `OoMyCache invalidation failed` (critical).
- Audit trail for every purge and per-table invalidation; ensure the purge Gate is operator-only.

## 6. Rollback

```dotenv
OOMYCACHE_ENABLED=false
```

Then `php artisan config:clear && php artisan config:cache` and restart PHP workers. Traits can remain;
they are inert while the package is disabled. No data migration is required.

## 7. Known operational limits

- Raw SQL, `saveQuietly()`/`withoutEvents()` and raw pivot-table writes need explicit
  `Oomycache::flush()` / `flushTables()`.
- `rememberQuery()` entries invalidate by the tables the caller lists; document and test every write
  path that changes those tables.
- A file store is per-host; do not use it on a multi-node fleet without shared storage.
- Database-backed caching adds SQL to warm reads; benchmark before enabling it broadly.
- Cache misses and cold starts are normal after deploy or purge; the cache is lazy, not a warm-up
  service.

See also: [store selection](store-selection.md), [operational guardrails](operational-guardrails.md),
[security review](security-review.md), [release checklist](release-checklist.md).
