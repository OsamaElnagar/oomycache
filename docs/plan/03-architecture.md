# 03 — OoMyCache Architecture & Design Considerations

> **Status:** v1.0 proposal (implementation contract)
> **Reads with:** [`01-features.md`](01-features.md), [`02-configuration.md`](02-configuration.md), [`04-filament-compatibility.md`](04-filament-compatibility.md), [`research/07-laravel-cache-internals.md`](../research/07-laravel-cache-internals.md).
> **Evidence rule:** framework behaviour in this document was checked against the installed Laravel 13.40 vendor tree; Filament/Livewire claims are in plan/04. Items marked **⚠ validate** are POC spikes in the roadmap.

---

## 0. Goals, constraints and quality attributes

| Priority | Attribute | Architecture consequence |
| --- | --- | --- |
| 1 | **Correctness** — no stale ERP data, no cross-tenant leak | Version-namespaced invalidation; dependency-aware keys; guarded context bypasses; arrays payload default (safe against class unserialisation policy). |
| 2 | **Compatibility** — Laravel 12/13, PHP 8.3+, Filament 5, Livewire 4, any store | Zero framework forks; documented extension points only; core has zero Filament/Livewire dependency; capability-probing store layer. |
| 3 | **Performance** — reduce repeated DB work in Filament panels | Cache paginated pages + totals + aggregates; per-request memo; optional compression; cheap keys; no per-request DB writes. |
| 4 | **Operability** — trust and debug | Events, doctor/status/clear/bench, Filament UI, deterministic bypass reasons, safe failure modes. |
| 5 | **Evolvability** — survive Laravel 14 / Filament 6 | Contracts for every variable behaviour; no interception of private internals; ADR record for every risky choice. |

**Non-violable invariants (tested by ArchTest + QA):**

1. No `env()` outside config; no Filament class referenced from core `src/` outside the `Filament/` namespace.
2. Core never requires `filament/*` or `livewire/*` at runtime.
3. Every cache entry's key contains the version tokens of **all** data namespaces it depends on.
4. A write that cannot bump its namespace successfully must **not** leave a cacheable stale entry reachable (fail-safe direction).
5. No query is cached inside a transaction, with `lockForUpdate`, or with streaming semantics.
6. The package never `unserialize()`s arbitrary classes outside an explicit allow-list.

---

## 1. Component map (planned `src/` tree)

```
src/
├── Oomycache.php                        # facade target: flush, status, withoutQueryCache()
├── OomycacheServiceProvider.php         # bindings, config merge, event listeners, commands, bridge boot
├── Concerns/
│   └── HasQueryCache.php                # trait: newEloquentBuilder(), flush helpers, options hooks
├── Builder/
│   ├── QueryCacheBuilder.php            # extends Illuminate\Database\Eloquent\Builder (the heart)
│   └── QueryCacheQueryBuilder.php       # extends Query\Builder: Filament table totals + join/subquery table recording
├── Contracts/
│   ├── QueryCacheable.php               # marker/marker+options interface implemented by the trait
│   ├── KeyGenerator.php
│   ├── QueryClassifier.php
│   ├── DependencyResolver.php
│   ├── InvalidationStrategy.php
│   ├── PayloadSerializer.php
│   ├── TenantResolver.php
│   ├── StatsRepository.php
│   ├── CacheStoreResolver.php
│   └── WarmableQuery.php                # P1
├── Query/
│   ├── QueryContext.php                 # immutable value object: SQL, bindings, model, eager loads, flags
│   ├── QueryFingerprint.php             # canonical array + hash
│   ├── CacheDecision.php                # cache/hit/miss/bypass + reason enum
│   ├── QueryClassifier.php              # default implementation (policy-driven)
│   ├── ClosureFingerprinter.php         # deterministic-ish hash of eager-load closures
│   └── PaginatorFactory.php             # rebuild paginators from cached parts
├── Cache/
│   ├── VersionStore.php                 # read/bump version tokens (all drivers)
│   ├── InvalidationManager.php          # event/write-path → namespace bumps (after commit)
│   ├── VersionInvalidation.php
│   ├── TagInvalidation.php              # opportunism when store is taggable
│   ├── CacheStoreCapabilities.php       # probing: tags/locks/serializer/limits
│   └── CacheGuard.php                   # transactions, locks, console, payload limits
├── Payload/
│   ├── ArrayPayload.php                 # default: attributes + relation tree + rehydration
│   ├── ModelPayload.php                 # opt-in: internal serialisation with allow-list
│   ├── ChunkWriter.php                  # versioned chunk keys for > driver-limit payloads (P1)
│   ├── ChunkReader.php                  # race-safe re-read; corrupt/missing chunk → miss
│   ├── Compressor.php
│   └── MemoizedPayloads.php             # per-request, Octane-safe
├── Relations/
│   ├── DependencyRecorder.php           # wraps withCount/withSum/whereHas… overrides
│   └── RelationResolver.php             # relation name → model classes (noConstraints)
├── Events/
│   ├── QueryCacheHit.php
│   ├── QueryCacheMissed.php
│   ├── QueryCacheBypassed.php
│   ├── QueryCacheInvalidated.php
│   └── QueryCacheCleared.php
├── Console/Commands/
│   ├── StatusCommand.php
│   ├── ClearCommand.php
│   ├── DoctorCommand.php
│   ├── BenchCommand.php
│   └── WarmCommand.php                  # P1
├── Exceptions/
│   ├── InvalidConfigurationException.php
│   └── CacheStoreUnavailableException.php
└── Filament/                            # optional bridge; loaded only when Filament exists
    ├── OoMyCachePlugin.php
    ├── Pages/QueryCache.php
    ├── Widgets/QueryCacheStats.php
    ├── Actions/ClearModelCacheAction.php
    └── Concerns/HasQueryCacheActions.php
```

This is deliberately **not** a framework replacement: it is one thin builder subclass, one trait, a manager, and support services. Every added abstraction must be justified by an extension point a real user needs (per skeleton `AGENTS.md` guidance).

---

## 2. Read-path lifecycle (cache hit / miss)

```
$query = Product::query()->where(...)->with('category')          # Filament/Tables/Widgets do exactly this
        │
        ▼
QueryCacheBuilder::get()                                         # overridden in our subclass
        │ 1. guard checks: enabled? model cacheable? in transaction? lockForUpdate?
        │                    streaming? blocklisted model/pattern? payload size risk?
        │ 2. $scoped = $this->applyScopes()                       # identical data set for key + execution
        │ 3. QueryContext::capture($scoped, $columns)             # sql, bindings, model, connection,
        │                                                        # eager loads, aggregate/whereHas deps,
        │                                                        # pagination intent, salts
        │ 4. classifier->decide(context)                          # CacheDecision{cache|bypass, reason}
        │ 5. keyGenerator->build(context, versions, segments)      # hash(sql+bindings+deps+versions+tenant+salt)
        │ 6. payload = memo->get(key) ?? store->get(key)           # per-request memo first
        │      ├── HIT  → deserialize/build collection/paginator → dispatch QueryCacheHit → return
        │      └── MISS → execute parent::get()/first()/count()… (real DB query)
        │                 → enforce max_payload_bytes (bypass only, never fail the request)
        │                 → serializer->serialize(result)
        │                 → store->put(key, payload, ttl+jitter)
        │                 → dispatch QueryCacheMissed → return result
        ▼
Filament/Livewire receives the same Collection/Paginator/Model shape it always expected
```

