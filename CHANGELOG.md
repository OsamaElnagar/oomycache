# Release Notes

## Unreleased

### Added

- **Phase 2 tenancy support:** `TenantResolver` contract, `TenantContext`, `NullTenantResolver`; tenant/panel/user segments are embedded in every cache key and resolved per query; unresolved tenancy fails closed with reason `tenant-unresolved`.
- **Filament bridge prototype (app-level):** Filament-aware tenant resolver (panel segment + fail-closed tenancy), `/admin/query-cache` cache studio page (status, cacheable models, selective invalidate + clear actions), and an `ActionCalled` invalidator for attach/detach/associate/dissociate/reorder/import and bulk mass branches.
- **Filament bridge extracted:** the bridge lives in the standalone `osamaelnagar/oomycache-filament` companion package (`packages/OoMyCacheFilament`) with `OomycachePlugin`, the studio page and the invalidation bridge.
- **Stats counters:** opt-in cache-backed hit/miss/bypass/invalidation/clear counters exposed in `oomycache:status` and the Filament studio.
- **`oomycache:bench`:** cold vs warm app-query/timing benchmark for any cacheable model with JSON output.
- **Payload hardening:** optional gzip/deflate/zstd compression envelope, transparent chunking above `payload.chunking.chunk_bytes` (manifest + xxh128 hash, corruption/partial → miss), per-request decoded-payload memo (purged on invalidation), negative caching with `negative_ttl`, and `QueryCacheEngine::forgetOptions()` for runtime config changes.
- **`oomycache:prune`:** expiry-guarded, keyset-ordered garbage collection for database cache stores (`--dry-run`, `--chunk`, `--max-seconds`); refuses non-database stores with typed hints.
- **Redis driver validated:** real phpredis-backed Redis cache store test in the dogfood app.
- Core Eloquent query cache engine: `HasQueryCache` trait returning caching Eloquent and base query builders.
- Version-namespace invalidation keyed by connection + table (O(1) on all cache drivers, no tag requirement).
- Model-event and mass-write invalidation with transaction read bypass and after-commit invalidation.
- Filament paginator total caching via the base query builder (`getCountForPagination`).
- Dependency tracking for eager loads, `withCount`/`withSum`/`withExists`, `has`/`whereHas`, joins and subqueries.
- Arrays-JSON payload codec (Laravel 13 `cache.serializable_classes=false` safe) with model/relation rehydration.
- Per-query API: `cacheFor`, `cacheForForever`, `dontCache`, `cacheKey`, `cacheIf`; per-model `queryCacheOptions()`.
- Commands: `oomycache:status`, `oomycache:clear`, `oomycache:doctor`.
- Publishable configuration with presets, safety guardrails, tenancy/Filament configuration scaffolding.

### Quality

- Pest test suite: 24 tests / 42 assertions; PHPStan level 7 clean; Pint clean; 100% type coverage.
- Dogfooded in a Filament 5 ERP app: 4 resources, 4 relation managers, select-heavy forms, custom page/actions, 3 widgets, 3 plain-Livewire front-office pages, 20k+ seeded rows; warm Filament list pages execute zero ERP-table queries.
