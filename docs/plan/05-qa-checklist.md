# 05 — OoMyCache QA Checklist & Release Gates

> **Status:** v1.0 proposal. A phase in [`06-roadmap.md`](06-roadmap.md) is **not done** until its gates here are green and the evidence is attached to the PR/release.
> **Reads with:** [`03-architecture.md`](03-architecture.md) (what is being proven), [`04-filament-compatibility.md`](04-filament-compatibility.md) (Filament scenarios).

---

## 0. Quality principles

1. **Correctness is the feature.** A faster cache that shows yesterday's stock count is a failed package. Stale-data bugs and tenant leaks are release blockers, not "known issues".
2. **Prove the invalidation algebra, don't just smoke-test it.** Every write path × read path that could go stale gets a test (an adversarial matrix, not examples).
3. **Test observable behaviour, not implementation details.** Tests use public APIs (`Model::query()`, `save()`, Filament Livewire pages), not internal method mocks. (Skeleton `package-testing` skill.)
4. **The package test suite must pass on the full support matrix**, including Windows. Filament Lanes are an additional, separate CI job so the core stays buildable without Filament.
5. **Every fixed stale/leak bug gets a regression test named after the scenario** and a CHANGELOG entry.

---

## 1. Automated gates (must all pass before merge)

| Gate | Command | Scope | Gate level |
| --- | --- | --- | --- |
| Formatting | `composer lint:check` (Pint) | whole package | Required in CI every lane |
| Static analysis | `composer analyse` (Larastan/PHPStan, skeleton level 7) | `src`, `config`, `database`, `routes` | Required; target level 8+ before v1.0 |
| Type coverage | `composer test:types` (`--type-coverage --min=100`) | package | Required |
| Architecture tests | `composer test:unit` includes `tests/ArchTest.php` | arch rules | Required; must be extended (§8) |
| Unit tests | `vendor/bin/pest tests/Unit` | pure logic: key builder, fingerprint, canonical bindings, config resolution, classifier, payload codecs | Required |
| Feature tests (core) | `vendor/bin/pest tests/Feature` | Testbench + SQLite + array/file/database stores | Required |
| Driver matrix feature tests | `vendor/bin/pest --group=drivers` | Redis/Memcached when services available; file/database always | Required stable lane; best-effort on PRs |
| Filament E2E | `vendor/bin/pest tests/Filament` (separate dev-dependency lane) | Filament 5.11 + Livewire 4.4 + Testbench | Required for Filament-touching PRs; nightly on main |
| Bench smoke | `php artisan oomycache:bench --smoke` | cold/warm ratio sanity, payload sizes | Required informational in CI; thresholds reviewed manually (machine variance) |
| Docs build/lint | markdown link check (P2) | docs + README | Recommended |

> The skeleton already ships `composer test` = analyse + lint + type coverage + pest. Keep it the single "green button"; add test groups rather than new scripts where possible.

---

## 2. Test suite layout (planned)

```
tests/
├── TestCase.php                     # Testbench + package provider; helpers to reset cache/version state
├── Pest.php                         # uses(TestCase) + per-directory groups
├── ArchTest.php                     # extended package architecture rules (§8)
├── Unit/
│   ├── Key/FingerprintTest.php
│   ├── Key/BindingCanonicalizerTest.php
│   ├── Key/ClosureFingerprinterTest.php
│   ├── Config/ResolutionPrecedenceTest.php
│   ├── Config/ValidationTest.php
│   ├── Query/ClassifierTest.php
│   ├── Payload/ArrayPayloadCodecTest.php
│   ├── Payload/ModelPayloadCodecTest.php
│   ├── Payload/CompressionTest.php
│   ├── Invalidation/VersionStoreTest.php
│   └── Support/CapabilityProbeTest.php
├── Feature/
│   ├── Read/GetFirstFindTest.php
│   ├── Read/AggregatesTest.php
│   ├── Read/PaginationTest.php
│   ├── Read/PluckValueExistsTest.php
│   ├── Read/RouteBindingTest.php
│   ├── Read/SearchSkipTest.php
│   ├── Read/StreamingBypassTest.php
│   ├── Invalidation/ModelEventTest.php
│   ├── Invalidation/MassWriteTest.php
│   ├── Invalidation/TransactionTest.php
│   ├── Invalidation/RelationDependencyTest.php
│   ├── Invalidation/WithCountTest.php
│   ├── Invalidation/WhereHasTest.php
│   ├── Invalidation/PivotTest.php
│   ├── Invalidation/SoftDeleteTest.php
│   ├── Payload/ArraysRoundTripTest.php
│   ├── Payload/ModelsRoundTripTest.php
│   ├── Payload/CorruptPayloadTest.php
│   ├── Payload/OversizePayloadTest.php
│   ├── Tenancy/TenantIsolationTest.php
│   ├── Tenancy/PanelIsolationTest.php
│   ├── Safety/StoreFailureTest.php
│   ├── Safety/InvalidationFailureTest.php
│   ├── Safety/ConsoleBypassTest.php
│   ├── Commands/StatusClearDoctorTest.php
│   └── Events/EventsTest.php
├── Drivers/
│   ├── FileStoreTest.php
│   ├── DatabaseStoreTest.php
│   ├── RedisStoreTest.php
│   └── MemcachedStoreTest.php
└── Filament/                        # separate lane/dev-deps
    ├── TableIndexCacheTest.php
    ├── TableFilterSearchTest.php
    ├── BulkActionInvalidationTest.php
    ├── RelationManagerPivotTest.php
    ├── WidgetAggregateTest.php
    ├── TenantSwitchTest.php
    ├── PurgeUiAuthorizationTest.php
    └── PagePaginationTest.php
```

