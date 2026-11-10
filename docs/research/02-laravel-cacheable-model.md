# 02 — laravel-cacheable-model — Deep Analysis

> Repo: https://github.com/elipZis/laravel-cacheable-model · commit `8fa6e4a3acdf7a6eaedb7567cc1e7660339642d7` (2026-06-08) · tag analysed: `v0.6.0` (`bd2adff`, 2026-05-20) + 3 post-tag commits · License MIT (`LICENSE.md`, `composer.json:15`) · Packagist: 62,889 total / 3,808 monthly / 47 daily downloads, 158★ / 14 forks / 3 open issues / 0 dependents (packagist.org JSON, fetched 2026-11-09) · PHP `^8.3`, `illuminate/contracts ^11.0|^12.0|^13.0`, `spatie/laravel-package-tools ^1.14` (`composer.json:24-28`) · 168 commits since 2022-06-21, last non-dep commit `77a787f` 2026-05-13 · analysed 2026-11-09. Runtime claims re-verified against this workspace's installed Laravel `v13.40.0`, Filament `v5.11.2`, Livewire `v4.4.8`, spatie package-tools `1.93.3` (`vendor/...`, paths workspace-relative).

## 0. TL;DR

- **What it is:** a ~530-line package (3 PHP source files, 0 non-composer deps) that swaps a model's base query builder for `CacheableQueryBuilder` and read-through-caches **raw result arrays of every SELECT** at `Builder::runSelect()`. Opt-in is literally `use ElipZis\Cacheable\Models\Traits\Cacheable;` inside the model (`README.md:15-28`, `src/Models/Traits/Cacheable.php:16-27`).
- **Strongest idea:** a single, minimal framework seam (`Model::newBaseQueryBuilder()`) makes *all* model reads - `get`/`first`/`find`/`paginate`/`count`/`sum`/`pluck` - cacheable with zero call-site changes, because those APIs all funnel through `runSelect()` (`src/Database/Query/CacheableQueryBuilder.php:126-173`; framework paths `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:3618-3643`, `:4135-4145`).
- **Best UX:** the per-query chainable escapes `withoutCache()` / `withTtl()` / `withLogging()` plus a manual `flushCache()` (`src/Database/Query/CacheableQueryBuilder.php:321-351`, `:239-267`), and per-model config via an overridable `getCacheableProperties()` (`src/Models/Traits/Cacheable.php:32-44`).
- **Biggest risk:** the cache key is **unhashed raw SQL + `_`-joined bindings** (`:274-285`) and invalidation is **whole-model** on any write, with **no event/relation/tenant/connection dimensions**. For a Filament ERP on the same app's default `CACHE_STORE=database` (`.env:40`, `config/cache.php:18`) this means: varchar(255) key overflows, tagless index-set fallback (unbounded, no-TTL, raced), and permanent stale data for `withCount`/joins/pivot changes.
- **Verdict for us:** **ADAPT the integration seam + API ergonomics, AVOID the package as a dependency or code base** (its design cannot satisfy OoMyCache's correctness bar: tenant safety, lock semantics, relation-aware invalidation, bounded key space, stampede protection).

## 1. What it does & category

**Category:** *automatic read-through query-result cache for Eloquent* (not a model/entity cache, not a tag-management layer). It caches the **raw row arrays** returned by the database driver for every `SELECT` issued through a cacheable model's query builder, keyed by the compiled SQL + bindings, with TTL and automatic flush on write statements executed through that same builder (`README.md:8-14`, `CHANGELOG.md`).

**Scope:** exact-query repeat caching only. One model query = one cache entry, valid for `cacheable.ttl` (default 300s), invalidated wholesale when a write for that model goes through Eloquent's query builder. It does not know about models, relations, tables, tenants, users, or Filament; it only knows SQL text and the model class name it captured from the trait (`src/Database/Query/CacheableQueryBuilder.php:75-100`, `:181-201`).

**Maturity signals:** first commit 2022-06-21 (`fda92c4`), last release v0.6.0 (2026-05-20, a Laravel 13/PHP 8.5 bump). The 168-commit history is dominated by dependabot (42) and github-actions (30); code was written almost entirely by one author (Savas Ziplies, 60). Every release since 0.2.0 is a compatibility bump or a small query-shape bugfix. The repo is healthy as an artifact (releases, MIT, CI files) but *not* healthy as a correctness-critical cache layer for an ERP (see §6, §8).

## 2. Architecture & integration model

**Entry points.** (1) Composer auto-discovery registers `ElipZis\Cacheable\CacheableServiceProvider` (`composer.json:65-70`); the provider only does `$package->name('laravel-cacheable-model')->hasConfigFile('cacheable')` (`src/CacheableServiceProvider.php:20-23`) - no commands, bindings, migrations, routes, events, middlewares, macros or model boot hooks. (2) The trait overrides `newBaseQueryBuilder()` to hand every Eloquent model query a custom `Illuminate\Database\Query\Builder` subclass, pre-loaded with `[ttl, prefix, identifier, logging.*]` (`src/Models/Traits/Cacheable.php:16-27`, `:32-44`). (3) All behavior lives in overridden `runSelect()` (read path) and write-method overrides (invalidation path) in `src/Database/Query/CacheableQueryBuilder.php`.

**Code map**

| Path | Role |
|---|---|
| `composer.json` | Package meta; auto-discovery provider; PHP `^8.3`; only dep is `illuminate/contracts` + spatie package-tools (no explicit `illuminate/database` though the code subclasses it) |
| `config/cacheable.php` | 4 config groups: `ttl`, `prefix`, `identifier`, `logging.{channel,enabled,level}` (no `env()` calls) |
| `src/CacheableServiceProvider.php` | spatie package-tools provider, config merge/publish only |
| `src/Models/Traits/Cacheable.php` | User-facing opt-in; `newBaseQueryBuilder()` + `getCacheableProperties()` |
| `src/Database/Query/CacheableQueryBuilder.php` | Everything: read-through cache, key building, tag names, flush, write overrides, logging |
| `tests/Pest.php`, `tests/TestCase.php` | Pest bootstrap only - **no test files** (see §8) |
| `.github/workflows/run-tests.yml` | CI that checks out the orphan `test` branch, not the library tree |
| `README.md` / `CHANGELOG.md` | Usage docs; release notes including the Laravel 13/PHP 8.5 bump |

**Cache read flow** (`runSelect()`, `src/Database/Query/CacheableQueryBuilder.php:126-173`):

```
runSelect()
 ├─ enabled? false → parent::runSelect()                              (:128-130)
 ├─ $cacheKey = toSql() . '_' . implode('_', getBindings())           (:133, :274-285)
 ├─ $isTaggableStore = Cache::getStore() instanceof TaggableStore     (:136)
 ├─ $tags = [Model] + ["Model#<value>"] for where(<identifier>=v)     (:138, :181-201)
 ├─ RedisStore? Cache::tags($tags)->flushStale()                      (:141-143)
 ├─ tags(...)->has(key) || Cache::has(key) → return cached rows       (:146-150)
 └─ parent::runSelect() → DB
     ├─ taggable:   Cache::tags($tags)->put(key, rows, ttl)           (:156-158)
     └─ non-taggable: Cache::put(key, rows, ttl)
                      + index Cache::put("{$prefix}_{$Model}", [...keys], FOREVER)   (:160-169)
```

**Invalidation flow** (write overrides, same file):

```
Model::save()/create()/update()/delete()/restore()/forceDelete()/touch()
  └─ Eloquent uses Model::newModelQuery() → CacheableQueryBuilder
       ├─ update()/updateFrom()          → flushCache()                    (:357-373)
       ├─ insert()/insertGetId()/insertOrIgnore()/insertUsing()/upsert()  → flushCache()  (:379-432)
       ├─ delete($id)                    → flushCache($id)                  (:438-443)
       └─ truncate()                     → flushCache()                    (:448-453)
flushCache($identifier = null)
  ├─ taggable:    Cache::tags([Model (+ Model#id)])->flush()   -- deletes ALL model entries  (:249-250)
  └─ non-taggable: read index array → Cache::forget(each key) → forget index  (:252-264)
```

There are no model events/observers involved (`src/Models/Traits/Cacheable.php` has 3 methods total), so correctness depends entirely on writes flowing through these builder methods. Writes via `DB::table()`, `DB::statement()`, other services/workers, migrations, `Model::query()->getQuery()->...` from a non-cacheable parent, or not-yet-overridden Laravel 13 methods (`insertOrIgnoreUsing`, `insertOrIgnoreReturning`; `vendor/.../Query/Builder.php:4351`, `:4273`) do **not** invalidate.

**Storage layout.**

- *Taggable stores* (Redis/Memcached/ArrayStore/ApcStore/NullStore are `TaggableStore` in L13; `vendor/laravel/framework/src/Illuminate/Cache/{RedisStore,MemcachedStore,ArrayStore}.php`): entry key = `sha1(tag-namespace):<raw SQL>_<bindings>` because `TaggedCache::itemKey()`/`taggedItemKey()` prefix sha1 of tag ids (`vendor/.../Cache/TaggedCache.php:96-110`); tags are **verbatim** Redis sorted sets `tag:{name}:entries` (`vendor/.../Cache/RedisTagSet.php:141-155`) - so a tag literally becomes `tag:App\Models\User#5:entries`, and per-read `flushStale()` issues one `ZREMRANGEBYSCORE` per tag (`RedisTagSet.php:91-109`, `RedisTaggedCache.php:269-272`).
- *Non-taggable stores* (`database`, `file`; both `implements ... Store` but not `TaggableStore`): entry key = the raw SQL string itself (no hash, no prefix, no namespace) plus index key `{$prefix}_{$ModelClass}` (e.g. `cacheable_App\Models\User`) holding a PHP array of every query key ever cached for that model **without TTL** (`:160-169`, `:291-294`).
- Config `prefix` is used **only** for that fallback index key; in taggable mode it is never applied to entries (`:291-294` vs `:156-158`).

## 3. Feature inventory

| Feature | Supported? | How it works (path:line) | Quality / limits |
|---|---|---|---|
| Automatic query caching | Yes | `runSelect()` read-through (`CacheableQueryBuilder.php:126-173`) | Every SELECT cached, including one-off searches; no allowlist/denylist |
| Opt-in | Yes | `use Cacheable` trait (`Cacheable.php:11`) | Trait presence is the only switch; custom `newBaseQueryBuilder()` in the model silently wins |
| Opt-out per query | Yes | `withoutCache()` sets `$enabled=false` (`:321-326`, honoured `:128-130`) | Flag lost on `newQuery()` (`:107-116`) and on relation/eager-load builders |
| Opt-out per model / global kill switch | No | - | Must remove trait; no runtime toggle, no env flag |
| Cache key construction | Raw SQL | `toSql() . '_' . Arr::join(bindings,'_')` (`:274-285`) | Not hashed: length/leak/ambiguity problems (§6) |
| Key namespacing: connection/DB/tenant/model/store | No | - | Same SQL in two connections/tenant DBs = same key |
| Key includes model class | Only via table name in SQL | - | Two Eloquent models on one table share entries |
| Invalidation: save/update/delete/restore/forceDelete/touch | Yes (indirect) | Builder `update()`/`delete()` overrides are hit by Eloquent model lifecycle (`:357-362`, `:438-443`) | Not event-driven; works only while writes go through Eloquent's builder |
| Invalidation: bulk `query()->update()/delete()` | Yes | Same overrides | Flushes the whole model, not affected rows |
| Invalidation: insert/insertGetId/insertOrIgnore/insertUsing/upsert/truncate | Yes | `:379-432`, `:448-453` | Covers classic API; L13 `insertOrIgnoreUsing`/`insertOrIgnoreReturning` unhandled |
| Invalidation: raw `DB::table()` / `DB::statement()` / other-process writes | **No** | - | Documented as manual `flushCache()` (`README.md:124-130`); origin/test branch test asserts stale result |
| Invalidation: pivot attach/detach/sync | **Partial/broken** | Pivot writes use `newPivotStatement()` = related model's base builder (`vendor/.../Relations/Concerns/InteractsWithPivotTable.php:648-651`); `insert()` flushes **related** model tag | Parent model's `withCount`/pivot-embedded caches never invalidated; plain builder when related model isn't cacheable |
| Granularity: per-entity vs model-wide | Model-wide only | `flushCache($id)` still includes the base `Model` tag (`:181-201`, `:248-250`) | Per-id `Model#id` tag is dead weight: it never narrows invalidation |
| Tags | Yes on taggable stores | `Cache::tags([Model, Model#id...])->put/flush` (`:156-158`, `:249-250`) | Tag names are FQCN (and even SQL); no tag config/limit |
| Index-set fallback (directory/file DB stores) | Yes | Index array in one cache key, no TTL (`:160-169`, `:252-264`) | Unbounded growth, lost-update race, O(n) read-modify-write per miss |
| TTL / stale / SWR | Fixed TTL only | `config('cacheable.ttl',300)`, `withTtl()` (`:346-351`) | No stale-while-revalidate, no jitter, `ttl=null` = forever, `withTtl(0)` = delete |
| Stampede protection | **No** | - | No lock/atomic add; concurrent Livewire polls all hit DB on miss |
| Warmup / prewarm | **No** | - | Cold start of every page = full DB fan-out |
| Eager loading (`with`) | Incidental | Eager queries are ordinary SELECTs on related cacheable model; constraints via `whereIn(parent ids)` | Key contains parent-id list ⇒ combinatorial keys; no invalidation when related model changes |
| Constrained eager closures | Incidental | Closure constraints compile into SQL, hence into key | Same as above; limits/`groupLimit` SQL cached opaquely |
| Aggregates count/min/max/sum/avg | **Yes** | `aggregate()` clones + `get()` → `runSelect()` (`vendor/.../Query/Builder.php:4135-4145`) | Cached as `[['aggregate'=>N]]`; stale for TTL |
| `exists()` / `doesntExist()` | **No** | Uses `connection->select()` directly (`vendor/.../Query/Builder.php:4010-4028`) | Not cached (silent inconsistency: count cached, exists not) |
| `pluck` / `value` | Yes | `pluck()` calls `runSelect()` (`Builder.php:3889-3900`); `value()` via `first()` | Cached rows include full selected column(s) |
| `first()` / `find()` / `sole()` | Yes | All route through `get()`/`first()` | `find()` uses qualified `table.id`, so no `#id` tag is produced (`:214`) |
| Pagination (`paginate`/`simplePaginate`/`cursorPaginate`) | Yes | Count via `getCountForPagination()`→`get()`; page via `forPage()->get()` (`vendor/.../Query/Builder.php:3768-3819`; `Eloquent/Builder.php:1177-1193`) | Two entries per page (count + page); cursor pagination = one key per cursor position |
| `chunk`/`chunkById`/`lazy` | Partial | Chunk batches are normal `get()` calls; `cursor()`/`lazy()` stream via `connection->cursor()` and bypass `runSelect()` (`Builder.php:3853-3866`) | `chunkById` creates an unbounded series of keys (key > last) |
| Row locking (`lockForUpdate`/`sharedLock`) | **Cached (wrong)** | Lock compiles into `toSql()` (`vendor/.../Grammars/Grammar.php:38-50` `'lock'`, `:1569-1572`), so the locked SELECT is cached as data | Second identical locked query returns from cache **without acquiring a lock** - inventory/stock ERP footgun |
| Non-deterministic SQL (`NOW()`, `RAND()`, `inRandomOrder`) | **Cached (wrong)** | Same raw-SQL key | Frozen "random"/timestamps for TTL |
| Serialization / hydration fidelity | Raw stdClass arrays | Rows cached before Eloquent hydration; hydration runs every read | Models are still hydrated (CPU not saved); casts/accessors fine; huge row payloads stored serialized |
| Store/driver support | Any configured store | Taggable branch vs index branch (`:136`, `:249`) | `database`: key >255 chars crashes on MySQL (`DatabaseStore.php:177-203` upsert; key column `database/migrations/0001_01_01_000001_create_cache_table.php:15`); memcached: >250-byte/space keys; Redis: extra zset churn |
| Multi-tenancy / auth scoping | Only if encoded in SQL | Single-DB global scopes (incl. Filament's) end up in `toSql()`, so per-tenant keys work | **Multi-database tenancy = cross-tenant key collision/leak** (no connection in key); auth/user model caching delays permission changes |
| Queue / CLI | Same store, no awareness | Nothing queue-specific | Long-running workers fine; separate processes share invalidation via store |
| Events / hooks | **No** | No package events dispatched | Only opt-in log lines (`:301-314`) |
| Commands | **No** | Provider registers none | No `cacheable:flush`, no inspect |
| Debug / inspect | Logging only | `cacheable.logging.*`, `withLogging()` (`:333-338`) | Logs hit/miss/put/flush lines; no metrics, no `Cache::tags` inspection helper |
| Configuration surface | 4 keys | `config/cacheable.php:4-16` | No env vars, no store selection, no tag prefix, no key strategy |
| Extension points | 3 | Override `getCacheableProperties()`, extend `CacheableQueryBuilder`, override trait method in model | No interfaces/contracts, no DI |
| Octane compatibility | Yes (stateless) | Builder state per query; phpstan `checkOctaneCompatibility: true` (`phpstan.neon.dist:9`) | Per-query `config()` + Log-driver resolution still costs (§6.15) |
| Laravel version matrix | Claims 11/12/13 | `composer.json:27`; CHANGELOG 0.6.0; CI matrix lists 11/12/13 | CI actually installs Laravel 9 (§8) - L12/L13 paths effectively untested |
| Filament / Livewire awareness | **None** | - | No table/widget adapter, no tenant/panel separation (§7) |

## 4. Configuration & flexibility

**Exact config file** (`config/cacheable.php:4-16`); publish tag is `cacheable-model-config` (`README.md:38-42`), which matches spatie package-tools' derivation `"{$shortName}-config"` where `shortName = Str::after($name,'laravel-')` → `cacheable-model` (`vendor/spatie/laravel-package-tools/src/Concerns/PackageServiceProvider/ProcessConfigs.php:52-55`, `src/Package.php` `shortName()`).

| Config key | Default | Env | Meaning | Trade-off |
|---|---|---|---|---|
| `cacheable.ttl` | `300` | none | TTL in seconds for every cached SELECT (`Cacheable.php:35`) | Whole stale-data window; no per-query default override except `withTtl()` |
| `cacheable.prefix` | `'cacheable'` | none | Prefix for the **non-taggable index key** only (`:291-294`) | Misleading: does not prefix cache entries on taggable stores |
| `cacheable.identifier` | `'id'` | none | Column matched in top-level/1st-nested wheres to derive `Model#id` tags (`:207-220`) | Tags are cosmetic for invalidation (model-wide flush anyway) |
| `cacheable.logging.channel` | `null` | none | Log channel; `null` → `Log::getDefaultDriver()`, invalid → error + default (`:94-99`) | Resolved per builder construction |
| `cacheable.logging.enabled` | `false` | none | Toggle hit/miss/flush logging (`:301-314`) | Only observability the package has |
| `cacheable.logging.level` | `'debug'` | none | Log level for non-explicit calls | Logs may contain SQL + bindings (PII) |

No `env()` calls anywhere in `config/cacheable.php`, so changing TTL/prefix per environment requires publishing and editing the config file (or `Config::set` in code). Config caching (`mergeConfigFrom`) works because lookups are runtime `config()` calls, not boot-time constants.

**Per-model overrides** (the only "flexibility" documented, `README.md:97-114`):

```php
public function getCacheableProperties(): array
{
    return [
        'ttl' => 60,
        'prefix' => 'orders',
        'identifier' => 'id',
        'logging' => ['channel' => 'cacheable', 'enabled' => true, 'level' => 'debug'],
    ];
}
```

**Per-query methods** (chainable on any model query, `README.md:116-130`):

| Method | Effect | Ref |
|---|---|---|
| `withoutCache()` | `enabled = false` for this builder instance | `:321-326` |
| `withTtl(int $ttl)` | Override TTL for this query | `:346-351` |
| `withLogging()` | Force logging on for this query | `:333-338` |
| `flushCache(mixed $identifier = null)` | Manually purge the model (or `Model#id` tag) | `:239-267` |

**Precedence rules:** per-query (`withoutCache`/`withTtl`/`withLogging`) > per-model `getCacheableProperties()` > `config('cacheable.*')` > hardcoded fallbacks. There is **no** global scope/registry, no `Cacheable::disable()`, no per-store config, no per-relation config, no query allowlist/denylist, and no way to change key derivation without subclassing `CacheableQueryBuilder` (which the trait's `newBaseQueryBuilder()` hard-instantiates, `Cacheable.php:20-26`).

## 5. Ideas worth stealing (ranked)

1. **Single-seam opt-in via `newBaseQueryBuilder()`** (`src/Models/Traits/Cacheable.php:16-27`) → in OoMyCache keep opt-in to `use Cacheable` (or a `$cacheable` model property) but hook the *Eloquent* builder (`newEloquentBuilder`/`Builder::get` macros) so model context (table, key, relations, connection) is available; the raw query builder has too little context to key safely.
2. **Read-through at the result boundary, not model instances** (`runSelect()`, `:126-173`) → cache raw rows/arrays, hydrate per request. Kills serialization-version headaches from caching model objects. OoMyCache should keep this and add explicit query allowlists (SELECT without lock/for-update/random) and a hashed fingerprint.
3. **Chainable per-query escapes** `withoutCache()/withTtl()/withLogging()/flushCache()` (`:321-351`, `:239-267`) → adopt as the primary DX: Filament `Resource::getEloquentQuery()` can call `->withoutCache()` on edit pages and `->withTtl(60)` on dashboards.
4. **Dual strategy: tags when taggable, generation/index when not** (`:136`, `:156-170`, `:249-264`) → keep the abstraction, replace the fallback: OoMyCache should use **per-model/per-entity generation counters** (single `Cache::increment`) instead of an untracked key array, so invalidation is O(1), TTL-safe and race-free on file/database stores.
5. **Tag both entity-level and per-id (`Model` + `Model#id`)** (`getIdentifiableModelClasses`, `:181-201`) → steal the *shape*, implement as version stamps (`model:version:{Model}` and `model:version:{Model}:{id}` composed into the key) concatenated with a hash so stale-version keys are simply never hit - real per-row invalidation, not just bookkeeping.
6. **Flush on the query-builder write surface** (`update/insert/insertGetId/insertOrIgnore/insertUsing/upsert/delete/truncate`, `:357-453`) → this is the cheapest correct hook for `save()`/`create()` paths; OoMyCache must extend it to Laravel 13's new methods and **defer flush to `DB::afterCommit()`** (fix the flush-before-write race, §6.3).
7. **Per-model overridable cache properties** (`Cacheable.php:32-44`) → adopt a typed `CacheableOptions`/`cache()` model method, but with explicit dependency tags: `cacheTags()/cacheRelations()` so `withCount`/joins/pivots invalidate correctly.
8. **Manual `flushCache()` as the documented escape hatch** (`:239-267`, `README.md:124-130`) → adopt plus a first-class `cache:flush {model?} {--id=}` command and a Filament panel action for ops (the source package has no CLI at all).
9. **Configurable per-model/per-query log channel** (`:94-99`, `:301-314`) → adopt as an *observability plugin* (hit ratio, key count, invalidation events, slow-query-cache warnings), not plain log lines.
10. **Raw SQL as a transparent key** (`:274-285`) → steal the *transparency* goal (debuggable) but produce a **hash + short readable prefix + connection/tenant/model namespace + query-shape fingerprint**, storing the raw SQL only in debug mode (avoid PII + 255-byte driver limits).

## 6. Weaknesses, risks, footguns

**6.1 Stale data by design (severity: high).** Any write not routed through the cacheable model's builder leaves entries stale for the TTL: `DB::table()` updates, queue jobs/workers in other services, SQL seeds/migrations, `Model::query()->getQuery()->delete()`, `DB::statement()`, and Laravel 13 methods the package doesn't override (`insertOrIgnoreUsing`, `insertOrIgnoreReturning`; `vendor/.../Query/Builder.php:4351`, `:4273`). The maintainers' own branch test encodes this: after `DB::table('users')->update(...)`, the cached query must still return the old name until `flushCache()` (`origin/test:tests/Feature/CacheableTest.php`, tests `test_without_cache_is_working` / `test_flush_is_working`). In an ERP with imports, webhooks, scheduled syncs and other packages writing the same tables, 300s of silent staleness is unacceptable.

**6.2 Relation/pivot blindness (high).** No cache knows its dependencies. `withCount('orders')`, `join('customers')`, `whereHas(...)`, and pivot-filtered lists are cached under the parent query text; writes to the other model never invalidate them. Pivot writes are worse: `attach()` inserts through `newPivotStatement()` = *related* model's base builder (`vendor/.../Relations/Concerns/InteractsWithPivotTable.php:648-651`), so it flushes the **related** model's tag and never the parent's cached `withCount`/pivot payload. Result: roles/permissions/category counts remain wrong for TTL after every assignment.

**6.3 Flush-before-write race (high).** `update()`/`insert()`/`delete()` call `flushCache()` *before* `parent::update()` (`:357-362`, `:379-384`, `:438-443`). Concurrent reads between flush and commit re-populate the cache with pre-write (or uncommitted-snapshot) data, which then survives until TTL. No `afterCommit`, no transaction awareness, no version bump that makes the re-populated entry unreachable. This is the classic cache-aside race the package is designed around but does not solve.

**6.4 Invalidation granularity is fake (high cost, zero benefit).** `flushCache($id)` tags `[Model, Model#id]` (`:181-201`), but because the base `Model` tag is *always* included, `Cache::tags(...)->flush()` (`:249-250`) deletes every entry for the model anyway. So per-id membership only pays overhead: on Redis, every `put` does one `ZADD` per tag and every read does one `ZREM...` per tag (`RedisTaggedCache.php:54-74`, `RedisTagSet.php:91-109`); a `whereIn('id', range(1,500))` read creates **501 tags** for one entry. A single row save then triggers `RedisTaggedCache::flush()` - a Lua script that scans the tag's sorted set in 1000-key chunks and DELs every entry (`RedisTaggedCache.php:166-215`) - i.e. every Filament inline edit can block Redis while deleting thousands of keys.

**6.5 Cross-tenant / cross-connection key collisions (critical for ERP).** The key contains only SQL text + bindings, never the connection, database, cache store, model class, or tenant (`:274-285`). Single-DB Filament tenancy is luckily encoded in SQL via a global scope (`vendor/filament/filament/src/Resources/Resource/Concerns/BelongsToTenant.php:128-157`), so tenants get distinct keys. But **multi-database tenancy** (per-tenant DB switched at runtime, e.g. stancl/tenancy with central Redis cache) produces identical SQL for different tenant databases under one cache store → tenant B reads tenant A's rows. Also two Eloquent models on the same table (STI/pivot-ish models) share the same entry but have different tags, so flushes leak across models.

**6.6 Cache keys are raw SQL strings (national-debt grade footgun).**
- *Length:* this workspace's default store is `database` (`.env:40`, `config/cache.php:18`) whose key column is `varchar(255)` on MySQL (`database/migrations/0001_01_01_000001_create_cache_table.php:15`); `DatabaseStore::put()` upserts the key untruncated (`vendor/.../Cache/DatabaseStore.php:177-203`) → SQLSTATE 22001 "Data too long" on any Filament table query with joins/filters. Memcached caps keys at 250 bytes and disallows spaces/control chars - the README recommends memcached (`README.md:28`).
- *Ambiguity:* bindings joined with `_` (`Arr::join(...,'_')`, `:279`) makes `('a_b','c')` and `('a','b_c')` collide for the same SQL; value types are not encoded (`1` vs `'1'`).
- *Leakage:* search terms, emails, IDs, tokens in bindings land in cache keys, `cache` table rows, Redis MONITOR/keyspace, and optional log lines (`:301-314`), i.e. outside the app's data-protection boundary.

**6.7 Unbounded cardinality + no admission control (high).** Every distinct SQL/binding combination is stored for TTL: Filament table search keystrokes, sort column changes, per-page filters, pagination offsets, `whereIn` id lists, eager-load parent-id lists, `chunkById` batches, `inRandomOrder`, cursor pages. There is no allowlist, size cap, hit-count heuristic or negative caching. On `database`/`file` stores the fallback index makes it worse: the whole key array is read and rewritten on every miss (O(n) payload per query, quadratic overall) and the index is written **without TTL** (`:168`), so dead key strings live forever, growing the `cache` value until `mediumText` limits.

**6.8 Semantics-altering caching (high).** `lockForUpdate()`/`sharedLock()` SELECTs are cached as plain rows (lock compiled into SQL, `vendor/.../Grammars/Grammar.php:38-50`, `:1569-1572`), so the second "locked" read returns cached data without touching the database - stock/ledger operations silently lose their row lock. Non-deterministic SQL (`NOW()`, `RAND()`, `inRandomOrder()`) is frozen for the TTL. `exists()` bypasses the cache (`:4010-4028`), so a page can show a cached list that `exists()`/validation says doesn't exist - inconsistent reads within one request.

**6.9 Race between `has()` and `get()` (medium).** The read is `has() ? get() : query` (`:146-150`) instead of `get() ?? query`; an expiry/flush between the two calls returns `null` from `runSelect()`, and `Builder::get()` then builds an empty Collection (`vendor/.../Query/Builder.php:3618-3631`) - a transient empty Filament table instead of a DB fallback. Two cache round trips per read also double latency versus `remember()`.

**6.10 No resilience (high).** There is no try/catch around cache operations. A Redis/database cache outage turns every cached read into an exception - the cache layer becomes the single point of failure, the opposite of Laravel's best practice (`Cache::remember` fallback semantics). No circuit breaker, no `NullStore` fallback.

**6.11 No stampede protection (medium-high).** Miss → all concurrent requests (Livewire polls, dashboard widgets, queue bursts) execute the same expensive ERP query. With TTL 300 and whole-model flushes, every write makes the next burst a herd.

**6.12 Hydration cost not saved (medium).** Raw rows are cached, then Eloquent hydrates N models per request anyway (`Builder::get()`/`getModels`). Savings are only the DB round trip; for 100-row ERP pages with many columns this is a minority of the cost. There is no option to cache hydrated collections/fragments (which would need model serialization + versioning).

**6.13 Config/deployment traps (medium).** No env support → TTL/prefix changes require config edits; `ttl` typed `?int` so `null` silently means *forever* (`:88`, `:158`); `withTtl(0)` immediately deletes (`Cache::put` ttl<=0 semantics); `identifier` must match the **unqualified** column (`$where['column'] === 'id'`, `:214`), so `find()`/qualified where clauses never produce `#id` tags; per-model override is a method, not config, so it can't be changed by ops. Trait method collision: a model defining its own `newBaseQueryBuilder()` silently disables caching.

**6.14 Perf overhead per query (medium).** `getCacheableProperties()` runs 4 `config()` lookups per builder construction, and the constructor resolves the log driver on every instantiation when `logging.channel` is null (`Cacheable.php:32-44`; `CacheableQueryBuilder.php:94-99`) - i.e. on every model query, including writes and uncached reads. Every cached select additionally does `toSql()` twice, ≥3 `Cache::tags(...)` object constructions (each rebuilding tag ids), `flushStale()` Redis calls, `has`+`get` round trips, and per-tag `ZADD`s. In a Filament ERP page with 30-80 model queries this is hundreds of Redis ops to save a few SQL round trips on small tables.

**6.15 Multi-process/deploy issues (medium).** Keys embed raw SQL, so a deploy that changes a default `orderBy`/select list silently orphans entries until TTL; `cache:clear` is the only global reset; no key versioning/hash of app version. `Cache::tags()` on a store that *was* taggable but changes driver (e.g. Redis→database) leaves entries unreachable and the fallback index empty → no invalidation path except TTL.

**6.16 Security/abuse (medium).** Public search/filter params flow into bindings and therefore into unbounded keys (cache poisoning of the metric space, memory exhaustion). Caching `users` (the origin/test branch does exactly this on the auth model, `origin/test:app/Models/User.php`) delays password/permission/2FA changes by TTL; role/permission caches invalidated only on writes to the role model, not on `permission`-relation changes.

**6.17 Maintenance/bus factor (medium).** 1 primary author, small community (158★), 3 open issues, 3.8k monthly downloads, no tests on main, CI testing Laravel 9 (§8). If OoMyCache depended on it, we'd own a fork within a release cycle.

**6.18 Octane/queue (low).** Stateless per-query; no static state in the builder. Safe under Octane, but config/log lookups per query remain; queue workers share invalidation through the external store, so cross-process invalidation is only as good as §6.1-6.4.

## 7. Filament 5 / Livewire 4 compatibility assessment

**Mechanical fit: it runs, but "works unchanged in a Filament ERP panel" is a no.**

What works today with a trait-only install (verified against Filament 5.11.2 / Laravel 13.40.0):

- `Resource::getEloquentQuery()` returns an Eloquent builder whose base query is `CacheableQueryBuilder`, so table reads are cached: `HasRecords::getTableRecords()` calls `$query->get()` for non-paginated tables (`vendor/filament/tables/src/Concerns/HasRecords.php:205`) or `paginateTableQuery()` (`:208`), which calls `$query->toBase()->getCountForPagination()` + `$query->paginate()` (`vendor/filament/tables/src/Concerns/CanPaginateRecords.php:41-71`) - count and page both flow through `runSelect()`.
- Sorting/filters/search are `where/orderBy` clauses, so each table state gets its own SQL key and will hit after the first render.
- Filament action deletes (`DeleteAction` → `$record->delete()`, `vendor/filament/actions/src/DeleteAction.php:52`) and form saves (`$record->save()`) do go through the overridden builder methods, so invalidation fires (whole-model).
- Single-database Filament tenancy: `registerTenancyModelGlobalScope()` installs a global scope (`vendor/filament/filament/src/Resources/Resource/Concerns/BelongsToTenant.php:128-157`) that adds the tenant predicate to SQL before `runSelect()`, so keys are per-tenant and there's no cross-tenant read in this mode.
- Widgets/metrics using `count()/sum()` are cached via `aggregate()` (`vendor/.../Query/Builder.php:4135-4145`) - which is also where the staleness hurts most (dashboard numbers 5 minutes old).

What breaks or needs glue (blocking for ERP):

1. **Default store is `database` in this app** (`.env:40`): tagless fallback + 255-byte key limit ⇒ key-overflow exceptions and the unbounded no-TTL index (§6.6-6.7). Drop-in install on this repo would be actively broken, not just slow.
2. **Writes are frequent; invalidation is model-wide.** Every Filament inline edit / bulk action / relation manager save deletes *all* cached queries for that model, including expensive dashboard aggregates; the next render recomputes everything. Cache hit rate in a heavily-edited ERP panel collapses to near zero while still paying tag + key overhead.
3. **Relation columns go stale.** Filament tables commonly show `withCount`, `->with()`, belongs-to columns via `join`/`select` with eager loading, and relation-manager lists. Because entries are invalidated only by writes to the queried model, editing a related record leaves visible stale counts/columns for `ttl` seconds (§6.2).
4. **Multi-database tenancy** (panel `->tenant()` + separate tenant DBs, central Redis) collides with the missing connection in the key ⇒ cross-tenant data exposure (§6.5). This alone disqualifies it for ERP tenancy.
5. **Row locks and money/stock logic**: `lockForUpdate()` inside Filament actions (inventory adjustments, invoice numbering) silently stops locking after the first read (§6.8).
6. **Livewire 4 request model** = one PHP request per interaction; no cross-request memory. The package's `database`-store index path does an O(n) array read+write on each miss inside a request that already renders tables/forms - measurable latency; polling widgets multiply it.
7. **Bulk actions/`whereIn` id lists** create dozens–hundreds of tags per cached entry (§6.4) and unique keys per selection, blowing up Redis and flush time.
8. **Debuggability in a panel**: no artisan command/panel widget to inspect or flush keys, and cache entries are invisible to Filament devs; support burden moves to Redis CLI.

**Glue needed (if we were to use it as-is):** require Redis/Memcached (taggable) + a 255-safe hashed key (impossible without subclassing), apply the trait only to read-mostly reference models (countries, currencies, taxes, settings, categories) and **never** to authoritative editable models; wrap `Resource::getEloquentQuery()` with `->withoutCache()` on edit/view pages; add `afterCommit` flush listeners; add tenant/panel prefixing; extend the builder for L13 write methods and pivot relations; add `cache:flush` commands and widget cache TTLs. That is ~a fork, not an integration.

**Verdict:** *integration needed and still unsafe* for Filament ERP. For OoMyCache the usable lesson is the ergonomic contract (`Resource::getEloquentQuery()`-level opt-out, chainable `withTtl/withoutCache`, model-level dependency tags), not the implementation.

## 8. QA / test-suite review

**Stack (declared):** Pest 3 + PHPUnit 11/12/13 + Orchestra Testbench 9/10/11 (`composer.json:29-41`), PHPStan level 4 with `checkOctaneCompatibility: true` (`phpstan.neon.dist:5-10`, empty `phpstan-baseline.neon`), php-cs-fixer (`php-cs-fixer` workflow). `phpunit.xml.dist` runs SQLite `:memory:` (`:39-42`), has `failOnEmptyTestSuite="true"` and `beStrictAboutOutputDuringTests` (`:16-18`).

**Reality on `main`:** `tests/` contains only `Pest.php` (`uses(TestCase::class)->in(__DIR__)`) and `TestCase.php` (testbench scaffolding that guesses a **non-existent** factory namespace `ElipZis\Cacheable\Database\Factories`, `tests/TestCase.php:15-17`). There are **no test files**. With `failOnEmptyTestSuite` + Pest "no tests found", `composer test` cannot pass on `main`.

**What happened (git evidence):** tests were added and deleted the same morning - `a49fe42` "test: Add some basic checks" (2024-07-09 09:33, which also introduced the `Expression` handling in `getIdentifiableModelClasses`) and `8f762aa` "test: Test cleanup" (09:52) deleted `tests/Feature/CacheableTest.php`, `tests/Mock/CacheableModel.php`, `tests/Datasets/CacheableModels.php`. The deleted main-branch Pest suite had exactly 3 tests: (1) select cached under key `select * from "cacheable_models" where "key" = ? limit 1_key` tagged `[Model]`; (2) subquery select tagged `[Model, Model#select "id" from "cacheable_models"]`; (3) update flushes. Notably the assertions hard-code the raw-SQL key format and expect a subquery tag without parentheses - the tags are so implementation-coupled that the author apparently preferred deletion to maintenance.

**Where real coverage lives:** the orphan `origin/test` branch (`6ac4bf5`, 2024-07-09) is a whole Laravel 9 skeleton app with the package as a git submodule and `composer.testing.json` (path repo + `laravel/framework ^9.19` + `phpunit 9.5`). Its `tests/Feature/CacheableTest.php` has 5 PHPUnit tests: seeded DB; query caching; subquery caching; `withoutCache()`; manual `flushCache()`. Two of them deliberately prove **stale-by-design** behavior after `DB::table()` writes. `tests/Unit` only a `.gitkeep`.

**CI truth:** `.github/workflows/run-tests.yml` sets `COMPOSER: composer.testing.json` (`:10`), checks out `ref: 'test'` (`:36`) and runs `vendor/bin/phpunit` (`:56`). The matrix declares `php: [8.3, 8.4, 8.5]` × `laravel: [11.*, 12.*, 13.*]` with unused `include` testbench pairs (`:20-28`), but the `laravel` matrix variable is never passed to composer, and `composer.testing.json` pins Laravel 9 ⇒ **Laravel 11/12/13 support (including the v0.6.0 "Laravel 13 + PHP 8.5" release) is claimed but not tested in CI**. `phpstan.yml` runs level 4 on `src` only (`:19-26`); no test coverage of the builder in CI's main tree.

**Coverage gaps:** no taggable-store tests (Redis path, `flushStale`, tag flush), no `database`/`file` fallback tests (index array, lost-update race, TTL-less growth), no bulk-write/stale tests other than the two by-design ones, no pivot/relation tests, no lock/aggregate/pagination tests, no multi-tenant tests, no Filament/Livewire tests, no concurrency/stampede tests, no key-collision tests, no large-result serialization tests. PHPStan level 4 over 454 lines is weak assurance.

**How to run (for reference; not executed here):** `git clone` + `composer install` then `composer test` (`vendor/bin/pest`); the clone in this workspace has **no `vendor/`** and no `composer.lock` (library), and network installs are out of scope for this read-only analysis, so the suite could not be executed. To reproduce the historical tests, check out `origin/test`, init the submodule and run `composer install` + `vendor/bin/phpunit` there (Laravel 9/PHP 8.x era).

## 9. Verdict: Adopt / Adapt / Avoid for OoMyCache

| Decision | Verdict | Payoff | Effort |
|---|---|---|---|
| Use `elipzis/laravel-cacheable-model` as a production dependency | **AVOID** | None: cannot satisfy tenant safety, relation invalidation, lock semantics, bounded keys | - (would need fork) |
| Vendor/copy its code into OoMyCache | **AVOID** | Code is 3 files/~530 lines but every core decision (raw-SQL key, whole-model flush, tag dead weight, no events) is wrong for ERP | S |
| Opt-in ergonomics: trait on model + zero call-site caching | **ADOPT** (as design) | Best-in-class DX; enables Resource-level rollout | S |
| `withoutCache()/withTtl()/withLogging()/flushCache()` chainables | **ADOPT** | Filament edit pages/dashboards can opt out or tune in one line | S |
| Read-through at result-row boundary (not model objects) | **ADOPT** | Avoids serialization version hell; hydration always fresh | S-M |
| Flush hooks on the query-builder write surface | **ADAPT** | Cheap correctness net for `save()/create()->delete()` paths | M |
| Tag-based invalidation with model-wide flush | **ADAPT** | Model-level flush is the only granularity its tags truly deliver; we need per-entity/per-relation version counters | M |
| Non-taggable store fallback via key index | **AVOID** | Unbounded, TTL-less, raced; replace with generation counters/`Cache::increment` | M |
| Raw SQL + binding cache key | **AVOID** | Replace with hashed fingerprint + connection/tenant/model namespace + typed bindings | S |
| Per-model overridable cache options | **ADAPT** | Keep, extend to declared dependency tags and TTL classes | S |
| Per-model/per-query logging | **ADAPT** | Turn into metrics/hit-ratio + Filament debug widget | M |
| Filament 5/Livewire 4 support | **NOT PROVIDED** | OoMyCache must ship its own table/widget adapter (tenant/panel namespace, `getEloquentQuery()` opt-out, afterCommit invalidation, TTL presets) | L |

**Bottom line:** the package proves the *demand* (3.8k monthly downloads for a 3-file auto-cache) and validates the *ergonomic contract*, but its internals are a 2022 prototype that never got the correctness work. OoMyCache should adopt the seam + API shape, and deliberately diverge on keying, invalidation granularity, transactions, locks, relation dependency tracking and Filament integration.

## 10. Evidence map

| Path | Purpose / observation |
|---|---|
| `composer.json` | PS4 `ElipZis\Cacheable\`; PHP `^8.3`; deps: spatie package-tools + `illuminate/contracts` only (`:24-28`); auto-discovery (`:65-70`); Pest 3 + Testbench 9/10/11 dev (`:29-41`); MIT (`:15`) |
| `config/cacheable.php` | Only config surface; 4 keys, no env (`:4-16`) |
| `src/CacheableServiceProvider.php` | Config-only provider (`:20-23`) |
| `src/Models/Traits/Cacheable.php` | Opt-in seam (`:16-27`); config snapshot (`:32-44`); declares `abstract getConnection()` (`:51`) |
| `src/Database/Query/CacheableQueryBuilder.php` | Everything: read-through (`:126-173`), tag naming (`:181-201`), identifier detection (`:207-220`), unused `isIdentifiableQuery()` (`:228-231`), flush (`:239-267`), key (`:274-285`), index key (`:291-294`), logging (`:301-314`), per-query API (`:321-351`), write overrides (`:357-453`) |
| `tests/Pest.php`, `tests/TestCase.php` | Bootstrap only; no tests on `main`; factory namespace typo `ElipZis\Cacheable\Database\Factories` (`TestCase.php:15-17`); unused `refreshServiceProvider()` (`:32-35`) |
| `phpunit.xml.dist` | `failOnEmptyTestSuite="true"` (`:16`); DB sqlite `:memory:` (`:39-42`) ⇒ suite cannot pass as-is |
| `phpstan.neon.dist`, `phpstan-baseline.neon` | Level 4, `src` only, empty baseline (`:5-10`) |
| `.github/workflows/run-tests.yml` | `COMPOSER=composer.testing.json` (`:10`), checkout `ref: 'test'` (`:36`), matrix laravel unused (`:20-28`, `:53`), `vendor/bin/phpunit` (`:56`) |
| `README.md` | Usage/trait (`:15-28`), config table (`:44-62`), per-model override (`:97-114`), escapes (`:116-130`), honest perf warning ("overrides the native QueryBuilder and is capturing every database query… imposes a load", `:132-141`) |
| `CHANGELOG.md` | v0.6.0 L13/PHP8.5 (`:8-19`); v0.5.0 L12 (`:27-43`); every release is a compat bump or micro bugfix |
| `LICENSE.md` | MIT, elipZis |
| `git log` | `fda92c4` 2022-06-21 initial; `a1560a7` 2022-12-20 "not capturing IN searches with multiple where values" (`$where['value'] ?? $where['values']`); `abc70ef` 2022-12-14 "Not checking taggable stores for existing keys"; `ba09c84` "Missing modelClasses for non-taggable stores"; `a49fe42` 2024-07-09 09:33 tests + `Expression` handling; **`8f762aa` 2024-07-09 09:52 deletes the whole Pest suite**; `65267d7` 2024-10-16 adds `flushStale()` for Redis; `77a787f` 2026-05-13 fixes `Undefined array key "values"` for `whereNull` (another query-shape crash); `bd2adff` v0.6.0 2026-05-20; `8fa6e4a` HEAD 2026-06-08 (dependabot-only) |
| `origin/test` branch (`6ac4bf5`) | Orphan L9 skeleton + submodule + `composer.testing.json` (path repo, `laravel/framework ^9.19`); `tests/Feature/CacheableTest.php` has the only real tests (5) including two that assert stale-by-design after `DB::table()` writes; `app/Models/User.php` puts the trait on the auth model |
| `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php` (workspace L13.40.0) | `get()`/`runSelect()` (`:3618-3643`), `cursor()` bypass (`:3853-3866`), `pluck()` cached (`:3889-3900`), `exists()` bypass (`:4010-4028`), `aggregate()`→`get()` (`:4135-4145`), pagination count (`:3768-3819`), `insertOrIgnoreUsing` (`:4351`), `insertOrIgnoreReturning` (`:4273`), `increment()`→`update()` (`:4498-4529`), `newQuery()` (`:4616-4619`) |
| `vendor/.../Query/Grammars/Grammar.php` | `lock` is a select component (`:38-50`), `compileLock` (`:1569-1572`) ⇒ locked SELECTs become cacheable SQL |
| `vendor/.../Eloquent/Builder.php` | `paginate()` = count + forPage get (`:1177-1193`); `touch()`→`toBase()->update()` (`:1365-1382`) |
| `vendor/.../Eloquent/Relations/Concerns/InteractsWithPivotTable.php` | `attach()` (`:336-352`), `newPivotStatement()` = related model's base builder (`:648-651`) ⇒ parent caches not invalidated |
| `vendor/.../Cache/DatabaseStore.php`, `.../Cache/{TaggedCache,RedisTagSet,RedisTaggedCache}.php` | `put()` upsert no truncation (`:177-203`); tagged key = `sha1(tag-namespace):key` (`TaggedCache.php:96-110`); verbatim Redis tag keys (`RedisTagSet.php:141-155`); `flushStaleEntries` per read (`:91-109`); `flush()` Lua scan (`RedisTaggedCache.php:166-215`) |
| `vendor/filament/tables/src/Concerns/{CanPaginateRecords,HasRecords}.php` | Table pagination path (`CanPaginateRecords.php:41-71`), records fetch `get()`/`paginate` (`HasRecords.php:113`, `:185-208`) |
| `vendor/filament/filament/src/Resources/Resource/Concerns/BelongsToTenant.php` | Filament tenancy = global scope (`:128-157`) encoded in SQL ⇒ single-DB tenants safe, multi-DB tenants unsafe |
| `vendor/filament/actions/src/DeleteAction.php` | `$record->delete()` (`:52`) ⇒ invalidation fires through builder |
| App evidence: `.env:40`, `config/cache.php:18`, `database/migrations/0001_01_01_000001_create_cache_table.php:15` | Target app defaults to `CACHE_STORE=database` ⇒ non-taggable fallback + `varchar(255)` cache key column, i.e. the package's worst path |
