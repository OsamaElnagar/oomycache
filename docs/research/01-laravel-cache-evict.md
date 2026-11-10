# 01 — laravel-cache-evict — Deep Analysis

> Repo: https://github.com/Vectorial1024/laravel-cache-evict · commit `f41952ac8efff4644bbf14d79b570ea0b03d0388` · tag `2.1.2` (2026-09-10) · License MIT · Packagist 22,393 total downloads / ~1,002 monthly / 17 daily, 59★ / 6 forks / 1 open issue (packagist.org + laraplugins.io detail, fetched 2026-11-09) · LaraPlugins health "Medium", 1 contributor, 0 security advisories · PHP `^8.3`, Laravel `12|13` via `illuminate/support ^12.0|^13.0` + hard dep `wilderborn/partyline ^1.0` (`composer.json:34-38`) · analyzed 2026-11-09.

## 0. TL;DR

- **What it is:** a small ops package that adds one Artisan command, `cache:evict {target?}`, which deletes *expired* entries from Laravel's `file` and `database` cache stores (`README.md:73-96`). It is **not** an Eloquent/query cache: it never intercepts queries, has no tags, no invalidation hooks, no payload awareness. The name is misleading.
- **Strongest idea:** expiry-guarded batched deletes on the cache table — read a chunk, collect expired keys, then `DELETE ... WHERE key IN (...) AND expiration <= now` in one statement, so a value refreshed between read and delete is not destroyed (`src/Database/DatabaseEvictStrategy.php:108-118`).
- **Best UX:** one command with a sensible default target, clear success/refusal exit codes, a completion event carrying a stats-bearing strategy object, and explicit "we refuse this driver because it has its own eviction" for memcached/redis/mongodb (`src/CacheEvictCommand.php:35-75`, `src/CacheEvictStrategies.php:41-54`).
- **Biggest risk:** no lock, no dry-run, no time/row budget, no option surface beyond `{target?}`; file mode races writers and (by default in Laravel 12/13) shares a directory with file locks; single maintainer; dormant third-party output dependency.
- **Verdict for us:** **ADAPT** its patterns (not its code as a dependency); **AVOID** adding it to OoMyCache's `composer.json`; **RECOMMEND** it in OoMyCache ops docs only for deployments that run the `file`/`database` cache driver, and only at `^2.1.2` (published `2.1.0`/`2.1.1` require PHP `^8.5`).

## 1. What it does & category

**Category:** cache-store maintenance / expiry garbage collection (ops tooling). NOT a Laravel "cache layer" and NOT an Eloquent query cache; the repo has no model, query-builder, middleware, or Livewire integration (34 tracked files excluding `.git`; 8 PHP classes under `src/`, 9 test files; no `Schedule`, `Cache::lock`, tag or dry-run references anywhere in `src/`).

**Problem it solves:** the `file` and `database` cache drivers never proactively remove expired entries (`README.md:21-24`). `cache:clear` is unusable for this job because it purges everything and also clears framework facade caches under `storage/framework/cache/` (`README.md:27-30`). Entries written with random keys — exactly the pattern a per-query cache produces — accumulate without bound (`README.md:26-27`). The tool scans the store and removes only items whose expiry has already passed.

**Scope of the fix:** only expired items; it does not reclaim database table space and says so (`src/Database/DatabaseEvictStrategy.php:131-134`, `README.md:201-202`). No tag/namespace/prefix configuration, no per-key exclusion, no sync/index rebuild. "Pruning/sync logic" exists only as "delete stale rows"; there is no two-phase or online-sync machinery.

## 2. Architecture & integration model

Laravel auto-discovery: `composer.json:60-66` registers `CacheEvictServiceProvider`; the provider resets a **static** driver→strategy registry in `register()` (`src/CacheEvictServiceProvider.php:15-18`) and only registers the command in console (`:25-29`). No config file, no container bindings, no publish tags, no migrations. Everything is resolved from `cache.php` at runtime and dispatched to a strategy object.

**Code map**

| Path | Role |
|---|---|
| `composer.json` | Package meta; `illuminate/support` + `wilderborn/partyline`; provider autoload; per-driver test scripts |
| `src/CacheEvictServiceProvider.php` | Auto-discovered provider: `initOrReset()` registry, console-only command registration |
| `src/CacheEvictCommand.php` | Entry point; store/driver resolution, strategy selection, execution, event dispatch, exit codes |
| `src/CacheEvictStrategies.php` | Static registry: driver→strategy map + "refused, driver has own eviction" map |
| `src/AbstractEvictStrategy.php` | Base strategy: readonly `storeName`, console output handle, byte formatting, `execute()` contract |
| `src/File/FileEvictStrategy.php` | Filesystem GC for the `file` driver |
| `src/Database/DatabaseEvictStrategy.php` | Table GC for the `database` driver (keyset scan + guarded delete) |
| `src/Events/CacheEvictionCompleted.php` | Post-run event carrying the strategy instance (hence its stats getters) |
| `src/EvictionRefusedFeatureExistsException.php` | Sentinel exception for drivers deliberately not handled |
| `phpunit.xml`, `test/**` | 7 PHPUnit test suites (core/file/mariadb/mysql/pgsql/sqlite/sqlsrv) |
| `.github/workflows/php*.yml` | 8 CI workflows: core, file, MariaDB 12.3, MySQL 9.6 & 8.4, PostgreSQL, SQLite, SQL Server 2025 |

**Execution flow**

