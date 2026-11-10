# 00 — Combined Comparison Matrix & Gap Analysis

> **Purpose:** synthesis of the five package deep-dives (`research/01`–`research/05`) plus the platform annexes (`plan/04`, `research/07`) into one landscape view, and the market gap that defines OoMyCache.
> **Sources analysed (frozen):** see the table below; full evidence in each report's §10.
> **Method:** each package was cloned and read at a pinned commit; claims carry `path:line` evidence in the individual reports. No package's test suite was executed (read-only analysis; clones had no `vendor/`), and download/star counts are external snapshots from 2026-11-09 — treat them as directional.
> **Companion docs:** [`plan/01-features.md`](../plan/01-features.md) · [`plan/03-architecture.md`](../plan/03-architecture.md) · [`plan/04-filament-compatibility.md`](../plan/04-filament-compatibility.md)

---

## 1. The five packages in one sentence each

| # | Package | Commit / tag | Category | One sentence |
| --- | --- | --- | --- | --- |
| 01 | `vectorial1024/laravel-cache-evict` | `f41952a` / `2.1.2` | Cache-store maintenance (CLI) | An expiry-only GC command for Laravel's `file`/`database` cache stores — a useful janitor, not a query cache. |
| 02 | `elipZis/laravel-cacheable-model` | `8fa6e4a` / `v0.6.0+3` | Eloquent query cache | A ~530-line prototype that swaps `newBaseQueryBuilder()` and caches raw SELECT rows with a raw-SQL key and whole-model flush. |
| 03 | `mike-bronner/laravel-model-caching` | `84d5b2e` / `13.2.2` | Eloquent query cache | The 9-year-old, heavily battle-tested benchmark: forked builder/relation layers, tag-by-table invalidation, and a commit history that is a catalogue of query-cache failure modes. |
| 04 | `iazaran/smart-cache` | `c526854` / `1.17.0` | Cache-value optimization decorator | A large general-purpose cache-repository decorator (compression, chunking, SWR, tags-by-index, dashboard, commands) with zero Eloquent awareness. |
| 05 | `mostafaznv/laracache` | `2c18cf3` / `3.1.0` | Declarative cache entities | A trait + entity DSL that recomputes named cache snapshots on model events, with queue/debounce and Artisan tooling — explicit, not transparent. |

**Health snapshot (LaraPlugins / Packagist, 2026-11-09):**

| Package | Total downloads | Monthly | Stars | Contributors (report) | Health | Security advisories |
| --- | --- | --- | --- | --- | --- | --- |
| model-caching | ≈ 393k | — | — | 72 contributors (9y) | Healthy | None published |
| cacheable-model | 62,889 | 3,808 | 158 | 7 | Healthy | None |
| laracache | 58,183 | 1,735 | 272 | 3 | Healthy | None |
| smart-cache | ≈ 24k (LaraPlugins) | — | — | 2 | Healthy | None |
| cache-evict | 22,393 | 1,002 | 59 | 1 | Medium | None |

All five are MIT. None requires Filament/Livewire. None has a Filament integration.

---

## 2. One-screen verdict

| Dimension | 01 cache-evict | 02 cacheable-model | 03 model-caching | 04 smart-cache | 05 laracache |
| --- | --- | --- | --- | --- | --- |
| Automatic Eloquent query caching | ❌ | ✅ minimal | ✅ mature | ❌ (value cache) | ❌ (explicit entities) |
| Invalidation correctness for ERP | n/a (expiry GC) | ❌ whole-model, flush-before-write, no relations/transactions | ⚠️ tag-by-table is the best model, but no after-commit, tag-only stores, no tenant dimension | ⚠️ index-based tag/dependency metadata, not query-aware | ❌ events only, no mass/raw/pivot |
| Filament 5 readiness | ✅ inert (no UI) | ⚠️ runs but unsafe | ⚠️ integration + tenancy + COUNT gap | ❌ no awareness, synchronous SWR harms panels | ❌ no awareness; narrow escape hatch only |
| Best thing to learn | guarded GC deletes, keyset sweep, typed refusal, stats event | `newBaseQueryBuilder()` seam, read-through at result boundary, chainable escapes | dependency set from full query plan; fail-safe unkeyable; eager-load state fingerprint; min-TTL; key self-check | versioned chunk protocol; single-flight; decoder registry; subtract-only jitter | entity DSL/policies, status envelope, business-time TTL presets, debounce concept |
| Biggest risk if adopted | none (ops only) | raw-SQL keys, no tenant/relations, no tests | 6k LOC internals coupling, second package on critical path, wrong-rows history | per-write metadata cost, own tag system, synchronous SWR | stale by design for non-event writes, key collisions, no tenant |
| Verdict | **Adapt / document as companion** | **Adapt design, avoid dependency** | **Adapt ideas, avoid dependency** | **Adapt algorithms, avoid dependency** | **Adapt DSL, avoid dependency** |

