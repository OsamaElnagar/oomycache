# OoMyCache — Security & Edge-Case Review (Launch Sign-off)

> **Read-only review** of OoMyCache core (`packages/OoMyCache`) and the Filament bridge (`packages/OoMyCacheFilament`), 2026-11-09/10. No source or test file was modified.
> **Reviewed stack:** PHP 8.5.12 · Laravel 13.40.0 · Filament 5.11.2 · Livewire 4.4.8 · SQLite, cache store `database` (dogfood app), real phpredis test lane for Redis.
> **Baseline:** primary pass **2026-11-10 00:30** on `QueryCacheEngine.php` `609C690F230A` · `QueryCacheBuilder.php` `349FE9D3EB32` · `Fingerprint.php` `57E76FAF3C3B` · `ArrayPayloadCodec.php` `D8ED10DB92AD` · `ChunkedPayloadStore.php` `C3B384880CCC` · `OomycacheServiceProvider.php` `D42FF28F1C19` · `config/oomycache.php` `5B0BB462A39E` · companion `QueryCacheStudio.php` `9E79ECF1001B`. **Re-check 2026-11-10 11:32–11:48** after the chunking merge: `QueryCacheEngine.php` `A44E10D12988`, `OomycacheServiceProvider.php` `008858C8881A`, `ChunkedPayloadStore.php` `92C66B32F628` (final 11:48); all other hashes above still match, and line references below are the post-merge ones. Re-verify hashes before citing after any further merge.
> **Method:** line-by-line source/config read (both packages + Laravel 13.40 vendor internals for serialization, `DatabaseStore`, `Model::increment`), plus executed test lanes: `vendor/bin/pest` in `packages/OoMyCache` → **53 passed / 3 failed / 215 assertions** at the 00:30 baseline, and **56 passed / 220 assertions, 0 failures** at the 11:48 re-check; `php artisan test --compact --filter=QueryCache` → **25 passed / 1 skipped / 134 assertions**; `php artisan tinker --execute="…TenantResolver…"` → bound class `Oomycache\Filament\Support\FilamentTenantResolver`.
> **Severity counts after post-review fixes: Critical 0 · High 0 open (3 fixed) · Medium 3 open (M1/M3/M6 fixed) · Low 3 open (L2/L3/L5 fixed) · Info 2 (17 findings).** H4 (chunking tests red) was **resolved** by the 11:32 merge — see §3.0. The three High items (H1–H3) and L5 were fixed after the review (see §3.1). Verdict: **conditional alpha sign-off** for single-tenant, FPM or queue/Octane deployments **after** the M1/M3/M4/M5 residual items are documented/accepted for the alpha; public/stable still requires the remaining medium items to be resolved or explicitly accepted.

## 1. Scope & assumptions

In scope: cache-key composition/collision risk, tenant/panel isolation + fail-closed, memoized payload/version-token risk, stale-read paths (transactions, `afterCommit`, raw/quiet/pivot writes, database-store visibility), cache poisoning via bindings/query shapes/search bypass, payload deserialization under `serializable_classes=false`, DoS/abuse (payload size, cache-table bloat, prune), command safety, Filament studio authorization/bulk flush/resolver.

Assumed trusted: application code, `config/*`, cache-store credentials/prefix, and anyone with cache-write access (cache write is treated as equivalent to app compromise). Out of scope: cross-app cache-prefix isolation beyond documenting the requirement, host/network security, dependency CVEs.

## 2. Findings summary

