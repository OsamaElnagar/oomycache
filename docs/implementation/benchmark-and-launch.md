# OoMyCache — Benchmark & Launch Readiness

> **Date:** 2026-11-09 · **Environment:** EnvKit site `filament-custom-pages` · PHP 8.5.12 · Laravel 13.40 ·
> Filament 5.11.2 · Livewire 4.4.8 · SQLite (dogfood) / database cache store · seeded ERP dataset
> (3k products, 2.5k purchase orders, 7k lines, 6k stock levels, 5k invoices, 200 suppliers).

This document is the launch-readiness snapshot: what has been proven, the measured performance,
payload/ops characteristics, honest gaps, and the recommended alpha → beta → stable sequence.

## 1. Executive summary

OoMyCache is feature-complete for the core promise — transparent Eloquent query caching with
O(1) table-keyed invalidation — and has been dogfooded in a real Filament ERP app with:

| Area | Evidence |
| --- | --- |
| Core package | **73 passed + 1 skipped driver lane / 261 assertions**, PHPStan level 7 clean, Pint clean, **100% type coverage** |
| Filament companion (`osamaelnagar/oomycache-filament`) | **6 tests / 9 assertions**, PHPStan/Pint clean, own GitHub Actions matrix (PHP 8.3–8.5 × Laravel 12/13 × Testbench 10/11) |
| Dogfood app suite | **162 tests / 580 assertions** (Filament resources/widgets/pages, relation managers, Livewire front office, Redis, tenancy) |
| Live cache behaviour | Warm reads: **0 application queries** on Filament pages, relation-manager E2E, and plain Livewire pages (database store still executes cache-table statements; see `store_queries` in bench JSON) |
| Real Redis | phpredis installed through EnvKit; cache-store test passes against Redis 8.8 |
| Tenancy | real tenant-enabled panel: A/B isolation, back-to-A hits, fail-closed `tenant-unresolved` |
| Payload hardening | compression envelope, per-request memo, negative caching with short TTL |
| Maintenance | `oomycache:prune` with expiry-guarded keyset deletes; live run removed 122 expired rows |

**Verdict:** ready for a public **alpha** after the remaining release chores (Packagist metadata/release,
chunking, a final hook/Octane pass). The product's core differentiator — correct O(1) invalidation under
Filament's pagination, eager loads, widgets and pivot actions — is proven end-to-end.

## 2. Benchmarks

Method: `php artisan oomycache:bench {model} --limit=50 --samples=3 --json`, run on the seeded app with
its normal database cache store (`CACHE_STORE=database`). The command clears the cache, measures one
cold run (includes the database query + cache-table write), then 3 warm runs. `app_queries` excludes
cache-store bookkeeping queries (`cache` / `cache_locks` tables); `queries` includes them.

| Model | Cold (total / app queries) | Cold time | Warm avg (total / app queries) | Warm time | Saved per warm read |
| --- | --- | --- | --- | --- | --- |
| `App\Models\Product` | 7 / 1 | 22.8 ms | 2 / **0** | **7.7 ms** | 1 app query, 66% latency |
| `App\Models\Supplier` | 7 / 1 | 20.3 ms | 2 / **0** | **5.4 ms** | 1 app query, 73% latency |
| `App\Models\PurchaseOrder` | 7 / 1 | 24.0 ms | 2 / **0** | **7.6 ms** | 1 app query, 68% latency |

Raw Product output:

```json
{"model":"App\\Models\\Product","limit":50,
 "cold":{"queries":7,"app_queries":1,"ms":22.818},
 "warm_avg_ms":7.675,"warm_avg_app_queries":0,
 "warm":[{"queries":2,"app_queries":0,"ms":7.809},
         {"queries":2,"app_queries":0,"ms":7.301},
         {"queries":2,"app_queries":0,"ms":7.914}]}
```

