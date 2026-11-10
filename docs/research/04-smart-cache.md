# 04 — smart-cache — Deep Analysis

> Repo: https://github.com/iazaran/smart-cache · HEAD `c526854475207525ac7b45eace05cd7c4fbabfcc` (`c526854`, 2026-10-30, merge of PR #60; `git describe` → `1.17.0-2-gc526854`) · Tag analyzed **1.17.0** = `da85af02e3c1567c4a030efaf64f178998a155c6` (2026-10-30) · License **MIT** (`composer.json:32`, `LICENSE`) · Health/downloads: **not verified** — Packagist/GitHub API unreachable from the research box (network blocked); repo proxy signals: 261 commits / 39 tags 1.0.0→1.17.0 since 2025-04-22, 254 of 261 commits by one maintainer, CI matrix PHP 8.1–8.5 × Laravel 8–13 (`.github/workflows/tests.yml:19-21`) · PHP `^8.1`; `illuminate/{support,cache,console,contracts}` `^8.0||…||^13.0` (`composer.json:43-48`) · Category: **framework-level cache optimization / observability package; not Eloquent- or query-aware** · Research date **2026-11-09**.

## 0. TL;DR

- A drop-in decorator for Laravel's cache Repository (Facade + `smart_cache()` helper) that adds value-space transforms (gzip compression, chunking, optional encryption/serialization), write-path bookkeeping (managed-key index, tag/dependency maps, content-hash dedup), resilience (self-healing, circuit breaker, single-flight lock, SWR, TTL jitter) and ops tooling (dashboard, 6 Artisan commands). One huge class: `src/SmartCache.php` is **4,802 lines / 170 methods**.
- Strongest idea worth stealing: **versioned, immutable chunk keys** + a wrapper that lists them + delete-old-chunks-after-write + one re-read when a chunk read races a rewrite (`ChunkingStrategy.php:184-224`, `SmartCache.php:848-873`, `SmartCache.php:2038-2068`). It is the only credible way to cache payloads past Memcached 1 MB / DynamoDB 400 KB without mixing versions.
- Best UX: one-line import swap, and `smart-cache:bench --profile=… --format=json` that reports bytes saved **plus** `data_integrity` and `key_shape_preserved` (`BenchCommand.php:132-150`); `rememberWithLock()` never fails where `remember()` would — it degrades to running the callback without the lock (`SmartCache.php:3968-4004`).
- Biggest risk: it caches *values*, not queries. There is no fingerprinting, no model semantics, no key validation, no Filament/Livewire awareness (grep of `src/` for filament/livewire/octane: **zero matches**). Its home-grown metadata substrate is an extra write per `put()`, and the 1.16/1.17 changelogs are a catalogue of concurrency/index-loss bugs (`CHANGELOG.md:8-67`).
- Verdict: **Adapt, do not adopt.** Graft ~5 mechanisms (chunk protocol, single-flight, SWR metadata, jitter, after-commit invalidation), reject its tag-by-scan/managed-key-index/circuit-breaker/memo/encryption machinery — our version-token correctness layer already replaces the expensive parts.

## 1. What it does & category

`iazaran/smart-cache` is a **cache store decorator**, not a query cache. It implements `Illuminate\Contracts\Cache\Repository` (+ the package contract that extends it, `Contracts/SmartCache.php:14`) and is bound as the `smart-cache` singleton (`Providers/SmartCacheServiceProvider.php:36-158`). Stored values keep their **original caller-defined key**; the package only changes the stored payload ("wrapper" arrays with `_sc_*` markers) and writes sibling metadata keys into the same store.

Three layers:
1. **Value optimization pipeline** — one strategy per value, ordered encryption → chunking → compression → serialization (`SmartCacheServiceProvider.php:50-142`, applied at `SmartCache.php:1934-1981`).
2. **Write-path bookkeeping + invalidation indexes** — `_sc_managed_keys`, `_sc_tag_{tag}`, `_sc_dependencies`, `_sc_dna:{key}`, `_sc_meta:{key}`, `_sc_chunk_registry`, metrics/cost metadata, all in the same cache store. No migrations, no DB tables, no service dependencies.
3. **Resilience & ops** — self-healing, dedup, circuit breaker, rate limiter, locks, SWR variants, TTL jitter, dashboard, 6 commands, 6 events, per-request memo.

Positioning: essentially "Rails-style cache store extensions for Laravel", vendored as a Repository replacement. It is a **general-purpose payload cache optimizer with invalidation conveniences**, marketed for large payloads/high traffic (`README.md:11-45`). Eloquent enters only through an optional model trait/observer that flushes user-declared keys/tags/patterns on write — it does not cache any Eloquent query (`Traits/CacheInvalidation.php:160-198`).

## 2. Architecture & integration model

### 2.1 Code map (path → role)

| Path | Role |
|---|---|
| `src/SmartCache.php` | God class: Repository + contract, strategies, tags, deps, SWR, locks, dedup, self-heal, memo, metrics, cost, circuit breaker, commands, jitter (4,802 lines, 170 methods; ctor `:393-440`) |
| `src/Contracts/SmartCache.php` | Public contract, extends `Repository`; adds SWR, tags, deps, namespaces, locks, stats, commands (438 lines) |
| `src/Contracts/OptimizationStrategy.php` | 4-method SPI: `shouldApply / optimize / restore / getIdentifier` (`:14-39`) |
| `src/Providers/SmartCacheServiceProvider.php` | Config merge, singleton binding, ordered strategy list + restore-only decoders, commands, dashboard routes, terminating hooks, queue `Looping` refresh (`:28-327`) |
| `src/Strategies/CompressionStrategy.php` | gzip level 6, threshold 50 KB, `_sc_compressed` wrapper (`:28-159`) |
| `src/Strategies/AdaptiveCompressionStrategy.php` | samples 1 KB, picks level 3/6/9 from ratio + access frequency (`:206-251`) |
| `src/Strategies/ChunkingStrategy.php` | >100 KB arrays/Traversables split into 1000-item chunks; versioned chunk keys (`:86-301`) |
| `src/Strategies/EncryptionStrategy.php` | serialise → `encrypter->encrypt`, key/pattern opt-in (`:131-169`) |
| `src/Strategies/SmartSerializationStrategy.php` | JSON → igbinary → PHP serialize per value (`:126-149`) |
| `src/Strategies/RestoreOnlyStrategy.php` | Decodes entries of a **disabled** strategy, never encodes (`:20-92`) |
| `src/Collections/LazyChunkedCollection.php` | Iterator/Countable/ArrayAccess/JsonSerializable over chunk keys, max 3 chunks loaded (`:19-486`) |
| `src/Drivers/MemoizedCacheDriver.php` | Per-request memo Repository with O(1) LRU + negative cache (639 lines) |
| `src/Traits/CacheInvalidation.php` + `src/Observers/CacheInvalidationObserver.php` | Eloquent hook: declarative keys/tags/patterns/dependencies, `{attribute}` placeholders, after-commit deferral |
| `src/Traits/HasLocks.php`, `src/Traits/DispatchesCacheEvents.php` | Lock helpers; 6 event dispatchers gated by config |
| `src/Services/CacheInvalidationService.php` | Pattern/glob/regex invalidation over the managed-key index; model-relation patterns; health check (`:69-343`) |
| `src/Services/{CostAwareCacheManager,CircuitBreaker,RateLimiter,SmartChunkSizeCalculator,OrphanChunkCleanupService}.php` | Scoring, breaker, XFetch/limits, chunk cleanup registry |
| `src/Console/Commands/*` + `src/Console/Concerns/EnumeratesCacheKeys.php` | clear/status/audit/bench/warm/cleanup-chunks + Redis SCAN enumeration |
| `src/Http/Controllers/StatisticsController.php` + `routes/smart-cache.php` | 5 GET endpoints, inline HTML dashboard |
| `src/Events/*` | CacheHit, CacheMissed, KeyWritten, KeyForgotten, OptimizationApplied, TagFlushed |
| `config/smart-cache.php` | 447-line published config; **no `env()` calls at all** |