```
artisan cache:evict [target?]
  └─ CacheEvictCommand::handle()
       ├─ target ?: config('cache.default')                      (CacheEvictCommand.php:35-39)
       ├─ config("cache.stores.{target}") exists? else FAILURE   (:42-46)
       ├─ driver = config("cache.stores.{target}.driver")        (:49-53)
       ├─ CacheEvictStrategies::getEvictionStrategy(target,driver)
       │    ├─ refused driver  → EvictionRefusedFeatureExistsException → INVALID (:93-95)
       │    ├─ mapped driver   → new Strategy(storeName)              → instance  (:96-99)
       │    └─ unknown driver  → null                                 → INVALID   (Command:57-61)
       ├─ $strategy->execute()                                   (Command:70)
       │    ├─ DatabaseEvictStrategy: count → chunked scan → guarded batch DELETE
       │    └─ FileEvictStrategy: walk dirs in reverse → read 10-byte header → unlink → rmdir
       └─ CacheEvictionCompleted::dispatch($strategy)            (Command:74)
```

Strategies are plain objects, not container-managed; they can only be constructed with a store name (`src/CacheEvictStrategies.php:98`), so there is no DI seam for injecting options, clocks, or loggers — a real extensibility limit.

## 3. Feature inventory

| Feature | Supported? | How it works (path:line) | Quality / limits |
|---|---|---|---|
| Library category | Ops/GC CLI, not query cache | `cache:evict` only; no Eloquent hooks | Cannot help OoMyCache's core problem directly |
| Expired-only eviction, `file` | Yes | Per-file read of first 10 chars; `time() < $expiry` keeps, else `unlink` (`src/File/FileEvictStrategy.php:156-179`) | Relies on Laravel's undocumented file format; no lock taken on files |
| Expired-only eviction, `database` | Yes | `expiration <= time()` collected per chunk, deleted per chunk (`src/Database/DatabaseEvictStrategy.php:95-118`) | Row store only; no tag/namespace filter |
| Tag/namespace-aware eviction | No | No tag API anywhere in `src/` | Our tags must never ride on this tool |
| File store internals | Path-rooted directory sweep | Root from `cache.stores.{store}.path` (`File:31-37`); recursive dir list (`:89`); per-dir `DirectoryIterator` (`:135-144`); `rmdir` empty dirs (`:105-108`) | Reads Laravel FileStore layout: 10-digit zero-padded expiry + serialized value (`vendor/laravel/framework/src/Illuminate/Cache/FileStore.php:94`), `forever` = `put(...,0)`→`9999999999` (`:215-218,480-485`), sha1 path `<path>/<2>/<2>/<sha1>` (`:467-472`) |
| Database store internals | Direct query-builder access | Reads `cache.stores.{store}.connection`/`.table` (`DB:42-44`), store prefix via `getPrefix()` (`:49`); count for progress (`:83-86`); seek-paginated SELECT (`:172-180`); guarded batch DELETE (`:108-118`) | Only the cache table; `cache_locks` untouched (good); no table-space reclaim |
| Prefix scoping | Yes (DB only) | Keyset walk filters `SUBSTRING(key,1,len) = prefix` (`DB:170-176`); prefix from `DatabaseStore::getPrefix()` (`DatabaseStore.php:538-541`), resolved from `cache.stores.*.prefix` / top-level `cache.prefix` (`CacheManager.php:460-463`) | Count query still uses raw `LIKE "{$prefix}%"` (`DB:83-86`) — unescaped `%`/`_` only corrupts the progress maximum. File driver has no prefix in L13 (`FileStore.php:538-541` returns `''`) |
| Multi-store support | One store per invocation | Positional target or default store (`Command:35-39`); no `--all` | To cover N stores the scheduler must invoke N commands |
| Scheduled command | Manual wiring only | README recommends `Schedule::command(CacheEvictCommand::class)->daily()->runInBackground()` (`README.md:88-96`) | No `Schedule::` autoregistration, no scheduler guard |
| Command options | Only `{target?}` | `protected $signature = 'cache:evict {target?}'` (`Command:15`) | No `--dry-run`, `--all`, `--limit`, `--chunk`, `--json`, `--force`, `--budget`; standard Symfony flags only |
| Exit codes | Yes, meaningful | `SUCCESS`; `FAILURE` for missing store/driver (`Command:44-45,51-52`); `INVALID` for refused/unknown driver (`:60,65`) | Uncaught exceptions bypass this contract and abort mid-run |
| Locks / concurrency safety | No explicit locking | Race-tolerant by design: reverse dir order (`File:98-100`), catch vanished dir (`:135-144`), catch unreadable file (`:166-170`); DB re-checks expiration inside DELETE (`DB:110-114`) | Two concurrent runs duplicate work; races can still lose a concurrently written cache item (cache miss, not corruption); no `Cache::lock`/`WithoutOverlapping` |
| Dry-run | No | — | Cannot preview deletions; unsafe as a Filament action without a wrapper |
| Chunking / batching | DB: 100 keys per chunk; file: per-directory | `$chunkCount = 100` (`DB:166`); chunk loop (`DB:91-122`); file iterates `allDirectories()` array (`File:89,100`) | DB scan = ~2 queries per 100 rows even when nothing expired (empty `whereIn` still issues a round-trip); file mode preloads all dir names into memory |
| Pacing / backpressure | Fixed 1 ms sleep | `usleep(1000)` per directory (`File:111`) and per chunk (`DB:121`); CHANGELOG explains stampede reduction (`CHANGELOG.md:26`) | Crude, not configurable, not adaptive; 1M rows ⇒ ~10 s of pure sleep in 100-row chunks |
| Progress / UX output | Yes | Symfony progress bar + Partyline `info`/`warn` (`File:88-127`, `DB:81-134`); default `NullOutput` outside console (`src/AbstractEvictStrategy.php:16-23`) | `Partyline::bind($this)` in the command (`Command:32`); no `--json`/machine output |
| Stats after run | Yes | `getDeletedFiles/FileSize/Dirs/ElapsedTime` (`File:44-74`); `getDeletedRecords/ElapsedTime` (`DB:56-68`); deprecated size counter (`DB:27-31`) | Available only by catching the event; not persisted |
| Event | Yes | `CacheEvictionCompleted` dispatched after `execute()` (`Command:74`; `src/Events/CacheEvictionCompleted.php:11-21`) | Not fired on failure or exception; payload is the mutable strategy object |
| Custom drivers / strategies | Yes, but first-wins | `registerDriverStrategy()` no-ops if driver already registered (`src/CacheEvictStrategies.php:61-70`); `registerDriverRefusedBecauseFeatureExists()` (`:78-83`); `initOrReset()` (`:41-54`); README FAQ (`README.md:186-199`) | Built-in `file`/`database` strategies **cannot** be replaced; only new driver names (which must also be registered with `Cache::extend`) |
| Config of its own | None | No `config/` dir, no publish tags, no env vars; reads host `cache.php` only | Zero-config is nice; also zero tunability |
| Performance claims | Unproven in repo | Generator-per-directory in file mode; chunked DB mode; sleep pacing | No benchmark suite, no memory numbers; README's "hourly-safe / non-blocking" (`README.md:204-206`) is assertion only |
| Failure modes | Partial | Uncaught DB/FS exceptions abort mid-run; event skipped; stats lost; missing `path` config for file store is a `TypeError` at `Storage::build`; missing table is a `QueryException`; unknown driver exits 2 | No transactional/checkpoint resume; rerun is safe-ish (idempotent deletes) |
| Version matrix | Narrow | Composer `php ^8.3`, `illuminate/support ^12|^13` (`composer.json:34-38`); README table (`README.md:63-67`) | **Tag-level trap:** published `2.1.0` and `2.1.1` require `php ^8.5`; only `2.1.2` relaxed to `^8.3` (verified via `git show 2.1.1:composer.json` / `2.1.2:composer.json`; Packagist metadata agrees) |