Notes:
- Timings vary with OS cache/opcache; an earlier run measured Product cold 41.4 ms / warm 20.3 ms on a
  cold PHP opcache. The invariant that matters is **0 application queries** for warm reads.
- On the database store, each warm read costs 2 cache-table queries (payload + version token). Redis/file
  stores remove that overhead; use Redis in production for the best latency.
- The live Filament page is heavier than raw model queries: `/admin/products` renders ~508 KB HTML in
  ≈0.50–0.70 s warm vs ≈0.77 s cold (EnvKit; dumps capture varies ±0.15 s). The automated
  `test('ProductResource warm page', …)` asserts **0 ERP-table queries** for that render.

## 3. Payload, memory and ops

Measured on the live SQLite cache table after a prune (read-only SQL):

| Metric | Value |
| --- | --- |
| Cache rows | 23 (14 are `:version:` tokens; the rest are warm payloads) |
| Payload+key bytes | ≈118 KB total (≈5 KB average payload) |
| `cache_locks` rows | 0 |
| Payload format | JSON string (`OOMYCACHE1:`) — safe under `cache.serializable_classes = false` |
| Optional compression | gzip/deflate/zstd envelope above threshold (default off, 64 KB threshold) |
| Negative caching | off by default; when enabled, empty results are cached for `negative_ttl` (10 s) |

Ops expectations:
- **Invalidation is a version-token bump** — no key scans, no tag dependency, no `Cache::flush()`.
- **Database store needs pruning**; schedule `Schedule::command('oomycache:prune')->hourly()->withoutOverlapping();`
  (live dry-run: scanned 136, expired 122; real run deleted 122 in 469 ms).
- **Redis**: native TTL; prune refuses it with a hint. Prefer Redis for production.
- **Driver matrix locally**: array/file/database covered by `tests/Feature/CacheStoreMatrixTest.php`; a real
  EnvKit smoke test wrote and read Laravel's database cache store on **MySQL 13** and **PostgreSQL 18**
  (both `OK`); the real phpredis test runs in the dogfood app.
- **File store**: prune refuses (recommends `vectorial1024/laravel-cache-evict`); TTL expiry is Laravel's job.
- **Long workers/Octane**: `QueryCacheEngine::forgetOptions()` re-reads config; version tokens are
  re-resolved per request/query when changed elsewhere.

## 4. Launch checklist (from plan/05 + plan/06)

| Item | Status | Notes |
| --- | --- | --- |
| Core query engine + Filament paginator COUNT | ✅ done | package tests + dogfood |
| Table-keyed O(1) invalidation | ✅ done | model events, mass writes, afterCommit |
| Relationship/eager-load dependency invalidation | ✅ done | withCount/whereHas/joins/subqueries |
| Arrays-JSON payload/version store commands | ✅ done | `status`, `clear`, `doctor`, `bench`, `prune` |
| Filament bridge + studio page + plugin | ✅ done | companion package, own CI lane |
| Real relation-manager attach/detach E2E | ✅ done | 3 tests, real Filament actions |
| Tenant/panel isolation + fail-closed | ✅ done | real tenant-enabled panel E2E |
| Payload compression + per-request memo + negative caching | ✅ done | package tests |
| Redis driver validation | ✅ done | live phpredis test |
| Prune command | ✅ done | 9 package tests + live run |
| **Chunked payloads** (> ~1 MB) | ✅ done | chunks + manifest, corruption/partial → miss, `max_chunks` guard; 9 tests |
| **Release rehearsal** | ✅ done | temp git split/tag rehearsal passes 23 assertions (1 expected prerelease-pin warning); see [release-rehearsal.md](release-rehearsal.md) |
| **Memcached / MySQL / PostgreSQL / Octane lanes** | 🟡 partial | Local matrix: array/file/database package tests; **real Redis 8.8 + Memcached 1.6.8 app tests pass**; **MySQL 13 + PostgreSQL 18 database-cache smokes green**; driver-matrix CI workflow exists. Octane + Eloquent-on-MySQL/PG package tests pending. |
| **Companion package Packagist release** | ⛔ pending | manifests + tag-triggered release workflows ready ([release-checklist.md](release-checklist.md)); needs repo split + tag + Packagist indexing |
| **Docs site / demo app / install video** | ⛔ pending | README + docs folder are solid; public demo URL missing |
| **Security review (audit of cache-key inputs, tenant leakage)** | ✅ review done + High/L2/L3/L5 fixed | [security-review.md](security-review.md): 3 High, 1 Medium, 3 Low fixed post-review; M2/M4/M5/M6 documented for alpha |
| Benchmark report | ✅ done | this document |