### 2.2 Request flow (text diagram)

```
WRITE put(k,v,ttl)
 ├ jitter TTL if enabled ................................ SmartCache.php:706-709
 ├ take pending tags; TTL<=0 → delete+chunks ........... :711-721
 ├ wrap null in sentinel marker ........................ :723-724, 83-110
 ├ strategy loop, FIRST shouldApply wins ............... :1934-1981
 │   order encryption→chunking→compression→serialization SmartCacheServiceProvider.php:50-142
 ├ managed-key index update (batched; locked merge) .... :730, 2125-2231
 ├ tag index append per tag (metadata lock) ............ :732-734, 3283-3359
 ├ DNA dedup: content hash + TTL-aware skip ............ :736-789
 ├ storeOptimized(): write wrapper, then drop replaced chunks :848-873
 └ metrics + KeyWritten ................................ :791-803

READ get(k)
 ├ normalise key + namespace ........................... :474
 ├ repositoryFor(): memo layer or store ................ :982-993
 ├ miss → CacheMissed + bind tags for next write ....... :477-493
 ├ restore loop: enabled strategies then restore-only decoders :2029-2117
 │   chunked entry replaced mid-read → re-read up to 3x  :2050-2068
 │   corrupt → self-heal evict + miss (opt-in) ......... :2070-2100
 └ unwrap null marker, CacheHit, adaptive access count .. :495-504, 2115-2116
```

### 2.3 Storage layout (all in the configured cache store; no migrations)

- **Values** at the caller's key; optimized payloads carry one top-level marker: `_sc_compressed` (`CompressionStrategy.php:106-110`), `_sc_chunked` + `chunk_keys` (`ChunkingStrategy.php:214-224`), `_sc_serialized`, `_sc_encrypted`.
- **Chunks**: `_sc_chunk_{originalKey}_{version}_{index}` where `version = bin2hex(random_bytes(4))` per write (`ChunkingStrategy.php:184-188`); no `chr(1)`/binary sentinel is used anywhere in this package (grep `chr(1)`, `\x01` → none).
- **Metadata keys**: `_sc_managed_keys` (forever, `:2218-2222`), `_sc_tag_{tag}` (forever key-lists, `:3332-3350`), `_sc_dependencies` (`:3490`), `_sc_dna:{key}` (hash+expiry, `:785-788`), `_sc_meta:{key}` (fresh/stale timestamps, `:2544, 2624-2635`), `_sc_chunk_registry` (`OrphanChunkCleanupService.php:20`), `_sc_cost_metadata`, `_sc_performance_metrics`, `_sc_adaptive_freq`, `_sc_rate:{key}`, `_sc_circuit_breaker:{driver}`, locks `_sc_meta_lock:{sha1(scope)}` and `_sc_remember_lock:{sha1(key)}`, `_sc_swr_refresh:{key}`.
- Operational consequence, documented (`README.md:229-234`): dedicated store/prefix recommended; `_sc_*` shares key space with the application.

## 3. Feature inventory