| ID | Sev. | Class | Finding | Evidence |
|---|---|---|---|---|
| H1 | High | **fixed post-review** | Studio purge/clear actions are available to *every* authenticated panel user; `can_purge` exists in config but is never read | `packages/OoMyCacheFilament/src/Pages/QueryCacheStudio.php:57-70,75-146`; `packages/OoMyCache/config/oomycache.php:262-263` |
| H2 | High | **fixed post-review** | Long-running processes: `requestMemo`/`versionMemo`/`globalToken` live on the singleton engine with no request/job reset → cross-request stale reads and unbounded memo growth (Octane/queue workers) | `src/Cache/QueryCacheEngine.php:49-57,218-222,309-311,664-679`; `src/OomycacheServiceProvider.php:79-103` (no reset hooks) |
| H3 | High | **fixed post-review** (multi-tenant) | No-panel contexts (queue, console, front-office/API) resolve to `TenantContext::none()` — un-tenanted keys; companion provider also overrides any configured `oomycache.tenant.resolver` globally | `packages/OoMyCacheFilament/src/Support/FilamentTenantResolver.php:29-37`; `packages/OoMyCacheFilament/src/OomycacheFilamentServiceProvider.php:26-33`; `src/OomycacheServiceProvider.php:42-51`; confirmed binding via tinker |
| M1 | Medium | **fixed post-review** | With chunking enabled, `safety.max_payload_bytes` no longer caps payloads: default logical budget = 256 KiB × 64 = 16 MiB/key; read path accepts manifests up to 4096 chunks / 256 MiB and reassembles in memory | `src/Cache/QueryCacheEngine.php:274-296`; `src/Payload/ChunkedPayloadStore.php:49-51,274-278`; `config/oomycache.php:115-139` |
| M2 | Medium | **document only** | Quiet/raw/non-Filament pivot writes bypass invalidation: `saveQuietly`/`withoutEvents`, `DB::table()->update()`, and relation `attach/detach` outside Filament actions are not observed → stale reads ≤ TTL (or longer with `cacheForForever`) | `src/Invalidation/InvalidationManager.php:25-40`; `src/Builder/QueryCacheBuilder.php:433-507`; `packages/OoMyCacheFilament/src/Support/FilamentCacheInvalidator.php:41-51` |
| M3 | Medium | **fixed post-review** | Invalidation failures throw (no catch around `versions->bump()`); `safety.log_invalidation_failures`/`fail_silently` (read-only flag) are not wired → cache-store outage fails DB writes after commit, contrary to config expectations | `src/Invalidation/InvalidationManager.php:59-74`; `src/Cache/CacheVersionStore.php:35-41`; `config/oomycache.php:93-94`; only `fail_silently` is read (`QueryCacheEngine.php:773-783`) |
| M4 | Medium | **document only** | `cacheForForever()` on the `database` store writes ~10-year rows (Laravel `forever` = 315,360,000 s); `oomycache:clear` only bumps a token and `oomycache:prune` deletes only expired rows → payloads are unreclaimable by the package | `vendor/…/Illuminate/Cache/DatabaseStore.php:329-332`; `src/Cache/QueryCacheEngine.php:476-487`; `src/Cache/DatabaseCachePruner.php:171-190`; `src/Builder/QueryCacheBuilder.php:93-98` |
| M5 | Medium | **needs fix / docs** | Cache-fill DoS: bindings are part of the key, so user-controlled filter/search values create unique entries; `reads`/`single_records` default on, TTL 300 s, no per-user key quota or rate limit in the package | `src/Cache/QueryCacheEngine.php:626-647`; `config/oomycache.php:69-80,47`; payload/chunk budgets `config:96,115-139` |
| M6 | Medium | **fixed post-review** | Advertised but unimplemented keys create a false security posture: `invalidation` (tags), `locking` (single-flight), `debug`, `log_channel`, `redact_bindings_in_logs`, `allow_serialized_models`, `max_relation_depth`, `payload.mode=models`, `filament.dashboard.can_purge` (now implemented) | grep of `src/` finds no reads; `config/oomycache.php:61,88-99,108,163-168,190-191,257-264`; `src/Cache/QueryCacheEngine.php:518` (mode is reporting only) |
| L1 | Low | **needs fix (defense in depth)** | Cache keys use non-cryptographic `xxh128` over attacker-influenced bindings; a crafted collision could merge two queries' keys within the same tenant/panel | `src/Query/Fingerprint.php:81-84`; `src/Cache/QueryCacheEngine.php:626-647` |
| L2 | Low | **fixed post-review** | Payload decode instantiates a class name taken from the cached payload for `__eloquent_collection` without a subclass/allow-list check (`new $collectionClass($items)`) | `src/Payload/ArrayPayloadCodec.php:109-119` |
| L3 | Low | **fixed post-review** | Decompression has no output-size cap (`gzdecode`/`gzinflate`/`zstd_uncompress`); a corrupt/hostile compressed entry can expand far beyond the stored size (memory DoS) | `src/Payload/PayloadCompressor.php:62-84` |
| L4 | Low | **document only** | Search bypass is a SQL-substring heuristic (` like `, ` ilike `, ` regexp `, ` match `); full-text/raw SQL shapes (`MATCH(`, `LOCATE(`, `INSTR(`) can still be cached by default | `src/Cache/QueryCacheEngine.php:608-619`; `config/oomycache.php:77` |
| L5 | Low | **fixed post-review** | Studio shows raw exception messages to authenticated panel users in failure notifications (internal paths/SQL hints possible) | `packages/OoMyCacheFilament/src/Pages/QueryCacheStudio.php:112-118,137-143` |
| L6 | Low | **document only** | Key-prefix truncation (24 chars) and dot-less concatenation of prefix/version/tenant segment can collide under misconfiguration; requires unique `CACHE_PREFIX` per app on shared stores | `src/Cache/QueryCacheEngine.php:684-695`; `src/Cache/CacheVersionStore.php:63-66` |
| I1 | Info | **document only** | Filament invalidator flushes on **every** `BulkAction` (not only event-bypassing names) → over-invalidation/cache thrash, no data risk | `packages/OoMyCacheFilament/src/Support/FilamentCacheInvalidator.php:108-117` |
| I2 | Info | **document only** | Studio/status expose the resolved tenant segments (own tenant/panel class+key) and version tokens; authenticated panel context only, tokens are not secrets | `src/Cache/QueryCacheEngine.php:509-537`; `packages/OoMyCacheFilament/src/Pages/QueryCacheStudio.php:193-206`; blade `packages/OoMyCacheFilament/resources/views/pages/query-cache-studio.blade.php:30-93` |

