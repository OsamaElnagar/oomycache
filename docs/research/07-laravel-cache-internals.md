# 07 — Laravel 13 Cache Internals, Drivers & Performance for OoMyCache

> Scope: verified against the installed framework **v13.40.0** (`vendor/laravel/framework/src/Illuminate/Cache`), the dogfood app's `config/cache.php` + `config/database.php`, and official docs (13.x `cache.md`, memcached protocol, AWS DynamoDB constraints, Laravel Octane 2.x source). Claims are `path:line`-cited; `[docs]` = documentation-based; `[measured]` = executed on this machine (PHP 8.5.12, Windows 11); `[reasoned]` = inference, not measured. Companion benchmark scripts were run from `%TEMP%` and are not committed.

## 0. Decision summary (ranked for OoMyCache v1)

1. **Build invalidation on versioned namespaces, not tags.** Tags are unavailable on file/database/dynamodb/storage ([docs] 13.x cache.md, warning under "Cache Tags"), and `RedisTaggedCache::flush()` is O(items) (`RedisTaggedCache.php:166-217`) while a generation-key bump is O(1). Tags stay an optional optimisation (`invalidation.mode = auto|version|tags`), never a correctness dependency — matches `docs/README.md:85`.
2. **Default payload = plain arrays, JSON-encoded (`payload.mode = json`); `models` must be explicit opt-in.** With the app's default `'serializable_classes' => false` (`config/cache.php:134`), raw Eloquent models/Collections read back as `__PHP_Incomplete_Class` on redis/file/database/storage/dynamodb [measured, §5]. Model payloads are also 4.7× larger than `toArray()` [measured: 1602 B vs 340 B per row].
3. **Never rely on the store's `unserialize`.** Store an already-encoded **string** payload (JSON, or our own `serialize()`), which the store leaves byte-identical even under `allowed_classes => false` [measured, §5.3]; decode with our own explicit allow-list/`json_decode`. Double serialization costs ≈0.2 ms per 1 000 rows and +0.01 % bytes [measured].
4. **Default store = the application's configured default** (`Cache::store(null)`); do not force Redis. Local app resolves `cache.default = database` [verified `php artisan config:show cache.default`]. Document that Redis is the production recommendation for shared multi-node panels, database is acceptable for single-node/low write, file only single node, array tests only, octane never for query cache.
5. **Feature-detect per store**: tags via `method_exists(store,'tags')` / `Repository::supportsTags()` (`Repository.php:931-934`), locks via `instanceof LockProvider`, SWR via lock availability. `storage`/`session` stores implement `Store` only (`StorageStore.php:10`, `SessionStore.php`) — `flexible()`/`lock()` will fatal on them; `null` has a no-op `NoLock` (`NullStore.php:80-83, NoLock.php:12-16`).
6. **Every package key must fit Memcached's 250-byte key cap [docs: memcached protocol] and the database store's `VARCHAR(255)` key column (`database/migrations/0001_01_01_000001_create_cache_table.php:15`).** Design: short readable namespace + `hash('xxh128', …)` (32 hex; measured 2.5 ms/10k hashes at 100 B vs sha1 6.8 ms) — never raw SQL+parameters in keys.
7. **Default payload budget 1 MiB, chunk larger; hard cap `max_payload_bytes`.** Store limits: Memcached item ≈1 MiB default, DynamoDB item 400 KB [docs], Octane table default 10 000 B/row (`octane/config/octane.php`), MySQL `MEDIUMTEXT` 16 MiB. 10 000 ERP rows serialize to ≈4.5 MB [measured] — always chunk or refuse.
8. **Compression is threshold-gated, not blanket.** Enabled at ≥64 KiB with zlib level 1 (`gzcompress`), because `igbinary`/`zstd`/`lz4` are **not installed** here (`php -m`/`extension_loaded` evidence, §11). Measured: gzip level 1 shrinks serialized array payloads to ≈12–13 % at 2.3 ms/1 000 rows / 91 ms/10 000 rows; level 6 is 2.5× slower for ~20 % fewer bytes, so level 1–2 only; below ≈16–32 KiB skip compression.
9. **Stampede control: prefer `Cache::flexible()` only for non-financial, stale-tolerant lists; otherwise `Cache::lock()`/`withoutOverlapping()` around a normal `remember`.** `remember()` has **no** lock (`Repository.php:583-599`). `flexible()`'s deferred refresh does not run on responses ≥400 unless `alwaysDefer=true` (`InvokeDeferredCallbacks.php:35-36`).
10. **Observability via cache events, but they are not free and not complete**: `increment`/`decrement`/`touch` bypass `Repository` event firing (`Repository.php:517-532, 701-712`); memo disables events (`CacheManager.php:99`); failover store disables events and emits `CacheFailedOver` instead (`CacheManager.php:282`, `FailoverStore.php:252-275`). Sample/guard listeners.
11. **Per-tenant prefixes are safe only inside one app instance**: store instances are memoised per name in the `CacheManager` singleton (`CacheManager.php:40,65-70`) and `cache.store` is a singleton (`CacheServiceProvider.php:22-24`), so mutating `cache.prefix`/config after first resolution does nothing (and leaks across requests under Octane). Route tenant identity into the key, not the prefix — see §7/§10.
12. **Package dependency change**: skeleton requires only `illuminate/support ^12||^13` (`packages/OoMyCache/composer.json:23-26`); research/implementation needs `illuminate/cache` + `illuminate/database` (query builder/`QueryExecuted`) in `require`, and Testbench workbench currently has framework v13.40.0 + Testbench v11.3.0 + Pest v5.3.5.

## 1. Store capability matrix

Sources: class declarations & methods in `vendor/laravel/framework/src/Illuminate/Cache/*`; defaults `config/cache.php:35-108`; missing "octane" factory verified by absence of `createOctaneDriver` in `CacheManager.php:171-346`.