| Feature | Supported? | How it works (path:line) | Quality / limits |
|---|---|---|---|
| Compression | ✅ on by default | gzip level 6, 50 KB threshold; wrapper is `base64(gzencode(raw string or serialize(v)))` (`CompressionStrategy.php:28,99-111,116-159`; config `:21-24,39-49`) | ~+33 % base64 inflation; encode on write, base64+gzip decode (+unserialize of non-strings) on **every read**; zlib-absent → silently no-op (`:39-42`) |
| Chunking / large-value splitting | ✅ on, arrays & Traversables >100 KB and >`chunk_size` items (`ChunkingStrategy.php:86-146,151-225`) | `array_chunk`, one store put per chunk, versioned keys, wrapper lists keys (`:176-224`); eager re-merge or lazy object (`:244-301`) | Multi-key writes are not atomic; store rejecting a chunk throws then falls back (`:190-204`); eager restore is N+1 gets and one `RuntimeException` on any missing chunk (`:278-288`) |
| `chr(1)` chunking / delimiter keys | ❌ not used | No `chr(1)`/`\x01` in `src/`; metadata is an **array wrapper**, not a delimiter protocol | Simpler to read, but wrapper keys are visible to any raw-store reader; not portable across apps without the package |
| Optimization strategies & ordering | ✅ | `OptimizationStrategy` SPI; provider builds order encryption→chunking→compression→serialization (`SmartCacheServiceProvider.php:50-142`); first `shouldApply` wins, one strategy per value (`SmartCache.php:1945-1978`) | Disabled strategies still decode via `RestoreOnlyStrategy` (good migration trick); no config hook to register custom strategy classes — only `addStrategy()` on a resolved instance (`SmartCache.php:448-452`) |
| SWR / flexible caching | ✅ (re-implementation) | `flexible($key,[fresh,stale])` stores timestamps in `_sc_meta:{key}`, serves fresh, else refreshes **synchronously** before returning stale (`SmartCache.php:2532-2589,2609-2642,2669-2707`); `swr/stale/refreshAhead` are thin wrappers (`:2720-2759`) | Method named `refreshInBackground` is synchronous in-process — stale caller pays refresh latency; only `asyncSwr()` queues a job (`:2774-2846`); separate meta key = extra read and can desync |
| Tag-based invalidation | ✅ all drivers | Own index `_sc_tag_{tag}` = list of fully-qualified keys, mutated under a metadata lock (`SmartCache.php:3283-3359,2873-2901`); `flushTags` forgets each key then prunes index (`:3045-3077`) | One extra forever-key write per tag per put; index can grow unbounded; per-key forget cost on flush; `supportsTags()` always true (`:2898-2901`) |
| Pattern-based invalidation | ✅ via managed-key index | `flushPatterns()` scans every managed key, glob→regex or `/regex/` (`CacheInvalidationService.php:69-105,214-242`) | O(all managed keys) per call, in PHP; needs the index to be complete; only as good as key naming conventions |
| Scoped tags | ⚠️ opt-in, default legacy | `tags.scoped=false` default (`config:271-273`); "fresh/keys/flushable" state machine (`SmartCache.php:224-249,2873-3040`) | Default footgun: tagged `get()` miss tags the **next write whatever key** (extra invalidation, never stale) — README recommends `true` for new apps (`README.md:227`) |
| Dependency tracking | ✅ | `dependsOn` writes `_sc_dependencies` map under lock; `invalidate()` walks reverse edges (`SmartCache.php:3183-3213,3218-3250,3429-3442,3475-3492`) | Reverse lookup is a full scan of the dependency map per invalidation; namespace needs duplicated edges (`:3193-3207`) |
| Locking / `rememberWithLock` | ✅ opt-in per call | On miss acquire `_sc_remember_lock:{sha1(key)}`, poll store every 100 ms up to `$waitSeconds`, re-check value; release in `finally`; no LockProvider → run unlocked (`SmartCache.php:3892-4004`; `REGENERATION_LOCK_POLL_MICROSECONDS=100000` `:37`) | Polling wastes up to 100 ms latency; DB store needs `cache_locks` table (`README.md:233`); lock TTL can expire under slow callbacks; correctness still fine (falls back) |
| Metadata locks | ✅ on by default | `withMetadataLock()` wraps metadata mutations; `lock->block($wait)` with 25 ms inter-attempt sleep; release failure never re-runs the update (`SmartCache.php:3361-3415`, consts `:44`) | Best-effort: unsupported locks silently execute unlocked; TTL 5 s / wait 1 s (`config:230-234`) — two processes can still lose an update on non-locking stores |
| Memoization | ✅ | `memo()` wraps repository in `MemoizedCacheDriver` (LRU 1000, negative-miss cache, TTL=0 touches forget) (`SmartCache.php:4709-4752`; `Drivers/MemoizedCacheDriver.php:47-162`); Laravel 12.9+ `Cache::memo()` opt-in (`config:292-294`, `SmartCache.php:4763-4787`) | Bookkeeping reads bypass memo (fix in 1.16); stale-memo/`add()` workaround uses a static `WeakMap` (`:168-177, 1002-1009`); memory bounded per layer but nested layers multiply |
| Dashboard / HTTP endpoints + auth/security | ⚠️ off by default | 5 GET routes (`routes/smart-cache.php:17-21`) under prefix/middleware config; controller re-checks `dashboard.enabled` to defeat cached routes (`StatisticsController.php:26-31`); escaped HTML + class sanitising (`:156-217`); default `['web']` = **no auth** (`config:409-427`) | If enabled unguarded it leaks managed key names (often embed tenant/user IDs); plain HTML, no Filament/Livewire integration; expensive endpoints (`/statistics`, `/health`) read every managed value |
| audit / bench / status / clear commands | ✅ | `smart-cache:clear`, `:status`, `:audit`, `:bench`, `:cleanup-chunks`, `:warm` (`SmartCacheServiceProvider.php:233-264`); audit JSON/table with orphan/broken-chunk detection (`AuditCommand.php:21-150`); bench profiles + integrity (`BenchCommand.php:14-19,82-151`); `clear --force` deletes non-managed store keys (`ClearCommand.php:123-168`) | `--force` is a store-wide destructive operation on shared stores; key enumeration only for Redis/array (`EnumeratesCacheKeys.php:19-36`); bench is sequential and timing-based |
| Events | ✅ off by default | 6 events; dispatch gated by `events.enabled` + per-event flags (`config:397-407`; `Traits/DispatchesCacheEvents.php:31-108`) | Events carry **values** (PII risk in listeners/logs); disabled-by-default is the right call for hot paths |
| Model observers / Eloquent integration | ✅ optional trait | `CacheInvalidation` trait registers `created/updated/deleted/restored` listeners in `boot()`, delegates to observer (`Traits/CacheInvalidation.php:29-38`); declarative `cacheInvalidation()` or `get*ToInvalidate()` overrides; `{attr}` placeholders (`:426-458`); original values for updates (`:214-227`); `afterCommit` deferral when in transaction (`Observer:61-82,121-140`; config `:247-249`) | Only **invalidates manually declared keys** — caches nothing; query-builder/mass updates bypass it (documented `README.md:133`); static `flushCacheTags()` offered as manual escape hatch (`Trait:243-259`); model IDs/placeholders leak into key names by design |
| Multi-store / driver support | ✅ | `store($name)` re-binds strategies to another store (`SmartCache.php:1208-1245`); per-driver override config `drivers.{redis,file,memcached,dynamodb,database,array}` (`config:376-386`); driver detected from store class (`SmartCache.php:2511-2518`); chunk sizing consults per-driver limits (`SmartChunkSizeCalculator.php:20-28`) | Driver defaults for database/dynamodb are **not** pre-set in config (only file/memcached); no runtime capability probe — lock/tag/serializer assumptions are manual |
| TTL semantics | ✅ Laravel-compatible | `ttlSeconds()` accepts int/float/numeric string/DateInterval/DateTime (`SmartCache.php:923-950`); TTL≤0 deletes entry+chunks+meta (`:715-721`); null = forever; jitter may shorten **or lengthen** TTL (`:4101-4147`) | Jitter extending TTL can outlive the freshness contract caller asked for; `_sc_meta` TTL equals stale TTL (if value outlives meta → treated as miss) |
| Key enumeration | ⚠️ partial | `EnumeratesCacheKeys`: Redis via `SCAN` (never `KEYS`), strip connection+store prefixes, cluster masters; array store via `all()`; everything else throws (`:19-36,53-128,151-314`) | Only used by clear/status/audit `--force`; no database/file/memcached enumeration; managed-key index is the primary enumeration surface |
| Laravel 12 SWR compatibility | ⚠️ claim untested | Own `flexible()` implementation; test file `Laravel12SWRTest` only asserts method existence and uses `sleep()` (`tests/Unit/Laravel12/Laravel12SWRTest.php:19-127`); does not call Laravel's `Repository::flexible()` or `Cache::flexible()` | No evidence of interop with framework flexible caching/`rememberWithWarmth` beyond re-implementing signatures (`SmartCache.php:1120-1159`) |
| Octane / long-running runtimes | ✅ | `reset()` clears per-request state; service provider runs it on `terminating()`; queue `Looping` calls `refreshState()`; docs mention Swoole/FrankenPHP/RoadRunner (`SmartCache.php:2301-2360`; provider `:215-228,278-300`; `CHANGELOG.md:301`) | Depends on lifecycle hooks firing; no Octane dev dependency, only `reset()`/single-flight unit tests (`tests/Unit/V115FixesTest.php:140`, `V112FeaturesTest.php:29-140`), no runtime Octane test; singleton holds mutable `activeTags`/`activeNamespace` by design |
| Multi-tenancy | ⚠️ manual | `namespace($ns)` prefixes keys; tag/pattern/dependency logic special-cases namespaced keys; `flushNamespace()` scans managed index (`SmartCache.php:3085-3178,3193-3207`; `CHANGELOG.md:110-113`) | Namespace is per-call mutable instance state; model invalidation is global (over-invalidates); no tenant context resolution |
| Filament / Livewire awareness | ❌ none | grep for `filament`/`livewire` under `src/` → zero matches; dashboard is hand-written HTML (`StatisticsController.php:144-217`); no panel/tenant/user key segments | Nothing to reuse for Filament bridge; any integration must be built by us |
| Write deduplication ("Cache DNA") | ✅ on by default | Hash optimized value, read `_sc_dna:{key}`, skip write only if content equal **and** stored expiry outlives requested one (`SmartCache.php:736-789,1796-1895`; config `:161-163`) | One extra serialize+hash+read per write; skipped write keeps old TTL (correctly guarded); chunked writes never skipped |
| Self-healing | ✅ on by default | Restore errors → evict entry+chunks+meta+DNA, return miss so `remember()` regenerates; logs warning/info (`SmartCache.php:2038-2117`; config `:176-178`) | Failure is a miss (correct), but silently loses data; swallows decode errors of any cause unless fallback.log_errors |
| Circuit breaker + fallback | ✅ opt-in | closed/open/half-open; optional shared state in cache (`Services/CircuitBreaker.php:54-318`; config `:82-91`) | Per-instance by default (useless under FPM unless `shared`); wrapping the whole cache call adds class overhead for an outage that a try/catch+DB fallback handles |
| Cost-aware scoring | ✅ on by default | GreedyDual-ish `cost*ln(1+freq)*decay/size` in `_sc_cost_metadata` (`CostAwareCacheManager.php:65-122`; config `:144-148`); calls `suggestEvictions()` | Diagnostic only — never evicts actual entries; metadata persisted 24 h; needs values sized/serialized for cost |
| Monitoring / performance metrics | ✅ on by default | Per-request buffers of hit/miss/write/dedup counts + sizes, persisted at terminate (`SmartCache.php:4384-4535,4457-4605`; config `:345-349`); `analyzePerformance()` recommendations | Extra `serialize()` for sizes on writes; metrics are sample-like and per-process until flushed; metrics key is a single shared blob (write contention) |
| Cache warmers | ✅ | `CacheWarmer` contract (`Contracts/CacheWarmer.php:11-33`), `smart-cache:warm --warmer=* --list`, config map `warmers` (`config:443-446`) | Warmer contract is global (not tenant/panel aware); sequential execution |
| Encryption / smart serialization | ✅ opt-in | `encrypt()` after serialize; keys/PCRE patterns/`encrypt_all` (`EncryptionStrategy.php:44-140`); JSON/igbinary/PHP choice with JSON round-trip safety (`SmartSerializationStrategy.php:126-189`) | Encryption: APP_KEY rotation = mass miss (self-heal evicts), no key versions, CPU cost; serialization: opt-in `serializable_classes` needed to avoid `__PHP_Incomplete_Class` mismatch (`config:296-317`; `Traits/Concerns/RestrictsUnserialization.php:16-59`) |

