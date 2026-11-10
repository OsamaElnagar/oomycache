# 03 — laravel-model-caching — Deep Analysis

> Repo: https://github.com/mike-bronner/laravel-model-caching · commit `84d5b2e7ddb4e277672189bd122cf7c5c1175fe4` · tag `13.2.2` (2026-10-30) · MIT (`composer.json:4`, `LICENSE`) · ~393k downloads (mission brief; Packagist JSON fetched 2026-11-09 exposed no aggregate counters, so not independently re-verified) · PHP `>=8.3`, `illuminate/* ^12.0|^13.0` (`composer.json:15-25`) · hard dep `mikebronner/laravel-pivot-events ^13.1` (`composer.json:17`) · 1,002 commits, 186 tags, 72 contributors since 2017-09-17 · src: 28 files / 5,983 LOC; tests: 363 files / 23,921 LOC; 151 fixture files · analyzed 2026-11-09.

## 0. TL;DR

- **What it is:** a transparent read-through cache for Eloquent. A trait (`GeneaLabs\LaravelModelCaching\Traits\Cachable`) or base class (`CachedModel`) swaps the model's Eloquent builder for `CachedBuilder`, caches `get/first/find/all/paginate/pluck/value/exists` plus `count/sum/avg/min/max/exists`, tags every entry with the model class, its table and every table the query touches, then flushes those tags from model events and from every builder write (`src/CachedBuilder.php:18`, `src/Traits/Buildable.php:111-254`, `src/CacheTags.php:66-88`).
- **Strongest idea:** invalidation is expressed as **tags derived from a full walk of the query**: root model class tag + table tag + eager-load related class tags + pivot table tags + join/subquery-where table tags + morphTo target tags (`src/CacheTags.php:66-88,90-146,272-388,538-543`). A write therefore invalidates every cached read of the written table — including reads from other models that join it — without any per-key bookkeeping.
- **Best UX:** add the trait and query normally; three independent opt-outs (`disableCache()` scope `src/Traits/ModelCaching.php:335-345`, `ModelCache::runDisabled()` `src/Helper.php:12-29`, `MODEL_CACHE_ENABLED=false`); per-model `$cachePrefix` / `$cacheTtlSeconds` / `$cacheCooldownSeconds`; `php artisan modelCache:clear [--model=]`; graceful degrade when Redis is down (`src/Traits/Caching.php:846-869`).
- **Biggest risk:** correctness is hand-built out of Eloquent internals — a 1,225-line key encoder (`src/CacheKey.php`), a 600-line tag walker, a forked builder pair, four forked relation classes, reflection copies of private builder state (`src/CachedBuilder.php:129-147`, `src/Traits/Caching.php:810-818`) and an external pivot-event fork. Git history is a stream of *"two queries shared one key and served each other's rows"* and *"a write did not flush a read"* fixes (13.1.8→13.2.2 alone: `#665 #664 #660 #656 #655 #654 #647 #646 #645 #640`), with no Filament/Livewire/tenant/user dimension, no stampede protection, and `rememberForever` as the default.
- **Verdict for us:** **ADAPT** the ideas (tag-by-table, fail-safe "unkeyable ⇒ run uncached", eager-load fingerprint, min-TTL across eager loads, driver-adapter seam); **AVOID** vendoring or extending it. 13.2.2 is the benchmark OoMyCache must beat on safety and Filament ergonomics — not the foundation to build on.

## 1. What it does & category

**Category:** Eloquent query-result cache (model-level read-through cache with tag invalidation). Not an HTTP/response cache, not a cache-store maintenance tool, not a Filament abstraction.

**Problem it solves:** repeated read queries on the same tables with identical SQL (`Post::with('comments')->where('published', true)->latest()->paginate(15)`) are served from the cache store; writes through Eloquent make those entries unreachable without the developer inventing keys or calling `Cache::forget`. README claims 100–900% fewer queries (`README.md:31-33`) — there is no benchmark artifact in the repo to substantiate it (see §8).

**Mechanism:** the model opts in; its `newEloquentBuilder()` returns `CachedBuilder` (Eloquent `Builder` subclass) wrapping a `CachedQueryBuilder` (Query `Builder` subclass); four relation factories return cached relation subclasses; reads are cached under `sha1(rawKey)` in tag sets; all writes that pass through the model or its builder flush those tag sets. Entities: 2,709 lines in the three core classes (`CacheKey.php` 1,225, `Traits/Caching.php` 884, `CacheTags.php` 600), 4 relation subclasses, 2 commands, 6 config keys, 1 facade, 1 external package.

**Maturity signals:** 9-year history, 186 releases, 1,000+ commits, Pest suite with Redis + Postgres + DynamoDB Local + Predis/Memcached CI lanes, PHPStan level 5 gate, Codecov. This is a heavily-battle-tested package — which is exactly why its commit log is the best available catalogue of query-cache failure modes.

## 2. Architecture & integration model

**Wiring.** Package auto-discovery only: `composer.json:55-61` registers `Providers\Service`; `boot()` merges `config/laravel-model-caching.php`, registers `modelCache:clear` + `modelCache:publish`, publishes the config under tag `config` (`src/Providers/Service.php:17-28`); `register()` binds `model-cache` → `Helper` (facade accessor `src/Facades/ModelCache.php:11-14`). No middleware, no global scope, no query listener: opt-in is per model, via `use Cachable` (`src/Traits/Cachable.php:9-16`) or `extends CachedModel` (`src/CachedModel.php:10-13`).

**Builder fork.** `ModelCaching::newEloquentBuilder()` guards against recursion with a per-instance `spl_object_id` flag (the fix for a `Cachable` child-class crash) and delegates to `newModelCachingEloquentBuilder()` (`src/Traits/ModelCaching.php:27-43,152-173`). A stock builder becomes `new CachedBuilder($query)`; a **custom** builder is kept and wrapped — `(new CachedBuilder($query))->setInnerBuilder($customBuilder)` — with unknown methods proxied through `Caching::__call()` (`src/Traits/Caching.php:56-87`), state re-synced onto the inner builder by reflection (`src/CachedBuilder.php:129-147`), and clones handled explicitly (`src/CachedBuilder.php:149-161`). Query-builder writes are intercepted so every write flushes (`src/Traits/Buildable.php:53-240`, `src/CachedBuilder.php:51-81,172-179`).

**Relation fork.** Only when the related side or the parent is cachable: `newBelongsToMany`/`newMorphToMany`/`newHasManyThrough`/`newHasOneThrough` are overridden to return `CachedBelongsToMany`/`CachedMorphToMany`/`CachedHasManyThrough`/`CachedHasOneThrough` (`src/Traits/ModelCaching.php:185-333`). `HasMany`/`HasOne` are not forked — their queries go through the related model's `CachedBuilder`, so they are cached anyway.