| Store | Persistence / sharing | Tags (`TaggableStore`) | Locks (`LockProvider`) | Serialization by store | Key/value limits | Notes |
|---|---|---|---|---|---|---|
| `array` | Process memory only; not shared | **Yes** (`ArrayStore.php:11`) — works in tests [measured] | Yes (`ArrayStore.php:285-300`) | `serialize` config flag, default `false` (`config/cache.php:37-40`; `CacheManager.php:184-190`) | none | values returned by reference when serialize=false; not persisted across requests |
| `file` | Local disk; single node | **No** (`FileStore.php:15` is `Store`) → `BadMethodCallException` | Yes (`FileStore.php:228-238`, `FileLock`) | always `serialize()` prefixed with 10-byte expiry (`FileStore.php:89-104`) | no key limit (path = sha1, `FileStore.php:467-472`); OS file handles | expired file deleted lazily on read (`FileStore.php:411-418`); no GC of never-read files |
| `database` | DB; shared if DB shared | **No** (`DatabaseStore.php:20`) | Yes (`DatabaseStore.php:342-353`) | `serialize()`; base64 wrap if `\0` on PG/SQLite (`DatabaseStore.php:566-577,585-598`) | key column `VARCHAR(255)` PK; value `MEDIUMTEXT`; expiration index (`cache.stub:14-24`) | one `whereIn` per `many()` (`DatabaseStore.php:127-167`); expired rows deleted lazily (`:152-158`); `forever` = 31 536 000 s (`:329-332`) |
| `redis` | Redis; shared across nodes | **Yes** (`RedisStore.php:16`; `RedisTaggedCache`/`RedisTagSet`) | Yes (`RedisStore.php:243-254`, `PhpRedisLock`/`RedisLock`) | numeric stored raw, everything else `serialize()` (`RedisStore.php:512-545`); phpredis serializer/compressor passthrough (`:554-577`) | key 512 MB [docs: redis.io strings]; practical: memory | `flush()` = `flushdb()` (`:296-301`) — respects no prefix |
| `memcached` | Memcached; shared | **Yes** (`MemcachedStore.php:10`) but N+1 reads, see §2 | Yes (`MemcachedStore.php:188-203`) | Laravel passes the PHP value; the **extension** serializes (`MemcachedStore.php:102-107`) — ignores `serializable_classes` | **250-byte key**, no whitespace/control chars [docs: memcached protocol]; item ≈1 MiB default; `forever` may be LRU-evicted | no tags/locks/serialization options wired in Laravel's connector (`MemcachedConnector.php`) |
| `dynamodb` | DynamoDB; shared | **No** (`DynamoDbStore.php:20`) | Yes (`DynamoDbStore.php:388-391`) | `serialize()`/`unserialize()` with allowed_classes (`DynamoDbStore.php:541-566`) | item **400 KB**, partition key 2048 B [docs: AWS Constraints] | `flush()` throws `RuntimeException` (`DynamoDbStore.php:517-519`); TTL attribute `expires_at` (`config/cache.php:87-94`) |
| `storage` | Any filesystem disk (incl. S3); shared if disk shared | **No** (`StorageStore.php:10`) | **No** | `serialize()` always (`StorageStore.php:77-81,237-244`) | path hashed sha1 (`StorageStore.php:262-266`) | not taggable, not lockable → avoid for our locks/SWR |
| `null` | None | "Yes" (`NullStore.php:8`) but every write returns false | Yes — `NoLock` always acquires (`NullStore.php:80-83`) | n/a | n/a | `flexible()` works but never caches; good for kill-switch |
| `octane` | **Worker-local Swoole table**; not shared; resets per worker | **No** (`OctaneStore` implements `Store` only) | **No** | always `serialize()`/`unserialize()` (no allowed_classes) | 1 000 rows × 10 000 B default (`octane/config/octane.php`) | registered by Octane via `Cache::extend('octane', …)` (`OctaneServiceProvider.php::registerCacheDriver`), only when `octane.cache` non-empty |
| `session` | Session store | No (`SessionStore.php`) | No | session payload | per session | not for cross-request query cache |
| `apc` | Per-process shared memory | Yes (`ApcStore.php` extends `TaggableStore`) | No | apc | none | not in `config/cache.php` stores; CLI has no APC here |
| `failover` | Delegates in order | Yes (`FailoverStore.php:12`) | Yes | delegates | delegates | events disabled + `CacheFailedOver` (`CacheManager.php:276-283`); failures re-thrown if all stores fail (`FailoverStore.php:252-275`) |

Key exception behaviour (verified by running the framework): `Cache::store('file')->tags(['x'])` and `database` equivalent throw `BadMethodCallException: This cache store does not support tagging.` (`Repository.php:847-862`); `supportsTags(file)=false`, `supportsTags(array)=true`.

### 1.1 Capability detection contract for our adapter
The `Store` contract itself has no tags/locks; they are separate interfaces (`Illuminate\Contracts\Cache\LockProvider`; taggability = "has `tags()` method", `Repository.php:931-934`). OoMyCache should compute capabilities once per resolved repository and expose an immutable `StoreCapabilities` value object:

```php
$repo    = Cache::store($name ?? config('cache.default'));
$caps    = [
    'taggable'   => $repo->supportsTags(),                       // method_exists, not interface
    'lockable'   => $repo->getStore() instanceof LockProvider,
    'flushLocks' => $repo->supportsFlushingLocks(),              // CanFlushLocks (Repository.php:939-942)
    'prefix'     => $repo->getStore()->getPrefix(),              // store-level, excludes config prefix for array/file
];
```

Fallback contract: `taggable=false` → versioned namespaces; `lockable=false` → plain `remember` + jittered TTL (log "degraded, no stampede protection"); `store === NullStore` → package no-ops (`NullStore::put()` returns false, `get()` null). Multi-key: only `RedisStore` (`mget`) and `MemcachedStore` (`getMulti`) and `DatabaseStore` (`whereIn`) implement true batch reads; everyone else uses `RetrievesMultipleKeys::many()` = N single `get()` calls (`RetrievesMultipleKeys.php:19-33`) and `putMany()` = N `put()` calls (`:42-53`) — so chunked storage is only a batch win on those three drivers; elsewhere it trades one big payload for N round-trips (still worth it to respect driver size limits).

## 2. Tags deep dive

### 2.1 Mechanism (two implementations)
- Generic: `TaggableStore::tags()` returns `TaggedCache` + `TagSet` (`TaggableStore.php:7-19`). A tagged item key is `sha1(TagSet::getNamespace()).':'.$key` (`TaggedCache.php:96-110`), i.e. tag names never leak into item keys — **tag list order matters**. `TagSet::getNamespace()` = `implode('|', tagIds)`; `tagId($name)` reads `tag:{name}:key` or creates a new `uniqid()` forever (`TagSet.php:40-118`). Consequence: **every tagged read costs N reads for tag ids + 1 item read**, and tag names are validated nowhere.
- Redis: `RedisTagSet::tagId()` returns the constant `tag:{name}:entries` (`RedisTagSet.php:141-155`) — no id reads. Writes `zadd` one sorted-set entry per tag, score = expiry timestamp (`RedisTagSet.php:19-33`); reads are plain `GET` of `sha1(tagIds):key` (no zset read). Tag set is cleaned with `cache:prune-stale-tags` / `zremrangebyscore` (`RedisTagSet.php:91-109`; `RedisStore.php:326-331`; `Console/PruneStaleTagsCommand.php`).
- Redis tagged **flush** deletes the actual data keys via a Lua script in chunks of 1 000 (`RedisTaggedCache.php:184-212`); on Redis Cluster it falls back to `flushValues()` + tag reset (`:224-234`) leaving orphaned entries until TTL. Non-Redis taggable flush = `TagSet::reset()` → N `forever()` writes; old items are **orphaned until their own TTL** (measured: array store still held `sha1(namespace):anne` after `tags()->flush()`).
- Tag events: `TaggedCache::event()` attaches `$tags` to every event (`TaggedCache.php:112-125`) — useful for observability.

### 2.2 Tag-name rules (v13 reality)
No validation exists in v13.40.0: `TagSet::tagKey()` / `RedisTagSet::tagKey()` concatenate raw names (`tag:{$name}:key`, `tag:{$name}:entries`); a runtime probe accepted `with:colon`, `with space`, `with,comma`, `with|pipe` [measured]. Treat this as a hazard, and enforce `[A-Za-z0-9_.\-]{1,64}` in OoMyCache because: Memcached keys may contain no whitespace/control characters and cap at 250 bytes total [docs]; `RedisStore::currentTags()` parses `^prefix tag:(.*):entries$` for pruning (`RedisStore.php:347-407`); reserved substrings (`:key`, `:entries`, `|`) can collide with internal naming; tags are unbounded-cardinality rows in Redis zsets if invalidated tags accumulate.

### 2.3 Cost & semantics summary
| Operation | Redis tags | generic tags (memcached/array/apc) |
|---|---|---|
| read tagged value | 1 `GET` | N id reads + 1 value read |
| write tagged value | 1 `SET` + N `ZADD` (not pipelined) | 1 `PUT` (ids read on demand) |
| flush by tag | O(entries) Lua delete, chunked 1 000; cluster: O(tags) + orphans | O(tags) writes + orphaned items until TTL |
| `flush()` all | `flushdb()` — ignores prefix (`RedisStore.php:296-301`) | Redis n/a; generic deletes namespace ids only |
| prune | `cache:prune-stale-tags` | none needed for ids; orphans only TTL |