Testbench workbench models cover: `Product` (cacheable), `Category` (belongsTo), `Tag` (belongsToMany + pivot), `Review` (hasMany), `Image` (morphOne), `User` (auth), plus an uncacheable model. Use factories and small fixtures; no production data.

---

## 3. Core correctness test matrix

Legend: **P0** release blocker · **P1** required before v1.0 stable / next minor · **P2** follow-up.

### 3.1 Read paths

| # | Scenario | Assertion |
| --- | --- | --- |
| R1 P0 | Second identical `get()` within TTL | DB query count 0 on second call; same data and model class |
| R2 P0 | `first()`, `find()`, route-model binding | Cached; same attributes/casts/exists |
| R3 P0 | `count()`, `exists()`, `sum()`, `avg()`, `min()`, `max()` | Cached scalar; correct after writes |
| R4 P0 | `pluck()`, `value()` | Cached arrays/scalars; no model payload |
| R5 P0 | `paginate()` page 1/2, per-page change, page change | Distinct keys; identical items/total to live query; paginator `path`/`queryString` correct |
| R6 P0 | `simplePaginate()` and `cursorPaginate()` | Correct items/hasMore/nextCursor round-trip |
| R7 P0 | Empty result set | Returned correctly; cached only if negative caching enabled |
| R8 P0 | `dontCache()` | DB hit on every call; no store writes |
| R9 P0 | `cacheFor(60)` overrides model/global TTL | Cache::spy/TTL assertion |
| R10 P1 | `lockForUpdate()` read | Always DB; never cached |
| R11 P1 | Eager-loaded nested relations (`with('a.b')`), constrained closure | Cached payload includes relations; distinct closures → distinct keys (or bypass) |
| R12 P1 | `withCount/withSum/withExists` | Cached result correct; alias distinct from plain count |
| R13 P1 | Raw expressions/selects/bindings with enums/dates | Key stable across equivalent values; no collisions |
| R14 P1 | Same SQL on different models/connections | Different keys |
| R15 P2 | `having`, unions, subqueries | Cached correctly or bypassed with reason (never wrong) |
| R16 P0 | Filament-style table total: call `$query->toBase()->getCountForPagination()` twice on the same filtered query | second call executes 0 count queries; total correct; paginator built with cached total |
| R17 P0 | Query joining another table (direct `join` and `joinSub`) | cached; write to the joined table invalidates the entry |
| R18 P1 | `whereIn`/`whereExists` subquery tables | recorded as dependencies; writes to those tables invalidate, or query bypasses with a named reason |

### 3.2 Invalidation paths (the adversarial matrix)

For each write operation, caches of **all** read shapes that include the written model must be invalidated. Test both "same request sees fresh data" and "next request sees fresh data".

