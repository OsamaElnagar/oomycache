# Release Notes

## 0.1.4 - 2026-11-16

### Added

- `QueryCacheBuilder::cacheNegativeFor(int $ttl)` and a `negativeTtl:` named argument on
  `cacheFor()`. Supplying a per-call negative TTL makes semantically-empty aggregate results
  (a zero `count`/`sum`, `false` from `exists()`, `true` from `doesntExist()`) negative-eligible
  and stores them for that TTL. Without it, default behavior is unchanged: zero counts still use
  the regular TTL and empty collections are still bypassed. `cache.negative_ttl` is now part of
  the model option set (so presets/`queryCacheOptions()` can override it and `forgetOptions()`
  refreshes it).
- Cached `cursorPaginate()`. Pages are keyed by the effective cursor (plus per-page and cursor
  name), the raw `perPage + 1` rows are cached, and the `CursorPaginator` is rebuilt on hits so
  items, `hasMorePages()`, `nextCursor()`, path and cursor name round-trip. Cacheable-model writes
  invalidate cursor pages through the normal table version token.
- `safety.throw_on_invalidation_failure` (default `true`, env `OOMYCACHE_THROW_ON_INVALIDATION_FAILURE`)
  gives operators an explicit, dedicated opt-out for loud invalidation failures in
  `QueryCacheEngine::flushModel()` / `InvalidationManager::flushModel()`. `safety.fail_silently`
  continues to cover **cache read/write paths only**; it never suppresses invalidation failures.
- `filament.dashboard.navigation_badge` (`off` by default) — config surface read by the companion
  bridge package for the studio badge; the core package does not render it.
- Stats counters use atomic `Cache::increment()` (seeded with `Cache::add()`), so concurrent
  requests no longer lose observations and the counters do not rely on cache events. A shared
  `window_start` key makes `snapshot()['window_seconds']` the real age of the counter window, and
  `CacheStats::reset()` starts a fresh window without flushing the application cache.

### Changed

- Dev-only: pin `phpstan/phpstan` to 2.3.5. The type-coverage plugin used by `composer test` calls
  `AnalyserResult::getCollectedData()`, which was removed in 2.3.6.

## 0.1.3 - 2026-11-12

### Fixed

- `QueryCacheBuilder` is now generic (`@template TModel`, `@extends Builder<TModel>`) and
  `HasQueryCache::newEloquentBuilder()` returns `QueryCacheBuilder<static>`. Larastan/PHPStan keep the
  concrete cached model type instead of falling back to `Model`, eliminating hundreds of false
  undefined-property/method errors in host applications.
- `sum()`, `avg()`, `min()` and `max()` accept `Expression` columns as well as strings, matching the
  Laravel builder signatures used by Filament/dashboard widgets.

## 0.1.2 - 2026-11-11

### Fixed

- `QueryCacheInvalidated` / `QueryCacheCleared` events are no longer dispatched while
  `OOMYCACHE_ENABLED=false`; version tokens still bump so re-enabling the cache cannot serve stale
  entries.
- A listener that writes another cached model from an invalidation event no longer recursively
  dispatches invalidation events (the nested write still bumps its table token).
- `cache.invalidation_dedupe_ms` makes the builder-write/model-event dedupe window configurable for
  slow hooks/listeners; default remains 100 ms.
- `rememberQuery()` accepts a per-call `negativeTtl` so an intentionally empty raw result can be
  cached without enabling global negative caching.
- `oomycache:bench --all` discovers and benchmarks every cacheable model, including Testbench/workbench
  applications; the namespace is no longer hardcoded to `App\Models`.

### Documentation

- Bypass-reason precedence (`search-query` before `excluded-pattern`), immutable-model invalidation
  testing, disabled-event behavior and the new config key.

## 0.1.1 - 2026-11-11

### Added

- Production runbook, adoption checklist and deployment/store rollback guidance.
- `scripts/benchmark-stores.ps1` and `scripts/benchmark-stores.sh` for host/store benchmarking.
- `SECURITY.md` and `CONTRIBUTING.md`.
- Agent-guide and Boost-skill links for the production/adoption docs.

## 0.1.0 - 2026-11-11

First stable release of OoMyCache: transparent, correctness-first Eloquent query caching for
Laravel 12/13, with an optional Filament 5 bridge.

### Added

- `HasQueryCache` trait and caching Eloquent/base query builders. Opted-in models cache reads
  (`get`, `first`, `paginate`, aggregates, `pluck`/`value`, eager loads) without resource or
  controller changes.
- O(1) version-token invalidation keyed by connection + table. Model events, mass writes,
  relation/pivot writes and transaction-aware after-commit invalidation work on array, file,
  database, Redis and Memcached stores without tags.
- Dependency-aware keys for eager loads, `withCount`/`withSum`/`withExists`, `has`/`whereHas`,
  joins and subqueries.
- Automatic invalidation for `belongsToMany`, `morphToMany` and `morphedByMany` pivot operations.
- Raw report/dashboard caching via `Oomycache::rememberQuery()` and explicit
  `Oomycache::flushTables()` invalidation.
- Arrays-JSON payloads (Laravel 13 `serializable_classes=false` safe), optional compression,
  chunking with integrity hashing, negative caching, and per-request payload memoization.
- Tenancy-aware keys with fail-closed behavior; optional Filament panel/tenant resolver.
- Per-query API: `cacheFor`, `cacheForForever`, `dontCache`, `cacheKey`, `cacheIf`; per-model
  `queryCacheOptions()` and named presets.
- Commands: `oomycache:status`, `oomycache:clear`, `oomycache:doctor`, `oomycache:bench`,
  `oomycache:prune`.
- `oomycache:status` counters: hits, misses, bypasses, empty-result bypasses, invalidations, clears.
- Optional companion package `osamaelnagar/oomycache-filament`: Query Cache studio, configurable
  navigation, tenant/panel-safe keys, deny-by-default purge Gate, audit hook and Filament
  `ActionCalled` invalidation.

### Correctness

- Cache failures fall back to the database; invalidation failures are logged and surface loudly.
- Reads inside transactions bypass the cache; writes invalidate after commit by default.
- Paired lifecycle writes emit one audit/stats notification per logical chain
  (update, soft delete, restore, force delete) while still bumping the version token.
- `oomycache:doctor` warns about uncacheable declared dependencies; `oomycache:bench` separates
  application queries from cache-store statements and resets memoization between samples.

### Quality

- Pest suite covering the engine, stores, payload security/chunking, tenancy, pivots, raw queries,
  lifecycle dedupe, bridge and commands; PHPStan, Pint and 100% type coverage in CI.
- Real-driver CI lanes for Redis, Memcached, MySQL and PostgreSQL, plus the PHP/Laravel matrix.
- Local EnvKit hardening: multi-process cron/queue/file/store validation, outage fail-open and
  authenticated Filament load tests.

### Known limits

- Raw SQL writes, `saveQuietly()`/`withoutEvents()` and raw pivot-table writes require an explicit
  `Oomycache::flush()` / `flushTables()`.
- `rememberQuery()` entries are invalidated only by the tables the caller lists.
- File-store deployments are per-host and need periodic cleanup of unreachable versioned keys;
  database stores pay cache-table statements on warm reads.
- A stable release does not remove the need to benchmark the deployment host and cache store.