## 4. Configuration & flexibility

**Publish tag**: only `smart-cache-config` (`SmartCacheServiceProvider.php:167-169`). **Environment variables**: **none** — `grep 'env(' config/` returns zero; every default is a literal and can only be overridden in `config/smart-cache.php` or at runtime with `config()`.

| Config key | Default | Env | Meaning | Tradeoff |
|---|---|---|---|---|
| `thresholds.compression` | `51200` (50 KB) | — | size that triggers compression | lower = more CPU, smaller store |
| `thresholds.chunking` | `102400` (100 KB) | — | size that triggers chunking | lower = more keys + read fan-out |
| `strategies.compression.enabled` | `true` | — | off ⇒ decode-only | existing entries still readable (`RestoreOnlyStrategy`) |
| `strategies.compression.mode` | `'fixed'` | — | `fixed` vs `adaptive` | adaptive costs a 1 KB sample + frequency bookkeeping |
| `strategies.compression.level` | `6` | — | gzip 0-9 | 9 = slower writes/reads |
| `strategies.compression.adaptive.*` | `sample_size=1024`, `high=0.5`, `low=0.7`, `frequency=100` | — | level selection thresholds (config `:43-48`) | tunables only; frequency persisted to `_sc_adaptive_freq` (24 h) |
| `strategies.chunking.enabled` | `true` | — | off ⇒ decode-only | |
| `strategies.chunking.chunk_size` | `1000` | — | items per chunk | smaller = more store round-trips on eager restore |
| `strategies.chunking.lazy_loading` | `false` | — | return `LazyChunkedCollection` instead of merging | lazy object is not a real `Collection`; breaks typed consumers |
| `strategies.chunking.smart_sizing` | `false` | — | driver-limit-derived chunk size (`SmartChunkSizeCalculator.php:38-78`) | samples + serialize overhead per write |
| `strategies.encryption.*` | `enabled=false`, `keys=[]`, `patterns=[]`, `encrypt_all=false` | — | encrypt selected/all values | CPU + APP_KEY coupling; patterns are **PCRE**, globs silently match nothing (now warned, `EncryptionStrategy.php:85-104`) |
| `strategies.serialization.*` | `enabled=false`, `preferred_method='auto'`, `auto_detect=true`, `size_threshold=1024` | — | JSON/igbinary/PHP per value | duplicate of what the store already does; only wins with igbinary |
| `circuit_breaker.*` | `enabled=false`, `failure_threshold=5`, `recovery_timeout=30`, `success_threshold=3`, `shared=false`, `shared_ttl=300` | — | breaker for backend failures (`config:82-91`) | off by default; only meaningful with `shared=true` in multi-process |
| `rate_limiter.*` | `enabled=true` **but flag not read**, `window=60`, `max_attempts=10` | — | throttle + XFetch rate-limit | config comment admits the flag is dead (`config:101-106`) |
| `jitter.*` | `enabled=false`, `percentage=0.1` | — | randomize TTL ±pct | may extend TTL past caller intent |
| `fallback.*` | `enabled=true`, `log_errors=true` | — | on optimization/restore failure: log, continue with plain value / throw | hide real corruption if too quiet |
| `cost_aware.*` | `enabled=true`, `max_tracked_keys=1000`, `metadata_ttl=86400` | — | value-score metadata for eviction hints | extra metadata blob + serialization work |
| `deduplication.enabled` | `true` | — | skip identical writes | hash+extra read per put; chunked writes never skipped |
| `self_healing.enabled` | `true` | — | evict corrupt entries | silent data loss on decode bugs |
| `swr.single_flight` / `lock_ttl` | `false` / `30` | — | opportunistic lock around SWR refresh (`SmartCache.php:2669-2707`) | adds a lock only when store supports it; off keeps legacy |
| `managed_keys.max_tracked` | `null` (unbounded) | — | cap managed-key index, drop oldest | capping breaks precise clear/pattern invalidation |
| `metadata_lock.*` | `enabled=true`, `ttl=5`, `wait=1` | — | lock around tag/dep/index mutations | best-effort fallback; extra round trips |
| `model_invalidation.after_commit` | `true` | — | defer model invalidation to DB commit | requires DB transaction support |
| `tags.scoped` | `false` | — | scope tags to looked-up keys | new apps should set `true` (README:227) |
| `memo.native` | `false` | — | use Laravel 12.9+ `Cache::memo()` | requires framework support; adds shared state |
| `serializable_classes` | `null` | — | `null` / `'cache'` / `false` / class list for package-side `unserialize()` | null = unrestricted (historical behavior); `'cache'` aligns with Laravel 13 skeleton policy |
| `chunk_registry.persist_every` | `1` | — | batch chunk-registry writes | batching saves writes, delays orphan tracking |
| `monitoring.enabled` / `metrics_ttl` / `recent_entries_limit` | `true` / `3600` / `100` | — | hit/miss + size metrics | extra serialize + shared metrics key |
| `warnings.hit_ratio_threshold` etc. | `70` / `20` / `0.1` | — | `analyzePerformance()` thresholds | diagnostics only |
| `drivers.{name}` | `redis=null`, `file={compression,chunking}`, `memcached={compression,chunking}` | — | per-driver strategy overrides (`config:376-386`) | only consulted via `context.config` in strategies; other drivers silently inherit globals |
| `events.enabled` + 6 flags | `false`, all `true` | — | dispatch cache events | value payloads in events = PII/log-volume risk |
| `dashboard.enabled` / `prefix` / `middleware` | `false` / `'smart-cache'` / `['web']` | — | opt-in HTTP surface | default middleware has no auth (config comments warn) |
| `warmers` | `[]` | — | warmer class map for `smart-cache:warm` | global, no tenant scoping |