| # | Write | Cached reads to pre-warm | Assertion |
| --- | --- | --- | --- |
| W1 P0 | `$model->save()` (create + update) | `get/first/count/find/withRelation` | Next read executes DB and shows new value |
| W2 P0 | `$model->delete()` (hard) | all | Next read misses and returns updated result |
| W3 P0 | soft delete + restore | all | deleted_at filter respected; restore invalidates |
| W4 P0 | `Model::where(...)->update()` | get/count/paginate | mass write flushes model namespace |
| W5 P0 | `Model::where(...)->delete()` | all | same |
| W6 P0 | `increment/decrement` (instance + mass) | aggregates | new totals visible |
| W7 P0 | Related model saved (`Post` while `Author` with posts was cached) | `Author::with('posts')` | Author query misses and returns new posts |
| W8 P0 | `withCount` dependency write | cached author with count | count updates |
| W9 P0 | `whereHas` dependency write | cached filtered list | membership updates |
| W10 P1 | pivot `attach/detach/sync` | belongsToMany cached reads | invalidated via optional event package or explicit flush; documented path works |
| W11 P0 | write inside transaction that **rolls back** | pre-warmed caches | cache remains valid and unchanged (no over-invalidation required, but must not serve wrong data) |
| W12 P0 | write inside transaction that **commits** | pre-warmed caches | after commit, reads are fresh |
| W13 P0 | nested transaction commit | all | after-commit fires once at outer commit |
| W14 P1 | `truncate()` | all model entries | fresh |
| W15 P1 | write via queue worker (`Bus::fake`/dispatch sync) | all | after-commit semantics hold in worker |
| W16 P2 | raw `DB::table()->update()` | all | documented limitation: explicit flush works; test proves docs |
| W17 P0 | write to uncacheable model does not affect cacheable model | caches of other models | no unnecessary invalidation (perf assertion) |
| W18 P1 | two rapid writes | all | final state fresh; no crash/lock |
| W19 P0 | cached falsy/empty values (`0`, `'0'`, `''`, `false`, `[]`, `null`) | `get/count/value/pluck` | exact round-trip; never coerced to a default or treated as a miss (competitor footgun: falsy coalescing) |
| W20 P1 | optional invalidation coalescing/debounce | N writes within the window | at most one namespace bump; final state correct; no lost invalidation |

### 3.3 Dependency resolution matrix

| # | Scenario | Assertion |
| --- | --- | --- |
| D1 P0 | `with('posts')` invalidated by Post write | key embeds both versions |
| D2 P0 | nested `with('posts.comments')` invalidated by Comment write | recursive dependency tracked |
| D3 P0 | cyclic relations (`a->b->a`) | depth guard stops; no infinite recursion |
| D4 P1 | `morphTo`/`morphMany` with morph map | correct dependencies or documented bypass (strict policy) |
| D5 P1 | custom pivot model, `withPivot`, `wherePivot` | dependency includes both sides |
| D6 P1 | relation with constrained closure, different closures | keys differ (fingerprint) or bypass; never share |
| D7 P1 | `queryCacheOptions()['dependencies']` | explicit dependency write invalidates |
| D8 P1 | `cacheWith()` per query | same |
| D9 P2 | polymorphic type classes not resolvable | bypass reason emitted, query never wrongly cached |

### 3.4 Payload & store matrix

| # | Scenario | Assertion |
| --- | --- | --- |
| P1 P0 | arrays mode round-trip: attributes (raw), casts, appends, relations, nested collections, null relations | Values identical to live query; casts applied on access |
| P2 P0 | models mode round-trip under stores whose own serializable policy would reject objects (pre-serialised string) | Works on Redis/file/database; allow-list rejects foreign class payload |
| P3 P0 | corrupted cache value | Miss + re-query + no exception |
| P4 P0 | payload over `max_payload_bytes` | Query succeeds; value not stored; event reason |
| P5 P0 | compression on/off around threshold | Round-trip equal; header detection |
| P6 P1 | per-request memo: two identical queries in one request do not re-deserialize | Store read count 1; Octane reset simulation |
| P7 P0 | driver matrix: array, file, database, redis, memcached (services) | All invalidation/read tests pass |
| P8 P1 | non-taggable store with `invalidation=tags` | doctor error / safe fallback to version; no crash |
| P9 P1 | array store in tests (serialisesValues false) vs model payload | consistent |
| P10 P2 | large payload (100k rows) | bypass, no OOM, documented |
| P11 P0 | `invalidate()` vs `purge()` semantics | invalidate = version bump (entry unreachable, O(1)); purge = physical forget; both leave correct data |
| P12 P1 | business-time TTL presets (`end_of_day/week/month`) across timezones and DST boundaries | frozen-time expiry assertions; no off-by-one/DST bug |
| P13 P1 | payload freshness metadata (`generatedAt/expiresAt/status/deps`) | populated correctly and surfaced to UI; never used for correctness decisions |
| P14 P1 | chunked payload above the store limit (Memcached ≈1 MB / DynamoDB ≈400 KB): write order (chunks → wrapper), old-version chunk cleanup after wrapper write, missing-chunk race re-read, orphan chunks expire | round-trip identical; no mixed versions; no lazy non-`Collection` returned; Filament table typed consumers unaffected |
| P15 P1 | TTL jitter never extends the requested TTL (subtract/clamp only, deterministic per fingerprint in tests) | expiry ≤ requested TTL; no freshness-contract violation |
| P16 P1 | `cacheIf()` conditional caching (empty result / over budget / error marker) | predicate respected; no cache poisoning |
| P17 P1 | `oomycache:prune`: expiry-guarded batch delete keeps a key refreshed mid-sweep; keyset pagination ordered; prefix scoping; `--dry-run`; `--max-seconds`; typed refusal for Redis/Memcached; stats event | no TOCTOU deletions; bounded run time; correct exit codes |
| P18 P1 | optional single-flight (lock) on hot-key miss: one DB regeneration, waiters get value; lock unavailable → unlocked fallback | never fail a read because locks are unavailable; latency bounded by wait config |

