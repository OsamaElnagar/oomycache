# 05 — laracache — Deep Analysis

> **Repo:** https://github.com/mostafaznv/laracache · **Commit:** `2c18cf36ed1e0a9c036519c4fac575e1ad30d99f` (`git log -1`, committed 2026-04-18) · **Tag analyzed:** `3.1.0` (tag points at HEAD; Packagist published 2026-04-18) · **License:** MIT (`LICENSE`; `composer.json:14`) · **Health:** Packagist 58,183 total / 1,735 monthly downloads, 272 stars, 6 forks, 0 open issues; LaraPlugins "Healthy", not abandoned, not secure-badged ([packagist](https://packagist.org/packages/mostafaznv/laracache), [laraplugins](https://laraplugins.io/plugins/mostafaznv/laracache)) · **Constraints:** PHP `^8.3`, `laravel/framework: ^12.0|^13.0` (`composer.json:28-31`); CI PHP 8.3/8.4/8.5 × Laravel 12/13 on Ubuntu (`.github/workflows/run-tests.yml:13-23`) · **Size:** 33 src files / 1,167 LOC; 31 test files / 2,421 LOC / 144 tests · **Analyzed:** 2026-11-09

## 0. TL;DR

- **What it is:** a *declarative named-entity materialized-query cache* — you define `CacheEntity` objects on a model (`cacheEntities()`), each with a closure, TTL and refresh policy; writes to that model fire Eloquent observers that recompute the entities. It is emphatically **not** automatic query caching: no builder interception, no SQL key generation, no transparent reads (`src/Traits/LaraCache.php:42`, `src/Traits/InteractsWithCache.php:167-187`).
- **Strongest idea:** the per-entity *policy DSL* — `refreshAfterCreate/Update/Delete/Restore`, `ttl()/forever()/validForRestOfDay()/validForRestOfWeek()`, `debounce()`, `isQueueable()`, `setDriver()`, `setDefault()` — plus an init-then-fill "CREATING" envelope that gives stale-while-revalidate semantics for free (`src/CacheEntity.php:148-216`, `src/Traits/InteractsWithCache.php:113-125`).
- **Best UX:** one-trait install, entities live next to the model, four Artisan commands (`laracache:update`, `laracache:delete`, `laracache:update-group`, `laracache:delete-group`), a facade, and status-visible cache reads via `get($name, withCacheData: true)` (`src/Commands/*`, `src/Facades/LaraCache.php`).
- **Biggest risk:** invalidation is limited to Eloquent model events — mass writes (`Model::where()->delete()`), `DB::table()` writes, pivot attach/detach and other services' writes never invalidate; plus debounce token is keyed per model, not per entity, so a model with >1 debounced entity silently refreshes only one (`src/Utils/RefreshDebouncer.php:14`, `src/Observers/LaraCacheObserver.php:15-44`).
- **Verdict for us:** **Adapt** the DSL, policy flags, status envelope, TTL presets, debounce coalescing concept, registry + commands; **do not** adopt the package as OoMyCache's engine and do not copy its events-as-only-invalidation architecture (our plan needs version namespaces, mass-write interception, tenant keys — `plan/01-features.md` §3).

## 1. What it does & category

LaraCache is a **developer-declared, event-refreshed query cache** ("cache entities"), not a query cache in the `mike-bronner/laravel-model-caching` sense. The read path is always explicit: the application calls `Article::cache()->get('latest')` (or `LaraCache::retrieve(Article::class, 'latest')`), and the package returns a serialized snapshot of whatever the entity closure returned last (`src/Cache.php:17-26`, `src/LaraCache.php:65-68`). There is no `newEloquentBuilder()`/`DB::listen()` hook anywhere in `src/`; nothing is cached unless a named entity is declared.

Category placement:

| Aspect | laracache | OoMyCache target |
| --- | --- | --- |
| Trigger for caching | developer declares named entity + closure | automatic per-query classification |
| Read API | explicit `Model::cache()->get()` | transparent via caching builder |
| Query shape | whatever the closure runs (arbitrary PHP) | resolved SQL + bindings signature |
| Invalidation | model events only | version namespaces + events + mass-write interception |
| Key model | `{kebab(modelBasename)}.{entityName}` | hashed SQL signature + dependency versions + tenant/panel |
| Data unit | whole named snapshot (collection/model/scalar) | per-query result set |

The package is well suited to dashboards/widgets/menus where a handful of expensive aggregates are recomputed on a write, and poorly suited to interactive Filament tables/filters because each user-visible query shape would need its own hand-written entity. It also has a separate companion Nova admin UI (`mostafaznv/nova-laracache`, LaraPlugins health "Medium") — useful proof that operators want visibility, but it is Nova-only, not Filament.

## 2. Architecture & integration model

**Composition:** one trait + one abstract static method on the model; an observer registered at boot; a `Cache` service (via `InteractsWithCache`) that resolves entities, computes TTL, calls closures, writes envelope DTOs and maintains registries; `Actions` for command execution; three queued jobs; two enums; a `CacheData` DTO; a `CommandData` DTO with validation; seven exception classes; two global helper functions.