**Per-call APIs** (all on the `SmartCache` facade/contract unless noted): Repository parity incl. typed getters and `rememberWithWarmth()` (`src/SmartCache.php:467-1600,1120-1159`); SWR `flexible/swr/stale/refreshAhead/asyncSwr/refreshAsync` (`:2532-2846`); invalidation `tags/flushTags/dependsOn/invalidate/flushPatterns/invalidateModel/namespace/flushNamespace` (`:2873-3538`); resilience `rememberWithLock/rememberWithStampedeProtection/throttle/withFallback/withCircuitBreaker/lock` (`:3701-4004`); memo & jitter `memo/withJitter/putWithJitter/rememberWithJitter` (`:4101-4225,4709-4752`); diagnostics `cacheValue/getCacheValueReport/suggestEvictions/getStatistics/healthCheck/getPerformanceMetrics/analyzePerformance/getManagedKeys/cleanupExpiredManagedKeys/getAvailableCommands/executeCommand` (`:1687-1744,3545-3558,4232-4610`); plus the `smart_cache()` helper (`src/helpers.php:5-35`).

**Strategy registration / extension points**: implement `SmartCache\Contracts\OptimizationStrategy` and `addStrategy()` on a resolved instance (`SmartCache.php:448-452`) — but the provider's strategy list is rebuilt at singleton construction, so per-request additions do not persist; there is **no config key for custom strategy classes**. Real extension points are: the `SmartCache` contract binding, `repository()`/`store()` raw access (`SmartCache.php:1208-1265`), and the model trait's `get*ToInvalidate()` overrides.

**Opt-out paths**: per-strategy `enabled=false` (decode-only, so old entries keep restoring); `drivers.*` per store; `events.enabled=false`; `monitoring.enabled=false`; `deduplication`/`self_healing`/`cost_aware` flags; `repository()` to bypass all transforms for one call; there is **no per-key/per-call "skip optimization" API** and no "disable tags" switch — tags are always on. Code excerpt of ordering/fallback (provider, abbreviated):

```php
// Order matters: the first strategy that applies to a value wins.
$strategies = []; $restoreStrategies = [];
if (config('smart-cache.strategies.encryption.enabled', false)) { $strategies[] = /* EncryptionStrategy */; }
else { $restoreStrategies[] = new RestoreOnlyStrategy('encryption', '_sc_encrypted', fn () => /* ... */); }
if (config('smart-cache.strategies.chunking.enabled', true))       { $strategies[] = /* ChunkingStrategy */; }
if (config('smart-cache.strategies.compression.enabled', true))    { $strategies[] = /* Compression/Adaptive */; }
if (config('smart-cache.strategies.serialization.enabled', false)) { $strategies[] = /* SmartSerializationStrategy */; }
return new SmartCache($cacheManager->store(), $cacheManager, $config, $strategies, $costAwareManager, $restoreStrategies);
```
(`SmartCacheServiceProvider.php:50-154`, condensed)

## 5. Ideas worth stealing (ranked)