## 3. Findings detail

### 3.0 Re-check after the chunking merge (2026-11-10 11:48 final)

At the 00:30 baseline the core suite was **53 passed / 3 failed / 215 assertions**, with all failures in `tests/Feature/ChunkingTest.php` (version invalidation of chunked entries, `max_chunks` bypass, chunking-disabled `max_payload_bytes` bypass). After the chunking merge (`QueryCacheEngine.php` → `A44E10D12988`, `ChunkedPayloadStore.php` → `92C66B32F628`, provider → `008858C8881A`) the suite is **56 passed / 220 assertions, 0 failures** (re-run at 11:48 after a follow-up chunk-store edit: still green), including the corrupt/missing-chunk, manifest-tamper and post-invalidation-miss cases (`tests/Feature/ChunkingTest.php`). H4 is therefore **verified fixed**, not counted in the severity totals; it remains a recurring gate: re-run `composer test` on every merge. The other High findings were re-verified against the post-merge tree: still no `can_purge` read anywhere (H1), still no Octane/queue reset hooks (`grep` finds zero `RequestReceived|JobProcessed|Looping|octane` in both packages — H2), companion resolver/provider files unchanged (H3).

**H1 — Studio privilege gap (highest launch risk).** `canAccess()` returns true for any authenticated user unless the optional `can_view` gate is configured (`QueryCacheStudio.php:57-70`); neither header action (`invalidateSelectedModel`, `clearQueryCache`) checks `filament.dashboard.can_purge` — the key exists (`config:263`) but is read nowhere (grep of both packages). Impact: any back-office user who can open the panel can flush model/global caches, enabling repeated cache-thrash/DoS and confusing operator actions; no cross-tenant data exposure. Fix: add `->visible(fn () => …Gate::allows($canPurge))`/`->authorize()` to both actions (default deny when the gate is missing), test unauthorized user; or document "admin-only panel required" until fixed.

**H2 — Long-running process state.** `QueryCacheEngine` is a singleton (`src/OomycacheServiceProvider.php:53-64`) holding `optionsMemo`, `versionMemo`, `globalToken`, `requestMemo` (`QueryCacheEngine.php:49-57`). Only `flushTable()`/`clear()` reset tokens locally; `requestMemo` entries have no TTL and are never cleared by the provider (no Octane `RequestReceived`/queue `JobProcessed`/`Looping` hooks; boot `:79-103` registers only invalidation + stats listeners). Consequences: (a) Octane workers can serve values memoized in an earlier request indefinitely and grow unboundedly; (b) queue workers keep a stale version token — staleness is bounded by payload TTL (300 s) for TTL'd entries, but `cacheForForever()` entries + memoized payloads can stay stale for the process lifetime. FPM (one process per request) is unaffected. Fix: make the engine request-scoped (`scoped()`/Octane listener) or add `resetForRequest()`/`resetForJob()` clearing all four fields; document `payload.memoize_per_request=false` for queue workers in the interim.

