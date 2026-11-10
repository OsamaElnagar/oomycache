# OoMyCache Configuration Reference

> **Source of truth:** `packages/OoMyCache/config/oomycache.php`. The package publishes it with
> `php artisan vendor:publish --tag=oomycache-config`. `php artisan oomycache:doctor` validates the
> values that would otherwise fail silently and exits non-zero on bad configuration.
>
> **v0.1.0-alpha.1 scope:** the keys below are the ones the code actually reads. Removed/roadmap keys
> (tags strategy, locking, debug/log-channel, serialized-model allow-list, relation-depth cap) were
> deleted rather than advertised-but-ignored; see §4.

## 1. Core keys

| Key | Default | Read by | Notes |
| --- | --- | --- | --- |
| `enabled` | `true` | engine | master switch; disables reads + invalidation listeners |
| `store` | `null` | engine/stats | `null` = Laravel default cache store, or a store name |
| `prefix` | `oomycache` | key builder/version store | use a unique prefix per app on shared stores |
| `version` | `1` | key builder | bump when the cached payload/model shape changes |
| `ttl` | `300` | engine | default entry lifetime (seconds); validated `>= 1` |
| `ttl_jitter` | `0.10` | engine | random fraction removed from TTL; validated `0–0.5` |
| `cache.reads` | `true` | engine options | cache `get`/`first`/aggregates etc. |
| `cache.aggregates` | `true` | engine options | `count`/`sum`/`avg`/`min`/`max`/`exists` |
| `cache.pagination` | `true` | engine options | `paginate`/`simplePaginate` item queries |
| `cache.single_records` | `true` | engine options | `find`/route-binding-shaped single reads |
| `cache.value_and_pluck` | `true` | engine options | `value`/`pluck` |
| `cache.negative` | `false` | engine options | cache empty/null results; off by default. When false, empty results are re-queried every time and counted as `empty_bypasses` in stats |
| `cache.negative_ttl` | `10` | engine | TTL for negative entries |
| `cache.search_terms` | `false` | engine | cache user-search-shaped queries (unsafe-ish; keep off) |
| `cache.skip_query_patterns` | `[]` | engine | raw SQL substrings never cached |
| `cache.skip_models` | `[]` | engine | model classes never cached even with the trait |
| `cache.pivot_invalidation` | `true` | engine (belongsToMany relation) | flush both sides after attach/detach/sync/toggle/pivot updates |

## 2. Safety & payload

| Key | Default | Notes |
| --- | --- | --- |
| `safety.skip_reads_in_transactions` | `true` | reads inside a transaction bypass the cache |
| `safety.invalidate_after_commit` | `true` | writes inside a transaction flush after commit |
| `safety.intercept_mass_writes` | `true` | builder `update/delete/insert/upsert/increment/...` flush namespaces |
| `safety.skip_in_console` | `false` | bypass caching for artisan/console reads |
| `safety.fail_silently` | `true` | report cache errors instead of throwing (invalidation stays loud) |
| `safety.log_invalidation_failures` | `true` | log critical on failed version bumps before rethrowing |
| `safety.max_payload_bytes` | `1048576` | single-key size cap when chunking is disabled |
| `payload.mode` | `arrays` | **only `arrays` is implemented**; doctor fails otherwise |
| `payload.memoize_per_request` | `true` | reuse decoded payloads within one request (purged on writes) |
| `payload.compression.enabled` | `false` | gzip/deflate/zstd envelope above `threshold_bytes` |
| `payload.compression.threshold_bytes` | `65536` | compress payloads at/above this stored size |
| `payload.compression.algorithm` | `gzip` | `gzip`, `deflate`, `zstd` (doctor validates) |
| `payload.compression.max_bytes` | `2097152` | decompression bomb cap; `0` disables |
| `payload.chunking.enabled` | `true` | split large payloads behind a manifest |
| `payload.chunking.chunk_bytes` | `262144` | chunk size; must be `>= 1` |
| `payload.chunking.max_chunks` | `64` | chunk count guard; bypass above it |
| `payload.chunking.max_total_bytes` | `2097152` | hard reconstructed-payload cap; `0` disables |

## 3. Tenancy, observability, maintenance

| Key | Default | Notes |
| --- | --- | --- |
| `tenant.resolver` | `null` | class implementing `Contracts\TenantResolver`; validated by doctor |
| `tenant.include_user` | `false` | bridge resolver adds a hashed user segment |
| `events.enabled` / `events.hits` / `events.misses` / `events.invalidations` | `true` | event emission switches |
| `stats.enabled` | `false` | cache-backed hit/miss/bypass/invalidation/clear counters; `empty_bypasses` tracks empty results skipped because negative caching is off |
| `stats.store` | `null` | store for counters (defaults to the main cache store) |
| `stats.ttl` | `3600` | counter window |
| `stats.sample_rate` | `1.0` | `(0, 1]` sampling; validated |
| `commands.register` | `true` | auto-register `status/clear/doctor/bench/prune` |
| `maintenance.prune.chunk` | `100` | rows per keyset page |
| `maintenance.prune.max_seconds` | `5.0` | soft budget (`0` disables) |
| `maintenance.prune.sleep_us` | `0` | micro-pause between pages |
| `presets.*` | built-in presets | named option bundles used by `model_presets` |
| `model_presets` | `[]` | model class → preset name; unknown names fail doctor |
| `filament.dashboard.can_view` | `null` | optional Gate for the companion studio page |
| `filament.dashboard.can_purge` | `null` | **required** Gate for destructive studio actions (deny-by-default) |
| `filament.dashboard.navigation_label` | `Query Cache` | studio navigation label |
| `filament.dashboard.navigation_group` | `null` | studio navigation group; `null` inherits the panel default |
| `filament.dashboard.navigation_sort` | `null` | studio navigation sort; lower values move it higher |

## 4. Removed in alpha (were advertised but unimplemented)

| Removed key | Why |
| --- | --- |
| `invalidation` / tag strategy | invalidation is always version-token based; tags never used |
| `locking` (stampede protection) | not implemented; no single-flight |
| `debug`, `log_channel` | no debug logging pipeline |
| `safety.redact_bindings_in_logs` | bindings are never logged (keys contain hashes only) |
| `safety.max_relation_depth` | not enforced; codecs store relations as delivered |
| `safety.allow_serialized_models` | arrays-JSON only; no serialized model mode |
| `payload.mode=models` | no models codec; `payload.mode` now only accepts `arrays` |
| `tenant.include`, `tenant.user_resolver` | segment composition is resolver-driven; bridge uses `include_user` |
| `filament.enabled/tenant_keys/panel_keys/flush_after_*` | bridge is opt-in per panel via the plugin; dashboard nav keys are now read (see §3) |

## 5. Validation rules enforced by `oomycache:doctor`

`payload.mode != arrays`, `ttl < 1`, `ttl_jitter` outside `0–0.5`, invalid compression algorithm,
negative compression/chunk caps, `chunk_bytes/max_chunks < 1`, `stats.sample_rate` outside `(0,1]`,
missing/invalid `tenant.resolver`, and unknown `model_presets` names fail the command with exit code 1.
Warnings (disabled package) do not fail it. Doctor also warns when a cacheable model's `dependencies`
entry points at a model without `HasQueryCache`: that dependency cannot invalidate the cached model
automatically, so add the trait or flush explicitly.