**Subquery instrumentation.** `CachedQueryBuilder` (extends `Illuminate\Database\Query\Builder`) is built from a stock builder by copying every property (`fromBase`, `src/CachedQueryBuilder.php:39-56`) and records tables reached through `joinSub/leftJoinSub/rightJoinSub/crossJoinSub/straightJoinSub/joinLateral/fromSub/whereIn/whereNotIn` via a `WeakMap` of recorder objects plus per-builder macros (`:24-37,58-123,227-284`). This is how tags learn about tables hidden inside subqueries.

**No vendor patching, but heavy internals use.** The package never edits `vendor/`, yet depends on exact internal shapes: `get_mangled_object_vars()` and private `wheres`/`bindings`/`orders`/`unions`/`havings` arrays in `CacheKey` (`src/CacheKey.php:41-81,725-775,1033-1048`), `ReflectionProperty` on `afterQueryCallbacks` (`src/Traits/Caching.php:810-818`), reflection copying of `scopes`/`removedScopes`/`afterQueryCallbacks` (`src/CachedBuilder.php:138-146`), protected `$pivotColumns/$accessor/$using` of `BelongsToMany` (`src/Traits/CachesPivotRelationKeys.php:67-92`), `RedisTagSet`/`RedisStore::connection()`/`getPrefix()` (`src/Cache/ModelCacheRepository.php:146-238,280-310`), and the `serialize()`/`unserialize()` envelope (`:69-125`).

**Disabled mode (consumer footgun).** With `enabled=false` or inside `runDisabled()`, the model returns a plain Eloquent builder, so a *chained* `->flushCache()` / `->cache()` throws `BadMethodCallException`; only `(new Post)->flushCache()` and `ModelCache::invalidate()` are safe in that state (`resources/boost/guidelines/core.md:279-283`; `README.md:691-696`). Code that flushes at the end of an import or in tests must not assume the cached builder is present.

**External fork.** Pivot events (`pivotAttached`, `pivotDetached`, `pivotSynced`, `pivotUpdated`) do not exist upstream; they come from `mikebronner/laravel-pivot-events`, which overrides pivot relation methods. `Cachable` must resolve the trait collision explicitly (`src/Traits/Cachable.php:13-16`) and `CachedPivotOperations` aliases/blob-wraps its `sync/attach/detach/updateExistingPivot` (`src/Traits/CachedPivotOperations.php:14-19,65-133`). So a second, same-author package is on the critical path of cache correctness.

**Code map**

| Path | Role |
|---|---|
| `config/laravel-model-caching.php` | 6 keys: `cache-prefix`, `enabled`, `use-database-keying`, `store`, `fallback-to-database`, `ttl` (15 lines) |
| `src/Providers/Service.php` | Auto-discovery, config merge, 2 commands, publish tag `config`, `model-cache` binding |
| `src/Traits/Cachable.php` | Model entry point: `Caching` + `ModelCaching` + pivot-event trait, conflict resolution, `__wakeup()` re-init (`:18-35`) |
| `src/CachedModel.php` | `abstract class CachedModel extends Model { use Cachable; }` |
| `src/Traits/ModelCaching.php` | Builder factory + relation factories, `all()`, boot listeners, `destroy()`, `disableCache()`, `withCacheCooldownSeconds()` |
| `src/CachedBuilder.php` | Eloquent builder: inner-builder proxy, afterQuery replay, write flushes, clone, relation-table recording |
| `src/CachedQueryBuilder.php` | Query builder: subquery table recorders (`WeakMap`), `fromBase()` |
| `src/Traits/Caching.php` | Core service: store/tag resolution, key/tag orchestration, `isCachable()`, flush, cooldown, morphTo flush, DB fallback |
| `src/Traits/Buildable.php` | Cached read/write API: `get/first/find/all/paginate/pluck/value/aggregates` + write hooks |
| `src/Traits/BuilderCaching.php` | `all()` delegation, `truncate()`, `withoutGlobalScope(s)` tracking |
| `src/Traits/CachedValueRetrievable.php` | Read-through + `retrieved` event replay + scoped eager-load reload + eager fingerprint |
| `src/CacheKey.php` | 1,225-line key encoder over query parts, bindings, eager loads, macro args |
| `src/CacheTags.php` | 600-line tag resolver + TTL resolution (min across eager loads) |
| `src/RelationResolver.php` | Per-class relation-path memo + per-instance `WeakMap` memo |
| `src/Cache/ModelCacheRepository.php` | Driver adapter: tag flush, Redis prefix-scoped clear, DynamoDB versioning, serialize envelope |
| `src/Traits/CachedPivotOperations.php` | Pivot write flush incl. pivot table tags |
| `src/Traits/CachesPivotRelationKeys.php` | Pivot-aware key segments (columns, accessor, `using` class, eager loads) |
| `src/Traits/CachesOneOrManyThrough.php` | Read methods duplicated for through relations |
| `src/CachedBelongsToMany.php`, `CachedMorphToMany.php`, `CachedHasManyThrough.php`, `CachedHasOneThrough.php` | Relation subclasses |
| `src/Traits/CachePrefixing.php` | Key/tag prefix: connection + database (opt-out) + config prefix + model prefix |
| `src/Helper.php`, `src/Facades/ModelCache.php` | `runDisabled()`, `invalidate()` |
| `src/Console/Commands/Clear.php`, `Publish.php` | `modelCache:clear`, `modelCache:publish` |
| `src/Exceptions/UnkeyableValueException.php` | Fail-safe marker: unkeyable value ⇒ run uncached |
| `src/ModelCaching.php` | Deprecated static `useEloquentBuilder()` shim |

**Cache read flow**

```
Post::where(...)->with('comments')->paginate(15)
  └─ CachedBuilder::paginate()                        Buildable.php:168-204
      ├─ isCachable()                                  Caching.php:733-788
      │    enabled && no lock && no base-query afterQuery && every first-level eager load Cachable
      ├─ makeCacheKey()                                Caching.php:386-416 → CacheKey::make():117-146
      │    unkeyable value ⇒ UnkeyableValueException ⇒ isCachable=false, key="" ⇒ query runs live
      ├─ makeCacheTags()                               Caching.php:468-498 → CacheTags::make():66-88
      └─ cachedValue()                                 Buildable.php:256-292
          ├─ checkCooldownAndRemoveIfExpired()         Caching.php:536-562
          ├─ get(sha1(rawKey)) in tag set              ModelCacheRepository.php:62-67,340-349
          │    hit  → fireRetrievedEvents() + reloadChangedScopedEagerLoads()   Buildable.php:348-351,364-384; CachedValueRetrievable.php:171-194
          └─ miss → run on inner/parent builder, envelope [key,value,eagerLoadFingerprint],
                    put with TTL (or forever)          Buildable.php:331-347; Caching.php:683-731
```

After-query callbacks are stripped while caching and replayed on every read (`src/CachedBuilder.php:115-127`), so `chaperone()`-style relations cache rows *before* callbacks run.

**Invalidation flow**