## 4. Configuration & flexibility

**There is no `config/cache-evict.php` and no `<env>` variable of its own.** All behavior is derived from the host's cache store definition; this is both the UX win (install = done) and the flexibility limit (no chunk size, no lock, no dry-run, no include/exclude).

| Key read | Default | Env (from host `config/cache.php`) | Meaning | Trade-off |
|---|---|---|---|---|
| `cache.default` (`src/CacheEvictCommand.php:37`) | `null` (required) | `CACHE_STORE` | Store name used when `target` argument omitted | A wrong default silently targets the wrong-store family (error message mitigates) |
| `cache.stores.{store}.driver` (`src/CacheEvictCommand.php:49`) | none | — | Driver key → strategy lookup | Custom driver names need separate registration |
| `cache.stores.{store}.path` (`src/File/FileEvictStrategy.php:31`) | none | — (stock config hardcodes `storage_path('framework/cache/data')`) | Root directory to sweep | `lock_path` is ignored; if `lock_path` differs, locks are untouched (safe), if equal, lock files are in scope |
| `cache.stores.{store}.connection` (`src/Database/DatabaseEvictStrategy.php:42-43`) | `null` → default connection | `DB_CACHE_CONNECTION` | DB connection for cache rows | Dedicated cache DB supported |
| `cache.stores.{store}.table` (`src/Database/DatabaseEvictStrategy.php:44`) | none (Laravel config default `cache`) | `DB_CACHE_TABLE` | Table to sweep | `lock_table`/`lock_connection` correctly not touched |
| Store prefix (`cache.stores.*.prefix` ?? `cache.prefix`) — read via `Cache::store(...)->getStore()->getPrefix()` (`DB:49`) | `''` | `CACHE_PREFIX` | Scopes deletion to this store's rows in a shared table | Count query's `LIKE` does not escape `%`/`_` |

The env names above come from the host Laravel app's `config/cache.php` (verified in this workspace at `config/cache.php:18,42-45,121`); the package itself defines no env var and ships **no publish tags**.

**Command options table**

| Signature element | Required | Meaning | Notes |
|---|---|---|---|
| `target` (argument) | No | Cache store *name* (not driver) from `cache.php` | Defaults to `cache.default`; running the `file` driver's store name still resolves by driver (`README.md:139-164`) |
| any option | — | No package options | Standard Symfony `--help/-q/-v` only |

**Exit-code contract** (worth copying): `0` success · `1` (`FAILURE`) unknown store or missing driver config · `2` (`INVALID`) driver known to manage its own eviction, or driver with no strategy.

**Extension points (all static, no service container)**

```php
// src/CacheEvictStrategies.php:61-70 — first registration wins; built-ins cannot be replaced
public static function registerDriverStrategy(string $driverName, string $strategyClass): void
{
    if (isset(self::$strategyMap[$driverName])) {
        return;
    }
    if (!is_subclass_of($strategyClass, AbstractEvictStrategy::class)) {
        throw new InvalidArgumentException(...);
    }
    self::$strategyMap[$driverName] = $strategyClass;
}
```