1. **Versioned immutable chunk keys + wrapper + post-write cleanup + race re-read** — the whole chunk protocol at `ChunkingStrategy.php:184-224` and `SmartCache.php:848-873`; race handling at `SmartCache.php:2038-2068`. For OoMyCache: when a result payload exceeds `max_payload_bytes`, split under `oomycache:chunk:{fingerprint}:{version}:{i}`, wrapper `{v, kind, keys, count}`; write all chunks, then wrapper; delete old version's chunks only after wrapper write; on a missing chunk during read, re-read the wrapper once and if the version changed restore the new one instead of treating it as a corrupt miss. This is P0 for "heavy data" Filament tables and the one thing smart-cache gets right.
2. **`rememberWithLock` single-flight semantics** (`SmartCache.php:3892-4004`, lock name hashed at `:3980`, 100 ms poll, `finally` release, unlocked fallback). Adapt as our optional P1 stampede guard: hash fingerprint lock name, lock TTL above expected query time, waiters poll the store for the winner's value before attempting the lock; never fail a read because locks are unavailable; log a debug event when skipped. Keep it off by default for latency-sensitive ERP pages (plan §6 already says this).
3. **SWR metadata envelope + explicit fresh/stale state machine** (`SmartCache.php:2532-2589,2609-2642`; `_sc_meta` at `:2544,2624-2635`). Adapt but merge meta into the payload envelope (single key, atomic, one round-trip instead of their value+meta pair) and make the refresh **queued** (`asyncSwr` pattern + `BackgroundCacheRefreshJob.php:86-131`), never in-process blocking for Filament pages; serve stale on refresh failure.
4. **Self-healing + restore-only decoders** (`SmartCache.php:2038-2117`; `RestoreOnlyStrategy.php:20-92`; config `serializable_classes` `:296-317`). Adapt: magic/version header in our envelope (plan §7.4) and a decoder registry; unknown/old format or decode error ⇒ delete entry, emit `QueryCacheMissed(reason=corrupt-payload)`, fall through to DB; a per-format decoder lets new releases read old payloads during rolling deploys. Do **not** copy their blanket log-and-serve-internal-wrapper fallback.
5. **TTL jitter** (`SmartCache.php:4101-4225`, config `:116-119`, Facade `putWithJitter/rememberWithJitter`). Plan already has `ttl_jitter` P0; implement as deterministic-per-fingerprint hash jitter for tests, and **only subtract** (or clamp to ≤ requested TTL) so we never extend a freshness contract; default 10 % like theirs.
6. **Metadata lock helper with adaptive retry + best-effort fallback** (`SmartCache.php:3361-3415`, `:44`; `lock->betweenBlockedAttemptsSleepFor(25)`). Adapt for mutable optional indexes (stats/purge registry), re-reading the stored value inside the lock and applying only this process's delta — their 1.16 fix (`CHANGELOG.md:106`) is exactly the lost-update pattern we must avoid if we ever keep a registry. Our version tokens keep the correctness path lock-free.
7. **After-commit model invalidation with original-value targets** (`Observers/CacheInvalidationObserver.php:61-82,121-140`; `Traits/CacheInvalidation.php:214-227`; `config:247-249`). Adapt the decision, not the code: Eloquent `updated` fires inside an open transaction; defer invalidation via `$connection->afterCommit()`; resolve relation/placeholder targets against `getOriginal()` **at event time** so moving a row between parents invalidates both old and new parent keys (plan §5 already targets this; smart-cache is corroboration that it bites in practice).
8. **`rememberIf` conditional caching** (`SmartCache.php:1897-1924`, contract `:424-437`). Cheap: only cache when predicate passes (e.g. non-empty page, no error marker, payload under budget); avoids poisoning cache with empty/error result sets. Add as per-query `cacheIf()` hook.
9. **Bench command with profiles, integrity and key-shape assertions** (`BenchCommand.php:14-19,82-151`; profiles `medium/api-json/large-array/sparse-array`; output `data_integrity`, `key_shape_preserved`, JSON report). Adapt for `oomycache:bench`: one profile per payload class (table page, totals, options, aggregate), assert decoded payload identical incl. collection shape (Eloquent Collection vs array), write JSON artifact to compare releases.
10. **Dashboard-by-config with controller kill-switch** (`StatisticsController.php:26-31`; `config:409-427`; route-cache guard provider `:311-318`). Adapt as a **Filament page/widget** with `canView()` + policy + panel tenant scoping, not middleware config; the kill-switch idea (404 even when routes are cached) is worth keeping; never expose key lists unauthenticated.
11. **Warmer contract** (`Contracts/CacheWarmer.php:11-33`; `WarmCacheCommand.php`; `config:443-446`) — adapt to plan P1 `WarmableQuery` + per-tenant iteration in a queued job; do not couple warmers to a global singleton map.
12. **`store()` re-binding with inherited runtime config** (`SmartCache.php:1208-1245,4734-4751`) — a small but real API-shape lesson: if OoMyCache supports multiple stores/panels, `store($name)` must copy tenant/panel/serializer settings, not reset them (their bug where `memo()` dropped the namespace is fixed at `CHANGELOG.md:156`).

## 6. Weaknesses, risks, footguns

- **Not query-aware**: keys are whatever the caller passes; no fingerprint, no schema/class correctness, no relation awareness. Caching `Model` instances via this package is just `serialize()` of models — brittle across deploys/class renames and misaligned with Laravel 13's `cache.serializable_classes=false` skeleton (only partially addressed by opt-in `serializable_classes`, `config:296-317`).
- **Correctness by index is fragile**: managed-key index/tag/dependency maps/chunk registry are best-effort; a lost metadata write leaves entries unreachable by invalidation. 1.16 fixed index loss across processes (`CHANGELOG.md:106`), 1.17 fixed registry loss (`:26-28`) — evidence this class of bug recurs. Our plan correctly makes version tokens, not indexes, the correctness layer.
- **Per-write overhead**: on the default path every `put()` does a strategy scan (+ serialize for threshold checks), `trackKey`, tag-index writes (if tagged), a hash + `_sc_dna` read/write, and metrics sizing; plus `_sc_managed_keys` merges every 10 changes, all as separate cache round-trips (`SmartCache.php:700-808,2125-2231`). For small ERP query payloads this can cost more than it saves — a major reason to keep our write path to **one** version-token write + one payload write.
- **SWR is synchronous despite the name** (`refreshInBackground`, `SmartCache.php:2669-2707`): a stale Filament page pays regeneration latency, exactly what SWR is supposed to avoid. Only `asyncSwr()` is truly async.
- **Two-key SWR inconsistency**: payload and `_sc_meta` are separate entries with separate TTLs; losing/expiring meta turns a hit into a regenerate (`:2547-2586`), and meta writes add a round-trip on every refresh.
- **Default tag semantics surprise**: `tags.scoped=false` means a tagged miss tags the *next write of any key* (`SmartCache.php:2968-2981`; `config:251-273`); over-invalidation only, but hard to reason about. They now recommend `true` for new apps.
- **`clear()` is not PSR-16 `clear()`**: it is managed-only clear; `flush()` clears the whole store (`SmartCache.php:1533-1536,1627-1656`; `README.md:216-223`). Intentional back-compat, still a trap.
- **Dashboard security default**: disabled, but `middleware=['web']` with no auth once enabled (`config:409-427`); endpoints expose managed key names which routinely embed tenant/user/record IDs. Their own config comment says to add auth (good), but the default is dangerous for copy-paste admins.
- **Destructive `clear --force`**: deletes every non-managed key in the store (`ClearCommand.php:123-168`; `README.md:234`) — unacceptable in a shared ERP cache; our purge must never enumerate-and-delete the store.
- **Chunking failure surface**: N+1 writes/reads, non-atomic wrapper+chunks, Memcached item rejection handling is a throw→fallback (`ChunkingStrategy.php:190-204`), missing chunk ⇒ `RuntimeException` on eager restore (`:278-288`), lazy path returns a non-`Collection` object that any `Collection`-typed consumer would reject (`Collections/LazyChunkedCollection.php:19`). Chunk cleanup relies on a forever registry for forever entries — more metadata to lose.
- **Compression cost/inflation**: `base64(gzencode(...))` per write and decode per read (`CompressionStrategy.php:99-159`); base64 adds 33 %; only repetitive JSON wins, and the 97.6 % README number is a hand-picked repetitive API array (`README.md:185`). Must benchmark per payload class, not trust the headline.
- **Encryption/serialization are traps for us**: APP_KEY rotation mass-misses; custom serializers duplicate the store's own; `serializable_classes` mismatch only fixed opt-in; igbinary payloads can't be restricted at all (`config:313-314`). Skip both for query payloads; arrays-mode avoids all of it (plan §7.1).
- **Memo layer**: `MemoizedCacheDriver` has had memory leaks and semantic bugs (negative cache, `add()` past memo, flush not clearing memo — `CHANGELOG.md:211-212,44,100-101`). Per-request memo is valuable for Livewire, but should be a thin request-scoped decorator, not a 639-line repository with static `WeakMap` hacks.
- **Mutable singleton state**: `activeTags`/`activeNamespace`/load flags live on the singleton; Octane safety depends on a `terminating()` hook and discipline (`SmartCache.php:2301-2360`; provider `:215-228`). A forgotten reset leaks tag/namespace across requests. Our design should keep request context out of singletons.
- **Cost-aware scoring does not evict**: it only suggests (`CostAwareCacheManager.php:65-191`); users hoping for automatic eviction get monitoring instead. Circuit breaker likewise only helps if `shared=true`.
- **Packaging smells**: `Jobs/BackgroundCacheRefreshJob.php:8` imports `Illuminate\Foundation\Bus\Dispatchable` while composer requires only `illuminate/*` components (`composer.json:43-48`); `SmartCache.php:14` imports the testing `EventFake` in production code. Works inside full Laravel, but not a clean component-level dependency.
- **Maintenance**: one maintainer, 39 tags / 18 minor release lines since 2025-04, 1.17 itself an audit-fix release with several behavior changes listed (`CHANGELOG.md:54-62`). Semver maintained, but churn is high; depending on it for correctness-critical cache invalidation is risky.
- **Test/CI blind spots**: CI only exercises the array/file stores (`tests/TestCase.php:63-73`; `phpunit.xml:28-33`); Redis SCAN is tested against fakes (`tests/Unit/Console/EnumeratesCacheKeysTest.php:33`); no real Memcached/DynamoDB/database concurrency, no multi-process/parallel tests; no Eloquent DB integration (mock model, `tests/Unit/Traits/ModelIntegrationTest.php:16-71`); real-time `sleep()` in SWR tests.