**H3 — Tenant resolution outside panels.** The bridge binds `FilamentTenantResolver` globally (`OomycacheFilamentServiceProvider.php:26-33`, confirmed by tinker) and overrides the core's configurable resolver (`OomycacheServiceProvider.php:42-51`), silently ignoring `oomycache.tenant.resolver`. The resolver returns `TenantContext::none()` when there is no current panel (`FilamentTenantResolver.php:35-37`), so queue jobs, artisan commands, and non-Filament routes/APIs write un-tenanted keys in a multi-tenant app. If those entrypoints rely on panel tenancy for isolation (rather than SQL scoping), entries are shared across tenants. App test intentionally asserts "does not add a tenant key segment outside tenant-enabled panels" (`tests/Feature/QueryCacheTenantPanelTest.php:176`). Fix: resolver should fail closed when tenancy is configured but no panel/tenant context exists, or bind a config-aware composite resolver; document the assumption loudly for API/queue adapters.

**H4 — resolved during the review window (chunking suite).** At the 00:30 baseline the core suite was red on chunking security-adjacent tests; the 11:32 re-check is green (56/220, see §3.0). No open action beyond keeping the suite green on future merges.

**M1/M5 — payload/DoS envelope.** Chunking config documents that `max_payload_bytes` no longer caps when enabled (`config:115-139`, engine `:274-296`), default budget 16 MiB per logical entry; read-time manifest bounds are 4096 chunks / 256 MiB (`ChunkedPayloadStore.php:49-51,274-278`) and reassemble into one PHP string. Combined with user-controlled unique filters/IDs (M5), a client can inflate DB cache rows/Redis memory and per-request memory; the engine's only guard is total chunk count. Recommend a hard `max_total_bytes` independent of chunking (e.g. 2 MiB default), lower `max_chunks`, and monitoring on cache-table bytes; document that app-level authorization/rate limiting is required for public entrypoints.

**M2 — stale-read residual (documented-only acceptable).** Invalidation is Eloquent-event + builder-write based: `eloquent.*` listener (`InvalidationManager.php:25-40`) and overridden builder writes (`QueryCacheBuilder.php:433-507`). `saveQuietly`/`Model::withoutEvents`, raw `DB::table()`/`DB::statement`, and non-Filament pivot writes bypass it; Filament actions are covered only through `ActionCalled` name/type allow-lists (`FilamentCacheInvalidator.php:41-51,108-117`). Staleness is bounded by TTL (300 s default) except `cacheForForever`. Required: document "raw/quiet/pivot writes must call `flushQueryCache()`/`Oomycache::flush()`", add a debug-mode statement/pivot listener later (roadmap Phase 5). This is the primary residual correctness risk for ERP data; the bounded-TTL failure mode is acceptable for launch only if documented.

**M3 — loud invalidation vs config drift.** `flushTable()`/`flushModel()` perform `versions->bump()` without try/catch (`InvalidationManager.php:59-74`; `CacheVersionStore.php:35-41`), so a cache-store outage propagates out of `save()`/mass-write after the DB commit. "Loud invalidation" is the intended ADR direction, but `log_invalidation_failures` and `redact_bindings_in_logs` are dead keys and no critical log/event is emitted; operators cannot tell outage-throw from bug-throw. Fix: wire the config (log critical + rethrow/collect depending on flag) or remove the key and document always-throw.

**M4 — forever rows.** `cacheForForever()` uses `Repository::forever()`; `DatabaseStore::forever()` is a 10-year TTL (`vendor DatabaseStore.php:329-332`). `clear()` only bumps the global token and `prune` only deletes `expiration <= now`, so these rows (and version-token rows, by design) are permanent unless the operator truncates the table. Document: avoid `cacheForForever()` with the database store, or add an opt-in purge-by-prefix command.

**M6 — config drift.** Keys advertised but never read by `src/`: `invalidation` (tags never used — version tokens only, which is safe but the doctor warning implies tag behaviour), `locking` (no single-flight), `debug`, `log_channel`, `redact_bindings_in_logs`, `allow_serialized_models`, `max_relation_depth`, `payload.mode=models` (no models codec; only arrays is bound), `filament.dashboard.can_purge`. Remove or implement before public docs; otherwise adopters will assume controls that do not exist.