```php
// README.md:186-199 — user-facing registration recipe
CacheEvictStrategies::registerDriverStrategy('your_driver_name', YourEvictStrategy::class);
CacheEvictStrategies::registerDriverRefusedBecauseFeatureExists('self_managed_driver_name');
```

**Opt-out:** none needed (no global hooks); simply not scheduling the command is the opt-out. Uninstall is clean — no migrations/config/published assets.

**Core algorithm excerpt** (the two patterns we actually want):

```php
// src/Database/DatabaseEvictStrategy.php:110-114 — guarded batch delete
$rowsAffected = $this->dbConn
    ->table($this->dbTable)
    ->whereIn('key', $possibleExpiredKeys)
    ->where('expiration', '<=', $currentTimestamp)
    ->delete();
```

```php
// src/Database/DatabaseEvictStrategy.php:172-180 — seek pagination (orderBy is load-bearing)
$recordsList = $this->dbConn->table($this->dbTable)
    ->select(['key', 'expiration'])
    ->where('key', '>', $currentActualKey)
    ->where(DB::raw("SUBSTRING(...wrap('key').", 1, $prefixLength)"), '=', $cachePrefix)
    ->orderBy('key')
    ->limit($chunkCount)
    ->get();
```

## 5. Ideas worth stealing (ranked)

| # | Idea | Where implemented | Adaptation for OoMyCache |
|---|---|---|---|
| 1 | **Expiry-guarded batch delete** (re-check `expiration` in the DELETE predicate instead of trusting the read) | `src/Database/DatabaseEvictStrategy.php:108-118` | Our own `cache_entries` GC: `UPDATE/DELETE ... WHERE id IN (...) AND expires_at <= now()` so a row refreshed after our scan is never dropped. Also removes the need for a GC lock for correctness; locks only avoid duplicated work. |
| 2 | **Seek/keyset pagination with an explicit cursor + mandatory `orderBy`** | `src/Database/DatabaseEvictStrategy.php:156-193`; comment explaining PostgreSQL ordering at `:177-178` (bug fix `91635db`) | GC sweep over millions of entries: cursor on `id`/primary key, `LIMIT $chunk`, no OFFSET; document "always order by the cursor column or PostgreSQL may return heap order". Pair with a `--budget` so a sweep can stop and resume later. |
| 3 | **Crash-tolerant, reverse-order filesystem sweep** | `src/File/FileEvictStrategy.php:98-112` (reverse dir order), `:135-144` (directory vanished), `:166-179` (file vanished/unreadable) | If OoMyCache ever ships a file-store backend/GC: walk deepest-first, swallow `ENOENT`/`EACCES` per item, and make the operation idempotent so two concurrent prunes are harmless. |
| 4 | **Header-only expiry read** (read 10 bytes, not whole payload) | `src/File/FileEvictStrategy.php:157-165` | Design our file payload as a fixed-width header (`expires_at` + format version) + body; GC reads `file_get_contents($path, length: 16)`. Do **not** parse Laravel's FileStore format — that is an internal, undocumented contract. |
| 5 | **Capability registry with explicit refusal** | `src/CacheEvictStrategies.php:41-54,78-83,91-102`; `src/EvictionRefusedFeatureExistsException.php:9-15` | `CacheDriverCapabilities` for OoMyCache: `redis` → rely on TTL/`maxmemory-policy`; `database`/`file` → we need our own eviction; unknown → warn + skip. Exit-code-typed refusal beats silent no-op. |
| 6 | **Completion event carrying stats** | `src/Events/CacheEvictionCompleted.php:11-21`; stats getters `File:44-74`, `DB:56-68` | Emit `OoMyCachePruned` (store, driver, scanned, deleted, bytes-ish, elapsed, budget-exhausted) so Filament can render a "cache health" widget, Pulse/telemetry can chart growth, and tests can assert counts instead of inspecting tables. |
| 7 | **Cooperative pacing** | `src/Database/DatabaseEvictStrategy.php:120-121`, `File:110-111`; rationale `CHANGELOG.md:26` | GC is extra load on a Livewire-driven panel. Give our prune command `--max-seconds` / `--sleep-us` and prefer "stop early, resume next run" over fixed sleeps; expose safe defaults (e.g. 1–2 s budget). |
| 8 | **Exit-code + default-target UX** | `src/CacheEvictCommand.php:35-75` | Mirror: `{--store=}` defaulting to our cache store, `0` ok, `1` bad config, `2` unsupported driver (so Filament action and schedulers can distinguish "nothing to do" from "failed"). |
| 9 | **One abstract test case inherited per driver** | `test/Core/AbstractCacheEvictTestCase.php:51-93`; vendor subclasses `test/SQLite`, `test/MySQL`, `test/PgSQL`, `test/MariaDB`, `test/SQLSrv`; suites in `phpunit.xml:5-27` | Copy the structure (not the bug) for our store matrix: one contract test asserting "expired gone, forever/live kept, other prefixes untouched"; then per-connection subclasses. Keep the CI matrix narrow: SQLite + MySQL + PostgreSQL is enough. |
| 10 | **Docs matrix + version-bump policy** | `README.md:45-52` (supported/never-supported drivers), `README.md:57-69` (version policy table), `README.md:88-96` (run in background) | Our README should state exactly which stores OoMyCache supports/invalidates and why; and a compatibility table per release line. Also: "schedule prune in background so it never delays other scheduled tasks". |