## 7. Filament 5 / Livewire 4 compatibility assessment

- **Awareness**: none. No `filament/*` or `livewire/*` dependency, no panel/tenant/user context, no Livewire lifecycle hooks, no table/widget integration (`grep src/`: zero matches). Dashboard is a self-contained HTML page, not a Filament page.
- **May it coexist under Filament 5 / Livewire 4?** As a framework-level Repository decorator on Laravel 12/13 + PHP 8.5: yes, technically — it is the same `Cache` contract Filament/Livewire use, and the CI matrix covers PHP 8.5/Laravel 13. But every Filament request is a Livewire request with many queries and a 50-200 ms latency budget; smart-cache's per-write metadata and synchronous stale refresh are actively harmful there.
- **Reusable under Filament** (with our own code, not their classes): the **compression** and **chunking protocols** are payload-level and framework-agnostic — directly applicable to large table result sets, and especially to the `database` cache store whose BLOB limit (their calculator says 16 MB, `SmartChunkSizeCalculator.php:24`) matters more than Redis. The **SWR metadata** and **single-flight lock** algorithms apply to dashboard widget/table-total keys. **TTL jitter** is almost mandatory: many users open the same Filament table with the same filter key → synchronized expiry → stampede.
- **Incompatible/unsafe under Filament**: `LazyChunkedCollection` is not `Illuminate\Support\Collection`; any consumer that type-hints `Collection|array` (Filament v5 table records, Livewire computed properties) will reject it — inference from the class definition (`Collections/LazyChunkedCollection.php:19`), not something the repo tests. Chunk restore records `collection_class` but the lazy path ignores it (`ChunkingStrategy.php:263-273,315-330`). Synchronous SWR blocks the Livewire response; chunked payloads turn one query into N store round-trips inside a request that already renders a table.
- **Dashboard as Filament page**: yes, and it should be — a panel `Page` (or Widget) with `canView()` gated by policy, tenant-scoped stats, and actions (purge by model/tag/tenant) using Filament notifications/confirmations. Do **not** copy the config-middleware + plain-HTML approach; reuse only the controller kill-switch idea (`StatisticsController.php:26-31`) and escaping discipline (their XSS test, `tests/Feature/DashboardSecurityTest.php:49-68`).
- **Verdict**: smart-cache is a **source of algorithms, not a dependency**. Graft chunking/single-flight/SWR-metadata/jitter into our query-cache layer; write our own Filament bridge; skip everything they bundled specifically for generic values (managed keys, tags-by-scan, deps map, memo driver, encryption, cost-aware, breaker).

## 8. QA / test-suite review

