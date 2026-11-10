# 01 — OoMyCache Feature Blueprint

> **Status:** v1.0 proposal (implementation contract)
> **Reads with:** [`03-architecture.md`](03-architecture.md) (how it works), [`02-configuration.md`](02-configuration.md) (how it is configured), [`04-filament-compatibility.md`](04-filament-compatibility.md) (why Filament is safe), [`05-qa-checklist.md`](05-qa-checklist.md) (how every feature is proven).
> **Priority legend:** **P0** = required in v1.0 · **P1** = targeted for v1.x after dogfooding · **P2** = post-1.0 / community-driven.

---

## 0. Product thesis

Laravel has several model-caching packages, but none of them was designed around a **heavy multi-tenant admin panel** where:

1. the same handful of expensive queries are re-executed on **every Livewire request** (Filament table index pages, widgets, relation managers, global search, filter/sort/page changes);
2. **stale data is a functional bug** (stock levels, invoices, ledger totals), so "eventually consistent" is not acceptable;
3. the panel runs under **multiple tenants and panels**, so a cache-key mistake is a **data-leak incident**;
4. the team cannot fork Eloquent to get correctness, and cannot require Redis-with-tags for every deployment;
5. operators need to **see, purge, warm and trust** the cache from inside the panel.

OoMyCache's thesis: **make the boring, expensive reads of a Filament ERP disappear — automatically, safely, and observably — without changing the Filament resources.**

The package is not "cache every Eloquent call". It is a **query-result cache with a correctness algebra**: every cache entry is namespaced by a data version, every write path bumps the namespace it affects, and every risky context (transactions, tenants, auth, morphs, raw SQL) is either handled or deliberately bypassed.

### Target user

| Persona | Need | What OoMyCache gives them |
| --- | --- | --- |
| Filament app developer | No boilerplate; resources get faster without edits | One trait in models + config; all Filament reads flow through the caching builder |
| Laravel API developer | Cuts DB load for list/aggregate endpoints | Builder methods (`cacheFor`, `dontCache`) + automatic opt-in per model |
| ERP operator | Fresh data, no cross-tenant leakage | Versioned invalidation, tenant-aware keys, purge/warm UI |
| Package maintainer | Compatibility, debuggability, trust | No framework forks, events, commands, doctor, benchmark, CI matrix |

---

## 1. Feature taxonomy at a glance

| # | Area | P0 (v1.0) | P1 (v1.x) | P2 (later) |
| --- | --- | --- | --- | --- |
| 2 | Core engine | Trait + custom Eloquent builder, auto-caching of reads, per-query API | Result representations, negative caching, explicit cache-entity escape hatch | Query-shape analytics |
| 3 | Invalidation | Version namespaces, model events, after-commit, mass-write interception, dependency tracking, `invalidate()`/`purge()` split | Pivot-event companion support, invalidation coalescing (debounce/batch) | DB-level write detection |
| 4 | Query coverage | `get/first/find/value/pluck/count/exists/sum/avg/min/max/paginate/simplePaginate/cursorPaginate/sole/firstOrFail/findOrFail/findMany` | `withCount`/`withSum`/`withExists` dependency awareness | Raw-query cache adapter |
| 5 | Filament/Livewire | Tenant-aware keys, auto-invalidation on saves/actions, bridge UI (page, widget, purge actions), theme-agnostic | Warmup of resource tables, global-search tuning, panel-aware doctor | Relation-manager pivot automation |
| 6 | Performance | Count + page caching, key hashing, per-request memo, TTL jitter, optional locks | Compression / large-payload chunking, driver profiles, business-time TTL presets (end-of-day/week/month) | Adaptive TTL |
| 7 | Observability & ops | Events, entry freshness metadata, `status`, `clear`, `doctor`, Filament dashboard, stats | `bench`, `warm`, Pulse card | Redis/Grafana exports |
| 8 | DX | Install command, publishable config, quickstart, upgrade guide | Demo repo, videos | Laracasts/community content |
| 9 | Compatibility | Laravel 12/13, PHP 8.3–8.5, drivers matrix, Octane, multi-tenant, multi-panel | Filament 4 bridge, Livewire 3 bridge | Laravel 14 lane |