Anti-steal candidates: Partyline output dependency, the fixed `usleep`, the hidden file-format coupling, and parsing Laravel's serialized payloads.

## 6. Weaknesses, risks, footguns

**Scope/correctness**

- Not a query cache: no model/query identity, no tags, no invalidation, no per-request reuse. In OoMyCache terms it is at most a janitor, never the cache itself.
- Eviction is delete-by-expiry only; because it enumerates whole stores it has no notion of which keys belong to OoMyCache. If a store is shared, one command scans/deletes other packages' expired entries too (by design, but surprising).
- No dry-run and no include/exclude prefix filter beyond store prefix: running it against the wrong target is not previewable.
- File mode treats **all** entries under `cache.stores.{store}.path` as cache files. In Laravel 12/13 the default `file` store sets `lock_path` to the same `storage/framework/cache/data` directory (`config/cache.php:50-53`), and `FileLock` files live in that directory with the same "expiry header" layout. Expired lock files are therefore candidates for `unlink`; a super-fast race could theoretically delete a just-re-acquired lock file and weaken FileLock mutual exclusion. Low severity (only already-expired locks) but undocumented.
- Reading a partially-written file: `file_get_contents($realPath, length: 10)` (`src/File/FileEvictStrategy.php:161`) takes no shared lock, while `FileStore::put` truncates+writes. A truncated header such as `"1762"` parses as an ancient timestamp and the freshly written item can be deleted. Consequence is a cache miss, not data loss, but it shows the "tolerant of races" claim is only partially true.
- Database prefix matching uses `SUBSTRING(key,1,len) = prefix` (good, case-sensitive intent, `DB:170-176`), but the initial `count()` uses `LIKE "{$prefix}%"` (`DB:83-86`) and the keyset comparison `key > cursor` is collation-dependent. With MySQL `utf8mb4_*_ci` and two store prefixes differing only by case (`App:` vs `app:`), a run can match/evict rows of the sibling store; no test covers this.
- Keyset scan is resilient but not a consistent snapshot: rows inserted behind the cursor during the run are skipped until the next run. Acceptable for GC, but should be documented (same for any "expired later" item).
- Batched delete issues a `DELETE ... WHERE 0=1` round-trip for chunks with zero expired items (empty `whereIn` still executes, `DB:108-114`). For a mostly-fresh cache this doubles query count per chunk.
- `usleep(1000)` per 100-row chunk is unconfigurable: on a 1M-row table that is ~10,000 chunks → ~10 s of sleeping plus ~20,000 queries. No benchmark or memory measurement exists in the repo to justify "run it hourly during traffic" (`README.md:204-206`).

**Robustness/UX**

- No concurrency guard (`Cache::lock`, `WithoutOverlapping`, advisory DB lock). Two schedulers/runs duplicate scans and deletes; correctness survives because deletes are guarded, but load doubles.
- No error handling around `execute()` (`src/CacheEvictCommand.php:69-70`): a missing cache table, DB timeout, unreadable path, or `cache.stores.*.path = null` (→ `TypeError` in `Storage::build`, `File:34-37`) aborts the run with a stack trace; partial deletion has happened, the event is never dispatched, and stats are lost.
- File mode discovers the root only through `cache.stores.{store}.path`; a store built with a custom `Filesystem`/path or a non-local disk is silently wrong or crashes. `lock_path` is ignored (`File:31-37`).
- Built-in strategies cannot be overridden: `registerDriverStrategy` no-ops on an existing key (`src/CacheEvictStrategies.php:63-65`), and provider `register()` runs before app `boot()`, so user code always loses. You can only add strategies for brand-new driver names, and those must also be wired with `Cache::extend` for the store to work at all.
- Static global registry (`CacheEvictStrategies::$strategyMap`) is process-global; tests must call `initOrReset()` (`src/CacheEvictServiceProvider.php:17`, `test/Core/AbstractCacheEvictTestCase.php:26`). Long-running workers (Octane) keep whatever got registered first.
- Progress bar and messages depend on Partyline binding; machine-readable use (CI, Filament action) has no JSON/array result path — only the event and public getters.
- `DatabaseEvictStrategy::yieldCacheTableItems()` is dead code kept as a `#[Deprecated]` generator that yields an empty `stdClass` (`DB:141-148`). On PHP 8.3 `#[Deprecated]` is an unknown attribute (harmless at runtime, noisy for static analysis); on 8.4 it is the real built-in attribute — version-dependent semantics with no value.

**Supply chain / maintenance**