## 5. Recommended launch sequence

### Alpha (internal, this week)
1. Tag `v0.1.0-alpha.1` on `osamaelnagar/oomycache` (core) and `v0.1.0-alpha.1` on
   `osamaelnagar/oomycache-filament`; push both; confirm Packagist auto-update.
2. Run `composer test` in both packages and `php artisan test --compact` in the dogfood app; attach
   output to the release notes.
3. Publish alpha to the Filament Discord / Laravel package channels; ask 5–10 Filament devs to try it.
4. Watch the dogfood app for one week: `oomycache:status` stats (hits/misses/invalidations), prune
   hourly, no stale-read reports.

### Beta (2–4 weeks)
1. Add chunked payloads and the Memcached/MySQL/PostgreSQL/Octane CI lanes.
2. Publish the demo app (the dogfood ERP is already a good candidate) + a short benchmark post with the
   tables from §2.
3. Add a Packagist README badge + docs site page; open GitHub Discussions for support.
4. Address external security review findings; add upgrade notes.

### Stable (after beta feedback)
1. Tag `v1.0.0` for both packages; freeze config keys and document BC guarantees.
2. Publish a launch post (Laravel News / Filament blog / dev.to), include "0 queries on a Filament
   product page" video and the relation-manager invalidation demo.
3. Set up Dependabot + release workflow for both repos; monitor issues for a week before calling it done.

## 6. Risk register

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Stale reads after raw DB writes / pivot writes outside Eloquent | Medium | High | Document; `Oomycache::flush([$model])`; Filament `ActionCalled` bridge; consider DB trigger/outbox later |
| Large payloads exceed cache limits | Medium | Medium | `safety.max_payload_bytes` bypass + dispatch `Bypassed` event; chunking on beta roadmap |
| Multi-tenant leakage | Low | Critical | Tenant/panel segments + fail-closed; real panel E2E; security review before stable |
| Database cache table bloat | Medium | Medium | `oomycache:prune` hourly; monitor rows/bytes |
| Filament/Livewire upgrades break builder assumptions | Medium | High | CI matrix; targeted tests; pinned package compatibility docs |
| Octane long-worker stale config/version memo | Low | Medium | `forgetOptions()`; version tokens stored in cache and memoized per process — audit before Octane lane |
| Adoption friction (Filament panel plugin + trait) | Medium | Medium | One-line trait, one-line plugin; install video; demo repo |

## 7. Reproduction commands

```bash
# core
cd packages/OoMyCache && composer test

# companion bridge
cd packages/OoMyCacheFilament && composer test

# dogfood app
php artisan test --compact
php artisan oomycache:status
php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json
php artisan oomycache:prune --dry-run

# live URLs (EnvKit)
open https://filament-custom-pages.test/admin/products
open https://filament-custom-pages.test/admin/query-cache
open https://filament-custom-pages.test/erp/products
```

Related: [phase-1-dogfood.md](phase-1-dogfood.md) · [phase-2-progress.md](phase-2-progress.md) ·
[plan/05 QA checklist](../plan/05-qa-checklist.md) · [plan/06 roadmap](../plan/06-roadmap.md) ·
[package README](../../README.md)