---

## 2. Core caching engine (P0)

### 2.1 Opt-in per model, transparent to Filament

```php
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class Product extends Model
{
    use HasQueryCache;
}
```

- The trait implements `Contracts\QueryCacheable` and returns the package's `QueryCacheBuilder` from `newEloquentBuilder()`.
- Everything that goes through `Product::query()` — **including every Filament resource, table, widget, global search provider and relation manager** — is executed through the caching builder with zero resource changes. See [`04-filament-compatibility.md`](04-filament-compatibility.md) §6.
- Models without the trait are **completely untouched** (no global builder replacement, no service-locator magic).
- Global kill switch (`OOMYCACHE_ENABLED=false`) and per-model `dontCache()` keep escape hatches trivial.

### 2.2 Default-safe, configurable query classifiability (P0)

Not every query should be cached. The builder classifies each read:

| Query shape | Default | Why |
| --- | --- | --- |
| `get()` / `first()` / `find()` / route binding | cache | The bread and butter of admin panels and detail pages. |
| `count()` / `exists()` / `sum()` / aggregates | cache | Filament paginator totals, StatsOverviewWidget, KPI cards. |
| `paginate()` / `simplePaginate()` / `cursorPaginate()` | cache page items **and** total count separately | Table index pages: each page/count is re-executed per Livewire request. |
| `pluck()` / `value()` | cache | Select options, filter option lists. |
| Queries containing `LIKE '%…%'` search terms | **skip by default** (configurable) | Search-term cardinality is near-infinite; low hit ratio, high memory. Filament table search still works — just not cached per keystroke. |
| `chunk()`, `lazy()`, `each()`, `cursor()`, `lazyById()` | never cache | Streaming semantics; caching would defeat memory guarantees. |
| Eager loads with un-serialisable / un-resolvable constraints | skip (strict policy) or cache without relation dependency (opt-in) | Correctness over hit ratio; see §3.3. |
| Reads inside a DB transaction | skip by default | Avoid returning pre-transaction data; configurable. |
| Queries against models declaring `needsFreshData` fields? | n/a | Model-level `dont_cache` rules handle this. |

The classifier is implementable because we own the builder: overrides call `parent::*` and wrap the result; no SQL parsing hacks.

### 2.3 Per-query builder API (P0)

The caching builder exposes a fluent, Filament-friendly API:

| Method | Effect |
| --- | --- |
| `->cacheFor(int\|DateInterval $ttl)` | Override TTL for this query. |
| `->cacheForForever()` | No TTL (still version-invalidated; discouraged, documented for lookup tables). |
| `->dontCache()` | Execute directly and do not write to cache (also evicts nothing). |
| `->cacheKey(string $suffix)` | Append a domain-specific discriminator (e.g. `'dashboard:2026-11'`). |
| `->cacheTags(string\|array $tags)` | Extra logical tags for purge APIs when the store supports tags; also recorded in stats when it does not. |
| `->cacheIf(bool\|Closure $predicate)` | Only store when the predicate passes (e.g. non-empty page, payload under budget); prevents poisoning the cache with empty/error result sets. |
| `->fresh()` | Not overridden; use `dontCache()` explicitly. |
| `Model::flushQueryCache()` | Static convenience: bump the model's namespace; also available as instance method. |

The API is additive: existing Filament resource code does not need to call it. Explicit calls appear in the architecture doc's precedence table.

### 2.4 Cache record fidelity (P0)

The cache stores results that behave like freshly hydrated Eloquent data:

- **Arrays mode (default):** rows + relation tree are stored as a **JSON envelope** and rehydrated through `newFromBuilder()`/`newCollection()`. Works out of the box with Laravel 13's default `cache.serializable_classes=false` (no PHP object unserialisation), is deploy-robust, and measured ≈340 B/row vs ≈1,602 B/row for serialized models (research/07 §5). Casts/appends are computed on access exactly like a fresh model.
- **Models mode (opt-in):** the package internally `serialize()`s models/collections into a **string** payload (so the store's serialisation policy never sees an object) and unserialises with a narrow class allow-list. Highest fidelity for pivot/cast-cache shapes, larger payload; opt-in for a reason.
- Aggregates/scalars/arrays are cached natively.
- The package owns a `PayloadSerializer` contract (see [`02-configuration.md`](02-configuration.md) §7) so both strategies are pluggable and testable.

### 2.5 Model-level configuration (P0)

Per-model overrides without touching global config:

```php
protected function queryCacheOptions(): array
{
    return [
        'ttl' => 600,                 // hot catalog data
        'cache_aggregates' => true,
        'cache_single_records' => false, // or all model overrides
        'dependencies' => [Post::class, Comment::class], // explicit extra namespaces
        'dont_cache' => ['columns' => ['stock_qty']], // never cache stale-sensitive reads
    ];
}
```

Precedence (highest first): per-query method → `queryCacheOptions()` → config preset per model → global config. Full schema in [`02-configuration.md`](02-configuration.md).

### 2.6 Explicit cache-entity escape hatch (P1 — inspired by laracache)

Some values cannot be expressed as an Eloquent builder at all: cross-table ERP aggregates, dashboard KPIs, report-style queries. For those, an **explicit entity layer** (opt-in, P1) provides the best UX of the `laracache` DSL while reusing OoMyCache's version-namespace invalidation instead of per-entity observers:

```php
protected function queryCacheEntities(): array
{
    return [
        CacheEntity::make('dashboard.stock_value')
            ->for(Product::class)                       // base namespace
            ->dependencies([StockMovement::class, Warehouse::class])
            ->ttl('end_of_day')                         // business-time preset
            ->refreshOn(['saved', 'deleted'])           // per-event policy matrix
            ->compute(fn () => DB::table('stock_ledger')->sum('delta')),
    ];
}
```

- Read via `OoMyCache::entity(Product::class, 'dashboard.stock_value')` (or a per-model accessor) with a status envelope (`generatedAt`, `expiresAt`, `status: fresh|stale|rebuilding`, deps) that the Filament cache UI can render.
- Writes invalidate through the same `InvalidationManager` (version namespaces + after-commit + dependency classes) — **no second invalidation architecture**.
- Perfect fit for `StatsOverviewWidget`/`ChartWidget` aggregates, navigation badges and select-option lists; explicitly *not* a way to cache dynamic table queries.
- Never enabled by default; the automatic builder cache remains the product.

### 2.7 Filament-scale read optimizations (P0 — the details that make the ERP fast)

These are the mechanics that distinguish a real Filament cache from a generic model cache:

- **Cached paginator totals.** Filament length-aware tables compute the total via `$query->toBase()->getCountForPagination()` and pass it to `paginate()` (`vendor/filament/tables/src/Concerns/CanPaginateRecords.php:61-68`). Because our trait replaces the model's *base* query builder too, that COUNT is cached — no more "one COUNT per table render" (a gap in the mature competitor, see `research/03` §7).
- **Join/subquery table dependency capture.** A table recorder on the base query builder records `from`/`join`/`joinSub`/`whereIn`/`whereExists`-style subquery tables (subquery builders are our subclass because `Query\Builder::newQuery()` returns `new static`), so writes to joined tables invalidate joined reads. Declared relations (eager loads, `withCount`, `whereHas`) contribute their related tables through the Eloquent layer.
- **Compiled eager-load state fingerprint.** The payload stores a hash of each eager load's compiled SQL + bindings (after constraints and global scopes). On a hit the fingerprint is recomputed; if authorization/global-scope state changed (e.g. `withTrashed()` or a user-dependent scope), the entry is treated as a miss (or relations are reloaded) instead of serving rows the current user should not see.
- **Min TTL across the dependency graph.** A cached page's TTL is the minimum of the base model, every eager-loaded/aggregate dependency model, and the global default — a cheap staleness bound for writes that came from outside Eloquent.
- **Key-collision self-check.** The payload stores the raw key hash; a hit verifies it and re-queries on mismatch (cheap protection against hash collisions and stale payload formats).
- **Streaming APIs are explicitly marked non-cacheable.** `chunk*/lazy*/each/cursor` set a bypass marker on the builder so their internal `get()` pages are never cached (a competitor footgun: chunk pages become one cache entry per offset).

---

## 3. Invalidation & correctness (P0 — the real product)

An Eloquent query cache is only as good as its invalidation. OoMyCache makes invalidation a **first-class, versioned algebra** rather than an afterthought.

### 3.1 Versioned namespaces (works on every driver)

- Every cacheable model has a **version token** in the cache store (`oomycache:version:{connection}:{model}`), stored without TTL.
- Every cache key embeds the version tokens of **all models the result depends on** (base model + resolved relation/aggregate dependencies + explicitly declared dependencies).
- A write bumps one token (write a new random token) → every entry in that namespace becomes unreachable in **O(1)** — no key scanning, no tags, works on file/database/array/Redis/Memcached.
- Old entries are unreachable but remain until TTL expiry; TTLs are the garbage collector. `oomycache:clear` bumps the global version token for a full O(1) purge.
- Tags (when the store supports them) are an **optimisation and operator convenience** for tag-scoped purge, never the correctness mechanism. See [`03-architecture.md`](03-architecture.md) §4 and [`research/07`](../research/07-laravel-cache-internals.md).

### 3.2 Write-path coverage matrix (P0)

| Write path | Detection | Invalidation |
| --- | --- | --- |
| `$model->save()`, `create()`, `update()`, `delete()` | Eloquent model events (`saved`, `deleted`) | Bump model version (after commit when in a transaction). |
| Soft delete / restore / force delete | `deleted`, `restored`, `forceDeleted` events | Bump model version. |
| Mass `Model::where(...)->update()` / `->delete()` / `->increment()` / `->truncate()` | Overridden write methods on our builder | Bump model version after the statement succeeds. |
| `attach/detach/sync` on pivot relations | Core fires no model events | **P0:** explicit `flushQueryCache()` documented + bridge/Filament action flush. **P1:** observe `mike-bronner/laravel-pivot-events` events if installed. |
| Raw `DB::table(...)->update()` / `DB::statement()` | Not interceptable safely | Documented limitation + `oomycache:clear`; optional statement-level detector (P2). |
| Relation model writes (e.g. `Post` saved while `Author` query eager-loads posts) | Dependency tracking (§3.3) | Bump `Post` version; keys that embed it become unreachable. |
| Transaction rollback | Only flush **after commit** (`Connection::afterCommit`) | No invalidation of uncommitted work; committed writes always invalidate. |
| Queue/console writes | Same model events (queue workers boot the package) | Same semantics; after-commit respected. |

### 3.3 Dependency tracking for relations & aggregates (P0, with strict fallbacks)

A cached `Author` query that eager-loads `posts` depends on the `Post` namespace too. The builder records:

- names passed to `with()` / `load()` — resolvable via `Relation::noConstraints()`, including nested `a.b.c`;
- names passed to our overrides of `withCount`, `withSum`, `withAvg`, `withMin`, `withMax`, `withExists`, `whereHas`, `whereRelation`, `whereBelongsTo`, `has`, `doesntHave`;
- morph relations: resolved through the morph map when possible; **unresolvable morphs skip auto-caching by default** (`relations => 'strict'`), with per-model `dependencies` as the escape hatch;
- explicit `queryCacheOptions()['dependencies']` for business-logic dependencies the builder cannot see.

If a dependency cannot be resolved deterministically, the builder **bypasses the cache for that query** and emits a debug event — the default posture is "slow but correct", never "fast but stale".

### 3.4 Safety contexts & guardrails (P0)

| Context | Default behaviour |
| --- | --- |
| Active DB transaction | Skip reads; defer invalidation to after-commit. |
| Console/queue | Caching enabled, but `migrate`, `db:wipe`, seeders and `queue:work` can be excluded via config; destructive commands recommend `oomycache:clear`. |
| Octane workers | No request-scoped state in singletons; version-token memo is request-scoped or cache-backed; documented in [`03`](03-architecture.md) §9. |
| Tests | Array store; cache flushed between tests by test helper; `withoutQueryCache()` helper for query-count assertions. |
| Cache store failure | Configurable `fail_silently`: reads fall back to DB; invalidation failures are logged as critical (stale risk) instead of silently swallowed (P0 decision recorded in ADR-7). |
| Tenant switch / impersonation | Keys include tenant + panel (+ optional user) segments; invalidation is global per model so a write in any tenant never serves stale data anywhere. |
| Deploy with schema/model changes | `oomycache.version` config (and `APP_VERSION`) participates in keys; deploy step documented; `oomycache:clear` is safe & O(1). |

### 3.5 Negative caching & hot-miss control (P1)

- Optional short-TTL negative caching (`null`/0 results) to protect route-model-binding misses from repeated DB hits.
- Per-query `cacheFor` on misses, TTL jitter, optional stampede lock (see §6) and `stale-while-revalidate` as an **explicit opt-in for non-financial data**, never for ERP correctness paths.

---

## 4. Query coverage matrix (P0 target)

| Eloquent API | v1 behaviour | Notes |
| --- | --- | --- |
| `get()`, `all()` | cache collection | Relations included when eager-loaded. |
| `first()`, `firstOrFail()`, `sole()`, `find()`, `findOrFail()`, `findMany()` | cache model(s) | Route-model binding covered (Filament edit pages). |
| `value()`, `pluck()` | cache scalar/array | Select options, filter lists. |
| `count()`, `exists()`, `doesntExist()` | cache scalar/bool | Paginator totals + widgets. |
| `sum()`, `avg()`, `min()`, `max()` | cache scalar | Dashboard KPIs. |
| `paginate()`, `simplePaginate()`, `cursorPaginate()` | cache items + count separately | Rebuild paginator on hit with correct page/per-page/query string. |
| `with()`, nested `with()`, `withOnly` | cached result includes relations | Dependency tracking handles invalidation. |
| `withCount/withSum/withAvg/withMin/withMax/withExists` | cached; dependency recorded by overrides | Alias collisions handled by SQL signature. |
| `whereHas/whereRelation/whereBelongsTo` | cached; dependency recorded | `exists` subquery is part of the SQL signature. |
| `chunk/lazy/each/cursor/lazyById` | **never cached** | Streaming; memory guarantees preserved. |
| `increment/decrement/update/delete/restore/forceDelete/truncate` | pass-through that **flushes** | Not cached writes; they invalidate. |
| `toSql()`, `toBase()`, `getQuery()`, `explain()` | untouched | No surprising query mutation. |
| Raw `DB::table()` reads | not cached | Out of scope for the Eloquent query cache; documented. |

### 4.1 Pagination correctness (P0)

Filament calls `$query->paginate($perPage)` and (for "all" page size) `$query->toBase()->getCountForPagination()` directly. The builder caches page items and total separately; a page-size/filter/sort/page change is a different key; the paginator is reconstructed with the correct `path`, `query` and page state. The one known bypass (`toBase()` calls made by Filament for "all" rows) is documented in [`04`](04-filament-compatibility.md) and can be covered by the Filament bridge.

---

## 5. Filament & Livewire features (P0 for the bridge)

Full requirements and evidence in [`04-filament-compatibility.md`](04-filament-compatibility.md). At feature level:

- **Zero-resource-change compatibility:** trait-only integration; Filament resources, tables, widgets, relation managers, global search, dashboard stats and panel pages keep working.
- **Tenant-safe by default:** automatic tenant/panel key segments from `Filament::getTenant()` / current panel; per-tenant invalidation policies.
- **Filament-aware invalidation:** bridge flushes model caches after bulk actions, attach/detach actions and actions that perform mass writes where the core event system cannot see them (P0 for the ERP; exact matrix in plan/04).
- **Cache management UI (bridge):**
  - panel plugin with a **Query Cache** page: status, hit/miss counters, per-model namespace tokens, purge buttons (P0 for v1 ERP; the page must use only Filament components so it renders under any custom theme, including `filament-liquid-glass`);
  - **widget**: cached-query health/hit-ratio (P1);
  - **per-resource purge action** trait/header action (P1);
  - **note:** Filament 5.11.2 has no command palette (verified plan/04 §7.1) — no palette integration will be promised;
  - authorization via policies/permission callback, never "every panel user can purge" (P0 security).
- **Warmup (P1):** warm the table query of selected resources after deploy/import, in a queued job, with tenant iteration.
- **Test helpers (P0):** `assertQueryCacheable`, `assertQueryWasServedFromCache` style helpers + documented Filament Livewire testing recipe (P1).

---

## 6. Performance & scale features (P0/P1)

| Feature | Priority | Detail |
| --- | --- | --- |
| Key hashing | P0 | xxh128 (fallback sha256); keys far below Memcached 250-byte limit; readable prefix + hash. |
| Per-request memoisation | P0 | Optional in-request layer avoids repeated deserialisation of hot entries within one Livewire request; Octane-safe by scoping. |
| TTL jitter | P0 | A percentage of TTL randomised per entry to prevent synchronized expiry stampedes. |
| Stampede protection | P1 | Lock-based single-flight recomputation when the store supports locks; default off for latency-sensitive ERP pages, documented. |
| Compression | P1 | Gzip/deflate large payloads above a threshold (graft from smart-cache); memory-vs-CPU tradeoff config. |
| Chunked large payloads | P1 | Versioned chunk protocol (wrapper + race-safe re-read) when a payload exceeds the store limit; never a lazy non-`Collection`; benchmark-gated (see architecture §7.5). |
| Driver profiles | P1 | `redis-recommended`, `database`, `file`, `array` presets (TTL/compression/locks defaults per profile). |
| Warmup & prefetch | P1 | Queue job + Filament action + `oomycache:warm`; per-tenant iteration; never blocks requests. |
| Benchmarks | P0 | `oomycache:bench` and a documented benchmark harness comparing cold/warm DB time, serialisation cost, payload sizes. |
| Failure fallback | P0 | Cache error → DB read; measure and log; never turn a cache outage into an app outage (except invalidation, which must alert). |

---

## 7. Observability & operations (P0 except where noted)

| Capability | Priority | Notes |
| --- | --- | --- |
| Events: `QueryCacheHit`, `QueryCacheMissed`, `QueryCacheInvalidated`, `QueryCacheCleared` | P0 | Enables Pulse/Telescope/custom metrics without package lock-in. |
| `oomycache:status` | P0 | Config, store capability, cacheable models, version tokens, counters, warnings. |
| `oomycache:clear [--model=] [--tenant=] [--tag=]` | P0 | O(1) version bumps; dangerous but fast. |
| `oomycache:doctor` | P0 | Diagnoses tags support, serialisation classes, transactions, Filament/Livewire versions, risky config, driver quirks, deploy-time mistakes. |
| `oomycache:bench` | P0 | Query-level cold/warm benchmark + payload size sampling. |
| `oomycache:warm` | P1 | Config-driven warmup jobs. |
| Filament page/widget | P1 | See §5. Custom-theme safe. |
| Stats collector | P1 | Sampled, off by default in the request path; cache-backed snapshot; no per-query write amplification. |
| Explain/debug mode | P1 | `OOMYCACHE_DEBUG=true` logs key, hit/miss, reason for bypass, dependencies; never logs bindings containing secrets (redaction). |

---

## 8. Developer experience features (P0/P1)

- **One-command install (P1):** `php artisan oomycache:install` publishes config, optionally scaffolds a model trait example, prints a Filament-aware quickstart, detects store capability and prints the recommended production config.
- **Excellent failure messages:** bypass reasons are named (`transaction`, `unresolvable-morph`, `search-query`, `store-unsupported-tags`, …), not silent.
- **Static-analysis friendly:** generics-friendly return types on builder methods (`@template`, `@return Collection<int, TModel>`), PHPStan level 7 (skeleton default) with intent to raise; 100% type coverage gate.
- **Transparent-by-default docs:** a "How invalidation actually works" page (the most-asked question in this category), a safety/limitations page, a migration guide from `mike-bronner/laravel-model-caching`, and a Filament cookbook.
- **Laravel-ish API:** config file, publish tags (`oomycache-config`), Artisan commands, events, no custom container gymnastics.

---

## 9. Compatibility features (P0)

| Dimension | Commitment |
| --- | --- |
| PHP | `^8.3` (CI on 8.3/8.4/8.5) |
| Laravel | `^12.0 \|\| ^13.0`, prefer-lowest + prefer-stable lanes |
| Filament | Core: zero dependency. Bridge: Filament 5.11+ verified first; Filament 4 lane P1. |
| Livewire | Core: zero dependency. Bridge: Livewire 4.x verified first; Livewire 3 lane P1. |
| Cache drivers | `array`, `file`, `database`, `redis`, `memcached` mandatory in test matrix; `dynamodb` best-effort. |
| Stores | Tag-capable stores use tags opportunistically; all stores use version namespaces for correctness. |
| Databases | SQLite/MySQL/PostgreSQL tested for pagination/count semantics (CI lanes). |
| Multi-tenancy | Single DB (scoped keys) P0; per-tenant DB connections P1 documented. |
| Multi-panel/multi-guard | P0 (panel id in key where Filament is present; guard-aware user scoping optional). |
| Octane | P0 documented + tested bootstrapping expectations. |
| Windows/Linux CI | P0 (skeleton already has the matrix — keep it). |

---

## 10. Non-goals / anti-features (deliberate)

These are features we explicitly **will not** build, to keep the package trustworthy:

1. **No HTTP/response caching.** Different problem (spatie/laravel-responsecache); we cache Eloquent result sets.
2. **No caching by default for models without the trait.** No framework-wide interception; opt-in is a safety feature.
3. **No forks or vendored copies of Illuminate classes.** Compatibility is more valuable than intercepting impossible internals.
4. **No requirement for Redis tags.** The correctness mechanism must work on every driver.
5. **No PHP-level result filtering on cache hits that could bypass authorization** — we cache the SQL result; application-layer filters/policies still run on every render.
6. **No "magic" auto-invalidation of raw SQL writes via triggers/query logs** in v1. Documented limitation; explicit flush API is the contract.
7. **No stale-while-revalidate on financial/inventory data by default.** Opt-in per query/model only.
8. **No cache of model identity/object graph** (identity map); no cross-request model registry.
9. **No built-in cache server management** (Redis memory eviction policies, etc.); we document, we don't manage infrastructure.
10. **No automatic relationship preloading/fixes** beyond caching what the developer asked to load.

---

## 11. Differentiation vs. the five packages

Full comparison in [`research/00-comparison-matrix.md`](../research/00-comparison-matrix.md); sourced steals in each `research/NN` report §5. Summary of our positioning:

| Need | Existing packages | OoMyCache |
| --- | --- | --- |
| Auto query cache for Eloquent | mike-bronner (mature but complex, version-sensitive, forked internals), elipzis (small/simpler) | Auto, modern Laravel 12/13, no forks, opt-in per model |
| Explicit cache entities refreshed on events | laracache (not query caching; developer declares each entity) | Automatic + explicit per-query API |
| Cache optimization mechanics (compression/SWR/locks/dashboard) | smart-cache (not Eloquent-specific) | Grafted selectively where they serve query caching |
| File/database cache store housekeeping | laravel-cache-evict (ops-only) | Recommended companion; our driver guidance + ops docs integrate it |
| Filament-native | none of the five | First-class bridge, tenant-safe keys, purge/warm UI, theme-agnostic |
| Multi-driver correctness | model-caching leans on tags/store features | Version namespaces by default on all stores |

---

## 12. Feature provenance map (to be finalised from research reports)

| OoMyCache feature | Inspired/validated by | Research reference |
| --- | --- | --- |
| Trait + custom builder interception | model-caching / cacheable-model | research/03 §2, research/02 §2 |
| Model-event invalidation | laracache, model-caching | research/05 §3, research/03 §3 |
| Aggregates & paginator caching | model-caching | research/03 §3 |
| Relationship dependency invalidation | model-caching edge-case history | research/03 §3 & §6 |
| Queue/after-commit & debounce semantics | laracache | research/05 §3 |
| Explicit cache-entity DSL + per-event policy matrix + `CREATING`/status envelope concept | laracache | research/05 §5.1–5.3, §7 |
| Business-time TTL presets (`end_of_day/week`) | laracache | research/05 §5.4 |
| Invalidation coalescing / token-debounce concept (redesigned per namespace) | laracache (fixing its per-model-token bug) | research/05 §5.5, §6.4 |
| `invalidate()` vs `purge()` split, no tombstones, no falsy-default coercion | laracache (critiqued and corrected) | research/05 §5.7, §6.12–6.13 |
| Registry/operator UX for update/delete/status commands | laracache | research/05 §5.6 |
| Per-entity store/queue overrides → store resolver contract | laracache | research/05 §5.8 |
| Compression, chunking, SWR/locks, commands | smart-cache | research/04 §3, §5 |
| Expired-entry eviction/ops for file/database stores | cache-evict | research/01 §5 |
| Store/tag/serialization constraints & defaults | Laravel 13 source | research/07 §1–§8 |
| Filament invariants, tenancy, invalidation matrix | installed Filament/Livewire source | plan/04 §2–§6 |

> During implementation, replace the generic references above with the exact `path:line` findings from the research reports where useful.

---

## 13. Success metrics (v1 acceptance)

| Metric | Target | How measured |
| --- | --- | --- |
| DB query count on a cold Filament table page vs warm | ≥ 70% reduction for opted-in models | `oomycache:bench` + Laravel query log in E2E tests |
| Warm page latency on a 100k-row ERP table | measurable p95 improvement in dogfood; no regression on cold path | Benchmark harness on the ERP |
| Cache hit ratio on ERP index/widget pages | ≥ 80% during dogfood (documented, not enforced) | Stats collector / Filament widget |
| Correctness | No stale reads in adversarial test matrix; no cross-tenant leakage tests failing | plan/05 §4–§6 |
| Memory per cached page | Bounded; documented per-result-count guidance; compression for large sets | Bench payload-size report |
| Install → first cached query | ≤ 5 minutes for a Filament developer | Quickstart docs + demo repo |
| Compatibility | Full CI matrix green, including Windows | GitHub Actions |

---

## 14. v1.0 feature-freeze checklist

- [ ] Trait + builder caching `get/first/find/value/pluck/count/exists/aggregates/paginate/simplePaginate/cursorPaginate`
- [ ] Version namespaces + model-event invalidation + after-commit + mass-write interception
- [ ] Dependency tracking for eager loads, counts and whereHas; strict fallback for morphs
- [ ] Tenant/panel-aware keys (core resolver contract + Filament auto-detection)
- [ ] Per-query + per-model + global configuration, precedence documented and tested
- [ ] Commands: `status`, `clear`, `doctor`, `bench`; events; debug logging
- [ ] Filament bridge: plugin registration, tenant resolver, cache page (P0 ERP), invalidation integration, authorization
- [ ] Docs: install, config reference, invalidation model, safety/limitations, Filament cookbook, upgrade guide
- [ ] QA: plan/05 gates all green on the full CI matrix
- [ ] Dogfooded on the ERP: no stale-data incidents in a defined observation window
