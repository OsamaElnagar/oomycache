# Local hardening lab (EnvKit) — results and handoff

> Local-only validation of OoMyCache against a real Laravel 13 / Filament 5 ERP, with real
> file/database/Redis/Memcached stores, PHP workers, queue workers, cron and authenticated HTTP.
> No production deployment is implied by this document.

## Lab stack

- Laravel 13.39, Filament 5.11, Livewire 4.4, PHP 8.5.12 (EnvKit).
- Cache stores exercised: `array`, `file`, `database` (SQLite app DB), `redis` (8.8), `memcached` (1.6).
- MySQL 13.0.2/PHP driver lane also exercised.
- EnvKit-managed scheduler, two queue workers, and four isolated soak cron jobs.

## Package changes produced by this lab

- `CachingBelongsToMany`: cacheable models now invalidate both sides on
  `attach`/`detach`/`sync`/`toggle`/pivot updates; `cache.pivot_invalidation` (default true).
- `Oomycache::rememberQuery()` / `flushTables()`: explicit raw-query caching for `DB::table()`
  reports with table dependency versioning.
- `oomycache:bench`: isolated keys, memo reset per sample, `--clear` opt-in, separate
  `app_queries` / `store_queries` / total counts.
- `empty_bypasses` stats counter and studio row; doctor warns when a declared dependency is not
  cacheable.
- Duplicate invalidation notifications suppressed for paired `update → saved`, `delete → deleted`,
  `forceDelete → forceDeleted`, `update → restored` write paths (tokens still bump; audit/stats see
  one logical invalidation).
- Studio navigation is configurable (`navigation_label`, `navigation_group`, `navigation_sort`).
- Stats follow `OOMYCACHE_STORE` by default; `OOMYCACHE_STATS_STORE` overrides.
- Real Redis/Memcached driver tests added (service-gated).
- Readmes/skills document panel-path-aware studio routes, file-store lifecycle, config:cache/worker
  restart requirements and long-running `resetRuntimeState()`.

Released during this work: core `v0.1.0-alpha.11`, bridge `v0.1.0-alpha.9`.

## Verified behaviour

### Cross-process store visibility

A separate PHP process read a cached `Box` query, then another process cleared the global namespace.
All stores showed: first-read miss → warm hit → clear → next-process miss. File/Redis/Memcached had
0 cache-store SQL; database showed the expected cache-table statements.

### Failure injection

With an unreachable Redis and then an unreachable Memcached (config cached, PHP workers restarted):
authenticated pages returned **200**, no server exceptions, no 5xx. Cache reads fell back to the DB.
First attempts were invalid because stale PHP workers served the previous config; the clean rerun
required a PHP-FPM restart.

### Concurrent authenticated HTTP load

- 4–8 Playwright sessions, login as local admin, concurrent global clears.
- 32 requests at 8 workers: all 200; 2 client-side navigation aborts; zero server errors.
- Stats increased under load after worker restart; page latency on the local EnvKit PHP pool is not
  a product benchmark.
- Admin: studio `/query-cache` 200. Accountant/manager: studio 403, normal resource pages 200.
- Queue worker processed a real dispatched job; scheduler stayed alive through the load.

### Pivot and raw-query concurrency

- Two reader processes + one writer doing 176 attach/detach operations against temporary pivot
  tables: readers observed misses proportional to writes (180/258 and 167/253) with no errors.
  Lab tables were dropped and rows cleaned afterward.
- Three raw-query readers + one Eloquent writer: readers observed 79–107 misses across ~325–347
  reads while the writer updated a temporary row, proving `rememberQuery()` table invalidation under
  concurrent writes. Without per-iteration `resetRuntimeState()`, readers stayed on cached version
  tokens; this is expected for long-running loops and is now documented.
- Temporary rows were removed; no app data left behind.

## Operational constraints

1. Long-lived PHP workers can serve stale config after `config:cache`; restart PHP-FPM/CGI after
   changing `OOMYCACHE_*`.
2. With cached config, `phpunit.xml` overrides do not apply; `config:clear` before tests and re-cache
   after. Keep test suites cache-inert (`OOMYCACHE_ENABLED=false`, `OOMYCACHE_STORE=array`).
3. File store has no GC for unreachable versioned keys; the dedicated store needs a periodic
   `php artisan cache:clear file` (weekly is the current app policy).
4. Custom long-running loops must call `QueryCacheEngine::resetRuntimeState()` per iteration; the
   package does it for queued jobs/Octane request events.
5. `rememberQuery()` invalidation is explicit: `flushTables()` must be called for tables written
   outside cacheable Eloquent models.
6. `morphToMany`/raw pivot writes still need explicit flushes; only `belongsToMany` is wrapped.

## Local lab artifacts

- Soak scripts/logs under `storage/app/team/` in the dogfood project (untracked local evidence).
- Four EnvKit cron jobs every 5 minutes, using isolated `nf-soak-*` prefixes; they do not touch the
  app's `nf` cache namespace.
- Scheduler and two queue workers are EnvKit-managed for the local site.

## NewFarm handoff

- Adoption remains: three models (`Species`, `Box`, `Warehouse`), file store, stats on file,
  weekly file-store purge, no Phase 2, no production deployment.
- Core `alpha.10` contains the invalidation-dedupe fix; upgrading is optional for correctness but
  removes the duplicate audit rows. Re-run doctor/status/bench after upgrading.
- Do not add `rememberQuery()` or pivot adoption to the app until their write paths have explicit
  flush coverage and a reviewed rollout plan.