**Low items.** L1/L2/L3/L4/L5/L6 as in the summary table; all require cache-write access or local misconfiguration except L4/L5 (documentation/UX hardening). V4/L1 note: full tenant segments are inside the fingerprint hash (`QueryCacheEngine.php:626-647`), so the visible 8-char tenant segment (`:684-695`) colliding does **not** merge keys.

### 3.1 Post-review fixes (2026-11-10 12:xx, Lead)

- **H1 — fixed.** QueryCacheStudio::canPurge() is deny-by-default: it requires an authenticated
  user, a configured Gate name in oomycache.filament.dashboard.can_purge, and Gate::allows().
  Both destructive actions are ->visible(canPurge) and re-check the gate inside the closure.
  The dogfood app defines oomycache.purge in AppServiceProvider::boot() and points the config at
  it; app tests cover denied (missing gate / failing gate) and allowed paths.
- **H2 — fixed.** QueryCacheEngine::resetRuntimeState() clears equestMemo, ersionMemo,
  optionsMemo and globalToken; the service provider registers it on queue JobProcessing and,
  by class name (optional dependency), Octane RequestReceived/RequestTerminated. A package test
  proves a memoized payload is re-read from the store after reset.
- **H3 — fixed (bridge override).** OomycacheFilamentServiceProvider only binds the Filament
  resolver when oomycache.tenant.resolver is empty, so multi-tenant apps keep their configured
  queue/API-aware resolver. Residual (documented): a Filament resolver used outside panels still
  returns 
one; multi-tenant operators must configure a composite resolver that fails closed
  outside a panel.
- **M3 — fixed (logging wired, loud throw retained).** InvalidationManager::flushModel() wraps the
  version bump: on failure it logs a critical message with table/connection/reason/exception class
  (respecting oomycache.safety.log_invalidation_failures) and then rethrows, preserving the ADR
  "invalidations are correctness incidents" behaviour. This closes the config-drift/observability gap;
  the throw-on-outage semantics remain intentional and documented.- **M1/L2/L3 — fixed (payload security).** payload.chunking.max_total_bytes (2 MiB) is now a
  hard cap independent of max_chunks; oversized chunked writes bypass and oversized reconstructed
  manifests are treated as corrupt/miss with cleanup. payload.compression.max_bytes (2 MiB) caps
  decompression (max_length where supported + post-decode length check; overflow = corrupt miss).
  ArrayPayloadCodec only instantiates cached collection classes that are actual
  Illuminate\Database\Eloquent\Collection subclasses, falling back to the safe base collection.
  Seven new tests cover cap bypass, decompression bombs, forged collection classes and normal paths.- **M6 — fixed (config drift).** Dead/unimplemented keys were removed from config/oomycache.php
  (tag strategy, locking, debug/log-channel, serialized-model allow-list, relation-depth cap, unused
  bridge flags); payload.mode only accepts rrays; the actual read set is documented in
  [config-reference.md](config-reference.md). oomycache:doctor now validates payload mode, TTL/jitter,
  compression/chunk caps, stats sample rate, tenant resolver class and model-preset names, failing with
  exit 1 on invalid values. Config tests cover valid + 5 invalid configurations.- **L5 — fixed.** Studio failure notifications now show a generic message and eport() the
  exception instead of exposing getMessage() to panel users.
- **M6 — partially fixed.** ilament.dashboard.can_purge is now implemented; the other advertised
  but unimplemented keys remain on the pre-launch cleanup list.

Re-verified after the fixes (plus later config/doctor and Memcached work): core package **73 passed + 1 skipped driver lane / 261 assertions**, PHPStan L7 clean, Pint clean;
companion package **6 tests / 9 assertions**; dogfood app bridge tests **10 passed** (plus full app
suite). The original severity table above is the pre-fix baseline; use this section for the launch
decision.
## 4. Verified safe (evidence-backed)