- One effective maintainer: 242/243 commits by Vincent Wong (`git shortlog -sne`); LaraPlugins reports 1 contributor and "Medium" health. Bus factor = 1.
- Hard runtime dependency `wilderborn/partyline ^1.0` for console output — latest release 1.0.2 was **December 2022** (Packagist), described as "a Laravel 5 package". It works today, but it is dormant and unnecessary: Laravel's own `$this->output`/`$this->components` suffice. Avoid inheriting this dependency in OoMyCache.
- The project already survived one supply-chain incident: it depended on `ramazancetinkaya/byte-formatter`, removed in 1.0.3 after an external rugpull (`CHANGELOG.md:69-74`; removal commit `7dd5aff`). Good example of why a tiny helper dependency is not free.
- Released-version trap: `2.1.0`/`2.1.1` require `php ^8.5` — users on PHP 8.3/8.4 must pin `^2.1.2` (verified in tags, not just changelog). Version requirements moved 8.1→8.2→8.3→8.4→8.5→8.3 and Laravel 10/11→12→13 within ~2 years (`git log -- composer.json`); README's own 2.2.x row is "Speculative" (`README.md:65`). Treat the package as version-fragile.
- Security: no runtime advisories (LaraPlugins: 0 unpatched/resolved). 2.1.1's "security advisory" is dev-only PHPUnit CVE-2026-41570 (GHSA-qrr6-mg7r-m243, HIGH, poisoned-pipeline/argument injection; patched in `phpunit ^12.5.22`/`^13.1.6` — commit `4a6c16b` bumps `^13.1.6`). It does not affect deployments of this package, but shows the CI-only patch-release pattern.
- Packaging nit: it requires only `illuminate/support` yet uses `Illuminate\Console\Command`, facades `Cache`/`DB`/`Storage`, `Illuminate\Cache\DatabaseStore`, Symfony console classes. Fine inside a Laravel app, misleading as a Composer contract.
- MySQL/InnoDB note (correct in the README): deletes do not shrink tables; deleted space is reused but files don't shrink (`DB:131-134`). For a query-cache table with random-key churn, plan for `OPTIMIZE TABLE` / `innodb_file_per_table` maintenance separately.

## 7. Filament 5 / Livewire 4 compatibility assessment

**Direct compatibility surface: none, and that is fine.** The package registers one Artisan command and a completion event; it has no HTTP routes, no Livewire components, no Blade, no UI, no queue jobs, no service-container extension, so Filament 5 / Livewire 4 cannot conflict with it. Its constraints (`php ^8.3`, `illuminate/support ^12|^13`) fit the target stack — this app currently runs Laravel `v13.40.0`, Filament `v5.11.2`, Livewire `v4.4.8` (local `composer.lock`), so it would install cleanly if ever wanted.

**Operational interplay with OoMyCache's stores**

- Filament 5's own optimization caches (`filament:cache-components`, icons/schema/panel caches) are PHP-array files under `bootstrap/cache/filament` (`vendor/filament/support/config/filament.php:103`, `vendor/filament/filament/src/Panel/Concerns/HasComponents.php:796`), not the app cache store — `cache:evict` never touches them, so there is no risk to panel boot performance.
- This workspace's `.env` sets `CACHE_STORE=database` with `DB_CONNECTION=sqlite` (`.env:40`, `.env:23`), i.e. the **database cache driver is the local default**, so `php artisan cache:evict database` is directly usable here and its SQLite path is covered by `test/SQLite/SQLiteEvictTest.php`.
- If OoMyCache stores payloads in the app's `file` or `database` store, expired entries pile up exactly as the README describes (`README.md:21-27`), and a daily `cache:evict <store>` in the background would clean them. This is the only genuinely useful combination for us: a documented ops companion for file/database-driver deployments.
- If OoMyCache stores payloads in its **own** table (the likely design for tags, dependencies, hit counters, and Filament UI inspection), this package does nothing for us — we must ship our own pruner. Its patterns (ideas 1–4, 7) are what we reuse; its command is not.
- For Redis/Memcached/MongoDB it deliberately refuses (`src/CacheEvictStrategies.php:48-50`) and points at the driver's native eviction; our package should likewise rely on Redis TTL + `maxmemory-policy` and not build its own eviction for Redis.
- Filament panels are bursty: table pagination/filters, dashboard poll widgets, and Livewire 4 islands cause repeated query bursts; cache hit-rates will be high, so entries written with random (per-filter/per-page) keys are the dominant growth vector. A bounded, scheduled prune with a completion event we can surface in a Filament widget is a product feature for OoMyCache, not just hygiene.

**Verdict:** no compatibility blocker; recommended only as an optional, documented companion for `file`/`database` cache drivers, never a requirement. It cannot be a substitute for our GC.

## 8. QA / test-suite review

**Stack:** PHPUnit `^12.0`, `orchestra/testbench ^10|^11` (`composer.json:39-47`); suites declared in `phpunit.xml:5-27`; per-driver Composer scripts (`composer.json:51-59`): `test`, `test-file`, `test-mariadb`, `test-mysql`, `test-pgsql`, `test-sqlite`, `test-sqlsrv`. CI = 8 workflows, PHP 8.3, real services: MariaDB 12.3, MySQL 9.6 (innovation) and 8.4 (LTS), PostgreSQL (latest image), SQL Server 2025, plus SQLite and file (`php-mariadb.yml:30` `mysql-version: 'mariadb-12.3'`; `php-mysql-inno.yml:30` `mysql-version: 'mysql-9.6'`; `php-mysql-lts.yml:30` `mysql-version: 'mysql-8.4'`; `php-sqlsrv.yml` service `mcr.microsoft.com/mssql/server:2025-latest`). Vendor matrix testing is the strongest part of this repo.

**Executed test cases:** 19 (1 file-driver test + 5 DB drivers × 3 tests + 3 registry tests), across 6 test methods:

| Test | Path | Covers |
|---|---|---|
| `testCorrectCacheEviction` | `test/Core/AbstractCacheEvictTestCase.php:51-93` | Writes one 1 s TTL item + one `forever` item, sleeps 1 s, runs strategy, asserts expired gone / forever kept (`:72-86`); cleanup re-runs strategy (`:92`) |
| `testDatabaseKeyWalking` | `test/Core/AbstractDatabaseCacheEvictTestCase.php:26-56` | 5 random 1 s items; asserts evictor terminates and removes them ("hopefully CI kills hanging scripts", `:28-29`) |
| `testDatabasePrefixRespect` | `test/Core/AbstractDatabaseCacheEvictTestCase.php:58-105` | Two prefixed stores; asserts related expired keys removed, unrelated live keys kept |
| `testCorrectPresetStrategies` | `test/Core/CacheEvictStrategiesTest.php:35-57` | memcached/redis/mongodb refuse; file/database resolve; unknown driver → null |
| `testNoOverridingStrategies` | `test/Core/CacheEvictStrategiesTest.php:59-66` | Registering a different strategy for `file` is a no-op (first wins) |
| `testDefineCustomStrategies` | `test/Core/CacheEvictStrategiesTest.php:68-81` | Custom driver name maps to `FileEvictStrategy` |

**Gaps / bugs in the tests**

- The prefix test is **ineffective**: both `$prefixRelated` and `$prefixUnrelated` are assigned the *same* `"prefix".$theNumber` (`test/Core/AbstractDatabaseCacheEvictTestCase.php:19-21`). The "unrelated" assertions pass only because those items have a 60 s TTL, not because eviction is prefix-scoped — the test would pass even if the prefix filter were removed entirely. (Our contract tests must use genuinely different prefixes, and one of them not matching the store prefix at all.)
- `testCorrectPresetStrategies` calls `expectException` three times (`:38-45`) but the first thrown exception ends the test; the Redis and MongoDB refusal paths are never actually executed (only memcached is exercised).
- No tests for the command itself: exit codes, default-store fallback, refusal message, missing driver/store, `CacheEvictionCompleted` event.
- No concurrency tests (multiple evictors, concurrent writers), no failure-mode tests (bad path, missing table, unreadable files), no large-table/performance test.
- Test speed: real `sleep(1)` per eviction test (`:79`, `AbstractDatabaseCacheEvictTestCase:46,92`); each of the 15 DB cases waits ≥1 s (the file case too). Acceptable in CI, wasteful locally.
- `FileEvictTest` points at the repo's `storage/framework/cache/data` and intentionally does not clean up (`test/File/FileEvictTest.php:13-28`); a `.gitignore` exists in that directory so the tree stays clean, but test isolation is weak.
- Odd workaround: `CacheEvictStrategiesTest` stubs `call/be/seed/artisan` as no-op methods (`:85-103`) — presumably to satisfy Testbench/PHPUnit 12 trait signatures; it signals the test stack is fighting its harness.

**How to run:** `composer run-script test` for core; `composer run-script test-file` / `test-sqlite` for quick driver checks; other scripts need live MySQL/MariaDB/PostgreSQL/SQL Server matching the fixed credentials in `test/MySQL/MySQLEvictTest.php:19-21` etc. (e.g. `testuser/testpass`, `postgres/postgres`, `sa/LaravelPassword!2026`). The clone contains no `vendor/`, so nothing was executed during this analysis (read-only review).

## 9. Verdict: Adopt / Adapt / Avoid

| Area | Decision | Payoff | Effort |
|---|---|---|---|
| Add `vectorial1024/laravel-cache-evict` to OoMyCache `composer.json` | **Avoid** | None for our core job; adds a dormant dependency chain | — |
| Document it as an optional ops companion for `file`/`database` cache driver deployments (pin `^2.1.2`) | **Adopt (docs)** | Real user value: prevents file/database cache bloat without `cache:clear` | Low: one docs page + scheduler snippet |
| Expiry-guarded batch delete for our own GC | **Adopt** | Removes TOCTOU bugs when a hot key is refreshed during a sweep; no GC lock needed for correctness | Low (~30 LOC in our pruner) |
| Keyset/seek pagination with explicit `orderBy` and chunk size | **Adopt** | O(1) memory over millions of rows; works on MySQL/PG/SQLite | Low–Med |
| Capability registry + typed refusal (Redis/Memcached → native TTL) | **Adopt** | Clear behavior/docs; avoids dangerous "GC Redis" footguns | Low |
| Completion event with stats for observability (Filament widget, telemetry, tests) | **Adopt** | Product differentiator; testable counts instead of table inspection | Low–Med |
| Cooperative budget/pacing (`--max-seconds`, resume next run) | **Adapt** | Keeps prune off the critical path of Livewire requests | Med |
| Header-only expiry read + reverse crash-tolerant FS sweep | **Adapt** only if we ship a file-store backend with our own envelope | Fast file GC without unserializing payloads | Med |
| Parse Laravel `FileStore`'s 10-byte header / serialize format | **Avoid** | Internal contract, no stability guarantee | — |
| Partyline + `usleep(1000)` as-is | **Avoid** | Unneeded dependency; crude pacing | — |
| Their "guarded delete + keyset + refusal + event" as a *whole* | **Adapt** | ~80% of the operational design for a fraction of the code | 1–2 dev-days |

**Bottom line:** an honest, well-tested-for-its-scope little janitor, not a query cache. It cannot be the basis of OoMyCache, but four of its patterns (guarded delete, keyset sweep, capability refusal, completion event with stats) belong in our pruner design, and its README-level ops recommendation (`Schedule::command(...)->daily()->runInBackground()`) belongs in our docs for file/database drivers. Do not bundle it.

**Suggested ops note for the OoMyCache docs** (only if we ship file/database-store support):