`Cache::forget()` on tagged data removes only the item; the zset entry remains until pruned (`RedisTaggedCache` inherits `Repository::forget`, `Repository.php:754-767`). File/database/storage/dynamodb cannot tag at all [docs]. Tag order is part of the namespace: `Cache::tags('a','b')` and `['b','a']` produce different `TagSet::getNamespace()` values, so writes/reads through differently-ordered tag lists never meet (and the docs' `Cache::tags('authors')->flush()` still flushes the *same* tag id regardless of order in the set). Tagged `remember`/`rememberForever`/`increment` work because `TaggedCache extends Repository`; `RedisTaggedCache` additionally maintains the zset on `put/add/forever/touch/increment/decrement` (`RedisTaggedCache.php:24-159`).
Framework tooling: `php artisan cache:clear {store} --tags=a,b` resolves `$cache->tags($tags)->flush()` (`Console/ClearCommand.php:150-160`); `--locks` is mutually exclusive with `--tags` (`:105-111`); Redis tag cleanup is `php artisan cache:prune-stale-tags {store}` (`Console/PruneStaleTagsCommand.php`, Redis only). There is **no** tag command for memcached beyond the same `cache:clear --tags` (generic reset) and no tag index inspection at all — a reason not to build product features (dashboards, selective purge UI) on tags alone.

### 2.4 Alternatives on non-taggable stores (recommended design)
1. **Versioned namespace (default).** One generation value per invalidation scope, e.g. `oomycache:gen:{tenant}:{scope}`. Read generation (memoise per request), key = `…:{generation}:{queryHash}`. Invalidate = single write of a new generation (or `increment`). Atomicity: generation bump itself is atomic per driver (`RedisStore::increment` = `INCRBY`, `DatabaseStore::increment` = transactional lock-for-update, `Memcached increment`), BUT seed first where increment-on-missing fails (`MemcachedStore.php:151-154`; `DatabaseStore.php:284-286` return false when missing; Redis `INCRBY` creates). Race: a reader that loaded gen *before* a writer commits can store stale data under the *old* gen (unreachable — safe); a reader that loads gen *after* bump but queried before the writer's transaction commits can store stale data under the new gen — mitigate by capturing gen before query and re-reading/verifying before write, or invalidate after commit. Old entries expire by TTL.
2. **Index sets (Redis-only optimisation).** `SADD ns:{gen} key` per write, `SMEMBERS`/`SSCAN`+`DEL` on flush; gives debuggability/partial purge but loses portability and needs Lua/CAS to be race-free; use as optional "tag emulation" only when Redis is confirmed.
3. **Tags as acceleration** for Redis/Memcached deployments that need multi-scope purge, with versioned namespaces always also present so correctness survives a store switch.

## 3. Keys, prefixes, hashing, limits

- Effective key = `config('cache.prefix')` + `store-specific` + package key. `cache.prefix` default `Str::slug(APP_NAME).'-cache-'` (`config/cache.php:121`, `CacheManager.php:460-463`). Redis adds a second, independent prefix from the connection config (`config/database.php:146-154` `redis.options.prefix`; combined when flushing tagged data: `RedisTaggedCache.php:177-183`). File/storage ignore prefix for paths but hash `prefix+key` into the path (`FileStore.php:467-472`, `StorageStore.php:262-266`).
- Limits: memcached key **250 bytes**, no whitespace/control [docs: memcached protocol]; database key column **`VARCHAR(255)`** (`cache.stub:15`; MySQL strict mode errors, not truncates); DynamoDB partition key ≤2 048 B, item ≤400 KB [docs]; Redis key ≤512 MB [docs] / value memory-bound; file path sha1 (fixed 40 hex) with 2-level fan-out.
- Hashing strategy: hash what you cannot fit/keep portable. Prefer `hash('xxh128', $raw)` → 32 hex (available in PHP 8.3+, measured **fastest for long inputs**: 5.7 ms/10k at 1 KB vs md5 30 ms, sha1 48 ms, sha256 69 ms; xxh64 3.0 ms). Use sha1 only if you need the 40-hex convention; collision probability is negligible for cache keys (128–160 bit) but **non-cryptographic** hashes must never be used for auth/secret material.
- Keep readability: `oomycache:v{payloadVersion}:{tenantId}:{modelSlug}:{scopeGen}:{xxh128(query+params+bindings+driver+conn)}`; store a debug side-table (`Cache::put('oomycache:debug:'.$hash, $human, short ttl)`) only in debug mode, because raw SQL in keys blows the 250-byte cap and leaks PII/schema.
- Tagged keys are `sha1(tagIds) + ':' + key` (40+1+len) — tag *names* do not consume item-key length, but tag meta-keys `tag:{name}:entries` do count against Memcached's 250 B.
- Beware case/collation: MySQL cache keys use DB collation (utf8mb4_unicode_ci → case-insensitive), Redis/files are byte-sensitive; always lowercase-hash the payload, never rely on DB key case.
- **Key-length budget** (worst case memcached + database): `cache.prefix` (≈25 B) + our namespace (≈40 B) + tenant (≈16 B) + model (≈16 B) + generation (10–32 B) + `xxh128` (32 B) + separators ≈ 180 B → leaves ≈70 B headroom under 250 B and 255 chars. Do **not** put SQL, JSON bindings, user IDs or filter blobs in the key; hash the canonicalized query+params string. Canonicalize before hashing: driver/connection name, table, selected columns (`*` vs list), where clauses in deterministic order, bindings cast by type, eager-load list, `limit/offset`, and a package `key_salt` (plan's domain version, `docs/plan/02-configuration.md:309`). Same SQL with different connection/DB must produce different keys, otherwise tenant isolation breaks at the driver layer.

## 4. TTL, SWR, locks, stampede control

### 4.1 TTL semantics (exact source)
- Accepted TTL: `\DateTimeInterface|\DateInterval|int|null` (`Repository.php:359-408`). `getSeconds()` converts DateInterval/DateTime and clamps negatives to 0 (`:903-914`); `put()` with `ttl <= 0` **forgets** instead of writing (`:381-383`).
- `remember($key,$ttl,Closure)` → `rememberWithWarmth(...)[0]`; `rememberWithWarmth` returns `[value, bool $warm]` (`:568-599`); `rememberForever` (`:624-638`). No locking around the callback — N concurrent misses run N queries.
- `touch($key,$ttl)` delegates to store (`:701-712`); Redis `EXPIRE`, DB `UPDATE expiration`, file re-serialises, memcached `touch`.
- Per-store "forever": file caps at `9999999999` (`FileStore.php:480-485`); database uses 31 536 000 s (`DatabaseStore.php:329-332`); Redis bare `SET` (`RedisStore.php:228-233`); memcached exptime 0 but LRU may evict [docs].
- Expired data is reclaimed **lazily on read**: file deletes the file (`FileStore.php:411-418`), database deletes rows in `many()` (`DatabaseStore.php:147-158`), Redis/memcached expire natively. No framework command prunes file/database caches (`Console/`: only `Clear`, `Forget`, `PruneStaleTags`, `CacheTable`). Never-read keys leak forever on file/database → recommend `vectorial1024/laravel-cache-evict` as companion (see research/01) or our `oomycache:prune` for `file|database` only.
- Database store index structure: PK on `key`, index on `expiration`, `MEDIUMTEXT value` (`cache.stub:14-24`); `putMany` = `upsert(key)` (`DatabaseStore.php:189-204`); a full-table delete backs `flush()` (`:448-453`).

### 4.2 `Cache::flexible()` stale-while-revalidate
Signature (`Repository.php:640-692`): `flexible($key, array $ttl /*[fresh, stale] seconds*/, callable $callback, ?array $lock = ['seconds'=>..,'owner'=>..], bool $alwaysDefer = false)`.
Behaviour verified in source and by runtime probe with fake clock:
1. Reads `key` and `illuminate:cache:flexible:created:{key}` in one `many()` (`:656-659`; prefix constant `:55`).
2. Missing/expired → callback runs synchronously; value + creation timestamp written with the **second** ttl (`:661-666`).
3. `created + ttl[0] > now` → fresh, return value (`:668-670`).
4. `created + ttl[0] <= now < created + ttl[1]` → return stale value, register a deferred refresh (`:672-691`). The refresh acquires `illuminate:cache:flexible:lock:{itemKey}` with `$lock['seconds'] ?? 0` (held until released), re-checks the created stamp to collapse duplicate refreshes, then writes value+timer with ttl[1].
5. Beyond ttl[1] the entry is gone → synchronous recompute (slower request, no herd protection at that boundary — the lock only protects refreshes that are still able to serve stale data).
Deferred timing: `defer()` callbacks run from `InvokeDeferredCallbacks` middleware after the response only if status `<400` unless `alwaysDefer` (`InvokeDeferredCallbacks.php:35-36`); console after successful command / job (`FoundationServiceProvider.php:213-225`); in tests `withoutDefer()` (`InteractsWithContainer.php:257-287`). So *an error page silently skips the SWR refresh*.
Lock dependency: `flexible()` calls `$this->store->lock(...)` — stores without `LockProvider` (`storage`, `session`, octane) throw/`Error`; `null` store uses `NoLock` and never caches.

### 4.3 Locks API (`Illuminate\Cache\Lock`)
`get($callback=null)` acquire+release (`Lock.php:90-103`); `block($seconds,$callback=null)` polls every 250 ms → `LockTimeoutException` (`:114-139`); `refresh($seconds=null)` (throws on base class, implemented by Redis/Memcached/Database/File/Array locks); `owner()` (`:157-160`); `isLocked()`/`isOwnedBy()` (`:167-191`); `betweenBlockedAttemptsSleepFor(ms)` (`:199-204`). Cross-process release: `Cache::restoreLock($name, $owner)` re-creates a lock with the owner token (`Store::restoreLock`, e.g. `FileStore.php:247-250`); verified owner-only release with array store (foreign release returns false). Repository convenience: `withoutOverlapping($key,$cb,$lockFor=0,$waitFor=10,$owner=null)` (`Repository.php:728-731`); `funnel()` requires `LockProvider` (`:739-746`). Separate lock connections: Redis `lock_connection` defaults to `default` while cache uses DB 1 (`config/cache.php:81-85`, `config/database.php:169-180`); database `lock_connection/lock_table` (`config/cache.php:42-48`); file `lock_path` (`:50-54`).
Stampede recommendation: `remember` + `Cache::lock('q:'.$hash, 10)->block(3, fn () => …)` (or `withoutOverlapping`), then double-check cache inside the lock. Reserve `flexible()` for dashboards/reports where ≤ttl[0] staleness is acceptable; financial lists must not serve stale.

### 4.4 `Cache::memo()`
`CacheManager::memo($driver=null)` returns a `Repository` wrapping `MemoizedStore` bound as **scoped** container binding `cache.__memoized:{driver}`, with cache events disabled (`CacheManager.php:89-106`). `MemoizedStore` memoises `get/many` results (including nulls) until any mutating call unsets the key (`MemoizedStore.php:45-127, 276-297`); tags are memoised per tag-set (`:248-268`); locks are proxied to the real store (`:180-205`). Scope: one request/job; reset under Octane by scoped-instance flushing (see §7).

### 4.5 API caveats that shape our wrapper
- `Repository::has()` is `!is_null(get())` (`Repository.php:106-109`): a legitimately cached `null`/`false` is indistinguishable from a miss for `has()`; `get()` itself returns `null` on a miss (`:143-151`). Our envelope must never be a bare scalar/null — wrap every payload in the shaped array/JSON so a miss is unambiguous.
- `put()`/`forget()` return `bool`; stores may refuse (Memcached too-long key → `false`, `MemcachedStore.php:102-107`) — treat a `false` write as "not cached" and emit our own `KeyWriteFailed`-equivalent metric (Laravel fires `KeyWriteFailed`, `Repository.php:390-393`).
- `remember($ttl)` accepts a closure TTL (`value($ttl, $value)`, `:596`) and `rememberWithWarmth` exposes warm/cold (`:583-599`) — the package should use `rememberWithWarmth`-style semantics for its own metrics.
- `Cache::tags([...])->remember(...)` is valid (`TaggedCache extends Repository`) but the exact same ordered tag list is required on read; if we expose tags publicly, normalise order + validate characters.
- Lock keys are namespaced only by store prefix (`RedisLock` gets `$this->prefix.$name`, `RedisStore.php:243-254`); `restoreLock()` reuses the same store/prefix, so cross-process/job release only works when both sides use the same store + prefix + name (`Lock.php:52-61`, `FileStore.php:247-250`).
- `Cache::lock()`/`flexible()` on a store without `LockProvider` fail at runtime, not at config time — validate on boot (`oomycache:doctor`).
- `flexible()` writes the value + created-key in `putMany()`; on file/database these are **two independent writes**, so a crash between them leaves orphan/inconsistent metadata (read treats missing created as a miss → safe, one extra query). No transaction spans cache writes on any driver.

## 5. Serialization & deploy safety

### 5.1 The `serializable_classes` finding (Lead question 1–2)
- App config: `'serializable_classes' => false` (`config/cache.php:123-135`, comment: "no PHP classes will be unserialized … prevent gadget chain attacks if your APP_KEY is leaked"). **Note:** the framework's own copy of `config/cache.php` (vendor `laravel/framework/config/cache.php`) does **not** contain the key — upgraded/older apps resolve to `null`, which means unrestricted `unserialize()` (backward-compatible but gadget-chain exposed). New skeletons get `false`.
- Plumbing: `CacheManager::getSerializableClasses()` returns `config('cache.serializable_classes') ?? null` and **ignores the per-store `$config` argument** (`CacheManager.php:465-474`) — a per-store `serializable_classes` override is a no-op; only the global value works. It is passed to Array/File/Database/Redis/Storage/DynamoDB stores; each `unserialize()` does `unserialize($v, ['allowed_classes' => $this->serializableClasses])` when the value is not `null` (e.g. `RedisStore.php:534-545`, `FileStore.php:442-449`, `DatabaseStore.php:585-598`, `StorageStore.php:237-244`, `DynamoDbStore.php:552-566`, `ArrayStore.php:318-325`).
- **Measured behaviour matrix** (FileStore + Repository, same code path as redis/database/storage/dynamodb):
  | config value | cached `stdClass` read back as |
  |---|---|
  | `null` (key absent) | `stdClass` (unrestricted) |
  | `false` (this app) | `__PHP_Incomplete_Class` — **no exception**, no fatal, value shape silently lost |
  | `true` | `stdClass` (all classes allowed) |
  | `['stdClass']` | `stdClass`; unlisted classes become incomplete |
  `Repository::handleIncompleteClass()` detects it, optionally invokes `Repository::handleUnserializableClassUsing()` (static handler, `Repository.php:90, 882-895, 1027-1030`), but still returns the incomplete object to the caller.
- Therefore: **yes — with default Laravel 13 config, caching Eloquent models/Collections as raw values returns `__PHP_Incomplete_Class` on redis/file/database/storage/dynamodb** [verified file store; other stores share the exact `allowed_classes => false` call]. Escapes: (a) Memcached — Laravel never passes `serializable_classes` (`MemcachedStore.php:41-48`); the PECL extension serialises/deserialises itself, so objects work but **allowed_classes protection is bypassed**; (b) Octane store — plain `serialize`/`unserialize` (`OctaneStore.php`), objects work, worker-local; (c) `array` store with default `serialize=false` returns live objects — **so tests pass while production breaks**, a serious test-matrix trap; (d) `array` with `serialize=true` behaves like file/redis.
- What apps caching models today must configure: global `'serializable_classes' => [/* every model, collection, Carbon, enum, DTO class */]`, or `true` (unsafe), or pre-encode payloads themselves. There is no per-store override and no partial/gadget-safe wildcard.

### 5.2 Model payload reality (measured)
One 12-attribute Eloquent model serializes to **1 602 B** vs `toArray()` **340 B** (4.7×) — it carries connection/table/primaryKey/casts/attributes/original/changes/relations and class names; a `Collection` wrapper is another object. Under `allowed_classes=false`, *both* `Model` and `Collection` are incomplete; enums and `DateTimeImmutable` inside an array payload are also blocked (`allowed_classes=[]` probe → `__PHP_Incomplete_Class`). Every class in the payload must be allow-listed, including Laravel/Carbon/enum internals — fragile across upgrades and deploys (`Model` property changes, class renames, enum renames).
Also deploy hazards: cached payloads survive deploys; renamed model classes / removed readonly props / changed `casts` decode into old shapes; `SerializableClosure` (from `laravel/serializable-closure`) is for queues/broadcast only — the cache cannot store closures (measured `Exception: Serialization of 'Closure' is not allowed`). Bump a `payload version` segment in every key on deploy (plan's `version`, `docs/plan/02-configuration.md:58-60`) and/or flush the package namespace during deploy.

### 5.3 Pre-serialized-string workaround (Lead question 3) — validated
- A string payload passes untouched through every store's unserialize, even with `allowed_classes => false`: measured `Cache::put($key, serialize($modelData))` on a `serializable_classes=false` FileStore returns an identical string; our own `unserialize($raw, ['allowed_classes' => $allowList])` then yields the intended array/objects. Condition: the string must not be numeric — `RedisStore` stores finite numerics raw (`RedisStore.php:512-526, 534-539`) and `DynamoDbStore` integer-casts numeric strings (`:541-566`); prefix payloads (e.g. `"oomyc1:"`) or use JSON whose first byte is `{`/`[`.
- Cost: double serialization measured **+0.01 % bytes** and `serialize(serialize($x))` 0.92 ms vs 0.71 ms per 1 000 rows; store round-trip of a JSON string 0.01 ms. Negligible relative to query/IO. Memcached's extension-side serializer also leaves a string untouched (it serializes the string, deserialises back to string), so the workaround is uniform — except memcached's own serializer still bypasses `allowed_classes` for the *string* (safe) while it would not help if we stored objects.
- Security caveats: unserializing attacker-controlled data is the gadget-chain risk; our allow-list must be explicit and minimal (arrays + enums we actually need, never wildcards), and cache trust boundary = whoever can write the cache (shared Redis/Memcached, leaked `CACHE_PREFIX`, debug endpoints). **JSON is the safest default** (`json_decode` instantiates nothing); choose PHP-serialized payloads only for speed/type fidelity with a hard allow-list. Cache values are not encrypted (`DatabaseStore.php:301-303` comment is stale; only serialize+base64 for `\0` on PG/SQLite, `:566-577`) — treat cached ERP data as plaintext; encrypt payloads for PII or keep them out.

### 5.4 Recommendation for `payload.mode` (Lead question 4)
`payload.mode` ∈ `{json, php}` with value shape `{format, version, created_at, tenant, deps, data}`;
- **default `json` + arrays** (safe on every driver incl. memcached/octane, debuggable, no `serializable_classes` dependency);
- `php` mode only with explicit `allow_list` config (auto-detect classes is unsafe) — it preserves `DateTime`/enums and is faster to encode than JSON for arrays (measured 35.4 vs 45.7 ms at 10k rows) but requires the allow-list discipline;
- **models mode: remove from defaults.** If kept, require `payload.mode=models` + explicit opt-in + auto-generate the global allow-list via `oomycache:doctor` (detect `__PHP_Incomplete_Class`), and warn that `serializable_classes=true` is a security regression. Change `docs/plan/02-configuration.md:123-145` (`allow_serialized_models => true`, `payload.mode => 'models'`) to `false` / `'json'`.

### 5.5 What can actually be cached (value matrix)
| Value | Safe through Laravel stores with default config | Notes |
|---|---|---|
| scalars, arrays, `null` | yes | null-as-miss caveat §4.5; arrays may contain enums/DateTime → not safe under `false` |
| JSON string / our pre-serialized string | yes | recommended envelope |
| Eloquent model instances | **no** (redis/file/db/storage/dynamodb) | 4.7× bytes of `toArray()`, relations re-serialized, classes must be allow-listed; Memcached/Octane bypass the guard |
| `Illuminate\Support\Collection` | **no** (same stores) | serializer sees a class; `->toArray()`/`->all()` first |
| backed/unit enums, `DateTimeImmutable` | **no** unless allow-listed | probe: `allowed_classes=[]` → `__PHP_Incomplete_Class` for both |
| closures, resources, PDO, generators | **never** | `Exception: Serialization of 'Closure' is not allowed` [measured]; `SerializableClosure` is queue-only |
| file uploads / binary blobs | avoid | base64 inflates 33 %; large payload limits §1/§8 |

### 5.6 Deploy & rollout safety
- Cache entries outlive deploys (file/redis/database TTLs). A deploy that renames a model/collection class, removes a property, changes `casts`, or renames a tag makes old entries fail to hydrate (`__PHP_Incomplete_Class`) or hydrate into a stale shape. Rule: **format version inside every key** (`payload.version` + `key_salt`) and a deploy step that purges the previous version namespace (`oomycache:purge --old-versions`); never reuse a format version.
- Blue/green or rolling deploys: old and new app code share the store/prefix; version-keying lets both sides coexist; without it, one version will read the other's payload and log incomplete-class warnings. Set `OOMYCACHE_PAYLOAD_VERSION` per release (plan already has `version` env, `docs/plan/02-configuration.md:58-60`).
- Cached payloads cannot be "migrated" cheaply — re-deriving is cheaper: bump the version/generation and let TTL/eviction remove old entries; for `file`/`database` add a prune pass (no framework command exists, §4.1).
- Rollout hazards specific to `serializable_classes`: turning it from `false` to an allow-list (or `true`) changes hydration for *all* cache users, not only ours; a missing class silently yields `__PHP_Incomplete_Class` (no exception), so add a doctor check/alert when `get()` returns an incomplete object.
- Cache is plaintext (no encryption; `DatabaseStore.php:301-303` comment is stale) — plan for PII/DSAR deletions: versioned purge per tenant is the practical tool (`oomycache:flush --tenant=…`).
- Security: allow-list must be minimal; do not accept client-supplied tags/keys; gate any debug key-listing command to non-production; remember `Cache::flush()` is `flushdb()` on Redis (`RedisStore.php:296-301`) and ignores prefixes (docs) — the package must only ever delete its own prefixed keys.

### 5.7 Direct answers to the Lead's four questions
1. **Yes**: default config `serializable_classes=false` makes raw model/Collection values read back as `__PHP_Incomplete_Class` on Redis/file/database/storage/dynamodb (no exception). Escapes: memcached/octane use their own serializer (guard bypassed), `array` with `serialize=false` keeps live objects (testing trap). No other mechanism exists; per-store override is ignored (`CacheManager.php:465-474`).
2. Accepted values: `null` (legacy, unrestricted), `false` (none), `true` (all), or an array of class names (PHP `unserialize`'s `allowed_classes` semantics). Apps caching models today must either set the global allow-list/`true` or pre-encode payloads; there is no scoped/partial allow-list.
3. Workaround validated: pre-serialized **string** payloads pass store-level unserialize untouched under `allowed_classes=false`; our own decode with an explicit allow-list works; cost ≈+0.01 % bytes and ≈0.2 ms/1 000 rows; ensure the string is non-numeric (`RedisStore.php:512-526,534-539`) and never unserialize untrusted data; JSON decoding is the safe choice for arrays.
4. Memcached's extension serializer bypasses `allowed_classes` entirely (Laravel passes no classes to it, `MemcachedStore.php:41-48`) — convenient but the gadget risk moves to memcached's own `unserialize`; prefer the same string-envelope path everywhere so behaviour is driver-independent. Recommended `payload.mode` default: **`json` (arrays)**, `php` opt-in with allow-list, `models` opt-in + doctor.

## 6. Events & observability hooks

18 concrete classes in `Events/` (17 documented + `CacheFailedOver`). Dispatch sites are all in `Repository`: `RetrievingKey` before read, `CacheHit`/`CacheMissed` after (`Repository.php:136-151`), `RetrievingManyKeys` + per-key hit/miss (`:165-176, 200-219`), `WritingKey`/`KeyWritten`/`KeyWriteFailed` (`:385-393, 428-440, 545-553`), `ForgettingKey`/`KeyForgotten`/`KeyForgetFailed` (`:758-766`), `CacheFlushing`/`CacheFlushed`/`CacheFlushFailed` (`:800-810`), lock-flush events (`:826-834`). `TaggedCache` injects `$tags` into every event (`TaggedCache.php:112-125`). Gaps: `increment`/`decrement` (`:517-532`) and `touch` (`:701-712`) bypass `Repository` and fire nothing; `CacheManager::memo()` disables events; failover disables them and dispatches `CacheFailedOver` only on first failure per store (`FailoverStore.php:265-268`). Per-store `'events' => false` removes the dispatcher at construction (`CacheManager.php:418-425`; docs "Events" section) — an easy production win and something our metrics listeners must respect.
Use for OoMyCache: cache hit-ratio counters from `CacheHit`/`CacheMissed` keyed by our namespace prefix (filter before doing work); invalidation counters from `KeyWritten`/`KeyForgotten`; slow-payload instrumentation is *not* available from these events — wrap our own encode/decode. Sample heavily and never do IO in the listener. Testing: `Cache::spy()` is built in (`vendor/.../Support/Facades/Cache.php:90-110`) and pairs with `Cache::shouldReceive()`.

```php
// Observability listener shape (registered by OoMyCacheServiceProvider, sampled)
Event::listen([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class],
    function (CacheEvent $e) use ($namespacePrefix): void {
        // events fire with the *user* key (prefix is applied later by the store), so
        // filter on our own key namespace, never on the store prefix.
        if (! str_starts_with($e->key, $namespacePrefix)) {
            return;
        }

        Metrics::increment($e instanceof CacheHit ? 'oomycache.hit' : 'oomycache.miss', tags: [
            'store' => $e->storeName,
        ]);
    });
```

Avoid listening in production unless sampled: every cache read dispatches 2+ events (`RetrievingKey` + `CacheHit/Missed`), and `many()` dispatches per key (`Repository.php:165-176`). With `events=false` per store (`CacheManager.php:418-425`) the listener simply never fires — health checks should surface that.

## 7. Octane & long-running workers

- `CacheManager` is a singleton (`CacheServiceProvider.php:18-24`) holding `$stores[]` (`CacheManager.php:40,65-70`); `cache.store` singleton captures the default store. **Store instances and their configured prefix live for the worker's lifetime.** Never keep tenant/request data in store decorators or in `CacheManager` subclasses; compute the tenant segment per call (or resolve a per-tenant repository each time and rely on the key, not the store prefix).
- `Cache::memo()` uses `scopedIf` (`CacheManager.php:97-103`), so it is per request/operation; Octane flushes scoped instances in `FlushTemporaryContainerInstances` (calls `resetScope()`/`forgetScopedInstances()`; octane 2.x listener) and flushes the `array` store via `FlushArrayCache`. **Do not cache mutable request state in singletons** — with Octane the memo/store can survive `RequestTerminated` unless the scoped/binding hygiene above is respected.
- Queue workers: `QueueServiceProvider` supplies `resetScope` to `Worker`, invoked after each job (`QueueServiceProvider.php:247-279`; `Worker.php:268-269`) — scoped bindings (memo) reset **per job**, but singleton `CacheManager`/array store do **not** reset: an `array` cache in a queue worker leaks values across jobs. Don't use `array` outside tests.
- Octane's own cache driver is worker-local (`OctaneStore`/`OctaneArrayStore`), rows/bytes 1 000×10 000 by default, and `registerCacheDriver()` only runs when `octane.cache` is non-empty; it is **not** a distributed query cache and has no tags/locks. Keep our package working under Octane by treating the configured distributed store as the source of truth; never store package registries/config in request-scoped singletons.

## 8. Performance guidance for large Eloquent result sets

### 8.1 Encoding costs [measured, PHP 8.5.12, this machine]
| rows (12 field arrays) | serialize | ser bytes | json | json bytes | gz1 bytes | gz1 ms | gz6 ms |
|---|---|---|---|---|---|---|---|
| 50 | 0.05 ms | 22 100 | 0.07 ms | 17 604 | 3 148 (14 %) | 0.20 | 0.64 |
| 1 000 | 2.18 ms | 448 901 | 3.57 ms | 358 003 | 58 606 (13 %) | 8.3 | 18.4 |
| 10 000 | 35.4 ms | 4 530 228 | 45.7 ms | 3 611 329 | 583 753 (13 %) | 91.4 | 225.0 |

Decode: `unserialize` 33 ms, `gzuncompress` 26 ms, both 62 ms, peak memory ≈58 MB for a 10k-row payload. Gzip level 1 is the sweet spot; below ≈16–32 KiB the gzip call itself is comparable to the value (measured 0.05–0.12 ms) and only saves tiny IO → threshold, not blanket compression.

### 8.2 Store throughput (same payloads) [measured]
| rows (payload) | array (no serialize) | array (serialize) | file | database (sqlite :memory:) |
|---|---|---|---|---|
| 50 (22 KB) | 0.01 / 0.01 ms | 0.12 / 0.13 ms | 1.01 / 0.73 ms | 0.61 / 0.74 ms |
| 500 (224 KB) | 0.01 / 0.01 ms | 1.09 / 4.76 ms | 5.20 / 2.47 ms | 3.18 / 4.47 ms |
| 5 000 (2.26 MB) | 0.01 / 0.01 ms | 20.9 / 21.2 ms | 29.6 / 31.7 ms | 21.2 / 32.2 ms |

(put/get median). Redis/Memcached cannot be measured here: no `redis`/`memcached` PHP extensions (`php -m`; see §11), so plan a CI harness. Reasoned envelope: Redis same-AZ `GET` 0.2–1 ms (+ payload transfer, e.g. 0.5 MB ≈ 4–10 ms on 1 Gbit/s), Memcached similar; on the same host with loopback 0.1–0.5 ms. **Serialization/decompression dominates for large payloads, network for small ones.**

### 8.3 Guidance
- Cache **page-sized** payloads for Filament tables (per page/perPage + filter/sort state), not 10k-row collections; use cursor/`lazy()` for exports and never cache an export blob in redis without chunking.
- Payload budget: default `max_payload_bytes` **1 MiB** (vs plan's 2 MiB), chunk above `chunk_bytes` 256 KiB / `chunk_items` 1 000 via `putMany`/`many` (Redis `MGET`/`MULTI`, Memcached `getMulti`/`setMulti`, DB single `whereIn`/`upsert`) and keep a manifest key; hard-refuse above cap.
- Compression: `zlib` only (present); no igbinary/zstd/lz4 (absent) → `gzcompress` level 1–2, threshold 64 KiB, store `encoding` byte in envelope.
- Hit-ratio math: expected latency `= h·C_get + (1-h)(C_query + C_set)`. Cache wins when `C_get + C_set·r < C_query` (r = write/read ratio). For a 10k-row query (`C_query` likely 100–500 ms on ERP) a 5 ms get + 63 ms decode is a clear win; for a PK lookup (`C_query` ~1 ms) or a 50-row indexed page (`C_query` 5–20 ms) the cache is a **net loss** unless executed repeatedly within TTL. Cache only queries above a measured threshold (e.g. DB time ≥10 ms) and not in write-heavy tables.
- Measurement: `DB::listen`/`QueryExecuted` + our own encode/decode timers + cache events; Laravel Pulse is not installed (good optional dev dependency, not a runtime dep); `spatie/laravel-responsecache` is a different layer (full HTTP response; Filament/Livewire requests are POST → it does not help the Livewire interactions we target). Build a Testbench benchmark command comparing array/file/database (+Redis in CI), print p50/p95, and gate PRs.

### 8.4 When caching is a net loss — worked envelope
Use measured numbers (§8.1/8.2) + reasoned query times:

| Scenario | Query cost (reasoned) | Cache read cost | Verdict |
|---|---|---|---|
| PK/first() lookup, unindexed? no — indexed PK | 0.5–2 ms | redis 0.3–1 ms + 0.2 ms decode | **loss** unless ≥3–4 hits/TTL |
| 50-row filtered page, indexed | 5–20 ms | redis 0.5–1.5 ms + 0.2–0.4 ms decode | win at ~1 hit; **loss on file/database** at 1 hit (1–5 ms get + write) |
| 1 000 rows report, joins | 50–200 ms | 1–3 ms + 3 ms decode (gz +0.3 ms) | clear win |
| 10 000-row export/dashboard | 300 ms–2 s | 5–15 ms + 33–63 ms decode | win, but chunk or recompute |
| heavily written tables (invoices posted continuously) | any | invalidation churn → <20 % hit ratio | **loss or neutral**; prefer 30–60 s TTL on aggregates, not row lists |

Rule of thumb: cache only if `C_query ≥ 10 × C_cache` and the same key is expected to be requested ≥2–3 times per TTL. Instrument the ratio in production (hits per key), and auto-disable caching for keys whose observed write/read ratio is high ("negative cache" list in config) — our package can count invalidations per namespace.

## 9. Testing patterns & pitfalls

- Stack: Testbench **v11.3.0**, Pest **5.3.5**, framework **13.40.0** in `packages/OoMyCache/vendor` (no `phpunit.xml` in the package yet — add one with `CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`).
- Use the `array` store for most tests: tags work [measured], locks work, no external services. But **also run the same suite with `serialize=true`/`serializable_classes=false`**, otherwise the production `__PHP_Incomplete_Class` failure is invisible (see §5.1) — add a matrix test over `null|false|true|allow-list`.
- Cross-test bleed: Testbench builds a fresh app/`ArrayStore` per test, so array state is isolated; the **static** `Repository::$unserializableClassHandler` is not (`Repository.php:90`) — reset it in `tearDown`. `file` store path persists between tests → unique temp dir per test; `database` → `:memory:` or `RefreshDatabase`; never point tests at a shared Redis DB and never call `flushdb` (it ignores prefixes, `RedisStore.php:296-301`); if testing Redis, flush only keys under a unique test prefix and gate with a Pest group/env.
- Time: `$this->travel()/travelTo()/freezeTime()/travelBack()` (`Foundation\Testing\Concerns\InteractsWithTime.php:18-80`) or `Carbon::setTestNow`; needed for `flexible()` fresh/stale/expired boundaries and TTL-0 semantics.
- Deferred callbacks: use `$this->withoutDefer()` to execute deferred work immediately (`InteractsWithContainer.php:257-287`); for HTTP, the middleware only invokes when status < 400 unless `alwaysDefer`.
- Deterministic keys: `TagSet`/`TagSet::resetTag` uses `uniqid()` (`TagSet.php:53`) → never assert raw tagged keys; our version namespaces must accept an injectable clock/id generator so tests can pin generations. Assert behaviour (hit/miss, invalidation) via `Cache::spy()`/`shouldReceive` and events, not key strings.
- Test list for our package: TTL/jitter determinism, versioned invalidation races (write-after-invalidate), SWR boundary + lock contention (ArrayLock), chunking edges (0 rows, exactly threshold, missing chunk), payload modes round-trip incl. enums/DateTime, incomplete-class handler, per-tenant isolation, lock suffix (`Cache::lock` + `restoreLock` cross-process shape), prune command idempotency, and a "store without tags/locks" fake (`StorageStore`-like) to prove graceful degradation.

```php
// tests/Cache/StoreMatrixTest.php — shape for the serialization matrix that catches prod failures
it('round-trips payloads on every store shape', function (string $driver, bool $serializeFlag, mixed $allowed): void {
    config([
        'cache.serializable_classes' => $allowed,
        'cache.stores.matrix' => ['driver' => 'array', 'serialize' => $serializeFlag],
    ]);
    Cache::purge('matrix');

    $repo = Cache::store('matrix');
    $ok   = $repo->getStore()->put('k', serialize(['id' => 1]), 60);   // our envelope = string

    expect($ok)->toBeTrue()
        ->and(unserialize($repo->get('k'), ['allowed_classes' => false]))->toBe(['id' => 1]);
})->with([
    ['array', true, false], ['array', true, ['stdClass']], ['array', false, false],
]);

it('serves stale while revalidate and refreshes after defer', function (): void {
    $this->travelTo('2026-01-01 00:00:00');
    $store = Cache::store('array');
    expect($store->flexible('swr', [2, 10], fn () => 'v1'))->toBe('v1');
    $this->travel(3)->seconds();
    expect($store->flexible('swr', [2, 10], fn () => 'v2'))->toBe('v1'); // stale served
    $this->withoutDefer();                                               // run deferred refresh now
    expect($store->get('swr'))->toBe('v2');
});
```

## 10. Recommended architecture implications for OoMyCache

1. **Store abstraction**: thin `StoreAdapter` over `Illuminate\Contracts\Cache\Repository` exposing `get/put/many/putMany/add/forget/lock` + capability flags (`taggable`, `lockable`, `supportsTouch`); resolve via `Cache::store($config)` (null = app default). Never construct stores directly; honour app `cache.prefix`; if our own prefix is configured, prepend it (single place) and assert total key length.
2. **Key composition** (one deterministic function): `oomycache:v{formatVersion}:{tenant}:{scope}:{gen}:{model}:{queryHash}` where `queryHash = 'xxh128'` of SQL + bindings + connection + driver + query flags. Keep ≤200 bytes (memcached/database safe after prefixes). Expose `oomycache:keys --explain` for debuggability.
3. **Invalidation**: versioned generation per `(tenant, table/model, optional scope)` stored as a small counter; bump on model save/delete/restore/forceDelete + queue `afterCommit`; tags only as optional accelerator. Provide `flushQueryCache()` + global `flush` that clears package prefix only (never `Cache::flush()` on a shared store — `RedisStore::flush()` is `flushdb()`).
4. **Payload envelope + modes**: `json` default (safe on every driver), `php` opt-in with explicit allow-list; `models` mode opt-in with doctor-verified `serializable_classes` allow-list; always store a string (never raw objects), and always include `format/version/created_at/tenant/deps`.
5. **Compression + chunking**: `gzcompress` level 1 at ≥64 KiB; chunk >256 KiB / 1 000 rows; manifest + `many()` reads; hard `max_payload_bytes` 1 MiB default, refusal counted in metrics.
6. **Stampede**: `Cache::lock()` + double-check for hot list pages (block 1–3 s); `flexible()` opt-in per query with an explicit "stale allowed" flag and `alwaysDefer` for error pages only if business-safe; never cache financial balances with SWR.
7. **Fallbacks**: not taggable → version; not lockable → `remember` + TTL (log degraded mode); `null` store → no-op; octane/array stores detected and rejected for production caching (or downgraded to per-request memo).
8. **Default config defaults** (update `docs/plan/02-configuration.md`): `store=null`, `invalidation.mode=version` (auto = tags-if-available + version always), `payload.mode=json`, `allow_serialized_models=false`, `compression.enabled=true` threshold 65536 level 1, `max_payload_bytes=1048576`, `chunking.enabled=true` threshold 262144, `lock.enabled=true` on lockable stores; `metrics.sample_rate`.
9. **Dependency**: add `illuminate/cache` and `illuminate/database` to `require` (plus keep `illuminate/support`); Octane/Filament/Pulse remain optional/suggested, never required.
10. **Documented operational rules**: per-node local drivers (file/array/octane) are single-node only; database cache needs its own connection/table + prune strategy (`laravel-cache-evict` or `oomycache:prune`); Redis needs a dedicated cache DB and a unique `CACHE_PREFIX`; deploy must bump payload format version and purge package keys; never `Cache::flush()` on shared stores.

### 10.1 Proposed v1 config defaults (delta vs `docs/plan/02-configuration.md`)
```php
'store'   => env('OOMYCACHE_STORE'),          // null = config('cache.default') (here: database)
'prefix'  => 'oomycache',
'version' => env('OOMYCACHE_PAYLOAD_VERSION', '1'),

'invalidation' => [
    'mode' => 'version',                       // version|tags|auto ; correctness never tags
    'generation_store' => null,                // null = same store
],

'payload' => [
    'mode'  => 'json',                         // json|php  (models = opt-in only, not a default)
    'allow_list' => [],                        // only for mode=php / models, explicit classes
    'max_payload_bytes' => 1_048_576,
    'compression' => ['enabled' => true, 'algorithm' => 'gzip', 'level' => 1, 'threshold_bytes' => 65_536],
    'chunking' => ['enabled' => true, 'chunk_bytes' => 262_144, 'chunk_items' => 1_000],
],

'lock' => ['enabled' => true, 'seconds' => 10, 'wait' => 2],   // ignored when store is not Lockable
'ttl'  => ['default' => 3_600, 'jitter' => 0.10],
'metrics' => ['enabled' => true, 'sample_rate' => 0.1],
```
Validate on boot: key length ≤200 B, allow-list non-empty when `mode=php`, `max_payload_bytes ≥ chunk_bytes`, store capability requirements (lock/tags) resolved or degraded with a warning.

## 11. Evidence annex

**Vendor paths read (all `vendor/laravel/framework/src/Illuminate/Cache/`)** — `Repository.php`, `CacheManager.php`, `CacheServiceProvider.php`, `TaggableStore.php`, `TagSet.php`, `TaggedCache.php`, `RedisStore.php`, `RedisTagSet.php`, `RedisTaggedCache.php`, `MemcachedStore.php`, `MemcachedConnector.php`, `MemcachedLock.php`, `FileStore.php`, `FileLock.php`, `DatabaseStore.php`, `DatabaseLock.php`, `DynamoDbStore.php`, `StorageStore.php`, `SessionStore.php`, `ApcStore.php`, `ArrayStore.php`, `ArrayLock.php`, `NullStore.php`, `NoLock.php`, `FailoverStore.php`, `MemoizedStore.php`, `MemoizedTaggedCache.php`, `Lock.php`, `CacheLock.php`, `RedisLock.php`, `PhpRedisLock.php`, `RetrievesMultipleKeys.php`, `Console/*`, `Console/stubs/cache.stub`, `Events/*`. Tests: `vendor/laravel/framework/src/Illuminate/Cache/Tests/` not present (framework ships no tests in dist).

**App configs** — `config/cache.php` (default store `env('CACHE_STORE','database')` line 18; stores lines 35-108; prefix line 121; `serializable_classes=false` line 134); `config/database.php` (redis clients/prefix lines 146-182; `cache` DB 1 line 169-180); `config/octane.php` absent (Octane not installed here); effective `cache.default = database` (php artisan config:show, 2026-11-09); package `composer.json` requires only `illuminate/support ^12||^13`; package vendor: framework v13.40.0, Testbench v11.3.0, Pest v5.3.5, `laravel/serializable-closure` present; `spatie/pest-plugin-test-time`, `laravel/pulse`, `laravel/octane`, `predis/predis`, `spatie/laravel-responsecache` absent.

**PHP 8.5.12 CLI (`php -m`)** — present: `zlib`, `openssl`, `json`, `mbstring`, `PDO`/`pdo_mysql`/`pdo_sqlite`, `sqlite3`, `mysqli`; **absent: `igbinary`, `zstd`, `lz4`, `redis`, `memcached`, `relay`, `apcu`**; `serialize_precision=-1`, `memory_limit=1024M` CLI, `opcache.enable_cli=0`. Hash algos available: `md5, sha1, sha256, crc32b, xxh32, xxh64, xxh128`.

**Measured probes** (ephemeral `%TEMP%` scripts, PHP 8.5.12, framework 13.40.0 autoload; not committed):
- serializable matrix on `FileStore`+`Repository`: null→stdClass, false→`__PHP_Incomplete_Class` (handler callback fires with class name `stdClass`), true→stdClass, `['stdClass']`→stdClass; pre-serialized string survives false-config byte-identically; closure put throws `Exception: Serialization of 'Closure' is not allowed`; `ArrayStore(serialize=false)`→stdClass vs `serialize=true`→incomplete.
- Tags: `tags()` on file/database → `BadMethodCallException("This cache store does not support tagging.")`; array-store tagged flush leaves orphaned item key; `TagSet::tagKey()` accepts `:`, space, `,`, `|`; tagged item key `sha1(40 hex):key`.
- Locks: array-store owner/restore/foreign-release semantics; `flexible()` clock probe: t=0 compute, t=1 fresh, t=3 stale + 1 deferred callback, t=4 stale dedupes to 1 callback, invoke → refresh only once (created-stamp check), t=30 expired synchronous recompute.
- Memo: second get served from memory despite store change; `put()` invalidates.
- Benchmarks: encoding/size table §8.1; store put/get table §8.2; model payload 1 602 B vs `toArray()` 340 B; 1 000 models 458 901 B vs 1 000 arrays 448 901 B; gzip ratio ≈12–13 % on serialized arrays; `serialize(serialize(x))` +0.01 % bytes; hash timings §3.

**Docs fetched this session**
- Laravel 13.x cache docs (raw): https://raw.githubusercontent.com/laravel/docs/refs/heads/13.x/cache.md — tag driver warning ("file, dynamodb, database, storage"), lock drivers list, memo/flexible/failover/events sections, `serializable_classes` not documented (only config comment), flush-ignores-prefix warning.
- Memcached protocol (keys ≤250 chars, no whitespace/control; expiration 30-day rule): https://raw.githubusercontent.com/memcached/memcached/master/doc/protocol.txt
- AWS DynamoDB Constraints (item ≤400 KB; partition key ≤2048 B): https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/Constraints.html
- Laravel Octane 2.x source (driver registration + OctaneStore + config): https://raw.githubusercontent.com/laravel/octane/2.x/src/OctaneServiceProvider.php , `.../src/Cache/OctaneStore.php`, `.../config/octane.php`, `.../src/Listeners/FlushArrayCache.php`, `.../src/Listeners/FlushTemporaryContainerInstances.php`
- Redis key/string size 512 MB and MySQL `MEDIUMTEXT` 16 777 215 B: docs-based, not re-fetched this session (https://redis.io/docs/latest/develop/data-types/strings/ ; https://dev.mysql.com/doc/refman/8.4/en/storage-requirements.html).

**Unverified / to validate in CI**: Redis/Memcached/DynamoDB runtime behaviour on this machine (extensions absent); Octane runtime resets (package not installed; verified from Octane 2.x source only); `DatabaseStore`/`DynamoDbStore` per-store `serializable_classes` override (source suggests no-op — `CacheManager.php:465-474`); DynamoDB `forever()` timestamp semantics; memcached item-size default (server config dependent).