**Bottom line:** the two query-cache packages prove the demand but neither meets a Filament-ERP correctness bar. The two adjacent packages contribute mechanisms but not an engine. The ops package contributes housekeeping patterns. **No package combines transparent Eloquent caching, multi-driver O(1) invalidation, tenant/panel safety, Filament-native UX and operability — that is the OoMyCache product.**

---

## 3. Capability matrix (verified from source; ❌/⚠️/✅; gaps in the reports)

### 3.1 Caching model

| Capability | 02 cacheable | 03 model-caching | 04 smart-cache | 05 laracache | OoMyCache target |
| --- | --- | --- | --- | --- | --- |
| Hook point | `newBaseQueryBuilder()` → `runSelect()` | forked Eloquent/Query builders + relation subclasses | cache Repository decorator | Eloquent observers + entity reads | `newEloquentBuilder()` **and** `newBaseQueryBuilder()` subclasses (no fork) |
| Cached unit | raw row arrays | models/collections/paginators (serialised) | arbitrary values | named entity snapshots | models + arrays (selectable) |
| Opt-in | trait | trait / base class | repository swap | trait + declared entities | trait + config |
| Transparent to Filament | partially | mostly | n/a | no | yes |
| Key = SQL+bindings | raw SQL (hashed by store) | 1,225-line custom encoder | caller key | `{model}.{entity}` | one canonical fingerprint (SQL + bindings + eager/scope state + deps) |
| Connection/DB in key | ❌ | ✅ (`use-database-keying`) | n/a | ❌ | ✅ always |
| Tenant/panel/user key | ❌ | ❌ (manual prefix) | manual namespace | ❌ | ✅ fail-closed resolver |
| Key hashing/length | ❌ raw SQL (`varchar(255)` risk) | sha1 after multi-KB string | caller | short keys | xxh128; segments hashed/escaped |
| Key collision self-check | ❌ | ✅ | n/a | ❌ | ✅ |

### 3.2 Invalidation

| Capability | 02 | 03 | 04 | 05 | OoMyCache |
| --- | --- | --- | --- | --- | --- |
| Model events | ❌ (builder methods only) | ✅ created/saved/deleted (+restore via save) | optional observer for declared keys | ✅ created/updated/deleted/restored | ✅ wildcard events incl. restored/forceDeleted |
| Mass builder writes | ✅ common methods; new L13 methods missed | ✅ enumerated + new L13 coverage | n/a | ❌ | ✅ overrides including L13 write surface |
| Pivot attach/detach | ⚠️ broken (flushes related model) | ✅ via extra pivot-events package | ✅ if using its declared keys | ❌ | ⚠️ core documented + bridge flush + optional pivot-events listener (P1) |
| Raw DB writes | ❌ | ❌ | ❌ | ❌ | ❌ documented + `invalidate()`/`purge()` escape |
| Transaction after-commit | ❌ (flush-before-write race) | ❌ (documented race) | ✅ for its own after-commit option | ❌ | ✅ default before writes (reads bypass), invalidation after commit |
| Relation/join/subquery deps | ❌ | ✅ tag walk of query plan (best in class) | ❌ (caller declares) | ❌ | ✅ table recorder (base builder) + relation overrides |
| Aggregate (`withCount`) deps | ❌ | ❌ (reported gap) | n/a | ❌ | ✅ |
| Invalidation cost | O(model) tag flush | O(tag-set entries), Redis Lua scans; whole-store flush on tagless drivers | O(keys/index) | O(entities) | O(1) version-token write |
| Works on all drivers | ⚠️ tagless fallback unbounded/raced | ❌ taggable only for correctness (whole flush otherwise) | ✅ own indexes | ✅ | ✅ version namespaces on every driver |
| Coalescing/debounce | ❌ | ✅ cooldown (awkward opt-in) | n/a | ✅ debounce (buggy per-model token) | P1 optional per-namespace |
| `invalidate()` vs `purge()` vs `clear()` | one `flushCache()` | `flushCache()`/`invalidate()` | clear/flush/store-wide force | tombstone delete (no forget) | three documented O(1)/O(n) operations |