```php
// app/Console/Kernel.php (Laravel 12+ schedule in routes/console.php or bootstrap/app.php)
use Illuminate\Support\Facades\Schedule;
use Vectorial1024\LaravelCacheEvict\CacheEvictCommand;

// Background + daily: evicts only expired entries of the store OoMyCache uses.
Schedule::command(CacheEvictCommand::class, ['file'])->daily()->runInBackground();
Schedule::command(CacheEvictCommand::class, ['database'])->daily()->runInBackground();
```

Pin `vectorial1024/laravel-cache-evict:^2.1.2` (2.1.0/2.1.1 need PHP 8.5); do not add it to our `require`.

## 10. Evidence map & git-log observations

| File | Purpose / why it matters |
|---|---|
| `composer.json:34-38` | Runtime requirements: PHP ^8.3, `illuminate/support ^12|^13`, hard dep `wilderborn/partyline ^1.0` |
| `composer.json:60-66` | Laravel auto-discovery provider — zero-config integration |
| `src/CacheEvictCommand.php:15` | Only CLI surface: `cache:evict {target?}` |
| `src/CacheEvictCommand.php:35-75` | Store resolution, strategy picking, exit codes, event dispatch |
| `src/CacheEvictStrategies.php:41-54` | Default driver matrix (file/database handled; memcached/redis/mongodb refused) |
| `src/CacheEvictStrategies.php:61-70` | First-registration-wins (why built-ins can't be overridden) |
| `src/File/FileEvictStrategy.php:89-128` | Directory sweep, progress, `rmdir`, pacing, final stats |
| `src/File/FileEvictStrategy.php:156-179` | 10-byte header expiry read + unlink; race handling |
| `src/Database/DatabaseEvictStrategy.php:83-122` | Count → chunk scan → guarded delete → pacing |
| `src/Database/DatabaseEvictStrategy.php:156-193` | Keyset generator, SUBSTRING prefix filter, mandatory `orderBy` |
| `src/Events/CacheEvictionCompleted.php:11-21` | Event payload = strategy object (stats via getters) |
| `test/Core/AbstractCacheEvictTestCase.php:51-93` | The reusable driver contract test we should imitate |
| `test/Core/AbstractDatabaseCacheEvictTestCase.php:19-21` | The identical-prefix bug (ineffective prefix test) |
| `phpunit.xml:5-27` + `.github/workflows/php*.yml` | 7 suites, 8 CI workflows, real DB vendor matrix |
| `README.md:21-33,45-52` | Accurate problem statement + supported/never-supported drivers |
| `README.md:63-67,186-206` | Version policy, custom strategies FAQ, "safe during high traffic" claim |
| `CHANGELOG.md:20-74` | Every production bug they hit; fastest way to see the footgun list |

**Git-log observations (hashes are real in the clone):**

- `38d7002` "Avoid database evict race condition" — replaced "read through the cache store then delete" with the expiry-guarded `DELETE`; the origin of idea #1.
- `639179b` "Fix improper handling of database cache prefixes" → `4a122e2` "DbEvict: standardize prefix matching" → `5cd707e`/`ad1c856` SUBSTRING + DB-agnostic quoting: prefix handling was wrong or DB-specific **four** times; treat collation/prefix as a high-risk area (also `CHANGELOG.md:43-45,20-23`).
- `91635db` "DbEvict: fix PostgreSQL iteration problem" — missing `orderBy('key')` made the scan skip rows on PostgreSQL; exactly why our keyset sweep must always order explicitly.
- `837dedb` "Fix bad database iteration on MySQL and SQL Server" + `1e02fff`/`8666566` type casts: DB driver differences in row comparison/types are real; test at least MySQL + PostgreSQL + SQLite.
- `aa83af2` "Speed up database evictor by using chunking" then `4f72e8d` "Reduce stampeding effect" — the chunked scan and the fixed `usleep(1000)` were both reactive fixes after performance concern.
- `40541f5` "FileEvict: safe handling of gone dirs" + `636fc26` "Correctly track removed directories" — file races and stats bugs are recurring; `CHANGELOG.md:56-58` (2.0.2) records the earlier `UnexpectedValueException` from parallel cleaners.
- `ccff06f` "Create 'eviction complete' event" + `8ce4573` "Add essential getters to expose eviction stats" — event was added only because consumers needed stats; design it in from day one.
- `7dd5aff` "Disuse the unreliable external library" — removal of the rugpulled `ramazancetinkaya/byte-formatter`; read `CHANGELOG.md:69-74` before adding any tiny helper dependency to OoMyCache.
- `4a6c16b` (2.1.1) — dev-only PHPUnit CVE-2026-41570 bump; `712c5e0` — PHP requirement downgraded 8.5→8.3 in 2.1.2, leaving 2.1.0/2.1.1 unusable on PHP 8.3/8.4; `14bcd60` "Property hooks doesn't work here" shows PHP-version churn.
- Timeline: 243 commits, 2024-05-28 → 2026-09-10, tags 1.0.3 → 2.1.2; effectively single-author (Vincent Wong: 242 commits; 1 external contributor: 1 commit). Stable, but not a large OSS project.

**Not verified in this analysis (limits of the evidence):** no code was executed (clone has no `vendor/` and installs are out of scope), so performance/pacing claims are derived from source structure, not measurements; the file/lock race is reasoned from `FileStore`/`FileLock` source, not reproduced; no GitHub issue/PR content was reviewed (only commit messages); Filament/Livewire interplay is assessed statically (no Filament panel was booted).
