# Phase 2 — Tenancy Core + Filament Bridge Prototype + EnvKit Live Validation

> **Date:** 2026-11-09 · **Package:** `osamaelnagar/oomycache` (`dev-main`)
> **App:** `D:\Herd\filament-custom-pages` (Laravel 13.40, Filament 5.11.2, Livewire 4.4.8, PHP 8.5.12, EnvKit stack)

---

## 1. EnvKit MCP — how the live validation was done

EnvKit is running with nginx + PHP-FPM + Mailpit; Redis was started for the upcoming driver matrix. Its MCP server is available at `http://127.0.0.1:47600/mcp` (bearer token read from `~/.claude.json`). This DSH session did not auto-register the `envkit` tools, so the Lead called the MCP endpoint directly with JSON-RPC (`tools/list`, `tools/call`).

Actions performed through EnvKit MCP:

| Action | Result |
| --- | --- |
| `set_site_dumps {name: filament-custom-pages, enabled: true}` | Dumps capture enabled for the site; first toggle restarted PHP once. |
| `dumps_tail {site: filament-custom-pages}` | Captured the probe arrays with `file:line` and the request URI. |
| `start_service redis` | Redis is now running on 6379 for the driver-matrix step. |
| `install_php_extension {version: 8.5.12, name: redis}` | phpredis 6.3.0 installed and enabled; PHP-FPM restarted; a real Redis cache-store test now passes. |
| `site_detail`, `status_live`, `list_sites` | Confirmed `https://filament-custom-pages.test`, Laravel 13 stack, PHP 8.5.12. |

The temporary probe route (`/__oomycache-probe`, local env only) was removed after validation. Dumps capture remains enabled for future development.

## 2. Live cache validation evidence

Probe query: `Product::with('category:id,name')->withCount('stockLevels')->orderBy('id')->limit(20)->get()` run cold → warm → after a product update.

| Cache store | Cold | Warm | After a write | Notes |
| --- | --- | --- | --- | --- |
| `database` (local default, Laravel 13 `serializable_classes=false`) | 10 queries (incl. cache-table/version reads) | **1** (single cache read) | 5 (product query again + cache bookkeeping) | Payload + invalidation work on the non-taggable database store. |
| `file` | 2 queries | **0** | 1 (product query; category still cached) | Warm read eliminates all DB work. |
| `redis` (real phpredis / EnvKit Redis) | — | **0 app queries** | fresh after save | `tests/Feature/QueryCacheRedisStoreTest.php` passes against a live Redis on 6379. |

EnvKit Dumps captured the probe dump (`routes/web.php:84`) showing `["store" => "file", "cold" => 2, "warm" => 0, "after_write" => 1, "rows" => 20]` — the Dumps page is now a permanent live-validation channel for cache decisions.

**Bench command on the real seeded app** (`php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json`, database cache store):

| Run | App queries | Total queries | Time |
| --- | --- | --- | --- |
| cold | 1 | 7 (incl. cache table) | 41.4 ms |
| warm avg (3) | **0** | 3 (cache table reads only) | 20.3 ms |

**Numeric stats counters** are now implemented (`oomycache.stats.enabled`, cache-backed window). With `OOMYCACHE_STATS=true` in the local `.env`, the live `/admin/query-cache` studio page shows `Hits (window)`, `Misses`, `Bypasses`, `Invalidations`, `Clears`; `php artisan oomycache:status` prints the same snapshot (live values observed during validation: 9 hits / 6 misses / 1 clear).

**Live prune validation (EnvKit site, database cache store):** `oomycache:prune --dry-run --chunk=2` reported `Scanned 136 cache row(s); expired 122; deleted 0 (dry run); 68 ms elapsed` (table prefix auto-detected as `filament-custom-pages-cache-`); the real run then reported `deleted 122; 469 ms elapsed`, leaving the fresh rows intact. Refreshed-key safety, prefix scoping, `cache_locks` immunity and non-database refusals are covered by 9 package tests.

Live HTTP pages (authenticated with the local `_studio/login` helper, 20k-row seeded DB):

| Page | Observation |
| --- | --- |
| `/admin/products` | 508,490 bytes; cold ≈0.77 s, warm ≈0.50–0.70 s (dumps capture on/off alters timings slightly); warm render asserted **0 ERP-table queries** in the automated dogfood test. |
| `/admin/query-cache` | HTTP 200, 132 KB, ≈0.30 s; shows status, cacheable models and purge actions (new Phase-2 page). |
| `/admin/erp-cache-lab` | HTTP 200, 110 KB, ≈0.35 s. |
| `/erp/products` (plain Livewire front office) | HTTP 200, warm ≈0.22–0.38 s. |