| # | Area | Evidence |
|---|---|---|
| V1 | Payload safety under `cache.serializable_classes=false`: arrays-JSON stores a **string**; store-level `unserialize(..., allowed_classes=…)` round-trips strings/scalars; no PHP objects in default mode | `src/Payload/ArrayPayloadCodec.php:25-41`; `vendor/…/Cache/DatabaseStore.php:585-597`; `tests/Feature/QueryCacheDogfoodTest.php:250`; `driver-matrix.md` |
| V2 | Tenant/panel isolation + fail-closed on tenant-enabled panels (A/B hit/miss/back-to-A, unresolved tenant bypass, panel switch) | `tests/Feature/TenancyTest.php:57-113`; `tests/Feature/QueryCacheTenantPanelTest.php:88-128`; app run 25 passed/1 skipped |
| V3 | No SQL injection surface: bindings are only canonicalised/hashed (`serialize`+`xxh128`), never interpolated into SQL; all builder overrides call `parent` and Laravel's bound execution path | `src/Query/Fingerprint.php:27-65`; `src/Builder/QueryCacheBuilder.php:129-291` |
| V4 | Transaction semantics: reads bypass inside transactions; invalidation deferred to `afterCommit` | `src/Cache/QueryCacheEngine.php:180-182,462-474`; `src/Invalidation/InvalidationManager.php:67-74`; `tests/Feature/QueryCacheTest.php:213` |
| V5 | Mass-write interception: `update/upsert/increment/decrement/delete/forceDelete/touch/truncate/insert` override + Eloquent `Model::increment` routes through the caching base builder | `src/Builder/QueryCacheBuilder.php:433-507`; `vendor/…/Eloquent/Model.php:1142-1145`; tests `QueryCacheTest.php:75,87`; dogfood `:183` |
| V6 | Studio XSS: all output uses Blade escaped `{{ }}`; badge colours come from fixed internal strings; no raw HTML injection path | `resources/views/pages/query-cache-studio.blade.php:30-93` |
| V7 | Prune command safety: keyset ordering, expiry-guarded `DELETE`, store-prefix scoping, `cache_locks` untouched, no user SQL; tests cover race-refresh, prefix, budget, refusals | `src/Cache/DatabaseCachePruner.php:133-204,231-258`; `tests/Feature/PruneCommandTest.php:55-197` |
| V8 | `oomycache:clear` never calls `Cache::flush()`; O(1) token bump only; commands validate model/table input | `src/Console/Commands/ClearCommand.php:22-39`; `src/Cache/QueryCacheEngine.php:476-487` |
| V9 | Version tokens are shared per `connection+table` (no tenant segment) — cross-tenant invalidation only (over-invalidation), never a stale-read leak | `src/Cache/CacheVersionStore.php:63-66`; `src/Cache/QueryCacheEngine.php:626-647` |
| V10 | Search queries are denied by default; keys/events contain hashed fingerprints and model/table names only — no binding values or PII | `src/Cache/QueryCacheEngine.php:198-200,608-619,626-647`; `src/Events/*.php` |

## 5. Test-coverage map

| Risk | Tests | Status |
|---|---|---|
| Payload deserialization / corruption / serializable_classes | `PayloadFeaturesTest.php:64-135`, `CacheStoreMatrixTest.php:63-142`, `QueryCacheDogfoodTest.php:250` | Covered |
| Tenant/panel isolation + fail-closed (panel paths) | `TenancyTest.php:57-113`, `QueryCacheTenantPanelTest.php:88-128`, `QueryCacheTenancyBridgeTest.php` | Covered |
| Studio access & purge authorization | `QueryCacheStudioTest.php:57-61` (guest redirect + authenticated view/actions) | **Partial** — no unauthorized-user / `can_purge` test |
| Long-running (Octane/queue) memo/version reset | none | **Gap** |
| Quiet/raw/pivot writes outside Filament actions | none (raw `DB::table` writes appear only as test fixtures) | **Gap** |
| Chunked payload corruption/missing/invalidation | `tests/Feature/ChunkingTest.php` (corrupt/missing/manifest-tamper/post-invalidation cases) | Covered — green 56/220 after merge (§3.0) |
| Prune safety | `PruneCommandTest.php:55-197` (9 tests) | Covered |
| Cache-key canonicalisation/collisions | `FingerprintTest.php:9-37` | Partial — no adversarial collision/cross-tenant key test |
| Transaction bypass / afterCommit | `QueryCacheTest.php:213`, inline invalidation tests | Covered |
| Bulk/mass-write invalidation | `QueryCacheTest.php:75,87`, `QueryCacheDogfoodTest.php:183` | Covered |
| Search bypass | `QueryCacheTest.php:226` | Partial (common shapes only) |
| Cache-fill / DoS quotas | none | **Gap** |
| Memcached / MySQL / PostgreSQL / Octane lanes | `CacheStoreMatrixTest.php` (array/file/database), `QueryCacheRedisStoreTest.php:55` | Partial — see [driver-matrix.md](driver-matrix.md), not executed |
| Commands (`clear`/`status`/`doctor`/`bench`) | `QueryCacheTest.php:237-248`, `StatusCommandTest`-equivalent inline | Covered |