Why this works transparently:

- `Model::newQuery()` → `newEloquentBuilder()` returns **our** `QueryCacheBuilder`, so *every* Eloquent read through the model (resources, tables, widgets, route bindings, `load()` where applicable, global search) goes through the overridden entry point. Framework internals are untouched and typed against `Illuminate\Database\Eloquent\Builder`, which we extend.
- `get()` applies global scopes via `applyScopes()` exactly once for the key and uses the same scoped builder for execution, so the key matches the SQL that runs (verified against `Builder::get()`: vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:926-940).
- No SQL parsing hacks: `toSql()` + `getBindings()` from the scoped builder is the canonical data signature.

### 2.1 Overridden read methods (and what each does)

| Method | Cache behaviour on miss | On hit |
| --- | --- | --- |
| `get($columns)` | parent query + eager load; cache models/arrays | rebuild `Eloquent\Collection` |
| `first($columns)`, `firstOrFail($columns)` | parent then cache model/null | rebuild model; throw `ModelNotFoundException` from cached miss marker when appropriate |
| `find($id, $columns)` / `findOrFail` / `findMany` | canonicalise into a where-key query, then as above | rebuild model(s) |
| `sole($columns)` | parent then cache model/null/**multiple-found marker** | rebuild or rethrow cached exception type |
| `value($column)` / `pluck($column, $key)` | cache scalar/array directly (do not cache whole models) | return scalar/array |
| `count()` / `exists()` / `doesntExist()` | cache scalar/bool | return scalar/bool |
| `sum/avg/min/max($column)` | cache scalar | return scalar |
| `paginate(...)`, `simplePaginate(...)` | cache items + total separately, then build paginator | rebuild paginator from cached parts |
| `cursorPaginate(...)` | cache page items keyed by the effective cursor SQL/bindings | rebuild cursor paginator |
| `chunk/lazy/each/cursor/lazyById` | never cached | unaffected |
| `update/delete/increment/decrement/upsert/insert/truncate` (builder writes) | execute then bump model version (config: intercept_mass_writes) | n/a (write path) |

Implementation detail: the overrides must be **thin**. Complex logic (decision, key, payload) lives in services injected through the container, so each method reads like `return $this->cacheRead(__FUNCTION__, $args, fn () => parent::get($columns));`.

### 2.1.1 Base query-builder interception (P0 — Filament paginator totals + table dependencies)

`Model::newBaseQueryBuilder()` (protected extension point, vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:2015-2018) returns a `QueryCacheQueryBuilder extends Illuminate\Database\Query\Builder`. This is the second seam, and it is not optional for Filament performance:

- **Filament's default COUNT is cached.** Length-aware pagination *always* calls `$query->toBase()->getCountForPagination()` before `paginate()` (vendor/filament/tables/src/Concerns/CanPaginateRecords.php:61-68). `toBase()` returns `applyScopes()->getQuery()` (Eloquent/Builder.php:2095-2098), which is our base builder, so `getCountForPagination()` is intercepted and cached under a `count` intent. This closes the "warm table = N cached page selects + 1 uncached COUNT" hole (plan/04 §0.5, §2.2.6).
- **Join/subquery tables are recorded.** `from/join/leftJoin/rightJoin/crossJoin/joinSub/leftJoinSub/rightJoinSub/fromSub` overrides record table names into the query's `TableRecorder`. `Query\Builder::newQuery()` returns `new static` (vendor/.../Query/Builder.php:4614-4629), so subquery builders are our subclass and propagate the recorder automatically.
- **Context plumbing.** `QueryCacheBuilder::toBase()` attaches a `QueryContext` (model, eager/aggregate dependencies, tenant/panel segments, recorder) to the base builder before returning it; a base builder without context (direct low-level use) bypasses caching. `newQuery()` propagates the recorder.
- **No fork.** Every override calls `parent::*`; grammar, processor and scope behaviour are untouched.
- **Known POC risk:** `getCountForPagination()` has group-by/having branches (vendor/.../Query/Builder.php:3776-3812). Count caching must fall back to uncached for grouped/union pagination where the count query is not a simple aggregate; the POC proves the safe subset.

### 2.2 Route model binding

`Model::resolveRouteBindingQuery()` returns `$this->newQuery()` (vendor/.../Model.php:2629), i.e. our builder, so Filament edit/view pages (`/products/{record}`) and implicit bindings hit the cache for free. The binding key/value is in the SQL/bindings, so tenant/user scoping applied by route binding is part of the key.

### 2.3 Residual uncached paths (documented)

The base-builder interception closes Filament's default COUNT path, but these paths remain deliberately uncached: streaming (`chunk/lazy/cursor`), exports (size guard), `lockForUpdate`/`sharedLock` reads, search-term queries, reads inside transactions, and **direct base-builder reads not initiated by an Eloquent builder** (e.g. custom code calling `Model::query()->toBase()->get()`, which skips result hydration and is not a Filament read path). plan/04 §2.2 and plan/05 enumerate the E2E expectations.

---

## 3. Write-path lifecycle (invalidation)

```
$product->save()                     Product::where(...)->update()          $product->delete()/restore()
        │                                    │                                      │
        ▼                                    ▼                                      ▼
eloquent.saved: App\Models\Product   QueryCacheBuilder::update()            eloquent.deleted: App\Models\Product
        │                                    │                                      │
        └──────────────┬─────────────────────┴──────────────────────────────────────┘
                       ▼
              InvalidationManager::flushNamespace(Product::class, reason)
                       │
        ┌──────────────┴──────────────┐
        │ transaction? (DB::transactionLevel() > 0 and safety.invalidate_after_commit)
        │      yes → connection->afterCommit(fn () => bump)          # no flush of uncommitted work
        │      no  → bump now
        ▼
   VersionStore::bump(table)          # new random token at oomycache:version:{conn}:{table}
        │
        ├─ dispatch QueryCacheInvalidated (table/model, reason, trigger)
        └─ optional: flush tag bucket when invalidation strategy = tags (speed-up, not correctness)
```

Why version bumps (not key deletion):

- O(1) writes regardless of how many entries exist; no `SCAN`/`KEYS`/tag bookkeeping.
- Works identically on Redis, Memcached, database, file and array stores — no tag support required.
- Concurrency-safe: a reader that captured an old version token can only write an entry under the old token, which is unreachable after the bump (benign race).
- Entries are garbage-collected by TTL; `oomycache:clear` bumps a **global** token to orphan everything in O(1).

**Failure direction:** if the bump fails (store outage), the manager:
1. retries once;
2. emits a critical log/event and throws when `safety.log_invalidation_failures` is true (default) — because **a failed invalidation is a correctness incident**; the request that committed the write should not be reported successful while stale cache remains reachable. (Reads fail silently; invalidations do not — ADR-7.)
3. exposes `oomycache:doctor --stale` check to detect a failed bump (version-token write timestamp vs model `updated_at` sample) — P1.

### 3.1 `invalidate()` vs `purge()` vs `clear()` (API semantics, P0)

| Operation | Effect | Cost | Use |
| --- | --- | --- | --- |
| `invalidate($model)` / `flushQueryCache()` | writes a new version token; previous entries become unreachable and are TTL-garbage-collected | O(1) | default write path; operator "refresh now" |
| `purge($model)` / `purge($tag)` | physically removes namespaced entries when the store allows (tags / flush); a version bump is applied first as the correctness action | O(entries) / store-dependent | memory reclaim after huge namespaces, ops/debug tooling |
| `clear()` | bumps the global version token; every entry becomes unreachable | O(1) | emergency reset, deploy hygiene, Filament "clear all" |

Rules: (a) never represent invalidation by writing a tombstone/default payload — a miss is the only representation of "not cached" (a competitor's falsy-default coalescing turned real `0`/`[]` results into "not cached"); (b) payload freshness metadata (`fresh/stale/rebuilding`) is **observability only**, never a reason to trust or reject a value; (c) optional invalidation coalescing (P1, e.g. import storm) batches version bumps with a token per namespace and atomic store ops — it is a performance feature, never required for correctness.

---

## 4. Cache-key specification

### 4.1 Segments

```
oomycache:{prefix-version}:{tenant-segment?}:{panel-segment?}:{user-segment?}:{query-hash}
```

| Segment | Source | Notes |
| --- | --- | --- |
| `oomycache` | `config('oomycache.prefix')` | Short, configurable; the store's own `cache.prefix` is applied by Laravel on top. |
| `version` | `config('oomycache.version')` | Format/deploy version. A bump orphans all entries at key-resolution level. |
| `tenant` | `TenantResolver` (auto: Filament tenant) | `t{id}` or `none`; hashed if long/unsafe. |
| `panel` | Filament current panel id when bridge active | Prevents cross-panel assumption leakage when panels customise queries. |
| `user` | optional `tenant.include_user` | Off by default (`u{none}`); per-user partitioning is expensive and usually unnecessary because authorization filters are in SQL. |
| `query-hash` | `xxh128` of the canonical fingerprint (fallback `sha256`) | Where SQL, bindings, model, connection, dependency versions and salts live. |

Readability: the hash carries a short human prefix, e.g. `oomycache:1:t42:admin:9f3a…` plus debug mode writing a sidecar `oomycache:debug:{hash}` map (key → description) when `debug` is enabled. Debug maps are TTL-limited and never required for correctness.

### 4.2 Canonical fingerprint (inside the hash)

The fingerprint is a deterministic PHP array serialized with `serialize()` (not JSON, to preserve types) and hashed:

| Field | Value | Why |
| --- | --- | --- |
| `model` | FQCN | Different models can produce identical SQL. |
| `connection` | connection name | Multi-DB apps. |
| `sql` | `$scoped->toSql()` | Canonical query shape (Laravel emits deterministic SQL for identical builder state). |
| `bindings` | canonicalised bindings, in order | Values determine the result set. |
| `columns` | requested columns (`['*']` default) | Select lists matter. |
| `eager` | eager-load names + closure fingerprint + aggregate deps | Relations loaded at query time. |
| `aggregate_deps` | recorded `withCount/withSum/...` relation **tables** | Invalidation dependencies not visible in SQL alone. |
| `dependencies` | version tokens of every **table** the plan reads: root + base-builder recorded joins/subqueries + relation tables + explicitly declared deps | Invalidation namespace vector (see §5). |
| `eager_state` | compiled eager-load SQL+bindings fingerprint (after constraints/scopes) | Detects authorization/global-scope drift on the relation queries (§5.4). |
| `min_ttl` | minimum TTL across the base + dependency graph | Freshness bound for writes outside Eloquent (§5.5). |
| `tenant`/`panel`/`user` | resolved segments | Data isolation (also part of the key prefix, defence in depth). |
| `version` | package config version | Deploy safety. |
| `salt` | per-query `cacheKey()` + per-model `key_salt` | Domain-level invalidation without model writes. |
| `intent` | `get`/`count`/`paginate`/`value`… | A count and a page of the same SQL must never collide. |

**Binding canonicalisation** (order matters, values may be objects):

| PHP value | Canonical form |
| --- | --- |
| `null`, `bool`, `int`, `float`, `string` | as-is (floats via `sprintf('%.17g')` to survive serialize/precision) |
| `DateTimeInterface` / `Carbon` | `format('Y-m-d H:i:s.uP')` + timezone |
| `BackedEnum` | class + backing value |
| `UnitEnum` | class + name |
| `Illuminate\Database\Query\Expression` / raw | `(expression)` + raw SQL string |
| objects with `__toString` | string form |
| other objects | class name + `spl_object_id` is **not** usable → classifier bypasses cache with reason `unhashable-binding` (never silently share) |

**Eager-load closure fingerprints:** constrained eager loads (`with(['posts' => fn ($q) => $q->where(...)])`) mean the same `with('posts')` name can produce different data. The `ClosureFingerprinter` hashes: file, start/end line, static variables (canonicalised), bound object class (`$this` class only — not state), and the composed parent closure when nested. If a closure cannot be fingerprinted deterministically (eval'd/generated, anonymous-fn created in `__call` magic), the classifier **bypasses** the cache under `cache.relations = auto|strict` (reason `unfingerprintable-eager-load`) and only caches under explicit opt-in. This is the single most subtle correctness area in the category — competitor regression histories confirm it (see research/03 §6).

### 4.3 Key length and limits

- Hash is 32 hex chars (`xxh128`) → key typically < 120 bytes, far below Memcached's 250-byte limit; total length asserted in debug/doctor.
- Tenant/panel segments are normalised and truncated/hashed if they contain unsafe characters or exceed 16 bytes.
- Tag names (when used for purge) are validated against `[A-Za-z0-9_.-]` + length guard; user-supplied tags are namespaced (`oomycache:tag:{...}`) to avoid collisions with app tags.

### 4.4 Pagination keys

`paginate()` uses the base query SQL (which includes `limit/offset` only after `forPage()`); we key **page items** by the effective SQL+bindings (page/limit included) and **totals** by a separate `intent=count` fingerprint of the unfiltered-by-limit query. Both embed the same dependency versions, so one write invalidates both. `simplePaginate()` caches items (limit+1) and derives `hasMorePages` from the cached page size; `cursorPaginate()` keys by the effective cursor predicate SQL/bindings, so each cursor page is a distinct entry with a bounded lifetime.

---

## 5. Dependency resolution & invalidation algebra

### 5.1 Formal rule

> A cached result `R` derived from query `Q` is valid iff, for every namespace `n` in `deps(R)`, `version(n)` is unchanged since `R` was written. **Namespaces are tables**, keyed as `{connection}:{table}`. A write to table `T` bumps `version(T)`. A cached entry is served only if no table it reads has been written since it was cached.

`deps(R)` is computed at write time from:
1. the root model's table (and connection);
2. every resolved eager-load relation's table (recursively, cycle-safe, depth-capped by `safety.max_relation_depth`);
3. every resolved `withCount/withSum/withAvg/withMin/withMax/withExists` relation table (recorded by overriding those methods before delegating to `parent`);
4. every resolved `whereHas/whereRelation/whereBelongsTo/has/doesntHave` relation table (same technique);
5. every table recorded by the base `TableRecorder` (`from`/joins/subqueries — §2.1.1);
6. explicit per-model `dependencies` (class-strings are mapped to their tables) / per-query `cacheWith()`.

The version vector is embedded in the fingerprint; a bump therefore changes the key. Table-keying means two models sharing a table (STI, custom table names) share invalidation — safe over-invalidation, never a leak. A write bumps one table token plus, where relevant, pivot tables it modifies.

### 5.2 Relation resolution (no queries, no class dependence)

For each tracked relation name on the model, resolve the related **class and table** without executing SQL:

```php
Relation::noConstraints(fn () => $model->newInstance()->{$name}());   // relation object
$related = $relation->getRelated();                                   // model instance
$table = $related->getTable();                                        // version namespace
```

Nested relations (`a.b.c`) are resolved from the related model recursively. `morphTo`/`morphToMany` require the morph map and the stored type; when the type is ambiguous (e.g. the query eager-loads a morph that could point at any configured model), the resolver returns `unresolvable` and the classifier applies the `cache.relations` policy: `auto`/`strict` → bypass this query; `declared` → only cache with explicit dependencies; `none` → cache base-table-only queries. Pivot relations contribute both the related table and the pivot table to the dependency set (pivot-table writes need the optional listener / bridge flush described in §6).

### 5.3 Recorded aggregate relations and base-query tables

`withCount` adds a correlated subquery to the SELECT; SQL + bindings distinguish *results*, but the **invalidation dependency** (the counted relation's table) is not reliably recoverable from the compiled SQL. Since we own both builders, we override:

```
Eloquent: withCount, withSum, withAvg, withMin, withMax, withExists, withAggregate,
          has, orHas, doesntHave, orDoesntHave, whereHas, orWhereHas, whereDoesntHave,
          whereRelation, orWhereRelation, whereBelongsTo, whereMorphRelation
Base:     from, join, leftJoin, rightJoin, crossJoin, joinSub, leftJoinSub, rightJoinSub, fromSub
```

each records `(relationName|table, alias)` in a per-query `DependencyRecorder` and calls `parent::*`. No behaviour changes, no SQL reimplementation, no vendor fork.

### 5.4 Eager-load compiled-state fingerprint (scope/auth drift protection)

Eager-load closures are fingerprinted for the key (§4.2), but global scopes on the **related** model can depend on runtime state that is not in the base SQL (e.g. a tenant/user visibility scope, `withTrashed()` choices made outside the closure, or a soft-delete scope toggled by policy). A base query can therefore be logically identical while its eager-loaded relations must not be shared.

- At write time the payload stores `eagerFingerprint`: a hash per loaded relation of the **compiled** relation query (SQL + ordered bindings after constraints and global scopes), recursively for nested relations.
- On a hit, the fingerprinter recomputes the same signature (building relation queries costs no DB round-trips) and compares. On mismatch the entry is treated as a **miss** (strict default) or the relations are reloaded against the DB while base rows are reused (`cache.relations=auto` optimization, P1). It is never served.
- No-op Laravel closures (no constraints) are recognized and contribute nothing, so plain `with('a','b')` keys stay short.
- The fingerprinter is deterministic across deploys (no absolute paths) and driver-independent.

### 5.5 Min-TTL across the dependency graph

The effective TTL of an entry is the **minimum** of: base model TTL, every eager-loaded/aggregate dependency model's TTL, and the global default. This bounds staleness for data written outside the package (raw SQL, other services) without per-query tuning, and it is computed from the same dependency set as invalidation. Business-time presets are resolved to absolute seconds before the minimum is taken.

### 5.6 Invalidation matrix per write source (implementation view)

| Write source | Hook | Namespace bumped | Notes |
| --- | --- | --- | --- |
| Model instance create/update | wildcard `eloquent.saved: *` listener | written model's table | `saved` covers both; after-commit aware. |
| Model delete / soft delete | `eloquent.deleted: *` | written model's table | Soft delete is an update under the hood but fires `deleted`. |
| Restore / force delete | `eloquent.restored: *`, `eloquent.forceDeleted: *` | written model's table | |
| Eloquent builder mass update/delete/increment/decrement | overrides in `QueryCacheBuilder` | written model's table | Handles writes that bypass instance events. |
| `truncate()` | override where exposed by Eloquent builder; otherwise document | written model's table + global token (safe) | Truncate rarely typed on Eloquent builder → doctor warns; `oomycache:clear` fallback. |
| Relation eager-load dependency writes | model events on the related model | related model's table | Base query's key embeds the related version → invalid. |
| Pivot attach/detach/sync | `mike-bronner/laravel-pivot-events` listeners **if installed**; otherwise documented explicit `flushQueryCache()`; Filament bridge flushes after relation-manager actions | both relation tables + pivot table | Core no-ops safely when the optional event package is absent. |
| Raw `DB` writes | not interceptable | `oomycache:clear` / explicit invalidate by the developer | Documented limitation; `doctor` flags nothing at runtime (P2 static scan idea). |
| Queue/console writes | same events | same | After-commit tracked from the writing connection. |

---

## 6. Query classification engine

The classifier is a pure function `QueryContext → CacheDecision` with an enumerable bypass reason. Default order:

1. `config('oomycache.enabled')` false → bypass `globally-disabled`;
2. model not `QueryCacheable` → `model-not-cacheable` (builder is only ours for trait models, so mostly structural);
3. per-query `dontCache()` → `explicit-bypass`;
4. lock check: `$query->lock !== null` (pessimistic read) → `pessimistic-lock`;
5. transaction: `safety.skip_reads_in_transactions` and `transactionLevel() > 0` → `transaction`;
6. console: `safety.skip_in_console` and `runningInConsole()` → `console`;
7. excluded model / pattern → `excluded-model` / `excluded-pattern`;
8. streaming method → `streaming` (structural: those methods are not overridden to cache);
9. search-term detection (`search_terms=false` and SQL/bindings contain `LIKE`/`REGEXP`/full-text) → `search-query`;
10. unhashable binding / unfingerprintable eager-load / unresolvable dependency (policy) → `unhashable` / `unfingerprintable-eager-load` / `unresolvable-relation`;
11. payload size guard: after execution, if serialized size > `safety.max_payload_bytes` → do **not** store (result returned normally), reason `payload-too-large`;
12. cache store unavailable (probe failed) → bypass `store-unavailable`.

Every bypass dispatches `QueryCacheBypassed` (debug-level), and `oomycache:status --explain` can show counts by reason — this is how users answer "why is this page not cached?" without reading source.

---

## 7. Payload representation & serialization strategy

**Verified in Laravel 13 (research/07 §5, measured):** `config/cache.php` ships `'serializable_classes' => false`; the cache stores (`RedisStore`, `FileStore`, `DatabaseStore`, `DynamoDbStore`, `StorageStore`) pass it to `unserialize(['allowed_classes' => …])` (vendor/laravel/framework/src/Illuminate/Cache/RedisStore.php:534-544 etc.). With the default `false`, **raw object payloads read back as `__PHP_Incomplete_Class` on redis/file/database/storage/dynamodb**; `ArrayStore` keeps values in memory (`serializesValues=false`) and `MemcachedStore` relies on the extension's own serializer, which is why "works in tests / worked on Memcached" experiences differ. Model payloads were measured at **4.7× the size** of `toArray()` (1,602 B vs 340 B per row), and pre-encoded string payloads are returned byte-identical under `allowed_classes=false` (double-serialization cost ≈0.2 ms/1,000 rows, +0.01 % bytes). **This is a critical design constraint for any model-caching package in Laravel 12/13.**

### 7.1 Decision: `arrays` is the default, `models` is opt-in

| Mode | Payload | Works with default Laravel 13 cache config? | Fidelity | Speed |
| --- | --- | --- | --- | --- |
| `arrays` **(default)** | Raw attributes + class/connection metadata + recursive relation tree; rehydrated via `newFromBuilder()` + `setRelation()` on hit | ✅ (no object unserialisation) | High: raw DB values + relations; casts/appends computed lazily on access | Slightly slower than object unserialise→use; still ≪ DB |
| `models` **(opt-in)** | `serialize()`/`unserialize()` of models and collections **inside the package**, stored as a string value; explicit class allow-list on unserialise | ✅ because the store-level value is a string; package controls its own allow-list | Highest (cast cache, loaded relations, pivot objects preserved) | Fastest on hit; slightly larger payload |
| `models` via raw object caching (never default) | Raw objects straight into the store | ❌ unless the app configures `cache.serializable_classes` appropriately | Highest | Fastest |

- The default respects Laravel's security posture (no PHP object unserialisation from cache) and requires **zero config changes** for users — a major DX differentiator.
- `models` mode is equivalent in security terms to configuring `cache.serializable_classes` for the package's own payload, but the allow-list is narrow (base model classes from the query + resolved relation classes + `Illuminate\Database\Eloquent\Collection`, `Illuminate\Database\Eloquent\Relations\Pivot`, date/enum classes). It never becomes `allowed_classes=true`.
- `oomycache:doctor` prints the active mode, whether the store's own serialisation policy would break raw objects, and a recommendation.
- `payload.mode` can be forced; `safety.allow_serialized_models=false` hard-disables `models` mode for security-sensitive installations.

### 7.2 Arrays payload structure (core must-build)

```
payload = {
  v: 1,                        # payload format version (config version)
  model: Product::class,
  connection: 'mysql',
  meta: {                      # observability/freshness metadata (never trusted for correctness)
    keyHash: '9f3a…',          # self-check against hash collisions / stale formats
    generatedAt: 1762…,        # unix ts
    expiresAt: 1762…,          # unix ts
    minTtl: 60,                # effective TTL after min-across-graph (§5.5)
    tables: ['mysql:products' => 'ver-token', 'mysql:categories' => 'ver-token'],
    eagerFingerprint: 'sha256…',   # recomputed on hit; mismatch => miss (§5.4)
    status: 'fresh',           # fresh|stale|rebuilding — UI/diagnostics only
  },
  items: [
    { attributes: {...raw DB values...},
      relations: {
        category: { __kind: 'model', attributes: ..., relations: ... },
        tags:     { __kind: 'collection', items: [ ... ] },
      } },
    ...
  ],
}
```

Rehydration rules:

- `$model = $model->newFromBuilder($attributes, $connection)`; `exists = true`, `wasRecentlyCreated = false`.
- Relations: `setRelation(name, rehydrated value)`; `Collection`/`Model`/`null` handled; a `Pivot` sub-object is rebuilt as `Illuminate\Database\Eloquent\Relations\Pivot` with `$parent` set lazily (pivot attributes only — never serialise the registered pivot model class? If the relation uses a custom pivot model, store its class and `newPivot`-style rebuild — ⚠ validate in spike).
- Dates stay raw DB strings until accessed (casts apply on access, matching `newFromBuilder` semantics exactly).
- Appended attributes are *not* stored (they are computed at access, as normal).
- Relation autoload callbacks (`relationAutoloadCallback`) are intentionally null (same as fresh models).

This mode is also more robust across deploys: rehydration uses current model classes; removed/renamed relation names are detected and the hit is treated as corrupt (miss + warning) rather than fatal.

### 7.3 Compression (P1)

- Configurable threshold (default 64 KB) and algorithm (`gzcompress` → available via zlib; `zstd` if `ext-zstd` loaded; plain `serialize` otherwise).
- Compressed payloads carry a 2-byte header/magic for detection; decompression errors → cache miss + `QueryCacheMissed` reason `corrupt-payload` + warning log.
- Compression is a memory/CPU tradeoff: recommended only for large relation-heavy pages; benchmark command reports size/CPU delta.

### 7.4 Payload format versioning

- `v` embedded in payload and `config.version` embedded in key. If they mismatch (deploy in progress), entries are treated as misses.
- Corrupt payload → miss, delete the key, emit debug event. Never throw to the user.
- Payloads are stored as **pre-encoded strings** (JSON for arrays mode, package-controlled `serialize()` for models mode) so the store's own `serializable_classes` policy never sees an object; decoding happens in the package with an explicit allow-list/decoder registry (research/07 §5.3).

### 7.5 Chunked large payloads (P1 — required for the "heavy data" claim)

Drivers have hard item limits (Memcached ≈1 MB, DynamoDB 400 KB, database MEDIUMTEXT, storage/object stores); a relation-heavy Filament page can exceed them. Above `payload.chunking.threshold_bytes` (or chunk count/size), use a versioned chunk protocol adapted from smart-cache's proven design (research/04 §5.1):

- Write chunks under `oomycache:chunk:{fingerprint}:{chunkVersion}:{i}` (chunkVersion = random 4-byte hex per write), **all chunks first, wrapper last**: wrapper `{v, kind:'chunked', chunkVersion, count, keys[]}`.
- On read: wrapper then chunks. If a chunk is missing (concurrent rewrite), re-read the wrapper **once**; if the version changed, read the new chunk set. Only then treat as a miss. Orphaned chunks expire via TTL; never rely on a registry for correctness.
- Chunk sizing is driver-aware (stay under item limits with serialization headroom); compression wraps each chunk; the envelope/magic applies to the set, not each chunk.
- **Never return a lazy pseudo-collection** — Filament/Livewire consumers type-hint `Collection|array`; always rebuild the real Eloquent Collection.
- Chunking is a capacity feature: default off in v1.0 unless dogfooding proves the need; enabling it is benchmark-gated (read fan-out vs payload size).

---

## 8. Store & driver layer

### 8.1 Capability probing

`CacheStoreCapabilities` (probed once per store per process, not per query):

| Capability | How | Fallback |
| --- | --- | --- |
| Taggable | `$store instanceof TaggableStore` or probe `tags()` | version namespaces (always available) |
| Locks | store implements `LockProvider` / `Cache::lock` works | no single-flight (fine; correctness unaffected) |
| Flush | `flush()` available (all) | global version bump preferred anyway |
| Max key length | known per driver (Memcached 250), assertion in debug | hash/segments truncation (already safe) |
| Serializer restrictions | inspect `cache.serializable_classes` + store class | arrays mode (default) |

### 8.2 Invalidation strategies

- **`version` (default correctness layer, always active):** version tokens per model; key embedding; O(1) bump. Independent of driver.
- **`tags` (opportunism):** when the store is taggable, entries are additionally written under `oomycache:tag:{model}` (and user tags). Writes may `tags([...])->flush()` as a faster physical cleanup *after* the version bump (never instead of it). Operator purges by tag/tenant are possible; this is a convenience that cannot compromise correctness.
- **Custom `InvalidationStrategy` contract** for exotic setups (e.g. write-through to a shared version table across regions).

### 8.3 Cache-store failure handling

| Situation | Reads | Writes/Invalidation |
| --- | --- | --- |
| Store connection exception | Catch, log warning, execute DB (fail-silently default) | Retry once, then throw / critical log (config) |
| Version read fails | Bypass cache (never risk unknown namespace) | Bump attempted via same store |
| Payload corrupt / version mismatch | Miss + delete key + debug event | n/a |
| Payload too large | Miss (not stored), DB result returned | n/a |
| Lock unavailable | Skip single-flight, query normally | n/a |

### 8.4 Housekeeping: `oomycache:prune` for file/database stores

- `file` and `database` stores reclaim expired entries lazily on read and never proactively; versioned keys accumulate physically even though they are logically unreachable. On a busy ERP with `CACHE_STORE=database` (the workspace default) or `file`, this must be operated, not ignored (research/07 §4.1).
- `oomycache:prune {store?}` (P1) handles **database** with a safe implementation: keyset pagination with mandatory `orderBy`, expiry-guarded batched delete (`DELETE … WHERE key IN (…) AND expiration <= now`) so a key refreshed mid-sweep is never deleted, prefix scoping, `--dry-run`, `--chunk`, `--max-seconds`, and a completion event carrying stats for the Filament health widget (patterns from research/01 §5.1–5.2, 5.6–5.7).
- Drivers that manage their own eviction (redis, memcached, dynamodb) are **refused with a typed exit code** rather than silently swept; `file` is refused too — we do not parse Laravel's internal file format. Docs recommend `vectorial1024/laravel-cache-evict ^2.1.2` as the optional companion for `file`/`database` deployments (research/01 §9).
- Never `flush()` a shared store (`RedisStore::flush()` is `flushdb()` and ignores prefixes — research/07 §1); pruning is always prefix-scoped and idempotent.
- Schedule guidance: nightly `runInBackground`; default off until the ERP enables it.

---

## 9. Transactions, consistency & concurrency

- **Reads inside a transaction bypass the cache** (`safety.skip_reads_in_transactions` default true): a cached result may predate the transaction's uncommitted changes; serving it would be surprising in ERP flows (stock adjustments, invoice posting).
- **Invalidation after commit:** if a write happens inside a transaction, the namespace bump is deferred via `$connection->afterCommit()`. Rollbacks therefore never invalidate (perf win) and committed writes always do (correctness).
- **Nested transactions** follow Laravel's `transactionLevel()`; afterCommit callbacks fire at the outer commit.
- **Benign races** (documented, tested):
  - Reader captures version V, writer bumps to V2, reader stores entry under V → unreachable (correct).
  - Reader misses, writer bumps, reader stores under V → unreachable (correct).
  - Two writers bump simultaneously → latest token wins; intermediate tokens unreachable (correct).
- **No distributed lock needed for correctness.** Locking is only for stampede reduction (P1).
- **Read replicas:** if the model uses a read/write connection, queries after a write may hit a lagging replica. Document: `cache` has the same staleness exposure as an immediate read; for strong requirements use `afterCommit` invalidation + read-your-writes connection configuration. (P2: optional "flush on write-through" mode.)

---

## 10. Multi-tenancy, panels, guards & authorization threat model

**Threat:** a cache key that ignores tenant → tenant B can see tenant A rows. **Mitigation (defence in depth):**

1. **Tenant segment in the key prefix**, resolved by `TenantResolver`; when Filament is present the bridge auto-resolves `Filament::getTenant()` and the current panel. Unresolvable tenant while Filament tenancy is active → **bypass caching** (never "none").
2. **Tenant/user scoping is also in the SQL bindings** for well-behaved apps (global scope writes `where tenant_id = ?`), so the fingerprint differs too.
3. **Query-time authorization is never cached as a decision**: we cache the database result set; policies/`can()` checks and PHP-level filters still run per request. If a code path relies on PHP filtering of a shared result, that filtering still runs on the cached result.
4. **Per-user partitioning** (`tenant.include_user=true`) exists for pathological apps whose SQL is identical across users but whose visible rows differ (PHP filtering) — documented as a big memory cost, off by default.
5. **Panel segment** prevents cross-panel cache sharing when two panels customise queries (e.g. `getEloquentQuery()` overrides, different global scopes).
6. **Impersonation:** when a Filament impersonation session is active, the resolver may include the impersonator (config flag in plan/04) — by default the user segment is off, so impersonation relies on SQL scoping; plan/04 defines the verified policy.
7. **Purge authorization:** bridge actions check a gate (`filament.dashboard.can_purge`, default panel access only) — never "any authenticated user can nuke the cache".
8. **Tests:** cross-tenant isolation is an adversarial test group in plan/05; a leak is a release blocker.

Non-goals: we do not attempt to detect authorization logic executed outside SQL. If an application caches a query result *before* PHP-level row filtering, the package still returns the same superset — filtering must keep running (documented in the security chapter of README).

---

## 11. Octane & long-running processes

- No request state on singletons: version tokens are read from the store per request (optionally memoised per request via `app()->scoped()`/`Cache::memo`), never static properties that survive requests.
- `payload.memoize_per_request` uses a request-scoped memo (container scoped binding or `Cache::memo` wrapper) — Octane resets scoped bindings between requests, and the package registers an Octane `RequestReceived`/`RequestTerminated` listener when Octane is installed to clear any fallback static memo.
- Boot-time capability probe results are static (safe: store capability doesn't change per request).
- Event listeners are stateless; after-commit callbacks are per-connection (safe).
- Documented: `queue:work`/`octane` must be restarted on deploy (`queue:restart`, `octane:reload`).

---

## 12. Error handling & degradation matrix (summary)

| Failure | Behaviour | User-visible? |
| --- | --- | --- |
| Cache store down (read) | DB read, warning log once/interval | No |
| Cache store down (invalidate) | retry + critical log + event; throw if configured | Potentially 500 on write — deliberate (ADR-7) |
| Corrupt payload | miss + delete + warning | No |
| Unsupported payload (size) | bypass storing | No |
| Unresolvable relation/closure | bypass caching (policy) | No |
| Invalid configuration | exception outside production; safe fallback + warning in production | Only at deploy/admin |
| Serializer missing zstd | fallback gzip; warning | No |
| Filament/Livewire version mismatch (bridge) | bridge refuses to boot with clear log; core unaffected | UI error only if bridge registered |
| Base-builder count shape is non-trivial (groups/havings/unions) | count executed uncached (no cache write), result still correct | No |
| Model defines its own `newEloquentBuilder()`/`newBaseQueryBuilder()` alongside the trait | conflict detected at boot/`doctor`: explicit exception, never silent double caching | Admin |
| Corrupt or missing chunk | re-read wrapper once; version mismatch → miss; warning event | No |
| `invalidation=tags` forced on a non-taggable store | config validation/doctor warning; safe fallback to version mode | Admin |

---

## 13. Performance budgets & measurement

| Operation | Budget (target) | Notes |
| --- | --- | --- |
| Key construction | < 0.1 ms typical | hash + canonical bindings; measured in bench. |
| Cache read (Redis, ≤ 100 KB) | < 1–3 ms | dominated by network; local benchmarks in CI are indicative only. |
| Serialisation (arrays, 1000 models) | < 5 ms | arrays vs models benchmarked; lazy casts help. |
| Memory per cached page | guide at 200–500 KB max for 25–50 rows with relations; compression for bigger | `max_payload_bytes` guard. |
| Warm page query count | ≤ 1 cache read + auth/session queries | E2E asserts DB query count reduction. |
| Cold-path regression | < 3% | adding key computation/decision must be negligible. |
| Invalidation overhead per write | 1 cache write + 1 event | no tag scans on version strategy. |
| Key length budget | < 200 B worst case | Memcached 250 B / DB `VARCHAR(255)`; asserted in tests + doctor. |
| Payload size per row | arrays JSON ≈340 B vs serialized model ≈1,602 B (measured, research/07 §5) | Favour arrays for large tables; `max_payload_bytes` guard. |
| Chunk read fan-out | ≤ N+1 gets only above the chunk threshold | Chunk only when payload exceeds a driver limit; never for normal pages. |
| TTL jitter | subtract/clamp only, never extends the requested TTL | Deterministic per fingerprint in tests. |

Measurement tooling: `oomycache:bench` (cold vs warm per model/query, payload size, hit ratio under a synthetic loop), Laravel query log in integration tests, and a reproducible benchmark script documented in plan/05.

**When caching loses:** tiny/fast indexed queries (< 1 ms), very low reuse (search terms), huge payloads, write-heavy models. The classifier defaults and config guidance must say so; benchmarks must prove the break-even line.

---

## 14. Compatibility & coexistence

- **Core dependency surface:** `illuminate/support`, `illuminate/cache`, `illuminate/database`, `illuminate/console`, `illuminate/contracts` (aligned with Laravel 12/13) — no Filament, no Livewire, no vendor forks. (Final composer requirement list to be minimal; skeleton currently requires only `illuminate/support`.)
- **Coexistence with other cache packages:** the builder subclass is returned only for trait models. If `mike-bronner/laravel-model-caching` (or similar) is also installed and its trait is on the same model, both builders cannot be the returned class — doctor detects both traits and refuses with a clear message (fail fast rather than double-caching).
- **Filament/Livewire compatibility contract:** see plan/04. Core does not reference `Filament\*` outside `src/Filament/`; bridge is optional and auto-detected.
- **Laravel 14 readiness:** only documented Eloquent APIs (`newEloquentBuilder`, model events, builder methods), cache contracts, and `afterCommit` are used; deprecation sweeps run on each Laravel major via CI against `--prefer-lowest`/stable + nightly (P1 workflow).

---

## 15. Architecture Decision Records (ADR index)

| ADR | Decision | Alternatives rejected | Consequence |
| --- | --- | --- | --- |
| ADR-1 | Trait + `newEloquentBuilder()` subclass as the interception mechanism | Global DB listener; Eloquent Builder macros only; global scope; service wrapper | Transparency for Filament, no framework fork; opt-in per model. |
| ADR-2 | Version namespaces **keyed by connection+table** are the correctness layer; tags are an optimisation | Tags-only (Redis-only); flush-all on write; key enumeration; model-class-only namespaces (miss shared-table invalidation) | Works on all drivers; O(1) invalidation; shared tables invalidate safely. |
| ADR-3 | Opt-in per model | Cache everything automatically | Prevents silent staleness in un-audited models; still zero-config for opted-in Filament resources. |
| ADR-4 | Skip search-term queries and streaming APIs by default | Cache everything for max hit ratio | Avoids cache pollution/stampedes; table search stays correct and memory bounded. |
| ADR-5 | Invalidate after commit; bypass reads in transactions | Invalidate immediately; cache reads inside transactions | No dirty-read surprises in ERP write flows; tiny latency cost. |
| ADR-6 | `arrays` payload default; `models` opt-in with internal allow-list | Models-only like existing packages (breaks under Laravel 13 default `serializable_classes=false`); forced serializable_classes=true | Works out of the box and respects Laravel's security posture. |
| ADR-7 | Reads fail open to DB; invalidation failures are loud (log/throw) | Fail-open everywhere; fail-closed everywhere | Cache outages don't take down reads; stale data is treated as an incident, not silently ignored. |
| ADR-8 | Core requires zero Filament/Livewire deps; bridge is optional/auto-detected | Make Filament a hard dependency | Adoption by plain Laravel apps; stable core; panel features live in plan/04. |
| ADR-9 | No vendored/forked framework classes | Copy custom builder internals like some competitors | Laravel 14 readiness; simpler support matrix; smaller security surface. |
| ADR-10 | Every bypass has a named reason + event | Silent bypass | Debuggability ("why isn't this cached?") and testability. |
| ADR-11 | Three distinct operations: `invalidate()` (version bump, default on writes), `purge()` (physical cleanup, ops/debug), `clear()` (global version bump) | Conflating all three under "clear"/"delete"; tombstone-writes as invalidation (competitor failure mode: falsy defaults mistaken for "not cached") | Clear operational semantics; no permanent tombstone values; UI can recommend the cheap operation. |
| ADR-12 | Business-time TTL presets (`end_of_day/week/month`) are computed at write time in an explicit timezone, with DST tests | Pure "seconds remaining today" math without timezone handling | Dashboard aggregates expire at meaningful boundaries without off-by-one/DST bugs. |
| ADR-13 | Explicit cache-entity layer (P1) reuses the same InvalidationManager/version stores; no second event/observer pipeline | A separate observer/registry architecture (laracache) | One correctness model, one set of invariants, less maintenance. |
| ADR-14 | A base-query-builder subclass via `newBaseQueryBuilder()` intercepts `getCountForPagination()` and records join/subquery tables | Accept Filament's uncached COUNT every render; patch/patch-like Filament internals | Warm Filament tables lose their last hidden DB query; dependency tracking covers joins/subqueries without a full Query-builder fork. |
| ADR-15 | Eager-load **compiled-state fingerprints** are stored in the payload and rechecked on every hit | Fingerprinting only closures/names; trust scope state implicitly | No cross-user rows from scope-dependent eager loads; costs a few no-DB relation builds per hit. |
| ADR-16 | Min-TTL across the dependency graph | Per-query TTL only; forever entries | Bounded staleness for out-of-band writes; one simple rule. |
| ADR-17 | Key-collision self-check stored in the payload | Trust the hash blindly | Cheap insurance against collisions/stale formats. |
| ADR-18 | Streaming APIs (`chunk/lazy/each/cursor`) set an explicit cache-bypass marker | Treat their internal `get()` pages as normal reads | No unbounded per-offset cache entries. |
| ADR-19 | Payloads are stored as **pre-encoded strings** (JSON arrays / package-serialized models with allow-list) | Raw object values; forcing `cache.serializable_classes=true` | Works with Laravel 13 defaults; security posture preserved; measured +0.01 % bytes. |
| ADR-20 | `oomycache:prune` only for `database`; typed refusal for other drivers; docs recommend cache-evict for file | Scan/delete every store; parse Laravel's file format | Safe ops for file/database deployments without coupling to internals. |

---

## 16. Open questions / POC spikes (⚠ validate)

1. **Arrays payload rehydration fidelity** for: custom casts on attributes, custom pivot models, `BelongsToMany` with `withPivot`/`wherePivot`, morph relations, `loadMissing` after hit, `refresh()`, `$model->wasChanged()` semantics after hit (should be false), and JSON/encrypted casts. (Spike 1 — highest risk for ADR-6.)
2. **Serialisable policy (confirmed):** research/07 §5 measured that raw models/Collections break under `cache.serializable_classes=false` on redis/file/database/storage/dynamodb and that pre-encoded strings survive. Remaining: validate the `models`-mode allow-list round-trip on every store (including the Memcached extension serializer) and the JSON-array envelope on encrypted/JSON casts.
3. **Polyglot `xxh128`** availability on PHP 8.3 CI images; fallback hash performance.
4. **Eager-load closure fingerprinting** consistency across deploy (absolute vs relative file paths; opcache/preloading), and behaviour with arrow-function static vars.
5. **Cursor pagination** keying edge cases (cursor encoding, reversed order, `CursorPaginator` API for rebuild).
6. **Filament tenancy resolution** under multi-tenant panels, impersonation, and panel switch without logout (plan/04 evidence).
7. **Query-count assertions** in Livewire 4 test helpers (what counts as one render's DB work; boot queries excluded).
8. **Memcached tag semantics** (flush by tag cost/behaviour) if we allow `tags` mode there.
9. **Transaction-level detection** for read/write split connections (which connection's `transactionLevel()` to consult).
10. **Base-builder count semantics**: validate the simple `count(*)` subset vs group-by/having/union fallbacks; measure the real warm-query count on ERP tables.
11. **Custom builder conflicts**: a model that already defines `newEloquentBuilder()`/`newBaseQueryBuilder()` must get a clear doctor error; test trait/class collision forms (a competitor's regression suite covers this class of bug).
12. **TableRecorder coverage**: tables inside `whereIn`/`whereExists` closures, `fromSub`, nested joins and `joinSub` aliases; confirm `new static` propagation through all Laravel 12/13 subquery paths.
13. **Filament-added aggregates**: verify the table layer's `withCount`-style columns are recorded by our overrides (plan/04 §2.2.3 marked this POC).
14. **`Cache::memo()` interplay**: request-scoped memo must be purged by same-request invalidation (save → render in one Livewire request, plan/04 §6.5).

---

## 17. Build order (architecture-level)

1. Skeleton plumbing: config merge, contracts, capability probe, `QueryContext`/fingerprint unit-testable core (no Eloquent yet).
2. Both builders + trait: `QueryCacheBuilder` (`get()`/`first()`), `QueryCacheQueryBuilder` (`getCountForPagination()` + `TableRecorder`), arrays-JSON payload, version invalidation, model events (minimal vertical slice, includes a Filament-style count test).
3. Aggregates + pagination (cached page + total) + classification + guardrails.
4. Dependency tracking (relations, `withCount`/`whereHas`, base-builder tables, eager-state fingerprints, min TTL).
5. Payload modes/compression/chunking/memo + store capability tests on all drivers.
6. Transactions, concurrency, tenancy contracts, events, stats.
7. Commands (incl. prune) + Filament bridge (per plan/04) + doc site copy.
8. Benchmark + dogfood on the ERP, then freeze v1.

Full milestone/exit criteria live in [`06-roadmap.md`](06-roadmap.md); the gates for every step live in [`05-qa-checklist.md`](05-qa-checklist.md).