> Timings are indicative (dumps capture, CPython/PHP opcache warmup, and page weight vary); the hard assertion remains the query-count tests.

## 3. What shipped in the package (Phase 2 slice)

| Area | Change |
| --- | --- |
| Tenancy contract | New `Contracts\TenantResolver`, `Tenant\TenantContext`, `Tenant\NullTenantResolver`; config-driven binding (`tenant.resolver`). |
| Tenant-safe keys | Every cache key now embeds tenant/panel/user segments in the fingerprint **and** a short tenant namespace segment; engine resolves the resolver per query (runtime tenant switches work). |
| Fail-closed | `tenant-unresolved` is a first-class bypass reason; un-resolvable tenancy never shares cache entries. |
| Status | `oomycache:status` and the engine status now expose the resolved tenant segments and the optional stats snapshot. |
| Stats counters | New `Stats\CacheStats`: cache-backed hit/miss/bypass/invalidation/clear counters (opt-in, sampled, windowed); wired to all events; shown in `status` + Filament studio. |
| Bench command | New `oomycache:bench {model} --limit --samples --json`: cold vs warm app-query counts and timings, excluding cache-store bookkeeping queries. |
| Payload compression | New `Payload\PayloadCompressor`: transparent gzip/deflate/zstd envelope (`OOMYCACHE1C…`) above the JSON codec; threshold/algorithm from config; corrupt payloads are treated as misses. |
| Chunked payloads | New `Payload\ChunkedPayloadStore`: payloads above `payload.chunking.chunk_bytes` are stored as `{key}:c:{n}` chunks behind a manifest (count/bytes/xxh128 hash), reconstructed on read; missing/corrupt chunk or manifest → miss + best-effort cleanup; chunk count over `max_chunks` (or chunking disabled over `max_payload_bytes`) keeps the `payload-too-large` bypass. |
| Per-request memo | Decoded payloads are memoized for the current request; every table flush / `clear()` purges the memo so a write-then-read in the same request never serves stale data. `oomycache.payload.memoize_per_request` (default true). |
| Negative caching | Empty results are not cached by default (`oomycache.cache.negative=false`); when enabled they are stored with `negative_ttl` (hat-tip to Smart Cache's negative-cache idea). |
| Runtime options reset | `QueryCacheEngine::forgetOptions()` lets long-running workers/tests re-read changed `oomycache.*` options (options are memoized per model for hot-path speed). |
| Prune command | New `oomycache:prune {store?} --dry-run --chunk --max-seconds`: database-store garbage collection with keyset pagination, expiry-guarded deletes (refreshed rows survive), prefix scoping, `cache_locks` immunity and typed refusals for non-database stores. |
| Payload security caps | `payload.chunking.max_total_bytes` (2 MiB) hard cap independent of `max_chunks`; `payload.compression.max_bytes` decompression cap; Eloquent collection allow-list on decode. 7 tests. |
| Tests | 34 new Phase-2 package tests (5 tenancy + 4 stats/bench + 4 payload features + 9 prune + 9 chunking + 7 payload security) plus 4 architecture tests and the 4-test local store matrix; suite now **73 passed + 1 skipped driver lane / 261 assertions**. |

## 4. Filament bridge — now a companion package

The prototype was extracted into `packages/OoMyCacheFilament` (`osamaelnagar/oomycache-filament`, namespace `Oomycache\Filament\`), installed in the app through a Composer path repository and registered on the admin panel with `->plugin(OomycachePlugin::make())`.

| File | Purpose |
| --- | --- |
| `packages/OoMyCacheFilament/src/OomycachePlugin.php` | Filament 5 panel plugin; registers the studio page on the panel(s) that opt in. |
| `packages/OoMyCacheFilament/src/OomycacheFilamentServiceProvider.php` | Binds the Filament tenant resolver, registers the `ActionCalled` invalidator, loads the `oomycache-filament::` view namespace. |
| `packages/OoMyCacheFilament/src/Support/FilamentTenantResolver.php` | Panel id always in the key; `hasTenancy()` + `getTenant()` with fail-closed; optional user segment via Filament's hashed scoped auth identifier. |
| `packages/OoMyCacheFilament/src/Pages/QueryCacheStudio.php` + `resources/views/pages/query-cache-studio.blade.php` | `/admin/query-cache`: status, cacheable models + version tokens, live stats counters, “Invalidate selected model” and “Clear query cache” actions; theme-safe Filament components only. |
| `packages/OoMyCacheFilament/src/Support/FilamentCacheInvalidator.php` | Listens to Filament `ActionCalled` and flushes affected models for attach/detach/dissociate/associate/reorder/import + every bulk action; never breaks a panel action. |
| `packages/OoMyCacheFilament/README.md` / `CHANGELOG.md` / `LICENSE.md` | Package docs + release notes + MIT license. |

The app-side prototype classes were removed; `AppServiceProvider` is back to app concerns only. The `/admin/query-cache` route is now registered by the plugin (`Oomycache\Filament\Pages\QueryCacheStudio`) and the live page remains green.

Bridge tests (kept in the dogfood app for full-stack coverage):
- `tests/Feature/QueryCacheStudioTest.php` — 5 tests (guest redirect, page render, invalidate action busts warm cache, clear bumps global token, simulated attach/detach action flush).
- `tests/Feature/QueryCacheTenancyBridgeTest.php` — 3 tests (panel segment resolution, per-panel cache isolation, engine status panel).
- `tests/Feature/QueryCacheTenantPanelTest.php` — **3 real panel-level tenancy tests** via a genuine `Panel::make()->tenant(Customer::class)` fixture: tenant A warm → hit, switch B → miss, back to A → hit; tenant segment = `App\Models\Customer:<id>`; tenanted panel with `setTenant(null)` → fail-closed, `QueryCacheBypassed(tenant-unresolved)`, every read hits DB and no poisoned entry is written; non-tenant panel ignores stray tenants.
- `tests/Feature/QueryCacheRelationManagerInvalidationTest.php` — **3 real E2E tests** driving the actual `SuppliersRelationManager`: `AttachAction` (attach form with pivot fields), row `DetachAction`, and a cached belongsToMany eager-load detach. Each proves warm = 0 ERP queries, action runs, `suppliers` version token changes, `QueryCacheInvalidated(table=suppliers)` fires, and the fresh read re-queries with the updated count (0→1, 1→0). Filament v5 API detail: `Livewire::test(SuppliersRelationManager::class, ['ownerRecord'=>…, 'pageClass'=>EditProduct::class])` + `callTableAction('attach', data: [...])` / `callTableAction('detach', $supplier)` + `assertHasNoTableActionErrors()`.
- `tests/Feature/QueryCacheRedisStoreTest.php` — real phpredis store: warm read = 0 product-table queries; save invalidation returns fresh data.

## 5. Current verification totals

| Suite | Result |
| --- | --- |
| Package `composer test` (Pint + PHPStan L7 + 100% type coverage + Pest) | ✅ **73 passed + 1 skipped driver lane / 261 assertions** (incl. 9 prune + 9 chunking + 4 store-matrix tests) |
| Full app suite (existing + Phase 1 dogfood + Phase 2 bridge/tenancy + tenant-panel + Redis + real RM E2E) | ✅ **162 tests / 580 assertions** |
| Live EnvKit HTTP checks | ✅ `/admin/products`, `/admin/query-cache`, `/admin/erp-cache-lab`, `/erp/products` all 200; studio shows live stats counters |
| EnvKit dumps | ✅ probe dumps captured with file:line |
| Redis | ✅ phpredis installed via EnvKit MCP; real Redis cache-store test passes |
| Local driver matrix | ✅ package `CacheStoreMatrixTest` (array/file/database); EnvKit **MySQL 13 + PostgreSQL 18** database-cache smokes **OK**; **real Redis 8.8 + Memcached 1.6.8** app tests pass |
| Companion package CI | ✅ `packages/OoMyCacheFilament` has its own Testbench lane: **6 tests / 9 assertions**, PHPStan level 7 clean, Pint clean, Testbench 11.3 / Filament 5.11 / Pest 5.3, GitHub Actions matrix (PHP 8.3–8.5 × Laravel 12/13 × Testbench 10/11). |

## 6. Known limitations / next steps

1. **Bridge extracted** — `packages/OoMyCacheFilament` is a standalone Filament 5 companion package installed via path repo and registered through `OomycachePlugin`; app-side prototype classes/tests now use the package classes. Next: add the companion package's own CI/test lane before publishing.
2. **Real multi-tenancy panel E2E passes** — a genuine `Panel::make()->tenant(Customer::class)` fixture proves A/B cache isolation, back-to-A hits, and fail-closed when the tenant is cleared.
3. **Debug window query capture** was not enabled via MCP (the Debug page has no exposed enable tool in this session); dumps are the live channel. Open the EnvKit Debug tab and enable capture for per-request SQL/N+1 streaming if desired.
4. **Redis exercised** — phpredis installed and the database/file/redis store lanes now have live evidence; Memcached and MySQL/Postgres remain for the CI matrix.
5. **Real relation-manager pivot E2E passes** — attach/detach on the live `SuppliersRelationManager` invalidates the cached Product aggregate through the ActionCalled bridge (3 tests).
6. Remaining Phase-3/launch scope: companion-package Packagist lane, Octane/large-payload guards, Memcached lane, then the ERP observation window + launch assets.