| Path | Role |
| --- | --- |
| `src/Traits/LaraCache.php:18-23` | `bootLaraCache()` registers `LaraCacheObserver` via `static::whenBooted(...)` (a late fix for nested-model boot recursion — commit `e48c655`) |
| `src/Traits/LaraCache.php:26-34` | static entry points `cache()` and alias `laraCache()` return `new Cache(self::class)` |
| `src/Traits/LaraCache.php:42` | abstract `cacheEntities(): array` — the DSL contract the app must implement |
| `src/CacheEntity.php` | fluent entity builder: name, driver, queue, debounce, TTL mode, refresh flags, default, closure |
| `src/Cache.php` | public service: `get/update/updateAll/delete/deleteAll/refresh/enable/disable` |
| `src/Traits/InteractsWithCache.php` | private engine: key building, entity lookup, closure invocation, cache read/write, registry writes, retrieve-on-miss |
| `src/Observers/LaraCacheObserver.php:15-44` | `created`/`updated`/`deleted`/`restored` → `cache()->refresh(CacheEvent::X)`; `updated` suppressed during restore (`:50-55`) |
| `src/Jobs/RefreshCache.php:22-27` | queued `model->cache()->update(name)` |
| `src/Jobs/DebounceRefresh.php:25-34` | token-guarded refresh (only latest queued token wins) |
| `src/Jobs/UpdateLaraCacheModelsList.php:33-42` | maintains flat model index `laracache.models` |
| `src/Utils/RefreshDebouncer.php:12-25` | writes token + dispatches delayed `DebounceRefresh` |
| `src/Utils/Helpers.php:3-46` | global `week_ending_seconds()` / `day_ending_seconds()` (autoloaded via `composer.json:41-43`) |
| `src/DTOs/CacheData.php:13-37` | envelope `{status, expiration, value}` stored *inside* the cache entry |
| `src/DTOs/CommandData.php:23-75` | command argument validation + `App\Models\` model-name fallback |
| `src/Actions/*` + `src/Commands/*` | update/delete + group variants, console output |
| `src/LaraCache.php:13-92`, `src/Facades/LaraCache.php` | class/facade API + `list()` registry reader |
| `src/LaraCacheServiceProvider.php:16-34` | publishes config under tag `config`, merges config, registers 4 commands, binds `'laracache'` |
| `config/config.php` | publishable config (note filename: source is `config/config.php`, target `config/laracache.php`) |

**Refresh flow (write path):**

```text
Eloquent write (create / save / delete / restore)
 └─ LaraCacheObserver::created|updated|deleted|restored     src/Observers/LaraCacheObserver.php:15-44
     └─ Model::cache()->refresh(CacheEvent)                 src/Cache.php:56-89
         ├─ guard static $isEnabled                          src/Cache.php:58
         ├─ dispatch UpdateLaraCacheModelsList  (always)     src/Cache.php:59 → src/Traits/InteractsWithCache.php:135-138
         └─ foreach cacheEntities():
             ├─ entityIsCallable(event)? else skip           src/Traits/InteractsWithCache.php:50-63
             ├─ debounce   → initCache(CREATING) + RefreshDebouncer(delayed)   src/Cache.php:62-74
             ├─ queueable  → initCache(CREATING) + RefreshCache(job)           src/Cache.php:75-83
             └─ sync       → updateCacheEntity: init → closure → store → registry  src/Traits/InteractsWithCache.php:140-155
```

**Read flow (miss path):** `get()` → `retrieve()` → `findCacheEntity()` (throws `CacheEntityDoesNotExist`) → `CacheData::fromCache()`; if status is `NOT_CREATED`: queueable entities write a default envelope with `CREATING` and dispatch `RefreshCache`, sync entities compute inline; otherwise return stored envelope (`src/Traits/InteractsWithCache.php:167-187`). Note the queueable miss returns `CacheData::fromCache(..., $entity->ttl)` — raw `ttl` rather than `getTtl()`, so metadata can disagree with the real TTL (`:180`).

```text
Model::cache()->get('latest')                         src/Cache.php:17-26
 └─ retrieve(name)                                    src/Traits/InteractsWithCache.php:167-187
     ├─ findCacheEntity(): linear scan or throw       src/Traits/InteractsWithCache.php:35-48
     ├─ CacheData::fromCache(): store->get(key, default)   src/DTOs/CacheData.php:27-37
     ├─ NOT_CREATED + queueable → initCache(default, CREATING) + RefreshCache, return CREATING   :173-181
     ├─ NOT_CREATED + sync      → updateCacheEntity(): run closure now, return CREATED           :183
     └─ else                    → return stored envelope (value unless withCacheData=true)       src/Cache.php:17-26
```

**Write-cost budget per refresh (measured from code, `≈` because registry writes are shared):**

| Operation | Cache gets | Cache puts | Closure runs | Jobs queued |
| --- | --- | --- | --- | --- |
| Sync refresh, per entity, per event | 2 (envelope + registry) | 3 (CREATING, CREATED, registry) | 1 | +1 `UpdateLaraCacheModelsList` per event (`src/Cache.php:59`) |
| Queued refresh, per entity, per event | 1 (initCache) + 2 when job runs | 1 + 3 when job runs | 1 (in job) | 1 `RefreshCache` + 1 `UpdateLaraCacheModelsList` + 1 more from `Cache::update()` |
| Debounced refresh, per entity, per event | 1 + 2 for winning job | 1 + 3 for winning job | 1 per debounce window (in winner job) | 1 `DebounceRefresh` per event + winner's update jobs |
| First read of a missing queueable entity | 3 (payload + init + re-read of CREATING envelope) | 1 (`CREATING` + default, full TTL) | 0 (deferred) | 1 `RefreshCache` |
| First read of a missing sync entity | 3 (payload + init + registry) | 3 (CREATING, CREATED, registry) | 1 | 0 (registry only; no model-index job) |

The practical consequence for an ERP: the package is not free on the write path — it performs more cache I/O per save than most query caches perform per read, and the extra model-index job fires on **every** write even when queuing is disabled (`src/Cache.php:59`, `src/Jobs/UpdateLaraCacheModelsList.php:23-42`).

**Storage layout (key formats):**

| Key | Format / value | Written at |
| --- | --- | --- |
| Entity entry | `{Str::kebab(class_basename($model))}.{entity.name}` e.g. `test-model.latest` | `src/Traits/InteractsWithCache.php:24,30-33`; asserted in `tests/Feature/UpdateCacheTest.php:22` |
| Entity registry | config `laracache-list` default `laracache.list` → `[ModelClass => ['test-model.latest', ...]]` | `src/Traits/InteractsWithCache.php:80-102`; `tests/Feature/CraeteCacheTest.php:73-84` |
| Model index | hard-coded `laracache.models` → flat unique list of model classes | `src/Jobs/UpdateLaraCacheModelsList.php:17,33-42`; `tests/Feature/UpdateLaraCacheModelsListTest.php:16` |
| Debounce token | `laracache.debounce.{ModelClass}:token` (per **model**, not per entity) | `src/Utils/RefreshDebouncer.php:14` |
| Envelope payload | `Mostafaznv\LaraCache\DTOs\CacheData` object (status/expiration/value), stored with `Cache::forever` or `Cache::put(ttl)` | `src/Traits/InteractsWithCache.php:104-111` |

Key-format footgun: `Str::kebab(class_basename($model))` means two same-basename models in different namespaces collide; and `TestModel2` → `test-model2`, not `test-model-2` (`tests/Unit/UpdateCacheActionTest.php:36` vs the inconsistent `test-model-2` expectation in `tests/Feature/UpdateCacheCommandTest.php:45-61`).

## 3. Feature inventory

| Feature | Supported? | How it works (path:line) | Quality / limits |
| --- | --- | --- | --- |
| Entity definition DSL | Yes | `CacheEntity::make(name)` + 14 fluent methods (`src/CacheEntity.php:67-216`); entities returned by static `cacheEntities()` (`src/Traits/LaraCache.php:42`) | No duplicate-name validation; no entity classes/files, closures live in the model; closure unbound (static context) |
| Per-entity driver | Yes | `setDriver(string)` (`src/CacheEntity.php:87-92`); default `laracache.driver` else `cache.default` (`:38-42`) | Registry/global list can fragment across stores (see §6) |
| Model event invalidation | Partial | `created` (`:15`), `updated` (`:23`, skipped if the update is a restore `:50-55`), `deleted` (`:33`), `restored` (`:41`) | No `retrieved`, no `saving`/`saved`, no explicit `forceDeleted` hook (force delete falls through `deleted`), no pivot events, no query-builder/mass-write events |
| Named/dotted entity names | Yes | `'list.forever'`, `'latest.no-update'` are legal and tests rely on dotted keys (`tests/TestSupport/TestModels/TestModel.php:19-71`) | No nesting semantics; dots are just key characters |
| Group caches | Yes (CLI-only) | config `groups` validated in `src/Actions/Support/UpdateDeleteCache.php:36-70`; `laracache:update-group {group}` / `laracache:delete-group {group}` (`src/Commands/UpdateGroupCacheCommand.php:12`, `DeleteGroupCacheCommand.php:12`) | No facade/runtime group API; groups are config arrays, no model-entity existence checks |
| Sync refresh | Yes (default) | `src/Cache.php:84-86` → `updateCacheEntity` runs the closure in the write request (`src/Traits/InteractsWithCache.php:140-155`) | Adds N closure queries to every save; N entities × 3 cache writes + registry read/write |
| Queue refresh | Yes | `isQueueable()` (`src/CacheEntity.php:94-107`) or config `queue.status`; `RefreshCache` job (`src/Jobs/RefreshCache.php`) | No de-duplication: each event dispatches another job; no `$tries/$timeout/$backoff/failed()` |
| Debounce / cooldown | Yes | `debounce(bool, wait, conn, queue)` (`src/CacheEntity.php:109-124`) + config `debounce.*`; token compare in `DebounceRefresh` (`src/Jobs/DebounceRefresh.php:25-34`) | Token shared per model, not per entity (bug); `debounce.queue.*` config and per-entity debounce queue args are dead (see §6) |
| Creation rules / callable hooks | Yes, narrow | 4 booleans `refreshAfterCreate/Update/Delete/Restore` (`src/CacheEntity.php:148-174`), evaluated by `entityIsCallable` (`src/Traits/InteractsWithCache.php:50-63`) | `CacheEvent::RETRIEVED` exists (`src/Enums/CacheEvent.php:8`) and always maps to `true` but is never emitted (dead case) — no per-event custom callbacks |
| TTL / expiration | Yes | default `forever = true`; `ttl(int)`; `validForRestOfDay()`; `validForRestOfWeek()`; precedence in `getTtl()` (`src/CacheEntity.php:129-144`) | Modes mutually reset (`:178-216`); TTL resolution at refresh time; no jitter; exact unix expirations asserted in `tests/Feature/CacheExpirationTest.php:18-36` |
| Default values | Yes | `setDefault(mixed)` (`src/CacheEntity.php:73-78`); returned on miss/delete | **Falsy coercion:** `$value ?: $entity->default` turns `0`, `[]`, `''`, `false`, `null` into the default (`src/Traits/InteractsWithCache.php:76`; codified in `tests/Feature/CacheDefaultValueTest.php`) — wrong for ERP aggregates like zero stock |
| Cache state visibility | Yes | `CacheStatus` = `NOT_CREATED` / `CREATING` / `CREATED` / `DELETED` (`src/Enums/CacheStatus.php`); `get(name, true)` returns `CacheData` (`src/Cache.php:17-26`) | Envelope is stored in-band; `CacheData::fromCache` uses `===` default comparison (`src/DTOs/CacheData.php:30-36`) — a cached value equal to default is reported `NOT_CREATED` |
| Manual operations | Yes | `update`, `updateAll`, `delete`, `deleteAll`, `get`, `refresh`, `disable`, `enable` (`src/Cache.php:17-99`); facade wrappers + `list()` (`src/LaraCache.php:13-92`) | `delete()` is a **tombstone** (writes `DELETED` envelope with default), not `Cache::forget`; no `forget()` anywhere in `src/` |
| Artisan commands | Yes, 4 | `laracache:update {--m\|model=*} {--e\|entity=*}` (`src/Commands/UpdateCacheCommand.php:13`), `laracache:delete` (`DeleteCacheCommand.php:13`), `laracache:update-group {group}`, `laracache:delete-group {group}` | No `--forever` on delete (unlike facade `delete($model,$name,$forever)`), no `--dry-run`, no confirmation, no global "update everything" command, `App\Models\` fallback only (`src/DTOs/CommandData.php:45-62`) |
| Helpers | Yes | Global `day_ending_seconds()` / `week_ending_seconds()` autoloaded (`src/Utils/Helpers.php`, `composer.json:41-43`) | Global namespace pollution; `first-day-of-week` config is defined but never read (only `last-day-of-week` at `Helpers.php:21`) |
| Events | None | No package events dispatched; only job dispatch (`grep` of `src/` finds no `Event`/`event(`) | Operators cannot observe refresh/purge; Nova companion fills the gap, no Filament equivalent |
| Multi-store / drivers | Per entity only | `Cache::store($entity->driver)` (`src/Traits/InteractsWithCache.php:104-111`); no store resolver contract | Debounce token uses the **default** store (`src/Utils/RefreshDebouncer.php:19`), model index uses `laracache.driver` (`src/Jobs/UpdateLaraCacheModelsList.php:25-26`) — three different store resolutions |
| Tags | No | No `Cache::tags()` use in `src/` | Purge requires enumerating keys from registries |
| Multi-tenancy | No | Keys contain only model + entity name (`src/Traits/InteractsWithCache.php:24,32`) | Tenant scoping must be baked into entity names and closures by hand; unsafe default for ERP |
| Relationship / lazy queries in entities | Arbitrary PHP | Closure runs whatever you write (`src/CacheEntity.php:80-85`); examples eager-load/none | No N+1 detection, no dependency tracking, Eloquent models/collections serialized as-is |
| Laravel / PHP matrix | Yes | `php ^8.3`, `laravel/framework ^12.0\|^13.0` (`composer.json:28-31`); 2.x line supports Laravel 8.40–12 (`README.md:41-44`) | 3.0.0 was Laravel-12-only; the 13 lane arrived via third-party PRs (`966a2bf`, `cc9b5c0`, merge `2c18cf3`); single 3.x minor so far |
| Filament / Livewire awareness | None | No Filament/Livewire code or dependency anywhere | Panel-agnostic package; bridge/UI only exists for Nova (`mostafaznv/nova-laracache`) |
| Registry of entities | Two, inconsistent | `laracache.list` model→entities (`src/Traits/InteractsWithCache.php:80-102`); `laracache.models` flat models (`src/Jobs/UpdateLaraCacheModelsList.php:17`) | Redundant; `LaraCache::list()` reads only `laracache.list` (`src/LaraCache.php:86-92`) so the model index is effectively dead weight |
| Opt-out | Partial | Static `enable()/disable()` per model class (`src/Cache.php:91-99`) and per-entity refresh flags | Static property persists process-wide (`src/Traits/LaraCache.php:15`) — leaks across Octane/Livewire test requests; no per-read bypass needed because reads are already explicit |

## 4. Configuration & flexibility

**Publishable config** — tag `config`, source `config/config.php` → target `config/laracache.php` (`src/LaraCacheServiceProvider.php:16-18`). There are **no `env()` calls in the config file at all**; toggling requires editing the published config (tradeoff: config-cache-safe but not deploy-env-driven).

| Config key | Default | Env | Meaning | Tradeoff / note |
| --- | --- | --- | --- | --- |
| `driver` | `null` (`config/config.php:15`) | — | Cache store for entities when not set per entity; `null` → `cache.default` (`src/CacheEntity.php:38-42`) | Global switch but per-entity `setDriver()` wins; registries then fragment |
| `laracache-list` | `'laracache.list'` (`:28`) | — | Entity registry key (model → `prefix.entity`) | Must not collide with app keys; read on every store write (read-modify-write, no lock) |
| `first-day-of-week` | `Carbon::SUNDAY` (`:40`) | — | Intended week start | **Dead config**: never read in `src/`; only `last-day-of-week` is used (`src/Utils/Helpers.php:21`) |
| `last-day-of-week` | `Carbon::SATURDAY` (`:52`) | — | End-of-week boundary for `validForRestOfWeek()` | Region config only; no timezone handling beyond Carbon defaults |
| `queue.status` | `false` (`:65`) | — | Default `isQueueable` for entities | Can also be a plain boolean (`true`/`false`) for BC (`src/CacheEntity.php:47-56`, tested `tests/Feature/CacheEntityTest.php:31-39`) |
| `queue.name` | `'default'` (`:66`) | — | Queue name for `RefreshCache` | Per-entity `isQueueable($status,$conn,$queue)` overrides |
| `queue.connection` | `null` (`:67`) | — | Queue connection for `RefreshCache` | Falls back to `queue.default` (`src/CacheEntity.php:39,50`) |
| `debounce.status` | `false` (`:82`) | — | Default debounce flag | Independent of `queue.status`; still needs a running worker |
| `debounce.wait` | `5` seconds (`:83`) | — | Delay before debounced refresh; `<= 0` disables debounce | Coalesces events but delays freshness |
| `debounce.queue.name` | `'default'` (`:86`) | — | Intended debounce queue | **Dead**: `Cache::refresh` passes `$entity->queueName` instead (`src/Cache.php:66-72`), and `setDriver`-style overrides in `CacheEntity.php:62-63,109-124` are never consumed |
| `debounce.queue.connection` | `null` (`:87`) | — | Intended debounce connection | **Dead**, same reason (`src/Cache.php:69-70` uses `queueConnection`) |
| `groups` | `[]` (`:129`) | — | Named `[model => entities[]]` groups for group commands | Validated only when a group command runs (`src/Actions/Support/UpdateDeleteCache.php:36-70`); config-cache-safe (holds FQCN strings, no closures) |

**Per-model entity DSL surface** (the whole public "flexibility" of the package):

| Method | Signature / default | Effect | Cite |
| --- | --- | --- | --- |
| `make` | `make(string $name)` | create entity | `src/CacheEntity.php:67-70` |
| `cache` | `cache(Closure)` | the query/aggregate to snapshot | `:80-85` |
| `setDefault` | `setDefault(mixed $default = null)` | miss/delete value | `:73-78` |
| `setDriver` | `setDriver(string)` | per-entity cache store | `:87-92` |
| `isQueueable` | `(bool $status = true, string $conn = '', string $queue = '')` | refresh via `RefreshCache` job | `:94-107` |
| `debounce` | `(bool $status = true, int $wait = 5, string $conn = '', string $queue = '')` | coalesced delayed refresh | `:109-124` |
| `refreshAfterCreate/Update/Delete/Restore` | `(bool $status = true)` | per-event policy | `:148-174` |
| `ttl` | `ttl(int $seconds)` | `put` TTL; `0` ⇒ `forever` | `:178-186` |
| `forever` | default mode | `Cache::forever` | `:188-196` |
| `validForRestOfDay` | — | TTL = `day_ending_seconds()` | `:198-206` → `src/Utils/Helpers.php:27-46` |
| `validForRestOfWeek` | — | TTL = `week_ending_seconds()` (uses `last-day-of-week`) | `:208-216` → `src/Utils/Helpers.php:3-25` |

**Precedence rules (verified in source):** per-entity driver > `laracache.driver` > `cache.default`; per-entity queue triple > `laracache.queue.*` > `queue.default`; per-entity debounce wait > `laracache.debounce.wait`; TTL mode is last-write-wins with mutual resets; default-value substitution uses PHP falsy coalescing (`?:`). There is **no config precedence between config and code flags** for debounce/queue status beyond constructor defaults being overwritten by methods.

**Callable hooks / extension points:** only the entity closure and `cacheEntities()`. There are no contracts/interfaces, no package events, no `beforeRefresh`/`afterRefresh`, no custom resolver, no key generator (keys are hard-coded in `InteractsWithCache`). `setDriver()` and the `'laracache'` container binding (`src/LaraCacheServiceProvider.php:32-34`) are the only seams; the facade class can be swapped, and `Cache::refresh(CacheEvent)` is public (so an app can hand-roll observers, at the cost of touching internals). The observer itself is `final` (`src/Observers/LaraCacheObserver.php:10`), so it cannot be extended or swapped.

**Opt-out:** `Model::cache()->disable()` sets a static per-model-class flag checked before every refresh (`src/Cache.php:58,91-99`; `src/Traits/LaraCache.php:15`). It is **process-wide, not request-scoped**, so under Octane/queue workers a disable call persists for subsequent jobs; in tests it leaks across cases unless re-enabled (see `tests/Feature/CraeteCacheTest.php:45-71`). Per-entity opt-out is only the four `refreshAfter*` flags.

**Short DSL excerpt (representative, from the package's own test fixture):**

```php
CacheEntity::make('list.week')
    ->isQueueable(true, 'redis', 'low')
    ->validForRestOfWeek()
    ->cache(fn () => Article::query()->latest()->get()),
```

## 5. Ideas worth stealing (ranked)

1. **Declarative entity DSL with policy-per-entity.** `CacheEntity::make()->cache(closure)` + chained policy is the best UX in this package: one place to see name, source query, TTL, invalidation policy (`src/CacheEntity.php`). *OoMyCache adaptation:* an optional P1 "explicit entity" layer (`OoMyCache::entity('dashboard.stock_value')->for(Product::class)->ttlRestOfDay()->dependsOn([Order::class])->compute(fn () => ...)`) that reuses our version-namespace invalidation instead of per-entity observers — explicit escape hatch for the handful of ERP aggregates that aren't expressible as builder queries (plan/01 §5; plan/02 §3).
2. **Init-then-fill + status envelope (`CREATING`) → built-in stale-while-revalidate.** On a queued/debounced refresh the package keeps the old value, marks the envelope `CREATING` and only then recomputes, so readers get stale-but-valid data instead of a miss storm (`src/Traits/InteractsWithCache.php:113-125,140-155`; behavior asserted in `tests/Feature/DebounceCacheTest.php:146-187`). *Adaptation:* every OoMyCache entry should store `{value, status, generatedAt, expiresAt, version, deps}` plus a freshness flag; add a `stale` segment surfaced in Filament widgets. This is exactly the observability our plan wants (`plan/01` §7) — but gate SWR off by default for financial data (`plan/01` §10.7).
3. **Per-entity event-policy matrix.** Four booleans make cost explicit: a "settings" entity can ignore deletes, a "latest" list can ignore restores, etc. (`src/CacheEntity.php:148-174`, `src/Traits/InteractsWithCache.php:50-63`). *Adaptation:* P1 explicit-entity policies and, more importantly, an explicit dependency map for our auto cache: `dependencies => [Order::class]`, `ignore => ['updated_at']`, plus new hooks `afterAttach/afterSync/afterMassUpdate` that the package lacks.
4. **Business-time TTL presets.** `validForRestOfDay()` / `validForRestOfWeek()` with configurable week boundary (`src/CacheEntity.php:129-144`, `src/Utils/Helpers.php:15-46`) are directly useful for ERP dashboards ("today's sales", "this week's orders") and cheap to implement. *Adaptation:* `ttlUntilEndOfDay/BusinessDay/Week/Month(timezone, weekStartsOn)` in the config/pre-set vocabulary; reuse for widget aggregates.
5. **Token-based debounce coalescing.** Delaying refresh and letting only the latest token win is a clean way to absorb a bulk action storm (`src/Utils/RefreshDebouncer.php:12-25`, `src/Jobs/DebounceRefresh.php:25-34`). *Adaptation:* keep the concept but key the token per **cache entry/query signature** and use an atomic store operation (`Cache::add`/lock or atomic increment) — the package's per-model key is a correctness bug (§6.4). For OoMyCache this belongs on the invalidation side: coalesce version bumps/`afterCommit` flushes, not result recomputation.
6. **Registry-driven global operations + Artisan UX.** `laracache.list` + `laracache.models`, `updateAll()/deleteAll()` and four commands with title-cased human output (`src/LaraCache.php:13-92`, `src/Actions/*`). *Adaptation:* our `oomycache:status|clear|doctor|bench` (plan/01 §7) should own a *single* durable registry (version keys are self-describing; keep an index only for operators/UI), with `--model`, `--tenant`, `--dry-run`, `--json`, confirmations.
7. **Delete-as-tombstone with explicit `$forever` flag.** `delete($name, forever: true)` keeps the key (default value) instead of `forget` so reads don't stampede the DB (`src/Traits/InteractsWithCache.php:157-165`; tests `tests/Feature/DeleteCacheTest.php:105-126`). *Adaptation:* expose two distinct ops: `invalidate()` (version bump, O(1)) and `purge()` (physical forget, for ops/debug UI); never conflate them.
8. **Per-entity store + queue overrides.** `setDriver()`, `isQueueable($status,$connection,$queue)` show that mixed Redis/DB deployments are real (`src/CacheEntity.php:87-107`). *Adaptation:* `CacheStoreResolver` contract already planned (plan/02 §6) — keep per-model/per-query store resolution, not per-entity mutation of shared state.
9. **Model-index for "everything" operations.** The flat `laracache.models` list plus `LaraCache::list()` hint at an operator registry (`src/Jobs/UpdateLaraCacheModelsList.php`). *Adaptation:* our version-token keys can be enumerated by prefix; a small index (or event-sourced stats) is still worth it for the Filament dashboard.
10. **Helper functions for TTL math.** `day_ending_seconds()` / `week_ending_seconds()` are testable pure helpers (`tests/Feature/HelpersTest.php` asserts exact epoch values). *Adaptation:* internal static helpers with one-hour DST/timezone test matrix; never global functions (namespace pollution).

## 6. Weaknesses, risks, footguns

1. **No automatic query caching — by design, and the biggest scope mismatch.** Every expensive read must be hand-declared; dynamic Filament table queries (filters/search/sort/page) cannot be flattened into named entities without combinatorial keys (`src/Cache.php:17-26`; read path is explicit).
2. **Stale-data by construction for non-observer write paths.** `DB::table()->update()` leaves entities stale until manual `update()` — the package's own test demonstrates the intended manual recovery (`tests/Feature/UpdateCacheTest.php:57-76`). Filament bulk actions (`deleteSelected`, bulk `update`) run mass query-builder statements and fire **no model events**, so caches for affected entities never refresh. This is the exact gap our plan's §3.2 mass-write interception is designed for.
3. **Sync refresh amplifies writes into read-request latency.** Non-queued entities run the closure once per entity per model event, and each store writes an envelope twice (`initCache` then `storeCache`) plus the registry read-modify-write (`src/Traits/InteractsWithCache.php:113-133`). A single `save()` on the fixture model (17 declared entities, 16 refreshed on create — the `no-create` entity is skipped, hence the 16-entry registry assertion in `tests/Feature/CraeteCacheTest.php:82`) runs ≈16 closures and ≈80 cache operations.
4. **Debounce token is per-model, not per-entity (silent staleness).** `RefreshDebouncer::dispatch` uses key `laracache.debounce.{ModelClass}:token` (`src/Utils/RefreshDebouncer.php:14`); when a model defines two debounced entities, each dispatch overwrites the token, so all but the last job no-op (`src/Cache.php:62-74` + `src/Jobs/DebounceRefresh.php:25-34`). No test covers two refreshable debounced entities on one model — the fixture's second debounced entity has `refreshAfterCreate(false)`/`refreshAfterUpdate(false)` (`tests/TestSupport/TestModels/DebounceTestModel.php:36-44`), which hides it.
5. **Debounce queue configuration is dead code.** `CacheEntity` stores `debounceQueueName`/`debounceQueueConnection` and tests assert them (`tests/Feature/CacheEntityTest.php:158-180`), but `Cache::refresh` passes the **refresh** queue triple (`src/Cache.php:66-72`); `debounce.queue.*` in config is never read. Either use it or remove it.
6. **Queue failure leaves permanent "CREATING".** `initCache()` writes a `CREATING` envelope before dispatch (`src/Traits/InteractsWithCache.php:113-125,173-181`); jobs declare no `$tries/$timeout/$backoff` and no `failed()` (`src/Jobs/RefreshCache.php`, `DebounceRefresh.php`). If the worker is down or the job fails, readers keep getting the default/old value with a full TTL — for `forever()` entities the stale value is permanent until manual `update()`.
7. **No de-duplication for queue mode.** Every model event dispatches a fresh `RefreshCache` (`src/Cache.php:75-83`), unlike debounce; a 500-row import queues 500+ jobs per entity.
8. **Every event dispatches `UpdateLaraCacheModelsList` regardless of `queue.status`.** `Cache::refresh` always calls `updateLaraCacheModelsList()` (`src/Cache.php:59` → `src/Traits/InteractsWithCache.php:135-138`), and the job always sets `connection = laracache.queue.connection ?: queue.default` (`src/Jobs/UpdateLaraCacheModelsList.php:23-30`). With `queue.status=false` but a non-sync default queue and no worker, the `jobs` table grows on every save. Tests mostly hide it with `Bus::fake([UpdateLaraCacheModelsList::class])` (`tests/Feature/QueueCacheTest.php:15-18`).
9. **Two conflicting registries.** Entity registry keyed by `laracache-list` (`laracache.list`) vs hard-coded `laracache.models`; `LaraCache::list()` reads only the former (`src/LaraCache.php:86-92`), the latter only feeds its own test (`tests/Feature/UpdateLaraCacheModelsListTest.php`). Dead state, extra job, extra cache write.
10. **Registry fragmentation under per-entity drivers.** Registry writes go to `$entity->driver` (`src/Traits/InteractsWithCache.php:83,101`) but `LaraCache::list()` reads `laracache.driver ?: cache.default` (`src/LaraCache.php:88-91`) and `updateAll()/deleteAll()` iterate that list (`src/LaraCache.php:21-59`). Entities on a custom store are silently skipped by global operations.
11. **Registry read-modify-write race.** `updateCacheEntitiesList()` reads, mutates and `forever()`s the shared array without a lock (`src/Traits/InteractsWithCache.php:80-102`); concurrent saves on different models can lose entries, and lost entries mean `updateAll()`/`deleteAll()` silently skip models.
12. **Falsy-value coercion.** `$value ?: $entity->default` (`src/Traits/InteractsWithCache.php:76`) and the `===` default comparison in `CacheData::fromCache` (`src/DTOs/CacheData.php:30-36`) make `0`, `[]`, `''`, `false` indistinguishable from "not cached" — for ERP KPIs (zero invoices, empty stock lists) this is a correctness bug waiting to surface. Tests codify the behavior rather than flagging it (`tests/Feature/CacheDefaultValueTest.php`).
13. **Delete is a tombstone, and for `forever` entities the tombstone is forever.** `deleteCacheEntity()` computes TTL 0 for forever entities → `expiration === null` → stored with `Cache::forever` (`src/Traits/InteractsWithCache.php:104-111,157-165`; `tests/Feature/DeleteCacheTest.php:105-126`). The default value can therefore persist indefinitely after a delete; `deleteAll(forever: true)` writes permanent tombstones for everything (`src/LaraCache.php:47-60`).
14. **Key collisions from `class_basename`.** Two models named `User` (e.g. `App\Models\User`, `Domain\Billing\Models\User`) both map to `user.*` (`src/Traits/InteractsWithCache.php:24`); no namespace/connection/tenant component. In a multi-panel ERP this is a data-leak vector, not just a cache bug.
15. **Static per-model kill switch leaks process-wide.** `public static bool $isEnabled` (`src/Traits/LaraCache.php:15`) is flipped by `disable()/enable()` (`src/Cache.php:91-99`) and never reset per request; Octane/queue workers inherit the last state. Same for tests (`tests/Feature/CraeteCacheTest.php:45-71` manually re-enables).
16. **Errors are loud inside the panel.** Unknown entity names throw `CacheEntityDoesNotExist` (an `InvalidArgumentException`) from `get()` (`src/Traits/InteractsWithCache.php:35-48`, `src/Exceptions/CacheEntityDoesNotExist.php`); a typo in a widget breaks the Filament page, and there is no `doctor`/`status` command to validate entity names against closures.
17. **Serialization/memory.** Envelopes embed whole Eloquent models/collections (`src/DTOs/CacheData.php`; fixture caches `latest()` and `get()` results), no compression, no chunking, no size guard; `database`/`file` stores can grow fast in an ERP, and cached model graphs break after schema/app changes until TTL.
18. **Driver quirks ignored.** No `Cache::lock`, no tag support, no `withoutOverlapping`, no handling of `Memcached` 1 MB/250-byte limits (keys are short, payloads are not), no Redis-specific guidance. Tests only use `array` store plus `database` queue (`tests/TestCase.php:36-37`) — zero driver-matrix coverage.
19. **No tenant/multi-panel awareness.** See §1/§6.14 — the feature most consequential for Filament ERP use is absent, and closures must implement tenancy manually.
20. **Maintenance/licensing risk (low but real).** MIT, one maintainer, 160 commits since 2022, release cadence ~1–4/year; 3.x has a single release and broke the 2.x compatibility line (Laravel ≤11 dropped, `README.md:41-44`); Nova companion is separate and rated "Medium" health. No security advisories known, but no LaraPlugins "secure" badge either.
21. **Global helper functions** (`day_ending_seconds`, `week_ending_seconds`) are autoloaded into the global namespace (`composer.json:41-43`) — collision risk in large apps, un-namespaced API.
22. **`config/config.php` naming** (not `laracache.php`) plus `first-day-of-week` dead key signals test/doc drift; `README` documents `debounce.queue.*` and `first-day-of-week` as working features that source refutes (`README.md:406,413-414`).

23. **Queueable first-read herd (mitigated, not eliminated).** The miss path writes `CREATING` before dispatching, which de-dupes *later* readers, but requests racing before that write all dispatch `RefreshCache` — no lock/`Cache::add` guard (`src/Traits/InteractsWithCache.php:167-187`). Fine in tests, noisy at ERP concurrency.
24. **The envelope format leaks into application code.** Direct `Cache::get($fullName)` returns a `CacheData` object, not the value (`tests/Feature/UpdateCacheTest.php:24-28` reads `$facadeCache->value`); any payload change has no version segment, so a minor release can deserialize old envelopes into the new class. OoMyCache must keep its envelope internal and version keys.
25. **No entity-name validation or dry-run.** Duplicate names silently shadow (linear first match) and typos throw only at runtime (`src/Traits/InteractsWithCache.php:35-48`); there is no `laracache:status`/lint command to catch it at deploy — another argument for our planned `doctor`.
26. **Commands cannot physically purge.** `DeleteCacheAction` calls `delete($entity)` without `forever` (`src/Actions/DeleteCacheAction.php:48-61`), so command-deleted entries retain entity TTL; there is no command path to `Cache::forget` a key or truncate the registries. Operators must clear the underlying Laravel store manually.

## 7. Filament 5 / Livewire 4 compatibility assessment

**Technical compatibility:** excellent on paper. `composer.json` requires only PHP `^8.3` + `laravel/framework ^12|^13` (`composer.json:28-31`) — no Filament/Livewire dependency, so no version conflict with Filament 5.11.2 / Livewire 4.4.8; the local stack (PHP 8.5, Laravel 12/13) is inside the CI matrix. The package is panel-agnostic (zero Filament/Livewire code), so it "works" but is **not panel-aware**.

**Could the entity DSL be a useful explicit escape hatch inside Filament ERP panels?** Yes, but narrowly:

- **Good fit:** a small set of dashboard/widget aggregates and option lists that are expensive and have a fixed shape — e.g. `StatsOverviewWidget` totals, "top 10 overdue invoices", status/select option lists, navigation badges. Declare one entity per widget, let model events invalidate synchronously or via queue, render from `get()`. Status envelope (`CREATED/CREATING/DELETED`, expiration) maps naturally onto a Filament "cache health" widget/action, and business-day TTLs fit "today's sales" cards.
- **Bad fit:** the table index query itself. Filament builds dynamic queries from search/filters/sort/page; a named entity cannot enumerate that space, so the package gives no speedup for the main ERP pain point (plan/01 §0.1). It also has no tenant key, no tag purge, no panel awareness, so using it across multi-tenant panels requires hand-built entity names/closures.
- **Livewire 4 specifics:** reads are cheap and safe inside Livewire computed properties/actions, but **do not store cached Eloquent collections in public Livewire properties** — the envelope contains serialized models and would bloat the component snapshot; convert to arrays/DTOs at the boundary (`->toArray()`), which the package does not do. Polling/island refreshes will hit the cache each cycle, which is exactly the intended pattern.
- **Invalidation conflicts:** Filament bulk actions and relation-manager `attach/detach/sync` do not fire model events, so manual `Model::cache()->update()` calls would have to be sprinkled through resources — precisely the boilerplate OoMyCache promises to remove. Sync refresh also adds closure queries inside the panel's save request (N entities × closures per save), competing with the ERP's write latency budget.
- **Method-name collision risk:** the trait adds static `cache()` to the model (`src/Traits/LaraCache.php:26-29`); a `cache()` relation/accessor on an existing ERP model would collide (PHP trait-vs-class resolution rules make this a hard-to-debug fatal). Same for `enable/disable` naming patterns in app code.
- **Octane note:** the static `$isEnabled` flag (§6.15) is a genuine panel-worker hazard; if a Filament action disables caching, it stays disabled in that worker.

**Compatibility matrix for our target stack:**

| Dimension | Assessment | Evidence / gap |
| --- | --- | --- |
| Composer conflicts with Filament 5.11 / Livewire 4.4 | None | no Filament/Livewire packages or code (`composer.json:28-36`; `grep` of `src/` empty) |
| PHP 8.3–8.5 / Laravel 12–13 | In matrix | `composer.json:28-31`, `.github/workflows/run-tests.yml:13-23` |
| Filament table index (filters/search/sort/pagination) | Not covered | explicit named entities only (`src/Cache.php:17-26`) |
| Dashboard/widget aggregates, option lists, nav badges | Good fit | closure + TTL presets (`src/CacheEntity.php:73-216`) |
| Filament bulk actions (`deleteSelected`, bulk update) | No invalidation | mass query-builder writes fire no model events (`src/Observers/LaraCacheObserver.php`) |
| Relation-manager attach/detach/sync | No invalidation | no pivot observers in `src/` |
| Multi-tenant / multi-panel isolation | Missing | keys have no tenant/panel segment (`src/Traits/InteractsWithCache.php:24,32`) |
| Cache moderation UI in panel | Only an envelope; no UI | `CacheData` status is readable (`src/Cache.php:17-26`); Nova UI is a separate package |
| Octane/Livewire worker state | Risky | static `$isEnabled` persists (`src/Traits/LaraCache.php:15`) |
| Testing a Filament page with the package | Inert unless entities declared; array store works | no Livewire/Filament test helpers (`tests/`) |

**Verdict:** the *entity DSL concept* is a viable explicit escape hatch for ~5–15 fixed dashboard aggregates inside Filament panels, **provided** tenancy is handled manually and entity names carry tenant/panel segments. The *package implementation* (events-only invalidation, per-model debounce token, static toggles, no tags/tenant, tombstone semantics) should not be a dependency of OoMyCache's core or of a Filament bridge. If we build our planned explicit-entity layer (P1), model it on the DSL surface in §5.1–5.4 but back it with our version-namespace invalidation and tenant-aware keys.

## 8. QA / test-suite review

**Stack:** Pest `^3.7|^4.1`, Orchestra Testbench `^10|^11`, `spatie/pest-plugin-test-time` for time freezing (`composer.json:32-36`), SQLite `:memory:` for DB, `array` cache store, `database` queue with hand-rolled `jobs`/`failed_jobs` tables (`tests/TestCase.php:36-95`, `phpunit.xml:17-20`). CI: PHP 8.3/8.4/8.5 × Laravel 12/13, `composer test:ci` with Xdebug coverage upload (`.github/workflows/run-tests.yml:13-55`).

**Scale:** 23 test files (16 feature, 7 unit), 144 `it()` cases, 6 support models (one with 17 declared entities, two queueable, two debounce fixtures). Coverage config present but no committed threshold (`phpunit.xml:9-15`).

**Notable tests (what they actually pin down):**

| Test file | What it proves |
| --- | --- |
| `tests/Feature/CacheEntityTest.php` | every DSL method/default; config fallbacks for driver, queue, debounce (329 lines — the de-facto spec of `CacheEntity`) |
| `tests/Feature/CacheExpirationTest.php:11-36` | exact unix `expiration` values for forever/end-of-day/end-of-week/ttl under frozen time |
| `tests/Feature/DebounceCacheTest.php:61-187` | `CREATING` envelope, default value, "SQL executes once" under 4 creations, stale-old-value-until-debounce-window |
| `tests/Feature/QueueCacheTest.php:125-172` | queue-mode `CREATING → CREATED` transitions and old value served until worker runs |
| `tests/Feature/CacheDefaultValueTest.php` | codifies falsy→default substitution (the §6.12 footgun) |
| `tests/Feature/DeleteCacheTest.php` | tombstone semantics, expiration preserved on delete, `forever` delete → null expiration |
| `tests/Feature/RestoreCacheTest.php` | restore refresh + `refreshAfterRestore(false)`; the observer's `isRestored` guard |
| `tests/Feature/CraeteCacheTest.php` (sic) | registry contents (`laracache.list`, 16 entities) and static enable/disable |
| `tests/Feature/UpdateGroupCacheCommandTest.php`, `DeleteGroupCacheCommandTest.php`, `tests/Unit/*GroupCacheActionTest.php` | group config validation errors + output formatting |
| `tests/Unit/RefreshDebouncerTest.php`, `DebounceRefreshTest.php` | token write/TTL/delay, per-entity name payload, single-winner token logic |

**Gaps:** no multi-entity debounce test (would fail, §6.4); no debounce-queue override test (config is dead, §6.5); no queue-failure/`failed_jobs` scenario; no concurrent/race test for the registry; no non-`array` driver test; no tags/tenant/multi-panel tests; no Filament/Livewire integration tests (out of scope for a Nova-only package); no PHPStan/static analysis or code-style gate (no `phpstan.neon`, no Pint config); no assertion that a write does **not** fire for mass writes (it can't, by design, but the limitation is undocumented in tests); typo'd filename `CraeteCacheTest.php`; no tests for `retrieve()` metadata bug (`src/Traits/InteractsWithCache.php:180`).

**Coverage map by area:**

| Area | Test evidence | Assessment |
| --- | --- | --- |
| Entity DSL defaults/flags/TTL modes | `tests/Feature/CacheEntityTest.php` (all methods) | Strong — de-facto spec |
| Expiration math (frozen time, exact epochs) | `CacheExpirationTest.php`, `HelpersTest.php` | Strong |
| Sync invalidation on CRUD/restore | `CraeteCacheTest.php`, `UpdateCacheTest.php`, `DeleteCacheTest.php`, `RestoreCacheTest.php`, `RetrieveCacheTest.php` | Strong on the four supported events; no mass-write/pivot case |
| Queue mode | `QueueCacheTest.php` | Strong on transitions; no failure/retry case |
| Debounce | `DebounceCacheTest.php`, `Unit/RefreshDebouncerTest.php`, `Unit/DebounceRefreshTest.php` | Strong for one entity; multi-entity token conflict and debounce-queue override untested |
| Commands/actions/groups | `Unit/*ActionTest.php`, feature command tests | Strong incl. validation errors and output |
| Registries | `CraeteCacheTest.php:73-84`, `UpdateLaraCacheModelsListTest.php` | Weak — two separate keys, no race/fragmentation case |
| Drivers/stores/tags/tenants/panels | none | Missing |
| Concurrency/stampede/failure | none | Missing |

**How to run:** `composer install && composer test` (Pest), or `composer test:ci` for coverage. **Not executed for this report** — the clone has no `vendor/` and no committed `composer.lock` (`.gitignore` excludes `vendor`, `composer.lock`), and the task forbids installs; therefore all quality claims are source-reading, not observed test runs.

## 9. Verdict: Adopt / Adapt / Avoid

| Area | Verdict | Payoff for OoMyCache | Effort |
| --- | --- | --- | --- |
| Package as runtime dependency | **Avoid** | None — architecture (explicit reads + event-only invalidation) conflicts with our transparent-builder thesis and tenant requirements | — |
| Entity DSL design (`CacheEntity` fluent + closure) | **Adapt** | P1 explicit-entity escape hatch for fixed dashboard aggregates/option lists; familiar Laravel idiom | M |
| Init-then-fill "CREATING" envelope + status field | **Adapt** (redesign) | Gives observable state + SWR for widgets without thundering herd; feeds Filament cache UI | S–M |
| Per-entity `refreshAfter*` policy flags | **Adapt** | Cheap granular invalidation for explicit entities; extend with pivot/mass-write hooks the package lacks | S |
| Business-day/week TTL presets | **Adapt** | Direct fit for ERP dashboards; add timezone/DST tests | S |
| Token-based debounce coalescing | **Adapt** (fix) | Coalesces bulk-action invalidation storms; key per entity/query, use atomic operations | S–M |
| Registry + `update/delete` Artisan UX | **Adapt** | Operators need status/purge/warm commands; use one registry, add `--dry-run/--json/--tenant` | M |
| Groups (config-defined model/entity bundles) | **Adapt** (light) | Useful for panel/tenant-scoped purge; better expressed as tags/namespaces | S |
| Delete-as-tombstone with `$forever` | **Adapt** (split) | Distinguish invalidate vs purge in our API; never leave permanent tombstones | S |
| Static process-wide enable/disable | **Avoid** | Octane/test leakage; use request-scoped kill switch + config `enabled` | — |
| Eloquent-model payloads as default | **Avoid** | Our plan already prefers arrays mode/serializer contract for schema-drift safety | — |
| Two registries + per-entity store registry writes | **Avoid** | Dead state/race/fragmentation; version keys are the better index | — |
| Nova-style moderate/purge UI idea | **Adopt concept** | Validates the Filament "Query Cache" page/widget requirement (plan/01 §5) | M |

## 10. Evidence map

| Path | Purpose / what it proves |
| --- | --- |
| `composer.json:28-53` | hard constraints (PHP ^8.3, Laravel ^12\|^13), dev/test scripts, `files` autoload of global helpers |
| `config/config.php` | all 12 config keys incl. dead `first-day-of-week` and dead `debounce.queue.*` |
| `src/CacheEntity.php` | complete DSL, defaults, TTL precedence, constructor config reads |
| `src/Traits/LaraCache.php` | trait surface, static `$isEnabled`, observer boot with `whenBooted` |
| `src/Cache.php` | refresh orchestration, debounce/queue/sync branches, facade-facing API |
| `src/Traits/InteractsWithCache.php` | key formats, envelope writes, registry, closure invocation (`?:` bug), retrieve-on-miss |
| `src/Observers/LaraCacheObserver.php` | the entire invalidation surface (4 events, no retrieved/forceDeleted/pivot) |
| `src/DTOs/CacheData.php` | status/expiration envelope and `===` default sentinel |
| `src/Jobs/*.php` | queue, debounce and model-index jobs; absent retry/failure handling |
| `src/Utils/RefreshDebouncer.php:14` | per-model debounce token (root cause of §6.4) |
| `src/Utils/Helpers.php` | end-of-day/week math + unused `first-day-of-week` |
| `src/Actions/Support/UpdateDeleteCache.php`, `src/DTOs/CommandData.php` | group validation, model-name resolution, command invariants |
| `src/Commands/*`, `src/LaraCacheServiceProvider.php:16-34` | command signatures, publish tag `config`, binding |
| `src/LaraCache.php`, `src/Facades/LaraCache.php` | facade API, `list()` reading only `laracache.list` |
| `tests/TestCase.php:36-95`, `phpunit.xml` | test env (array cache, database queue, sqlite memory) and driver blind spot |
| `tests/Feature/DebounceCacheTest.php`, `QueueCacheTest.php` | CREATING/stale semantics and single-execution debounce proof |
| `tests/Feature/CacheDefaultValueTest.php`, `DeleteCacheTest.php` | falsy-default and tombstone semantics |
| `tests/Feature/CacheEntityTest.php` | de-facto DSL spec (all defaults/overrides) |
| `.github/workflows/run-tests.yml:13-23` | PHP/Laravel CI matrix claim |
| `README.md:33-44,140-156,309-415` | documented compatibility/DSL/commands/config (several claims refuted by source, §6.22) |
| `LICENSE` | MIT |

**Git-log observations (gotchas are visible in the history):**

- `2c18cf3` (HEAD, 2026-04-18) — "Merge pull request #34 from laravel-shift/l13-compatibility": Laravel 13 support was a third-party PR (`laravel-shift`), not the maintainer's own; 3.1.0 is the only 3.x tag so far.
- `e48c655` — "fix: nested model Booting instantiation": observer registration had to move into `static::whenBooted(...)` (`src/Traits/LaraCache.php:18-23`). Registering observers during trait boot caused failures when entity closures instantiated models — a warning for our own model-event observers (register lazily, never inside boot).
- `fd342aa` — "fix: ensure debounce logic respects entity callability during cache initialization": `initCache()` used to run for debounced entities even when the event policy was disabled; this is exactly the class of silent-write bug to test for in our debounce implementation.
- `22a1fef` — "fix: respect creation rules for queueable cache entities": queue mode used to ignore `refreshAfterCreate`; both bugs show the package's branch matrix (event × queue × debounce) is fragile and largely untested as a matrix.
- 160 commits over 2022-06 → 2026-04, one maintainer; release cadence slowed (2.4.0 2024-03, 2.5.x 2025-08→12, 3.0.0 2025-12, 3.1.0 2026-04). Expect a large API-stability risk if we build around internals.
- Inconsistencies that survived review: `first-day-of-week`/`debounce.queue.*` documented but unused; two registries; `test-model-2` vs `test-model2` key expectations; `CraeteCacheTest.php` typo — signs that the package is "healthy" but not rigorously maintained.

**Uncertain / unverified:** all health/download figures are external snapshot values as of 2026-11-09 (Packagist/LaraPlugins), not audited; the per-model debounce conflict (§6.4) and dead `debounce.queue.*` (§6.5) are inferred from source (no test exercises them) — flagged as such; test suite was not executed (no `vendor/`, installs prohibited); `forceDelete` behavior was reasoned from Laravel's SoftDeletes event flow (no package test covers it).