### 3.3 Query coverage

| Capability | 02 | 03 | 04 | 05 | OoMyCache |
| --- | --- | --- | --- | --- | --- |
| get/first/find/pluck/value | ✅ (via `runSelect`) | ✅ | n/a | explicit closures | ✅ |
| count/sum/avg/min/max | ✅ | ✅ | n/a | explicit | ✅ |
| exists/doesntExist | ❌ (bypasses) | ✅ | n/a | explicit | ✅ |
| paginate/simple/cursor | ✅ (rows + count) | ✅ (paginator object) | n/a | explicit | ✅ page items + cached total (base-builder count fix) |
| `lockForUpdate`/`sharedLock` | ❌ cached (loses lock!) | ✅ excluded (`isCachable` checks lock) | n/a | n/a | ✅ never cached |
| chunk/lazy/cursor streaming | ⚠️ pages cached | ⚠️ chunk pages cached | n/a | n/a | ✅ explicitly bypassed |
| with eager loads | incident (no deps) | ✅ (constrained/nested 13.2.2) | n/a | arbitrary | ✅ with eager-state fingerprint |
| withCount/whereHas deps | ❌ | ✅ whereHas tags; ❌ withCount deps | n/a | ❌ | ✅ |
| join/subquery tables | ❌ | ✅ (deep instrumentation) | n/a | ❌ | ✅ via base-query table recorder (no full fork) |
| Custom builders/macros | ❌ | ✅ (proxy/reflection; complex) | n/a | n/a | ⚠️ documented: extend ours; doctor detects conflicts |

### 3.4 Safety, ops & compatibility

| Capability | 02 | 03 | 04 | 05 | OoMyCache |
| --- | --- | --- | --- | --- | --- |
| Cache store failure fallback | ❌ no try/catch | ✅ strict exception allow-list | ✅ self-healing/circuit breaker | ❌ | ✅ reads fail-open, invalidations loud |
| Corrupt payload handling | ❌ | ⚠️ | ✅ self-healing | ❌ | ✅ magic/version envelope + decoder registry |
| Stampede protection | ❌ | ❌ | ✅ single-flight/SWR (synchronous) | ❌ | P1 optional single-flight; jitter clamp |
| Large payload handling | ❌ | ❌ (forever entries, cardinality risk) | ✅ chunking protocol | ❌ | ✅ size guard + P1 chunk protocol |
| Events/observability | logging only | only `retrieved` replay | ✅ 6 events + dashboard (no auth default) | ❌ | ✅ events + stats + doctor/status/bench |
| Commands | ❌ | clear/publish | ✅ 6 commands | ✅ 4 commands | ✅ status/clear/doctor/bench/warm/prune |
| Filament-native UI | ❌ | ❌ | ❌ (HTML dashboard) | ❌ (Nova only) | ✅ optional bridge (page/widget/actions) |
| Multi-tenancy | ⚠️ SQL-only / multi-DB leak | ⚠️ manual prefix/db keying | ⚠️ manual namespace | ❌ collisions | ✅ tenant+panel keys, fail-closed |
| Tests (quality signal) | ❌ none on main; CI tests L9 (`origin/test`) | ✅ deep suite (~968 tests, Redis/Postgres/DynamoDB; Redis required/skipped locally) | ✅ large suite (array/file stores; no real Redis in CI) | ✅ 144 tests (single driver) | ✅ gates in plan/05 |
| PHP/Laravel | ^8.3 / L11–13 (claimed; L12/13 untested) | >=8.3 / L12–13 | ^8.1 / L8–13 | ^8.3 / L12–13 | ^8.3 / L12–13 |
| Code size / coupling | 3 files ~530 LOC | 28 files ~6k LOC + 2nd package | 4,802-line God class | 33 files ~1.2k LOC | small core, contracts at seams |

---

## 4. What each package contributes to OoMyCache (the "steal list")

> Full ranked lists with `path:line` are in each report's §5. This table is the consolidated engineering backlog input for [`plan/01`](../plan/01-features.md) §12.