### 3.5 Configuration & precedence

| # | Scenario | Assertion |
| --- | --- | --- |
| C1 P0 | global → preset → model → query precedence | effective TTL/flags correct (data provider test) |
| C2 P0 | invalid config values | exception in local/testing; safe fallback + warning in production |
| C3 P0 | guardrail floor: query/model config cannot enable caching in transactions/lock reads | still bypassed |
| C4 P0 | config caching compatibility (`config:cache` equivalent) | no closures used; package boots |
| C5 P0 | class-string bindings for TenantResolver/StatsRepository | container resolves; docs correct |
| C6 P1 | kill switch `OOMYCACHE_ENABLED=false` | no reads cached, bridge reports disabled, invalidation no-ops |
| C7 P1 | env vars only where documented | ArchTest: no `env()` outside config |

### 3.6 Safety/failure modes

| # | Scenario | Assertion |
| --- | --- | --- |
| S1 P0 | cache store throws on get/put | reads succeed via DB; warning logged once |
| S2 P0 | cache store throws on invalidation | event/log/exception per config; test both modes |
| S3 P0 | version token missing (new namespace) | treats as version 1; no stale |
| S4 P1 | invalidation failure simulated | doctor/status surfaces degraded state |
| S5 P1 | debug logging redacts sensitive bindings | password/email values not in logs |
| S6 P1 | logging disabled by default in production config guidance | debug config off |
| S7 P1 | store eviction of version key | entry unreachable → fresh data (safe direction) |
| S8 P2 | cache prefix collision with another app sharing Redis | package segments prevent collisions; documented |
| S9 P0 | array store masks production serialisation policy | run the store matrix with `serializable_classes=false` config; arrays-JSON payload round-trips; raw models mode rejected unless opted in |
| S10 P0 | `oomycache:clear` never calls `store->flush()`/Redis `flushdb()` | prefix/version-only invalidation; shared store untouched |
| S11 P1 | tag names validated (`[A-Za-z0-9_.-]{1,64}`) and namespaced | invalid user tag rejected/normalised; no reserved-substring collision |
| S12 P1 | Memcached key-length/item and DB `VARCHAR(255)` budgets | generated key < 200 B asserted; payload size guard per driver |

---

## 4. Filament end-to-end scenarios (Filament 5.11 + Livewire 4.4 lane)

Detailed technical mapping in [`04-filament-compatibility.md`](04-filament-compatibility.md). The E2E suite should assert **both data correctness and DB-query reduction**.