```
$post->save() | $post->delete() | Post::destroy() | Post::where(...)->update()
  ├─ model event: created|saved|deleted                    ModelCaching.php:107-119
  │   (restore()/touch() reach `saved`; forceDelete() fires `deleted`; quiet writes are
  │    caught by the builder write hook below; no explicit `restored`/`forceDeleted` listener)
  ├─ pivot: pivotSynced|Attached|Detached|Updated          ModelCaching.php:121-135 + CachedPivotOperations.php:23-63
  ├─ builder write hooks: insert/update/delete/forceDelete/increment/decrement/
  │   incrementEach/decrementEach/touch/truncate/upsert/updateFrom/updateOrInsert/
  │   insertOrIgnore*                                      Buildable.php:53-240; CachedBuilder.php:51-81; BuilderCaching.php:22-29
  └─ checkCooldownAndFlushAfterPersisting()               Caching.php:564-579
      ├─ cacheCooldownAllowsFlush()                       Caching.php:581-592
      ├─ $model->flushCache()  (tags of class+table+its eager loads)   Caching.php:321-341
      │   └─ ModelCacheRepository::invalidateTags()       ModelCacheRepository.php:132-144
      │       ├─ taggable store  → TaggedCache::flush() per tag
      │       ├─ DynamoDB        → rotate version keys    :351-383
      │       └─ other store     → repository->flush()  (entire store)
      ├─ flushMorphToRelatedCaches()  (reflection over MorphTo relations)   Caching.php:596-640
      └─ flushRelationshipCache()     (pivot write: related model tags)     Caching.php:642-665
```

There is no transaction awareness: the flush happens when the write statement or model event runs, not at commit (`README.md:456-462`, `resources/boost/guidelines/core.md:73-78`).

**Storage layout**

- Raw key (in memory, then hashed): `genealabs:laravel-model-caching:{connection}:{database}:{configPrefix}{modelPrefix:}{tableSlug}:{modelSlug}{clauses}` — prefix from `src/Traits/CachePrefixing.php:16-36`, order from `src/CacheKey.php:117-146`, table slug + `:` from `:632-645`, model slug from `:478-481`. Example pinned in `tests/Integration/CachedBuilder/ScopeTest.php:23`.
- Stored key: `sha1(rawKey)` (`ModelCacheRepository::itemKey(..., hash: true)` `:340-349`).
- Payload: `genealabs:lmc:v1:serialized:` + `serialize(['key' => rawKey, 'value' => …, 'eagerLoadFingerprint' => …])` (`:26,69-125`; `Buildable.php:339-343`). The echoed `key` is a cheap hash-collision self-check (`Buildable.php:294-315`).
- Tags (raw, in tag sets): `{prefix}{Str::slug(FQCN)}` and `{prefix}{Str::slug(table)}` (`CacheTags.php:589-599`), plus related-class tags for eager loads (`:90-127`), pivot table tags (`:138-146`), join table tags (`:272-306`), where/join-subquery table tags (`:367-388`), morphTo target tags (`:538-543`). Tags carry **no query values**, so nothing sensitive lands in tag keys.
- Cooldown keys (different prefix builder: no connection/database keying; config prefix gains `:`, model prefix *replaces* config): `genealabs:laravel-model-caching:{prefix}:{FQCN}-cooldown:seconds|invalidated-at|saved-at` (`Caching.php:365-384,500-534,871-883`; `ModelCaching.php:359-374`).
- DynamoDB namespace keys: `genealabs:laravel-model-caching:dynamodb:v1:global-version` and `…:tag-version:{sha1(tag)}` (`ModelCacheRepository.php:24,375-383`).

## 3. Feature inventory