- **Stack**: PHPUnit 9–13, Orchestra Testbench 6–11, Mockery (`composer.json:50-55`); base `TestCase` boots Testbench with the package provider, array store (`serialize=false`) and a file store (`tests/TestCase.php:16-73`); `phpunit.xml` has Unit + Feature suites and `CACHE_DRIVER=array` (`phpunit.xml:11-33`). No Pest.
- **Size**: 39 test classes (34 `Unit` + 5 `Feature`; 41 PHP files incl. `tests/bootstrap.php`, `tests/TestCase.php`) totalling ~13.7 k lines; `TESTING.md:29-33` claims **725 tests / 2,611 assertions** (README says "700+", `README.md:41`). We could not run it: the clone has no `vendor/`, installing is out of scope (read-only rules), and network is blocked — so the count is a claim, not measured.
- **CI**: matrix PHP 8.1–8.5 × Laravel 8–13 with documented exclusions (`.github/workflows/tests.yml:16-57`), plus a PHP 8.3 Xdebug coverage job (`.github/workflows/code-analysis.yml:12-33`). Composer advisory blocking is disabled for EOL framework lines (`tests.yml:80-87`).
- **Notable tests**: `tests/Unit/V117FixesTest.php` (850 lines, newest bug regressions), `V116FeaturesTest.php` (1,054), `SmartCacheTest.php` (1,187), `StrategyOrderingTest.php` (ordering contract), `RememberWithLockTest.php` (333), `ScopedTagsTest.php` (140), `tests/Unit/Locks/AtomicLocksTest.php`, `tests/Unit/Drivers/MemoizationTest.php`, `tests/Unit/Console/EnumeratesCacheKeysTest.php` (fake Redis SCAN + phpredis cursor quirk), `tests/Feature/DashboardSecurityTest.php` (XSS, poisoned shared breaker state, route-cache-disabled 404s), `tests/Unit/Traits/ModelIntegrationTest.php` (mock model with after-commit callback capture at `:223-270`).
- **Gaps that matter for us**: no Redis/Memcached/DynamoDB/database-store integration tests (CI runs the array store; Redis SCAN is exercised against fakes only); no multi-process/concurrent writer tests (`pcntl` unused); no real Eloquent/SQLite transaction tests; no Octane/queue-worker process tests (only `reset()`/`refreshState()` unit tests and the `Looping` listener registration); `Laravel12SWRTest.php` is weak (method-exists + `sleep()`; never exercises Laravel's own `flexible()`/`Cache::memo()` interop); SWR tests sleep 2-4 s each; no payload-size/perf assertions in CI (bench is manual); no Filament/Livewire tests (none possible).
- **How to run**: `composer install && composer test` (`composer.json:74-77`), `vendor/bin/phpunit --testsuite=Unit|Feature`, `--filter=…`, coverage via `composer test-coverage` (`TESTING.md:20-54`). Their CI is the only real matrix verification.

## 9. Verdict: Adopt / Adapt / Avoid for OoMyCache

| Mechanism | Verdict | Payoff | Effort |
|---|---|---|---|
| Versioned chunk keys + wrapper + cleanup + race re-read | **Adopt (design)** | High — enables >1 MB Memcached/DynamoDB payloads and heavy ERP pages; no mixed versions | M (chunk writer/reader + tests; ~300 LOC) |
| Single-flight regeneration (`rememberWithLock` semantics) | **Adapt (P1, opt-in)** | Medium — kills cold-key stampedes on deploy/invalidation | S (~80 LOC + lock capability probe) |
| SWR fresh/stale metadata | **Adapt (single-key envelope, queued refresh)** | Medium — dashboard/widget keys only; never for financial data | M |
| Self-healing + decoder registry / payload version header | **Adopt (design)** | High — safe rolling deploys + corrupt-payload handling | S-M |
| TTL jitter | **Adopt** | High for Filament (many identical filter keys) | S |
| Metadata lock helper (re-read + delta apply) | **Adapt (only for optional indexes)** | Low-Medium — avoids lost-update bugs if we keep any registry | S |
| After-commit model invalidation + original-value targets | **Adopt (decision)** | High — correctness during transactions and relation moves | S (event hooks) |
| `rememberIf` conditional caching | **Adopt** | Low-Medium — prevents empty/error poisoning | XS |
| Bench command with profiles + integrity + JSON | **Adopt (shape)** | Medium — release-over-release evidence | M |
| Cache warms (`CacheWarmer` + `warm --list`) | **Adapt (P1 plan item)** | Medium — deploy warmup for panel opening | M |
| Dashboard-as-Filament-page + kill switch | **Adapt** | Medium — operator trust/purge UI | M |
| Managed-key index + pattern tags + dependency map + tag-by-scan | **Avoid** | Negative — correctness layer is version tokens per plan ADR-2; our purge is O(1), no key scans | — |
| `chr(1)`-style delimited payloads / wrapper markers | **Avoid** | None — use our magic-header envelope | — |
| Encryption strategy | **Avoid** | Negative — APP_KEY rotation, CPU, unserialize risk; arrays-mode only | — |
| Smart serialization (JSON/igbinary) | **Avoid** | Low — duplicate of store, complicates `serializable_classes` | — |
| MemoizedCacheDriver (LRU repo) | **Avoid** | Low — use Laravel 12 `Cache::memo()`/scoped binding thin wrapper | — |
| Cost-aware scoring + managed-key cap + circuit breaker + rate limiter + monitoring blob | **Avoid (v1)** | Low — diagnostics only, adds round-trips | — |
| Lazy chunked collection | **Avoid** | Negative under Filament typed consumers | — |
| Own tag system on non-taggable stores | **Avoid** | Negative — plan uses tags only opportunistically when driver supports | — |

## 10. Evidence map

| Key file | Why it matters |
|---|---|
| `src/SmartCache.php` (4,802 lines) | The implementation; most mechanisms readable in isolation by line range: get/put `:467-809`, restore/self-heal `:2018-2117`, keys index `:2125-2231`, SWR `:2532-2858`, tags `:2873-3077`, namespace/deps/invalidation `:3085-3538`, locks/stampede `:3800-4004`, jitter `:4101-4225`, memo `:4709-4802` |
| `src/Providers/SmartCacheServiceProvider.php` | Strategy ordering, lifecycle hooks, routes/commands, publish tag — the integration model |
| `src/Strategies/ChunkingStrategy.php` | Chunk protocol, versioning, lazy/eager restore, store-rejection fallback |
| `src/Strategies/CompressionStrategy.php` | Marker format, threshold sampling, base64/gzip cost, error handling |
| `src/Strategies/RestoreOnlyStrategy.php` | Decode-only migration trick for disabled features |
| `src/Traits/CacheInvalidation.php` + `src/Observers/CacheInvalidationObserver.php` | Eloquent hook semantics incl. after-commit and original-value placeholders |
| `src/Services/CacheInvalidationService.php` | Pattern/glob/regex invalidation and namespace matching rules |
| `src/Console/Concerns/EnumeratesCacheKeys.php` | The Redis SCAN + prefix/cursor complexity we should avoid needing |
| `src/Console/Commands/BenchCommand.php` | Benchmark shape worth copying (profiles, integrity, key shape, JSON) |
| `src/Http/Controllers/StatisticsController.php` + `routes/smart-cache.php` | Dashboard surface and its auth/security posture |
| `src/Collections/LazyChunkedCollection.php` | Non-Collection lazy object — the typed-consumer edge |
| `config/smart-cache.php` (447 lines) | Every knob; also the config comments document many footguns (scoped tags `:251-273`, memo native `:275-294`, serializable classes `:296-317`, dashboard security `:409-427`) |
| `CHANGELOG.md` | Best "gotchas" source: chunk races (`:86-97`), index loss (`:106`), Redis prefix mismatch (`:107`), memo bugs (`:98-101`), queue/store mismatch (`:34`), console numeric-key bugs (`:39-43`) |
| `tests/Feature/DashboardSecurityTest.php` | Their security test bar: XSS, poisoned shared state, disabled-dashboard 404 |
| `.github/workflows/tests.yml` | Real support matrix (PHP 8.1–8.5 × Laravel 8–13) |

**Git-log observations**: 261 commits since 2025-04-22; 1.0.0→1.17.0 across 39 tags (last tag 2026-10-30, HEAD two commits later: "CI: allow advisory-affected framework releases"). Recent history is dominated by *correctness audits*: `28edaff` memo/tags/chunks, `1c195ea` per-namespace dependency edges, `5ac5986` "label every 1.16 test that also passes on 1.15 as a guard", `6372268` remember-with-lock, `415441e`/`71ce9f2` "Address Codex review" fixes, `3a3a8e7` "Address Fable review of 1.17.0". Lesson: a general value-cache with home-grown indexes accumulates exactly the concurrency/edge-case load this package spent 1.15–1.17 fighting; OoMyCache should keep its correctness state to version tokens + one payload envelope and refuse generic-cache scope creep.