| # | Scenario | Assertion |
| --- | --- | --- |
| F1 P0 | Cold vs warm list page (50k rows, 25/page) | row data equal; warm executes **0 table data + 0 table COUNT queries** (base-builder count cached); residual direct-toBase paths asserted absent |
| F2 P0 | Pagination to page 2 and back | correct pages; cached; distinct keys |
| F3 P0 | Search/filter/sort | results correct; search not cached by default (or configured policy honoured) |
| F4 P0 | Edit a record from an action/form → redirect to list | list shows updated value immediately (no stale cached page) |
| F5 P0 | Bulk delete/force-delete/restore actions | affected rows disappear/appear on next render; cache invalidated |
| F6 P0 | Relation manager attach/detach action | relation list refreshed correctly |
| F7 P0 | Tenant switch (multi-tenant panel) | no data leak between tenants; keys include tenant |
| F8 P0 | StatsOverview/Chart widget with aggregate queries | correct KPI and cached between renders; invalidated on write |
| F9 P0 | Purge action authorization | non-authorized user cannot purge; action hidden/403 |
| F10 P0 | Global search | correct results; not cached by default; no leak across tenants |
| F11 P1 | Polling table (`pollingInterval`) | serves cache between intervals, correct after writes |
| F12 P1 | Lazy widget / deferred loading | no errors; cache works on deferred request |
| F13 P1 | Route model binding (view/edit page) | cached record; no stale after update |
| F14 P1 | Panel switch (two panels, different resources) | panel segment isolates keys |
| F15 P1 | Impersonation (if bridge supports) | correct visibility policy, no leak |
| F16 P1 | Custom theme package (`filament-liquid-glass`) installed | cache page/widget render correctly; no CSS/JS overrides required |
| F17 P1 | Livewire `#[Computed(cache: true / persist: true)]` misuse on panel components | bridge doctor warns; results equal; no cross-tenant computed leak (Livewire's computed cache has no tenant segment) |
| F18 P2 | Octane simulation / repeated boot | no static state leak between requests |

---

## 5. Performance benchmarks & thresholds

Tooling: `oomycache:bench` + a documented harness (`bench/README.md`) run against seeded data (e.g. 100k products, 1M order lines, 50k users). Measure:

| Metric | Method | Target / rule |
| --- | --- | --- |
| Cold vs warm query time per shape | bench command with query log | warm ≥ 5× faster for DB-bound queries; report absolute numbers per store |
| DB query count per Filament page render | Livewire test + query log | ≥ 70% reduction for opted-in resource list pages |
| Key construction cost | microbenchmark in unit test | < 0.1 ms typical (report, not hard CI gate) |
| Serialise/deserialise cost | bench per payload size 1/25/100/500 models | report; ensure no pathological O(n²) |
| Payload size | bench | 25-row page with relations typically ≤ 200–500 KB; compression report |
| Invalidations per write | event count | exactly one namespace bump per model write (after-commit rules) |
| Memory/CPU regression on cold path | bench | < 3% vs plain Eloquent |
| Cache hit ratio under synthetic ERP access pattern | bench loop | ≥ 80% for repeat table/widget workloads |

CI runs `--smoke` (ratio sanity only) because absolute timings are noisy; release candidates run the full benchmark on a fixed machine and results are pasted into the release PR.

---

## 6. Security test matrix

| # | Threat | Test |
| --- | --- | --- |
| SEC1 P0 | Cross-tenant data leak | Multi-tenant panel fixture: tenant A caches; switch to B; assert B never receives A rows across all read shapes, including widgets/global search |
| SEC2 P0 | Cross-user data leak (policies in SQL) | Two users with user-scoped global scope; assert separate keys/results |
| SEC3 P0 | Purge authorization in Filament page/actions | Guest/unauthorized user cannot purge; gate wiring asserted |
| SEC4 P0 | Foreign-class unserialise (models mode) | Write a payload containing a non-allow-listed class into the cache; read → treated as corrupt/miss, no gadget instantiation |
| SEC5 P0 | Purge/clear abuse via model binding | `oomycache:clear` signature/parameters non-injectable; no SQL/path traversal from `--model` |
| SEC6 P1 | Tag-name injection | User-supplied tag with `:`/`/`/wildcards sanitised or rejected |
| SEC7 P1 | Debug log leakage | Bindings with passwords/tokens redacted |
| SEC8 P1 | Cache poisoning via user-controlled bindings | Bindings only affect key hash, never SQL execution; test with malicious-looking values |
| SEC9 P1 | Serialized payload from stale schema | version mismatch → miss; test payload version bump |
| SEC10 P2 | APP_KEY leak scenario (Laravel 13 serializable_classes rationale) | documents models-mode allow-list; test that `allowed_classes` is never `true` |

---

## 7. Compatibility matrix (CI lanes)

| Lane | PHP | Laravel | Testbench | Extra | Frequency |
| --- | --- | --- | --- | --- | --- |
| Core lowest | 8.3 | 12.* prefer-lowest | 10.* | SQLite/file/database/array | every PR |
| Core stable | 8.3/8.4/8.5 | 12.*/13.* prefer-stable | 10/11 | same | every PR |
| Windows | 8.3 | 12.*/13.* | 10/11 | path handling | every PR (existing skeleton matrix) |
| Drivers | 8.4 | 13.* | 11 | Redis + Memcached services | PRs touching drivers; nightly |
| Filament 5 E2E | 8.4 | 13.* | 11 | filament/filament ^5.11, livewire ^4.4, SQLite | Filament-touching PRs + nightly |
| Filament 4 lane (P1) | 8.3 | 12.* | 10 | filament/filament ^4, livewire ^3 | nightly/weekly |
| MySQL/PostgreSQL pagination | 8.4 | 13.* | 11 | services | nightly (count/pagination semantics) |
| Octane smoke (P1) | 8.4 | 13.* | 11 | laravel/octane | nightly |

Rules: the core suite must **never** require Filament; the Filament lane installs it as an extra dev dependency in an isolated job. `composer test` remains the package's own gate.

---

## 8. Architecture tests to add (extends skeleton ArchTest)

- `Oomycache\Oomycache` (core namespaces except `Filament\`) must not depend on `Filament\*` or `Livewire\*`.
- No `env()` usage outside `config/`.
- Core source uses `declare(strict_types=1)` everywhere (already present).
- No `unserialize(` without an explicit `allowed_classes` argument in core (regex-based arch assertion or grep gate).
- No direct `Cache::` facade calls in core outside the cache layer (keep testability; use contracts).
- No `dd/ddd/exit` (already present).
- Contracts are interfaces only; every implementation class is `final` unless designed for extension.

---

## 9. Manual QA / release checklist (each release)

**Before tagging:**

- [ ] `composer test` green locally on PHP 8.5 and on the lowest supported PHP via CI.
- [ ] Full matrix CI green (including Windows); Filament E2E lane green.
- [ ] CHANGELOG entry written for every user-visible change.
- [ ] Docs updated: config reference, invalidation model, Filament cookbook, upgrade notes.
- [ ] Fresh install in a clean Laravel 13 + Filament 5 app: `composer require`, publish config, add trait, page loads; verify cache hits via debug command.
- [ ] Upgrade path from previous tag tested in a copy of the dogfood ERP (composer update + migrate nothing + smoke pages).
- [ ] Verify `php artisan config:cache` + `optimize` still boot correctly.
- [ ] Verify the package does nothing harmful when the cache store is `null`/`array` in production config (documented).
- [ ] Security checklist §6 reviewed; no debug mode enabled by default.
- [ ] Bench numbers recorded in the release PR (cold/warm, payload sizes).
- [ ] No stale TODOs blocking user-facing docs; README examples tested.
- [ ] Version bump + tag prepared (do not push/tag without user approval — skeleton `package-release` skill).

**After release:**

- [ ] Packagist webhook processed; `composer require` from the public package works.
- [ ] Demo app deployed/updated with the new tag.
- [ ] Announcement drafted (Laravel News / Filament community / X / Reddit) only after user sign-off.
- [ ] Watch issue tracker for 72h: stale-data reports are hotfix priority.

---

## 10. Bug severity & response policy

| Severity | Definition | Example | Response |
| --- | --- | --- | --- |
| Critical | Wrong/leaked data, data corruption risk, security | tenant leak, stale financial total, unserialise gadget | hotfix release + disclosure note, pause feature work |
| High | Core feature broken / crash | Filament page 500, pagination wrong | fix within days, regression test |
| Medium | Correctness limited to documented edge / perf regression | closure fingerprint bypasses cache, slower cold path | next minor |
| Low | Docs, DX, observability gaps | unclear doctor output | backlog |

Every Critical/High fix requires: regression test, CHANGELOG entry, and (if data-affecting) a note in the README's "known constraints" section.

---

## 11. Definition of Done (per feature/PR)

- [ ] Behaviour implemented behind the documented config/contract (no undeclared magic).
- [ ] Unit tests for pure logic + feature test for observable behaviour + failure-mode test.
- [ ] Arch rules respected; Pint + PHPStan + type coverage green.
- [ ] Docs updated in `docs/` + README if user-facing.
- [ ] No new `Cache::`/Eloquent internal coupling without an ADR line in plan/03.
- [ ] CHANGELOG entry.
- [ ] If it touches invalidation/tenancy/payload: adversarial matrix rows updated and cross-checked in plan/05 §3.