| Feature | Supported? | How it works (path:line) | Quality / limits |
|---|---|---|---|
| Automatic query caching | ✅ | Trait swaps builder (`Traits/ModelCaching.php:27-43,152-173`); reads intercepted in `Traits/Buildable.php:21-254` | Only for models opting in; only for the methods it overrides |
| Opt-in / opt-out | ✅ | `use Cachable` / `CachedModel`; `disableCache()` (`ModelCaching.php:335-345`); `runDisabled()` (`Helper.php:12-29`); config `enabled` | `disableCache()` affects reads only; writes still flush |
| Cache-key construction | ✅ | `CacheKey::make()` concatenates table, model, columns, distinct, joins, wheres, group/having/with/order/offset/limit/unions, bindings, deferred callbacks, extra query props, macro key (`CacheKey.php:117-146`) | Hand-written per query-part encoder; 1,225 lines; every new Eloquent where-type is a gap (see §6 commit log) |
| Tag/index invalidation | ✅ | `CacheTags::make()` (`CacheTags.php:66-88`); flush via tag sets (`ModelCacheRepository.php:132-160`) | Requires taggable store; non-taggable stores flush everything |
| Model created / updated | ✅ | `bootCachable`: `created`, `saved` (`src/Traits/ModelCaching.php:107-136`) | Fires during transaction; no after-commit option |
| Model deleted / restored / force-deleted | ✅ (restore indirect) | `deleted` listener; `destroy()` (`:113-115,138-150`); builder `forceDelete()` (`Buildable.php:100-109`); no `restored` listener — `restore()` flushes only because it calls `save()` | `forceDelete()` fires `deleted` so model-level hard deletes are covered too |
| Pivot attach/detach/sync/updateExistingPivot | ✅ | External `PivotEventTrait` + `CachedPivotOperations` + pivot table tags (`CachedPivotOperations.php:23-63`; `CacheTags.php:129-146`) | Works with events off and non-Cachable related model, but depends on second package |
| Bulk `query()->update()/delete()` | ✅ | Overrides flush before/after statement (`Buildable.php:60-69,235-240`) | `DB::table()` and `toBase()` bypass — document says invalidate manually (`core.md:68-71`) |
| Other builder writes (`insert*`, `upsert`, `updateFrom`, `updateOrInsert`, `increment*`, `touch`, `truncate`) | ✅ | `Buildable.php:53-240`; `CachedBuilder.php:51-81`; `BuilderCaching.php:22-29` | Enumerated allow-list; new Laravel write methods need explicit support |
| `Model::destroy()` | ✅ | `ModelCaching.php:138-150` | Flushes only when rows deleted |
| Eager loading (`with`) | ✅ | Related class tags added (`CacheTags.php:90-127`); key gets `-{connection}:{database}:{relation}` (`CacheKey.php:752-775`) | If **any** first-level eager-loaded model is not Cachable, the **whole** query runs uncached, silently (`Caching.php:743-770`) |
| Constrained eager-load closures | ✅ | Snapshot relation+builder+query before/after closure, hash what changed, plus nested eager slugs (`CacheKey.php:791-846,903-966,979-992`) | Path through `morphTo` with a constraint ⇒ uncached; no-op Laravel closures recognised (`:887-901`) |
| Nested eager loads `with(['a.b' => fn])` | ✅ (since 13.2.2) | Constraint keyed at owning model (`CacheKey.php:791-846`; fix `b9f1c43`, #665) | Was a real wrong-rows bug until 2026-10-30 |
| morphTo / morphToMany | ✅ partial | morphTo tags from morph map or distinct type scan (`CacheTags.php:538-587`, 60 s memo); morphToMany = pivot relation subclass | Constrained dotted path through morphTo runs uncached; eager-loaded BTM *under* morphTo does not tag pivot (`README.md:510-516`) |
| hasManyThrough / hasOneThrough | ✅ | Cached relation subclasses + through-parent tags (`CachedHasManyThrough.php:19-36`; `CachesOneOrManyThrough.php:202-233`) | Read methods duplicated; macro keys fixed only in 13.1.11 (#656) |
| Aggregates `count/exists/sum/avg/min/max` | ✅ | Own key differentiators (`Buildable.php:21-51,146-166,224-233`) | Cached forever by default; no TTL unless configured |
| `pluck` / `value` | ✅ | `Buildable.php:212-222,242-254` | `value()` skips cache when base builder has afterQuery callbacks |
| Pagination `paginate()` | ✅ | Caches the whole `LengthAwarePaginator`, page/`perPage`/pageName/total in key (`Buildable.php:168-204`) | Path re-set on hit (`:199-202`); total from caller in key |
| `simplePaginate()` / `cursorPaginate()` | ✅ indirectly | Not overridden; Laravel calls `get()` internally (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1204-1234`, `Concerns/BuildsQueries.php:483-571`) — rows cached via `CachedBuilder::get()` | Paginator object re-built per request; page size/cursor in `get()` key; no package-level tests for cache hits here |
| `first/find/firstOrFail/findOrFail/findMany/sole` | ✅ | `first`/`find` overridden (`Buildable.php:71-98`); the OrFail/`sole` variants delegate to them in the framework | `findMany` → `whereKey()->get()` cached; `sole()` → `take(2)->get()` cached |
| `Model::all()` | ✅ | Custom static method, tags/TTL include `$with` (`ModelCaching.php:78-105`; `CacheTags.php:148-159`; `README.md:618-625`) | Separate code path from builder `all()` |
| `chunk/chunkById/lazy` | ✅ indirectly | Framework chunk → `forPage()->get()` → cached page | Pages cached forever unless TTL; one entry per offset |
| `cursor()` | ❌ | Framework calls `applyScopes()->query->cursor()` on the base builder (`Builder.php:1101-1115`) — bypasses cached builder | Streams stay uncached |
| afterQuery callbacks / `chaperone()` | ✅ partial | Callbacks stripped at write, replayed at read (`CachedBuilder.php:90-127`) | Callbacks on the **base** query make the query uncached (`Caching.php:796-818,784-788`) |
| Raw SQL / Expression values | ✅ | Compiled through grammar (`CacheKey.php:1217-1224`); extra expression hash when constraints hold Expressions (`:848-867`) | Raw expressions in unkeyed properties hashed via `toSql()`+bindings (`:1050-1075`) |
| Custom builders & macros | ✅ | Proxy through `innerBuilder` (`Caching.php:56-87`), macro args appended to key as JSON list (`Caching.php:110-201`), builder args keyed by nested cache key (`:165-182`) | Any closure / non-Stringable object arg ⇒ uncached; 512-depth array limit |
| Global scopes | ✅ | Applied to the instance before keying (`Caching.php:250-293`); `withoutGlobalScope(s)` tracked in key (`BuilderCaching.php:31-49`) | Empty-array `withoutGlobalScopes([])` bug fixed only in 13.1.8 (#646) |
| Serialization / hydration fidelity | ✅ | Values PHP-serialized with an envelope; `unserialize(allowed_classes: true)` (`ModelCacheRepository.php:26,103-125`) | Class renames/casts/append changes can break or freeze values; see §6 |
| Model flushing APIs | ✅ | `$model->flushCache()`, builder `flushCache()`, `ModelCache::invalidate()` (`Caching.php:321-341`; `Helper.php:31-55`) | Throws for classes not using `Cachable` |
| Forked Eloquent internals | ⚠️ | Builder + query builder + 4 relations subclassed; reflection for private state | Not a vendor patch, but tightly coupled to internal property shapes |
| Store/driver support | ✅ Redis/Memcached/DynamoDB; ❌ array/file/database for production | Tag check via `TaggableStore` (`Caching.php:307-312`; `ModelCacheRepository.php:328-338`); README table `README.md:93-103` | Non-taggable store ⇒ every write flushes the whole store |
| Multi-tenancy / auth scoping | ⚠️ manual | `use-database-keying` (connection+database in prefix), `cache-prefix` config, per-model `$cachePrefix` (`CachePrefixing.php:16-36`) | No tenant/user/panel dimension; global scopes are keyed, but PHP-side filtering leaks across users |
| Queue / CLI | ✅ | No special integration; shared store makes flushes visible (`README.md:659-663`) | Long-running workers keep static class-relation memo (`README.md:665-668`) |
| Events / hooks | ✅ `retrieved` replay only | `fireRetrievedEvents()` re-dispatches `eloquent.retrieved: {class}` on hits (`Buildable.php:364-384`; `CachedValueRetrievable.php:398-437`) | No package events, no hook registry, no ability to veto caching |
| Commands | ✅ 2 | `modelCache:clear {--model=}` (`Console/Commands/Clear.php:14-81`), `modelCache:publish` (`Publish.php:12-49`) | No warm/rebuild/inspect command |
| Debug / inspect | ❌ | Nothing in src | Tests reach into private methods themselves (`tests/Helpers/CacheKeys.php:8-12`) |
| Stampede protection | ❌ | Only `remember`/`rememberForever` (`Caching.php:683-731`); no `Cache::lock`, no `Cache::flexible` anywhere in src | Cold-key thundering herd on heavy ERP tables |
| Warmup | ❌ | No command/API | — |
| Octane | ⚠️ documented manual | `RelationResolver::forgetClassRelationPaths()` must be called (`RelationResolver.php:24-27`; `README.md:665-668`) | Static memo otherwise grows per class+path |
| Laravel/PHP matrix | Laravel 12–13, PHP 8.3+ | `composer.json:15-25`; CI matrix `.github/workflows/laravel.yml:26-40` | README badge still says "Laravel 11|12|13" (`README.md:4`) — stale; L11 dropped |
| Filament / Livewire awareness | ❌ | No `filament`/`livewire` strings in src | See §7 |

## 4. Configuration & flexibility

**Config file** (`config/laravel-model-caching.php`, all 6 keys):

| Key | Default | Env | Meaning | Tradeoff |
|---|---|---|---|---|
| `cache-prefix` | `''` | — (file only) | Appended to key/tag prefix **raw, without a separator** (`CachePrefixing.php:27`) | README example `'tenant-123'` yields `…:tenant-123genealabs…` — you must include the trailing `:` yourself |
| `enabled` | `true` | `MODEL_CACHE_ENABLED` | Global on/off for reads **and** flushes (`Caching.php:225-233,733-741`) | Off ⇒ no invalidation either; `runDisabled()` relies on this |
| `use-database-keying` | `true` | `MODEL_CACHE_USE_DATABASE_KEYING` | Put connection name + database name in the prefix (`CachePrefixing.php:22-25`) | Separates DB-per-tenant apps; inflates prefix length; false ⇒ shared-DB tenants collide |
| `store` | `null` | `MODEL_CACHE_STORE` | Named cache store; `null` ⇒ default store (`ModelCacheRepository.php:33-55`) | Dedicated store strongly recommended so full flushes don't nuke app cache (`core.md:118-144`) |
| `fallback-to-database` | `false` | `MODEL_CACHE_FALLBACK_TO_DB` | On Redis/Predis/DynamoDB connection errors, log warning and query DB instead of throwing (`Caching.php:820-869`) | Only listed connection exceptions; other errors still propagate |
| `ttl` | `null` | `MODEL_CACHE_TTL` | Global TTL in whole seconds; unset/`0` ⇒ forever (`CacheTags.php:148-212`) | Invalid values throw `InvalidArgumentException` naming the source |

**Per-model properties** (all discovered via `property_exists` / `__get`):

| Property | Effect |
|---|---|
| `protected $cachePrefix` | Per-instance prefix appended with `:` to key/tag prefix (`CachePrefixing.php:29-33`); for cooldown keys it *replaces* the config prefix (`Caching.php:365-384`) |
| `protected $cacheTtlSeconds` | Per-model TTL; overrides global; `0` opts this model out of the global TTL (`CacheTags.php:173-184`; `README.md:599-606`) |
| `protected $cacheCooldownSeconds` | Declares the debounce window; does nothing until a query calls `withCacheCooldownSeconds()` (`ModelCaching.php:347-377`; `README.md:378-424`) |
| `protected bool $isCachable` | Must be declared `bool` (as in `src/Traits/Caching.php:49`); a subclass redeclaring it untyped is a fatal error (breaking note in `a9f0889`) |
| `protected $with` | Counted as eager loads for `all()` tags and TTL (`README.md:618-625`) |

**Per-query APIs**

```php
Post::disableCache()->where(...)->get();                 // reads uncached; writes still flush
Post::withCacheCooldownSeconds();                        // start/refresh window for this model class
$post->flushCache();                                     // flush this model's tags (class+table+eager loads)
(new Post)->newQuery()->flushCache(['custom-tag']);      // builder-level flush with explicit tags
ModelCache::invalidate([Post::class, Comment::class]);   // throws for non-Cachable classes
ModelCache::runDisabled(fn () => Post::whereKey(1)->update([...])); // then invalidate() manually
$post->cache()->tags($tags);                             // facade-ish access to the configured store
```

**Publish tags / extension points.** Only `config` is published (`Providers/Service.php:25-27`); `modelCache:publish` forwards `--assets/--config/--views/--migrations` but only `config` is registered by the provider (`Console/Commands/Publish.php:17-47`). Extension points are: custom `newEloquentBuilder()` (wrapped), custom base query builder (passed through untouched, subqueries recorded via macros), custom pivot class (`->using()`), and subclassing `CachedBuilder`/relation classes. There is **no** documented hook/event/plugin registry; changing key or tag format is explicitly treated as a breaking contract with consumers (`AGENTS.md:21-24`).

**Precedence rules.** `enabled=false` short-circuits everything (reads and flushes). `disableCache()` affects only the chain's reads. TTL: per-model beats global; a query's TTL is the **minimum** of its model and every eager-loaded (nested + morphTo) model's TTL/global fallback (`CacheTags.php:148-159`; `README.md:608-616`). Store: `laravel-model-caching.store` beats `cache.default`. Key prefix: `genealabs:laravel-model-caching:` + connection + database (if enabled) + config prefix + model prefix (`CachePrefixing.php:16-36`). Cooldown: an active window suppresses event/builder/pivot flushes for that class, but explicit `flushCache()`/`invalidate()` and writes through another model class still flush (`README.md:413-424`).

## 5. Ideas worth stealing (ranked)

1. **Fail-safe "unkeyable ⇒ run uncached".** `CacheKey` marks any value it cannot faithfully key (resources, closures, objects hiding state, invalid UTF-8) with a random marker and throws `UnkeyableValueException`; `makeCacheKey()` catches it, disables caching for that instance and returns `""` (`CacheKey.php:191-196,1126-1137,143`; `Caching.php:407-415`). Wrong-rows bugs are worse than a cache miss — OoMyCache should build this in as a first-class `UnkeyableException` → uncached path and log/count it.
2. **Tag by every table the query reads, not by model.** Root class + table + eager-load related tags + pivot + join + subquery-where + morphTo targets (`CacheTags.php:66-88,272-388`). This is what makes cross-model invalidation correct (`JoinCacheInvalidationTest.php` exists for it). OoMyCache: derive a dependency set from the *final compiled query* + eager-load graph, and store it on the entry.
3. **Eager-load constraint fingerprinting.** Snapshot relation + builder + base query before/after the closure; if anything but wheres changed (order/limit/columns/scopes/nested `with`), hash SQL+state; if only a no-op Laravel closure, emit nothing (`CacheKey.php:791-846,903-966`; no-op detection `:887-901`). Prevents "withTrashed() load served the plain load's rows" (`a9f0889`) while keeping plain `with()` keys short. OoMyCache should hash the post-constraint relation builder wholesale instead of diffing wheres.
4. **Eager-load "fact memory" per model class.** `RelationResolver` remembers `{related class, pivot class, pivot table}` per class+path, resolved on a fresh unconstrained instance like Laravel's eager loader, with explicit `forgetClassRelationPaths()` for long-lived workers; morphTo/runtime relations fall back to live resolution (`RelationResolver.php:24-118`; `84d5b2e`). Removes one relation-method call per eager load per read.
5. **Eager-load state fingerprint stored with the entry.** Store `sha1(each eager load's SQL+bindings after constraint+global scopes, nested)` next to the payload; on a hit, re-take it and only reload eager loads when it differs; scoped/SoftDeletes eager loads get re-run so tenant/`withTrashed` state can't leak (`CachedValueRetrievable.php:171-254,116-220`; `e519016`). OoMyCache: avoid serving global-scope-dependent rows from a globally cached parent.
6. **Min-TTL across the result graph.** One query's row set contains eager-loaded models; TTL = min(model, all eager-loaded models, global fallback); `0` opts out; invalid values throw with a named source (`CacheTags.php:148-212`). Cheap freshness bound for data written outside Eloquent.
7. **Key-collision self-check.** Payload stores its own raw key; on hit, compare against the key just computed and re-fetch on mismatch (`Buildable.php:294-315`). Trades one comparison for protection against hash collisions/stale formats.
8. **Driver-adapter seam + logical versioning.** `ModelCacheRepository` hides tags-vs-DynamoDB-vs-full-flush behind one API; DynamoDB invalidation rotates per-tag UUID version keys that are appended to item keys (`ModelCacheRepository.php:132-160,340-360`). OoMyCache should go further: versioned tag namespaces on **all** drivers → O(1) invalidation instead of Redis tag-set member deletion.
9. **Redis prefix-scoped full clear.** `invalidateAll()` scans tag sets and package keys honoring both store prefix and client prefix, neutralizes Predis/phpredis client prefixes, and recovers from `CROSSSLOT` (`ModelCacheRepository.php:192-330`). Valuable for `oomycache:clear` on shared Redis; complex but well tested (`ModelCacheRepositoryTest.php:79-101`, `InvalidateAllCrossSlotTest.php`).
10. **Graceful degradation with a strict exception allow-list.** `withCacheFallback()` only swallows Redis/Predis/DynamoDB connection exceptions and only when `fallback-to-database` is on; everything else propagates (`Caching.php:820-869`). Good operational posture to copy.
11. **Debounce window (cooldown) as opt-in.** Two-step activation (property + query scope) is awkward UX, but "writes don't flush for N seconds after activation, first read after expiry flushes" is a useful ERP tool for write-heavy logs (`Caching.php:500-592`; `core.md:175-216`). OoMyCache should make it per-tag (`cache:debounce`) and never default-on for auth data.
12. **Key-segment escaping.** `%`, `-`, `_` escaped to `%25/%2D/%5F` so segment boundaries can't be forged by values (`CacheKey.php:35-39,1028-1031`). Cheap and worth copying into any string-concatenated key scheme.

## 6. Weaknesses, risks, footguns

- **Stale data by design for non-model writes.** `DB::table()`, `toBase()`, `getQuery()`, external scripts, another service's connection: nothing flushes (`core.md:68-71`, `README.md:450-454`). README's answer is manual `ModelCache::invalidate()` — unacceptable as a default for an ERP.
- **Transaction race.** Flush happens inside the transaction (model event / statement), a rollback does not undo it, and a concurrent read can refill the entry from uncommitted data (`README.md:456-462`; `core.md:73-78`). No `afterCommit` support in src.
- **Silent uncaching trap.** One eager load on a non-Cachable model turns off caching for the **entire** query, with no warning; lock queries, base-query `afterQuery`, constrained paths through morphTo, unkeyable macro args also silently fall back (`README.md:69-92`; `Caching.php:733-788`). First-line support question in the docs.
- **Key-correctness history = wrong rows, not just stale cache.** Recent fixes were all key gaps: nested eager-load constraints ignored (#665, `b9f1c43`), macro/builder closure args keyed by class name (#656, `2a6dc28`), every where() value type (#629), DateTimeImmutable (#632), `from` subquery (#633), where-subquery joined tables (#631), where-clause boolean (#615), count of bindings vs values (#618), pivot shape/accessor/using + related global scopes (#646, `a9f0889`), private-Stringable bindings (#621/`a9f0889`). Fail-safe unkeyable behavior only landed in 13.2.0 (`a9f0889`).
- **Tag-store requirement / whole-store flush.** On Memcached/file/database/array the store is not taggable, so `invalidateTags()` calls `repository->flush()` — one model save clears everything in that store (`ModelCacheRepository.php:132-160`; `core.md:118-144`). Must have a dedicated store.
- **Redis tag-set growth.** Tag sets do not expire with TTL'd entries; docs tell you to schedule `cache:prune-stale-tags` (`README.md:627-637`). Tag-set writes are one `zadd` per tag per entry; a query with many joins/eager loads writes many tags.
- **DynamoDB weak spots.** Multi-tag invalidation is not atomic (partial rotation on crash), dead rows linger until TTL cleanup (`README.md:286-296`).
- **Serialization/deploy risk.** Payload is PHP `serialize()` with `allowed_classes: true` (`ModelCacheRepository.php:103-125`). Renaming/moving a model class, changing casts/`$appends`, or a property-shape change while entries live can yield `__PHP_Incomplete_Class`/frozen attributes; no version stamp in keys (only `lmc:v1` in the payload prefix). Prefer storing attributes and rehydrating with `newFromBuilder`.
- **Key size/CPU cost.** Raw keys inline every binding, every `whereIn` value, LIKE patterns, and raw subquery SQL (e.g. the pinned key in `tests/Integration/CachedBuilder/SubQueryAddSelectTest.php:10`), then sha1 them; a 5,000-id `whereIn` builds a multi-KB string on every read (`CacheKey.php:219-234,331-383,573-598`). Cache key building is on the hot path.
- **Cardinality explosion for admin tables.** Every filter/sort/page/per-page combination is a distinct forever-entry; no TTL default, no size guard, no warmup/eviction. For Filament ERP tables this is the #1 Redis-memory risk.
- **No stampede protection.** Two simultaneous misses both run the query; paginated 3-second queries on cold keys can cascade (`Caching.php:683-731`; no lock usage in src).
- **Complexity/fork maintenance.** 28 src files / 5,983 LOC for one behavior; four relation subclasses duplicate read methods (`CachesOneOrManyThrough.php`); second same-author package on the critical path; reflection copies of private builder state break on internal Laravel changes (13.1.8 regression `newBaseQueryBuilder` trait collision, #640 `8345db8`; 13.1.11 regressions #640/#646/#651).
- **CI signal can hide local failures.** Every test calls `markTestSkipped` when Redis is unreachable (`tests/CreatesApplication.php:138-157`), so a Redis-less local run reports skips and exercises no caching at all.
- **License/security posture is fine:** MIT, `SECURITY.md` present, no advisories found in the repo; main supply-chain consideration is the unpinned-in-spirit companion package version (`^13.1`, same author).
- **Known open issues:** the GitHub issue tracker was not fetched in this session (only the cloned repo and Packagist were reachable); the defect clusters the commit log names are the de-facto risk register — #610/#611/#641 pivot tagging, #634/#655 pivot flush, #640/#651/#653 regressions from perf fixes, #646/#647/#665 key gaps, #649 TTL from eager loads, #656 macro/builder closure keys, #657/#659/#660 morph-type casts and inherited morphTo, #663/#664 nested eager-load keys, #667/#668 scope-aware reloads and relation-fact memo. Assume fresh defects in the newest 13.2.x commits.

## 7. Filament 5 / Livewire 4 compatibility assessment

Target/verified stack: Filament **v5.11.2**, Livewire **v4.4.8**, Laravel **v13.40.0**, PHP 8.5 (verified in `vendor/composer/installed.json` and workspace `composer.json`). The package has **zero** Filament/Livewire/Octane-specific code (no matching strings in `src/`; only a README note about long-running processes).

How a Filament 5 table reaches the builder (`vendor/filament/tables/src/Concerns/CanPaginateRecords.php:41-72`):

- Length-aware mode: `$total = $query->toBase()->getCountForPagination();` then `$query->paginate(perPage, pageName, total: $total)`.
- Simple mode: `$query->simplePaginate(...)`.
- Cursor mode: `$query->cursorPaginate(...)`; both modes call `toBase()->getCountForPagination()` when per-page is "all".
- Table rendering: `filterTableQuery()` applies filters, search, per-column relationship aggregates (`applyRelationshipAggregates`) and eager loads (`applyEagerLoading`) on the same builder (`vendor/filament/tables/src/Concerns/HasRecords.php:51-72`; `Columns/SelectColumn.php:922-932`; `Grouping/Group.php:492-504`).
- Query clones: summarizers do `(clone $query)->cursorPaginate(perPage: 1, …)` and `(clone $query)->skip(...)->first()` (`Concerns/CanSummarizeRecords.php:44-56`); bulk-action count uses `$this->getFilteredTableQuery()->count()` (`HasBulkActions.php:259`).

| Filament surface | Would it work unchanged? | Notes |
|---|---|---|
| Table rows (`paginate`, simple, cursor) | ✅ rows cached | `paginate` caches whole Paginator (`Buildable.php:168-204`); simple/cursor cache rows through `get()`; per-page/sort/filter is part of the key |
| Relationship aggregate columns (`withCount`/`withSum` select subqueries) | ⚠️ stale counts likely | Key includes the subquery SQL via selected columns, but **tags do not include the aggregated relation's table** — a write to the child table does not flush the parent's cached counts. `WithCountTest.php:8-28` "passes" only because the second read adds a `where`, producing a different key; it never proves invalidation |
| Table total count | ❌ not cached | Filament computes `toBase()->getCountForPagination()` itself, bypassing `CachedBuilder::count()`; one COUNT per table render per request still hits DB |
| Relationship eager loads for columns | ✅ if models opt in | `applyEagerLoading` calls `with()`; a single non-Cachable related model silently disables caching for the whole table query |
| Filters / search / sorts | ✅ | `where`/`whereHas`/`whereLike`/`orderBy` are keyed; where-subquery tables tagged |
| `->getEloquentQuery()` tenancy scopes | ✅ correctness, ❌ isolation | SQL-level tenant scope is part of the key, but entries are shared across tenants unless `use-database-keying` separates DBs or a tenant prefix is set per request. PHP-side row filtering (policies) leaks |
| Widgets / StatsOverview / summarizers | ✅ mostly | `count/sum/avg/min/max` cached; aggregate select subqueries enter the key through `columns` but their child tables are not tagged (same gap as aggregate columns) |
| Bulk actions / inline edit / delete | ✅ if through Eloquent | Model events + builder write hooks flush; raw SQL or chunked `toBase()` updates in custom actions leave stale entries |
| Relation manager tables | ✅ | Query is a relation builder backed by the related model's `CachedBuilder`; belongs-to-many relation manager uses `CachedBelongsToMany` with pivot tags |
| `cursor()` / exports streaming rows | ❌ uncached | Base-builder cursor; exports still deliver fresh rows (acceptable) |
| Livewire 4 request model | ✅/⚠️ | Requests are ordinary PHP requests; cache persists across users/components. No Livewire awareness, so identical component queries share one entry across all users |
| Octane / long-running workers | ⚠️ manual | Must call `RelationResolver::forgetClassRelationPaths()` per worker lifecycle (`README.md:665-668`) |

**Verdict:** **integration needed, not drop-in.** A minimal panel would run, but for an ERP you must add: (1) tenant/user/panel dimension to the prefix or key (and include a trailing `:` in `cache-prefix`), (2) a dedicated taggable Redis store + TTL policy + cardinality cap for table pages, (3) a fix or invalidation hook for relationship-aggregate columns (or exclude such tables from caching), (4) explicit invalidation for Filament bulk/import actions that write outside Eloquent, and (5) all models in every eager-load graph must opt in or tables silently degrade to uncached. That is precisely the gap OoMyCache should close natively.

## 8. QA / test-suite review

- **Stack:** Pest 4/5 (`composer.json:38-39`; `tests/Pest.php:1-6`), Orchestra Testbench 10/11 (`composer.json:35`), Pest TIA enabled locally (`tests/Pest.php:5-7`), PHPStan/Larastan level 5 with a baseline (`composer.json:30,67-73`), Pint.
- **Size:** 140 `tests/Integration/**/*Test.php` files + 1 Feature test; 363 PHP test files / 23,921 lines; ~968 `test()`/`it()` declarations (grep count); 151 fixture files under `tests/Fixtures`, 17 factories, 19 migrations, 1 seeder. README's "335+ tests" badge (`README.md:9`) is stale — commit `29764fd` reports 555→610 tests after the Pest migration.
- **Infra:** every test boots Testbench, copies a seeded SQLite baseline per test (`tests/CreatesApplication.php:199-237`), stores cache in Redis DB 1 with prefix `lmc-test-{token}:` (`:264-287`), **skips** when Redis is unreachable (`:138-157`); optional Postgres via `tests/RunsOnPostgres.php`; DynamoDB local + Predis/Memcached are separate CI jobs (`.github/workflows/laravel.yml:163-347`).
- **CI:** matrix PHP 8.3–8.6 × Laravel 12/13 (8.6/12 excluded), Redis 7 + Postgres 15 services, coverage on 8.5/13 → Codecov, deprecation-in-src gate, PHPStan job, DynamoDB-Local smoke job, Predis+Memcached full-suite job, TIA baseline workflow (`.github/workflows/laravel.yml:12-347`). `composer test` = Pest on `Integration,Feature` and requires Redis (`composer.json:67-73`).
- **Notable regression tests to mine for requirements:** `ClassRelationMemoryTest.php` (relation facts memoized but prefixes/connections/TTL re-read — `:35-107`), `UnchangedScopeEagerLoadTest.php` (fingerprint skip + scope-binding changes on hit), `QueryPartCacheKeyTest.php` (one test per query part), `ConstrainedEagerLoadTest.php` / `NestedConstrainedEagerLoadTest.php` / `ScopedEagerLoadReloadTest.php`, `JoinCacheInvalidationTest.php` (541 lines), `SubqueryTagTest.php`, `PivotTableTagFlushTest.php`, `UnkeyableMacroArgumentTest.php`, `PrivateStateBindingTest.php`, `CacheFallbackTest.php`, `InvalidateAllCrossSlotTest.php`, `CloneTest.php`, `CustomBuilderTest.php`, `TraitCollisionFormsTest.php` (pins that alias/precedence rules naming `Cachable::newBaseQueryBuilder` must fail to load), `CacheTtlTest.php`, `SerializableClassesCompatibilityTest.php`, `DisabledCachedBuilderTest.php`, `ScopeTest.php`.
- **Gaps:** no concurrency/stampede tests, no transaction/after-commit tests, no multi-process worker tests, no cross-user/cross-tenant leakage tests (only prefix/connection tests), no Filament/Livewire/Octane integration tests, no benchmark artifact for the 100–900% claim, no assertion that relationship-aggregate columns invalidate, PHPStan baseline hides pre-existing findings, and Redis-less local runs skip the entire suite.
- **How to run:** `composer test` (needs Redis at `REDIS_HOST`), or `vendor/bin/pest --configuration phpunit.xml.dist --testsuite Integration,Feature`; package-level console via `vendor/bin/testbench` (`AGENTS.md:26-50`).

## 9. Verdict: Adopt / Adapt / Avoid for OoMyCache

| Aspect | Verdict | Payoff | Effort | Why |
|---|---|---|---|---|
| Use as a dependency / fork | **AVOID** | low | — | 6k LOC of internals coupling, 2-package supply chain, global cache semantics, no tenant/Filament dimension; every upgrade is their key/tag churn |
| Tag-by-table invalidation model | **ADOPT** | very high | M | Proven correctness answer for cross-model joins/eager loads; we implement as a dependency set derived from final SQL + eager graph |
| Fail-safe unkeyable ⇒ uncached (+ metric) | **ADOPT** | high | S | Turns subtle wrong-rows bugs into visible misses; log/counter so it doesn't get silently turned off |
| Eager-load constraint + state fingerprint | **ADOPT** | high | M | Only trustworthy way to key `with(['a.b' => fn])` and scope-dependent eager loads |
| Relation-fact memo per class + explicit flush | **ADOPT** | medium | S | Big read-path CPU saving with a clean invalidation API for workers |
| Min-TTL across eager-loaded graph | **ADOPT** | medium | S | Simple staleness bound for out-of-band writes |
| Cooldown/debounce | **ADAPT** | medium | S | Make it per-tag and opt-in only; never for auth/user data |
| Driver adapter seam (tags now; versioned namespaces next) | **ADAPT** | very high | L | O(1) invalidation on Redis/Memcached instead of O(n) tag-set member deletes |
| Redis prefix-scoped clear | **ADAPT** | medium | M | Needed for `oomycache:clear` safety on shared Redis; simpler if we own the key namespace |
| Whole-payload PHP `serialize()` | **AVOID** | — | — | Store attributes + class and rehydrate via `newFromBuilder`; deploy-safe, smaller, no object-injection surface |
| Hand-rolled per-where-type key encoder | **AVOID** | — | — | Use one canonical fingerprint (compiled SQL + ordered bindings + eager-load closure state + scope state) and run uncached when it can't be built |
| Filament-specific integration (table/tenancy/bulk actions) | **BUILD OURSELVES** | very high | M | Their package has none; this is OoMyCache's differentiation |

## 10. Evidence map

| Path | Purpose / what it proves |
|---|---|
| `composer.json` | PHP `>=8.3`, Laravel 12/13 only, pivot-events dependency, DynamoDB suggest, CI/PHPStan scripts |
| `config/laravel-model-caching.php` | Exact 6 config keys/env names |
| `src/Providers/Service.php` | Auto-discovery, `model-cache` binding, 2 commands, only `config` publish tag |
| `src/Traits/Cachable.php` | Public opt-in trait; explicit pivot-trait conflict resolution; `__wakeup()` re-init |
| `src/Traits/ModelCaching.php` | Builder/relation factories, recursion guard, model-event listeners, `destroy()`, `disableCache()`, cooldown scope, `all()` |
| `src/CachedBuilder.php` | Eloquent-builder fork: inner-builder proxy, afterQuery replay, write flushes, clone semantics, reflection state sync |
| `src/CachedQueryBuilder.php` | Query-builder fork: subquery table recorders, `fromBase()`, macro-based recording |
| `src/Traits/Caching.php` | Orchestration: `cache()`, key, tags, `isCachable()`, flush, cooldown, morphTo flush, DB fallback |
| `src/Traits/Buildable.php` | Cached read API + write hooks + collision self-check + retrieved replay |
| `src/Traits/CachedValueRetrievable.php` | Scoped eager-load reload + eager fingerprint; duplicate read-through code |
| `src/Traits/BuilderCaching.php` | `all()`, `truncate()`, `withoutGlobalScope(s)` bookkeeping |
| `src/CacheKey.php` | 1,225-line key encoder: query parts, bindings, eager constraints, macros, unkeyable marker |
| `src/CacheTags.php` | Tag taxonomy + TTL resolution + morphTo type scan memo |
| `src/Cache/ModelCacheRepository.php` | Driver adapter: tag flush, Redis prefix scan, `CROSSSLOT` recovery, DynamoDB versioning, serialize envelope, `sha1` item keys |
| `src/RelationResolver.php` | Per-class relation-path memo (Octane caveat) and live fallback rules |
| `src/Traits/CachePrefixing.php` | Key/tag prefix: connection + database + config prefix + model prefix |
| `src/Traits/CachedPivotOperations.php`, `CachesPivotRelationKeys.php` | Pivot write flush, pivot-aware key segments |
| `src/Traits/CachesOneOrManyThrough.php` | Duplicated read methods for through relations |
| `src/Helper.php`, `src/Facades/ModelCache.php`, `src/Console/Commands/*` | `runDisabled`, `invalidate`, `modelCache:clear`, `modelCache:publish` |
| `resources/boost/guidelines/core.md` | Authoritative behavior contract shipped to consumer apps (invalidation/cooldown/traps) |
| `README.md` | Feature claims, driver matrix, transactions caveat, TTL, Redis `prune-stale-tags`, static-analysis caveats |
| `tests/CreatesApplication.php`, `phpunit.xml.dist`, `tests/Pest.php` | Test bootstrap, Redis requirement, SQLite baseline, TIA config |
| `.github/workflows/laravel.yml` | PHP/Laravel matrix, Redis/Postgres/DynamoDB/Predis lanes, PHPStan, Codecov, deprecation gate |
| Git log (selected gotchas) | `84d5b2e` relation-fact memo (behavior change: attribute-dependent relations keyed from a fresh instance); `e519016` fingerprint skip; `b9f1c43` nested-eager constraint collision (#665); `2a6dc28` macro closure-arg collision (#656); `9c16f08`/`1e20c58`/`b2acc62` pivot-table tagging gaps (#610/#634/#641); `5346a20` morph-type flush TypeError + inherited morphTo (#660); `a9f0889` empty `withoutGlobalScopes([])` treated as "remove all" + pivot/through key gaps (#646); `29764fd` breaking release that fixed `modelCache:clear` wiping the whole Redis store, `runDisabled` restore, and write-flush gating; `8345db8` regressions from 13.1.8/13.1.11 (#640); `b278630`/`b9f1c43`/`84d5b2e` landed same day — perf and correctness fixes continue after 9 years |