## 6. Pre-launch sign-off checklist

- [x] Read-only review completed against pinned hashes; no source/test edits. (this document)
- [x] Arrays-JSON payload safety under Laravel 13 `serializable_classes=false` verified (V1).
- [x] Tenant isolation + fail-closed verified for tenant-enabled Filament panels (V2); app QueryCache lane green (`25 passed / 1 skipped / 134 assertions`, 00:24).
- [x] SQL injection, transaction, mass-write, prune, clear, XSS checks verified safe (V3–V8, V10).
- [x] **Chunking gate cleared:** full core suite green after the merge — **56 passed / 220 assertions**, 2026-11-10 11:32 (includes post-invalidation-miss and corrupt/missing-chunk tests).
- [ ] **H1 blocker:** implement `can_purge` authorization on studio actions (+ test unauthorized user) **or** document "admin-only panel" as a hard requirement.
- [ ] **H2 blocker (queue/Octane):** implement per-request/per-job engine reset (Octane `RequestReceived`, queue `JobProcessed`/`Looping`) or document FPM-only + `payload.memoize_per_request=false` for workers.
- [ ] **H3 blocker (multi-tenant):** fail closed outside panels / honor configured `tenant.resolver`; document queue/API tenancy responsibilities.
- [ ] Document M2/M4/M5 residual stale-read and DoS guidance in README + `oomycache:doctor` output (raw/quiet/pivot writes, `cacheForForever`+database store, public-entrypoint rate limiting).
- [ ] Clean up M6 config drift (remove or implement advertised keys) before public docs/Packagist.
- [ ] Complete driver lanes (Redis, Memcached, MySQL, PostgreSQL, Octane) per [driver-matrix.md](driver-matrix.md) and [../plan/05-qa-checklist.md](../plan/05-qa-checklist.md) §7.
- [ ] Re-run this review after H1–H3 fixes; update baseline hashes.

**Sign-off decision: DO NOT SIGN for public alpha.** Conditional exception possible for a single-tenant, FPM-only (no Octane, no queue-side query caching), database/Redis-store deployment with H1 closed and the chunking suite verified green (it is green as of 11:32), with M2/M4/M5 documented. Beta/stable additionally require H2/H3 closed, M1/M3/M6 resolved, and the driver lanes green.

## 7. References (validated relative links)

Implementation reports: [phase-1-dogfood.md](phase-1-dogfood.md) · [phase-2-progress.md](phase-2-progress.md) · [benchmark-and-launch.md](benchmark-and-launch.md) · [driver-matrix.md](driver-matrix.md) · [release-checklist.md](release-checklist.md)
Design/QA: [../plan/05-qa-checklist.md](../plan/05-qa-checklist.md) · [../plan/06-roadmap.md](../plan/06-roadmap.md) · [../plan/03-architecture.md](../plan/03-architecture.md) · [../research/07-laravel-cache-internals.md](../research/07-laravel-cache-internals.md)
Package: [../../README.md](../../README.md) · [../../CHANGELOG.md](../../CHANGELOG.md) · [../../config/oomycache.php](../../config/oomycache.php)
Bridge: [../../../OoMyCacheFilament/README.md](../../../OoMyCacheFilament/README.md) · [../../../OoMyCacheFilament/src/Pages/QueryCacheStudio.php](../../../OoMyCacheFilament/src/Pages/QueryCacheStudio.php)
Review baseline re-check command: `Get-FileHash packages/OoMyCache/src/Cache/QueryCacheEngine.php, packages/OoMyCache/config/oomycache.php` (compare §header hashes).