| Source | Adopt (design) | Adapt (redesign) | Avoid |
| --- | --- | --- | --- |
| **01 cache-evict** | expiry-guarded batch delete; keyset pagination + mandatory `orderBy`; capability registry with typed refusal; completion event with stats | cooperative budget/pacing; 10-byte header expiry read (only with our own file envelope) | parsing Laravel FileStore internals; Partyline dependency; fixed `usleep` |
| **02 cacheable-model** | `newBaseQueryBuilder()` as a second interception seam; read-through at result-row boundary; chainable `withoutCache/withTtl/cacheIf`; write-surface flush | per-model options; identifier/id tags → per-row version stamps (not bookkeeping) | raw-SQL keys; tagless index-set fallback; flush-before-write; fake id-granularity tags; no try/catch |
| **03 model-caching** | dependency set derived from full query plan (tag-by-table → table-version vector); fail-safe unkeyable ⇒ uncached; eager-load constraint/state fingerprint; min-TTL across graph; key-collision self-check; driver-adapter seam | cooldown/debounce per-tag; Redis prefix-scoped clear; relation-fact memo | forked builders/relations/reflection; whole-payload `serialize` with `allowed_classes:true`; tag-required correctness; forever default TTL; 6k LOC complexity |
| **04 smart-cache** | versioned chunk protocol (wrapper+cleanup+race re-read); self-healing decoder registry; subtract-only jitter; bench shape (profiles/integrity/JSON) | single-flight lock (P1); SWR merged into single envelope; metadata lock only for optional registries; warmer contract → per-tenant jobs | managed-key index/tag-by-scan/dependency map as correctness; encryption; smart serialization; LRU memo driver; store-wide `clear --force`; synchronous SWR |
| **05 laracache** | explicit cache-entity DSL + per-event policy matrix (P1); status envelope (`fresh/stale/rebuilding`); business-time TTL presets; registry/command UX | debounce coalescing with per-namespace atomic tokens (fix its bug); per-entity store/queue overrides → store resolver | events-only invalidation; static kill switch; two registries; tombstone deletes; falsy-default coercion; class-basename keys |

---

## 5. Cross-cutting lessons (the traps that will decide our failure modes)

1. **Key correctness is where competitors actually fail.** Recent model-caching releases are dominated by "two queries shared one key" / "a write didn't flush a read" fixes (research/03 §6). Our single canonical fingerprint (compiled SQL + typed bindings + eager state + dependency versions + tenant/panel) with a fail-safe *unkeyable ⇒ uncached* path is the direct response.
2. **Tag-based correctness is a store constraint in disguise.** model-caching's non-taggable path flushes the entire store; cacheable-model's tagless fallback is an unbounded, raced, TTL-less index. Our version-namespace model makes invalidation O(1) on every driver and reduces tags to an optional purge convenience.
3. **"Correct until a bulk action/relation write" is the norm.** Events-only invalidation (laracache), builder-only invalidation (cacheable-model) and even model-caching's tag walk all miss pivot writes and some Filament mass paths. We handle events + builder writes + after-commit, and the Filament bridge closes pivot/mass-action gaps explicitly (plan/04 §5).
4. **Flush-before-write races are silent stale-data factories.** cacheable-model flushes before the statement; model-caching flushes inside the transaction. Our after-commit version bump plus read bypass inside transactions is the fix (plan/03 §9).
5. **Transport limits are real for ERP pages.** Memcached ≈1 MB, DynamoDB ≈400 KB, database BLOB limits, 250-byte keys. Hash keys, size guards, optional chunking, and arrays payload (~smaller than serialized models) are all needed before claiming "heavy data".
6. **The paginator COUNT is the most overlooked Filament win.** Both query-cache packages leave Filament's default `toBase()->getCountForPagination()` uncached (research/03 §7, plan/04 §2.2). Intercepting the base query builder is a small change with a large repeated-query payoff.
7. **Admin tables create devastating cardinality.** Every filter/sort/page/search combination is a new key; a default TTL/forever mismatch fills Redis. We skip search-term queries by default, default TTLs (not forever), jitter, size guards and a Filament status page so memory is observable.
8. **No package closes the tenancy/impersonation threat.** Multi-DB tenancy collides keys in cacheable-model; model-caching needs manual prefixing; laracache has class-basename collisions. Our fail-closed tenant/panel resolver is the differentiator that matters most for an ERP.
9. **Operability is a feature, not a nice-to-have.** model-caching has no doctor/bench/warm; cacheable-model has no commands; laracache has commands but no events; smart-cache has a dashboard with no auth default. Our v1 ships events, status/clear/doctor/bench (+bridge UI) and an authorization model.
10. **Maintenance/coupling risk is real.** A 6k-LOC internals fork plus a second same-author pivot package means every Laravel upgrade is a migration project. Sticking to documented extension points (`newEloquentBuilder`, `newBaseQueryBuilder`, model events, cache contracts) is a strategic choice, not just style.

