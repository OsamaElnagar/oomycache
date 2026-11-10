# Phase 1 — Core Engine + Filament/Livewire Dogfood Report

> **Date:** 2026-11-09 · **Package:** `osamaelnagar/oomycache` (`dev-main` in `packages/OoMyCache`)
> **Target app:** `D:\Herd\filament-custom-pages` — Laravel 13.40.0, Filament 5.11.2, Livewire 4.4.8, PHP 8.5, 8 panels, custom `filament-liquid-glass` theme.

---

## 1. What was delivered (package)

| Area | Delivered |
| --- | --- |
| Interception | `HasQueryCache` trait → `QueryCacheBuilder` (`newEloquentBuilder`) **and** `QueryCacheQueryBuilder` (`newBaseQueryBuilder`); no framework forks |
| Filament COUNT | `getCountForPagination()` cached, so Filament's default `toBase()` COUNT is no longer a hidden query every render |
| Invalidation | Table-keyed version tokens (`connection+table`), O(1) on all drivers; model events (`saved/deleted/restored/forceDeleted`), mass builder writes, after-commit, transaction-read bypass |
| Dependencies | Eager-load table resolution + closure fingerprints; `withCount/withSum/withAvg/withMin/withMax/withExists`; `has/whereHas/whereRelation` recording; base-builder join/subquery table recording |
| Query coverage | `get/first/count/exists/doesntExist/sum/avg/min/max/pluck/value`, `paginate` (page + total), `simplePaginate`; streaming APIs explicitly bypass; writes flush the namespace |
| Payload | Arrays-JSON envelope (works under Laravel 13 `cache.serializable_classes=false`), model/collection/relation rehydration, size guard, corrupt-payload handling |
| API / config | Per-query `cacheFor/cacheForForever/dontCache/cacheKey/cacheIf`; per-model `queryCacheOptions()`; publishable `config/oomycache.php`; events (hit/miss/bypass/invalidated/cleared) |
| Commands | `oomycache:status`, `oomycache:clear`, `oomycache:doctor` |
| Quality gates | **`composer test` green**: Pint, PHPStan level 7 (clean), 100% type coverage, **24 Pest tests / 42 assertions** |

## 2. What was delivered (dogfood app)

**ERP domain** (data + factories + heavy seeder):

| Table | Rows |
| --- | --- |
| products | 3,000 |
| product_categories | 60 |
| suppliers | 200 |
| warehouses | 12 |
| stock_levels | 6,000 |
| product_supplier | 4,000 |
| purchase_orders | 2,500 |
| purchase_order_lines | 7,000 |
| invoices | 5,000 |
| customers / orders baseline | 100 / 300 |

Eight new models + 9 migrations + 8 factories + `ErpDogfoodSeeder` (seeded locally in ~1.5 s via chunked inserts). Twelve models use `HasQueryCache` (the 8 ERP models plus existing Customer/Order/Task/ShowcaseItem).

**Filament 5 coverage** (41 files, auto-discovered by the admin panel):
- `ProductResource`: select-heavy form (searchable relationship category, multiple searchable supplier relationship with pivot fields, derived availability select, toggle, date, KeyValue), table with search/sort/filter/count columns, bulk actions (activate via mass builder update, increase stock via `increment`), row actions (restock `increment`, duplicate), two relation managers (`StockLevels` hasMany, `Suppliers` belongsToMany attach/detach with pivot).
- `SupplierResource` (+ PurchaseOrders relation manager), `PurchaseOrderResource` (+ Lines RM with product selects), `InvoiceResource` (customer select, live dependent order select, enum status, date-range filters, mark-paid/flag-overdue actions).
- `ErpCacheLab` custom page: navigation entry, header actions (mass update, guarded OoMyCache purge), live aggregate stats (count/sum/avg across five ERP tables) and a heavy PO-lines listing.
- Widgets: `ErpStatsOverview`, `ErpPurchaseOrdersChart`, `ErpLowStockTable`.

**Front office (non-Filament):** plain Livewire 4 components `ProductExplorer` (URL-bound search/filter/sort, pagination, computed stats), `StockDashboard` (KPI aggregates, low-stock list, polled), `SupplierDirectory` (withCount + last-order date); three public routes; **7 Pest tests**. `npm run build` passes for the new Tailwind classes.

## 3. Verification evidence

| Check | Result |
| --- | --- |
| Package `composer test` (Pint, PHPStan L7, 100% type coverage, Pest) | ✅ green — 24 tests / 42 assertions |
| Full app test suite (existing + ERP resources + front office + dogfood) | ✅ **144 passed / 476 assertions** |
| Warm Filament ProductResource list page | ✅ **0 ERP-table queries** on second render (was product/count/category queries cold) |
| Warm `ErpCacheLab` custom page | ✅ 0 ERP-table queries on second render |
| Filament bulk mass-update action (`activateSelected`) | ✅ writes then invalidates; next reads are fresh |
| Relationship count column (`counts('stockLevels')`) after child insert | ✅ invalidated; count changes 1 → 2 |
| Plain Livewire front-office page warm render | ✅ 0 ERP-table queries |
| Product save → front-office recompute | ✅ fresh name visible immediately |
| Laravel 13 defaults (`cache.serializable_classes=false`) on the **database** cache store | ✅ payload round-trips and invalidates correctly |
| Existing Filament app regression tests | ✅ all pass (trait added to Customer/Order/Task/ShowcaseItem) |

## 4. Local environment state

- Migrations ran on the local SQLite DB; `ErpDogfoodSeeder` seeded the heavy dataset.
- `CACHE_STORE=database` locally (the package is exercised on Laravel's non-taggable database store); Redis/Memcached remain the production recommendation.
- Package is wired into the app via a Composer path repository (`packages/OoMyCache`, symlinked) and auto-discovered.
- `php artisan oomycache:status` reports 12 cacheable models and the store capabilities.

## 5. Known gaps / Phase 2 backlog (honest list)

1. **Filament bridge UI** — tenant/panel key resolver, cache health page, purge/warm actions, `ActionCalled` pivot/mass-action flush, `#[Computed(cache:true)]` misuse warning, authorization. (plan/04)
2. **Tenancy** — core tenant/panel resolver is stubbed; do not use on multi-tenant panels until the bridge phase lands. Single-DB SQL scoping already works; multi-DB tenancy keys are not yet included.
3. **Pivot attach/detach** — not yet intercepted by core; needs the optional pivot-events listener and/or the bridge flush (documented limitation for now).
4. **Deep subquery dependency edge cases** — joins/fromSub are recorded; `whereIn`/`whereExists` closures routed through plain query builders are not fully traced and should fall back to uncached in Phase 2.
5. **Grouped/union pagination counts** — count caching should be restricted to simple aggregate shapes (POC pending).
6. **Chunked payloads, compression, per-request memo, locking/single-flight, stats, warmup/prune command, chunked payload protocol** — designed in plan/02–03, not implemented yet.
7. **Benchmark harness and Octane/Redis/Memcached/MySQL/Postgres CI lanes** — pending Phase 2 QA work.
8. **Race condition checks, same-request memo invalidation, `first()` warm test for `with()` closures** — partially covered (warm eager loads and withCount are covered now); more adversarial coverage due in Phase 2.

## 6. Suggested next milestone

Phase 2 (per `docs/plan/06-roadmap.md`): full query-surface parity (value/exists/negative caching, grouped count guard), pivot events, tenant/panel resolver, Redis/Memcached/file/database driver matrix CI, benchmark harness and `oomycache:bench`, then Phase 3 = Filament bridge + the ERP observation window.