---

## 6. The gap OoMyCache fills (mapped to the product)

| Market gap | Evidence | OoMyCache answer |
| --- | --- | --- |
| No Filament-native query cache | all five reports §7 | core transparent builder + optional bridge: tenant/panel keys, purge/warm UI, invalidation safety net (plan/04) |
| Tenant-safe invalidation | cacheable §6.5, model-caching §7, laracache §6.14 | fail-closed tenant/panel resolver; cross-tenant adversarial tests (plan/05 §6) |
| Multi-driver correctness | model-caching requires taggable stores; cacheable's tagless index is broken | O(1) table-version namespaces, tags only as optimisation; driver matrix CI |
| Transaction-safe writes | model-caching §6, cacheable §6.3 | reads bypass in transactions; invalidation after commit (ADR-5) |
| Filament COUNT/cache gap | model-caching §7, plan/04 §2.2 | base query-builder interception caches `getCountForPagination()` |
| Relationship/pivot invalidation | cacheable §6.2; model-caching withCount gap | table recorder + relation overrides + bridge pivot flush |
| Bounded memory for ERP admin tables | model-caching §6 (forever, cardinality), laracache §6.17 | TTL defaults, search skip, size guard, jitter, stats UI |
| Observability/operability | smart-cache dashboard unauthenticated; others have none | events + commands + Filament page + authorization |
| Safe Laravel 13 serialization story | Laravel 12/13 `cache.serializable_classes=false`; model-caching uses `allowed_classes:true`; cacheable stores raw rows | arrays payload default (no object unserialize); models opt-in with allow-list |
| Modern support matrix | cacheable's L12/13 untested; model-caching L11 dropped | Laravel 12/13, PHP 8.3–8.5, Windows CI, Filament 5/Livewire 4 lane |

---

## 7. Competitive positioning (what we should say publicly, honestly)

- **vs `mike-bronner/laravel-model-caching`:** "We learned from the most battle-tested package in the category — and built the pieces it never had: after-commit invalidation, all-driver O(1) invalidation, tenant/panel safety, Filament-native observability, bounded payloads, and no fork of Eloquent internals." Never claim theirs is wrong; claim ours is safer for multi-tenant admin panels.
- **vs `elipZis/laravel-cacheable-model`:** "Same one-trait ergonomics, but keys/namespace/relations/transactions are designed for ERP correctness." Avoid disparaging its size (it's a useful minimal reference).
- **vs `mostafaznv/laracache`:** "Automatic where it can be, explicit entities where you need them" — laracache's DSL is a P1 escape hatch, not a competitor to dismiss.
- **vs `iazaran/smart-cache`:** complementary: we reuse the chunking/single-flight ideas for query payloads; they remain a general value-cache optimizer.
- **vs `vectorial1024/laravel-cache-evict`:** recommended ops companion for `file`/`database` drivers; we mimic guarded-delete/capability-refusal lessons in our prune command.

---

## 8. Source notes, caveats & what was not verified

- **Commits/tags:** 01 `f41952a`/2.1.2, 02 `8fa6e4a`/v0.6.0+3, 03 `84d5b2e`/13.2.2, 04 `c526854`/1.17.0, 05 `2c18cf3`/3.1.0.
- **Not executed:** no package test suite was run (clones lacked `vendor/`; read-only analysis). Test-quality claims are based on reading test files and CI configs; the CI-reality gaps found in 02 are a source-reading finding, not a local failure.
- **Not independently verified:** download/star counts (external snapshots; smart-cache's were unavailable from the research box and come from the LaraPlugins snapshot); GitHub issue/PR contents (commit messages only); runtime Filament panel behaviour (plan/04 marks its unverified items explicitly).
- **Platform annex:** Laravel 13 cache-layer behaviour (store/tag/serialization/lock semantics) is summarised in [`research/07-laravel-cache-internals.md`](07-laravel-cache-internals.md); Filament/Livewire behaviour is verified in [`plan/04`](../plan/04-filament-compatibility.md).
- **Re-run trigger:** any upstream release that changes key/invalidation/tagging code invalidates the corresponding report; re-validate the matrix before quoting it publicly.
