> **Implementation source of truth:** [../implementation/config-reference.md](../implementation/config-reference.md). The alpha removed roadmap keys that were never read (tag strategy, locking, debug/log-channel, serialized-model allow-list, relation-depth cap) and validates the rest via oomycache:doctor; treat P1/P2 keys below as design history until they reappear in the reference.

# 02 — OoMyCache Configuration & Flexibility Contract

> **Status:** v1.0 proposal (implementation contract)
> **Reads with:** [`01-features.md`](01-features.md) (what), [`03-architecture.md`](03-architecture.md) (why it works), [`04-filament-compatibility.md`](04-filament-compatibility.md) (Filament-specific keys).
> **Rule:** every option below becomes a documented, validated config key. No hidden `env()` calls outside the config file, no undocumented knobs, no closures in config (breaks `php artisan config:cache` — use class-strings/contracts).

---

## 0. Configuration philosophy

1. **Safe defaults, explicit opt-ups.** Out of the box the package is enabled but only models using the trait are cached, search queries are skipped, transactions bypass reads, and every write path invalidates synchronously.
2. **Four levels of configuration**, from broadest to narrowest, each overriding the previous:
   1. global `config/oomycache.php`;
   2. named presets (`cache.presets` + `cache.model_presets`) — for families of models (reference data, financial data, dashboards);
   3. per-model `queryCacheOptions()` declared on the model;
   4. per-query builder API (`cacheFor`, `dontCache`, …).
3. **Every option has a documented tradeoff.** The config reference is part of the public API and must stay stable within a major version.
4. **Fail safe, not clever.** Invalid values raise a clear exception in non-production environments and fall back to safe defaults with a log warning in production. `oomycache:doctor` validates the effective configuration.
5. **Config-cache safe.** All configurable behaviour is resolved from `config('oomycache.*')`; injectable behaviour (tenant resolver, custom serializers, stats repository) is configured as **class-strings implementing contracts**, compatible with `php artisan config:cache`.
6. **Filament never required.** Filament-specific keys live under `oomycache.filament.*` and are inert when Filament is absent.

---

## 1. The complete configuration file (proposed v1.0 default)

```php
<?php

declare(strict_types=1);

use Oomycache\Oomycache\Contracts\TenantResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Turn everything off without touching code (emergency kill switch).
    | When false: no reads are cached, no invalidation runs, the bridge UI
    | still renders (reporting "disabled").
    */
    'enabled' => env('OOMYCACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache store & namespace
    |--------------------------------------------------------------------------
    | store: null  = use Laravel's default cache store.
    |        string = a store name from config/cache.php ("redis", "database"…).
    | prefix: logical prefix for all package keys (before the cache store's own
    |        cache.prefix). Keep it short; total key length is enforced.
    | version: format/deploy version baked into every key. Bump this (or set
    |        OOMYCACHE_VERSION in the deploy env) whenever the payload format or
    |        the cached model shape changes; old entries become unreachable
    |        immediately without a full cache clear.
    */
    'store' => env('OOMYCACHE_STORE'),
    'prefix' => env('OOMYCACHE_PREFIX', 'oomycache'),
    'version' => env('OOMYCACHE_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | TTL defaults
    |--------------------------------------------------------------------------
    | ttl: default entry lifetime in seconds (>= 1).
    | ttl_jitter: fraction of the TTL randomised per entry (0.0–0.5) to avoid
    |   synchronized expiry stampedes. 0 disables jitter (deterministic tests).
    */
    'ttl' => (int) env('OOMYCACHE_TTL', 300),
    'ttl_jitter' => (float) env('OOMYCACHE_TTL_JITTER', 0.10),

    /*
    |--------------------------------------------------------------------------
    | Invalidation strategy
    |--------------------------------------------------------------------------
    | 'version' = version namespaces only (works on every store; O(1) writes).
    | 'tags'    = tag-based invalidation where the store supports tags, and
    |             version namespaces as the correctness fallback elsewhere.
    | 'auto'    = tags when the selected store is taggable, otherwise version.
    | Correctness never depends on tag support. See architecture §4.
    */
    'invalidation' => env('OOMYCACHE_INVALIDATION', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Automatic caching policy
    |--------------------------------------------------------------------------
    | Knobs used by the query classifier to decide what is cached by default.
    | Per-model overrides may narrow (never widen beyond safety constraints).
    */
    'cache' => [
        'reads' => true,            // get/first/find/route binding
        'aggregates' => true,       // count/exists/sum/avg/min/max
        'pagination' => true,       // paginate/simplePaginate/cursorPaginate
        'single_records' => true,   // first/find/firstOrFail/findOrFail/sole
        'value_and_pluck' => true,  // value()/pluck()
        'relations' => 'auto',      // auto | strict | declared | none
        'negative' => false,        // cache "no result" (null/0/empty)
        'negative_ttl' => 10,       // seconds, only when negative = true
        'search_terms' => false,    // cache queries with LIKE/wildcard search
        'skip_query_patterns' => [  // regexes matched against normalized SQL
            // '/\blike\b/i',       // example; superseded by search_terms=false
        ],
        'skip_models' => [],        // class-strings never auto-cached
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety & correctness guardrails
    |--------------------------------------------------------------------------
    | Read these before changing any default. Disabling a guardrail trades
    | correctness or debuggability for hit ratio.
    */
    'safety' => [
        'skip_reads_in_transactions' => true,   // never serve pre-transaction data
        'invalidate_after_commit' => true,      // flush only after COMMIT
        'intercept_mass_writes' => true,        // Eloquent query-builder writes flush
        'skip_in_console' => false,             // set true for migrate/seed-heavy envs
        'fail_silently' => true,                // cache errors degrade to DB reads
        'log_invalidation_failures' => true,    // never swallow failed invalidation
        'redact_bindings_in_logs' => true,      // debug logs never expose secrets
        'max_payload_bytes' => 1_048_576,       // 1 MiB budget; larger results bypass cache (research/07)
        'max_relation_depth' => 3,              // dependency-resolution recursion guard
        'allow_serialized_models' => true,      // false = force arrays payload mode
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload representation
    |--------------------------------------------------------------------------
    | mode: 'arrays' = raw rows + relation tree stored as a JSON envelope and
    |       rehydrated on hit (default; works with Laravel 13's default
    |       serializable_classes=false, ~340 B/row measured).
    |       'models' = package-serialized models/collections stored as a STRING
    |       with a narrow allow-list on read (opt-in; highest fidelity, larger).
    | compression: transparently compress entries over the threshold. Requires
    |   zlib (gzcompress) or zstd extension; disable for small payloads.
    | memoize_per_request: keep deserialised hot entries for the current
    |   request only (never crosses requests, Octane-safe).
    */
    'payload' => [
        'mode' => env('OOMYCACHE_PAYLOAD_MODE', 'arrays'),
        'compression' => [
            'enabled' => env('OOMYCACHE_COMPRESSION', false),
            'threshold_bytes' => 65_536,
            'algorithm' => env('OOMYCACHE_COMPRESSION_ALGO', 'gzip'), // gzip|deflate|zstd
        ],
        'memoize_per_request' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy & user scoping
    |--------------------------------------------------------------------------
    | resolver: null = auto-detect (Filament tenant when available, otherwise
    |   no tenant segment); or a class-string implementing TenantResolver.
    | include: which segments to embed in every key. 'tenant' | 'panel' | 'user'.
    | include_user: opt-in per-user partitioning; only needed when the same SQL
    |   can legitimately return different rows per user (policies applied in PHP).
    |   Leave false for query-level authorization that is part of the SQL.
    */
    'tenant' => [
        'resolver' => env('OOMYCACHE_TENANT_RESOLVER'), // class-string|null
        'include' => ['tenant', 'panel'],
        'include_user' => false,
        'user_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stampede protection
    |--------------------------------------------------------------------------
    | Optional single-flight recomputation on misses. Adds latency and requires
    | a store with lock support; off by default because ERP pages are latency
    | sensitive and invalidation is version-based.
    */
    'locking' => [
        'enabled' => env('OOMYCACHE_LOCKING', false),
        'ttl' => 10,   // seconds the recomputation lock is held
        'wait' => 3,   // seconds to wait for another worker before falling back to DB
        'only' => ['paginate', 'count'], // reads eligible for single-flight
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    | events: dispatch QueryCacheHit/Missed/Invalidated/Cleared.
    | stats:  cache-backed counters for the Filament widget/status command.
    |         Enabled by default only outside production (see docs §5).
    */
    'events' => [
        'enabled' => true,
        'hits' => true,
        'misses' => true,
        'invalidations' => true,
    ],
    'stats' => [
        'enabled' => (bool) env('OOMYCACHE_STATS', false),
        'store' => null,        // null = same as cache store
        'ttl' => 3600,          // snapshot lifetime
        'sample_rate' => 1.0,   // 0.0–1.0
    ],

    /*
    |--------------------------------------------------------------------------
    | Debugging
    |--------------------------------------------------------------------------
    | Never enable in production: logs one structured line per cache decision.
    | Bindings are redacted when safety.redact_bindings_in_logs is true.
    */
    'debug' => (bool) env('OOMYCACHE_DEBUG', false),
    'log_channel' => env('OOMYCACHE_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    */
    'commands' => [
        'register' => true,   // status, clear, doctor, bench, warm
    ],

    /*
    |--------------------------------------------------------------------------
    | Named presets
    |--------------------------------------------------------------------------
    | Merge order: global config -> preset -> model -> query. Presets let an
    | ERP keep, for example, reference tables warm while financial tables stay
    | short-lived. Keys use the same vocabulary as queryCacheOptions().
    */
    'presets' => [
        'reference' => [
            'ttl' => 3600,
            'cache_aggregates' => true,
        ],
        'financial' => [
            'ttl' => 60,
            'cache_single_records' => true,
            'negative' => false,
        ],
        'dashboard' => [
            'ttl' => 120,
            'cache_aggregates' => true,
        ],
    ],
    'model_presets' => [
        // App\Models\Country::class => 'reference',
        // App\Models\Invoice::class => 'financial',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament bridge (inert when Filament is not installed)
    |--------------------------------------------------------------------------
    */
    'filament' => [
        'enabled' => true,
        'tenant_keys' => true,     // auto tenant segment from Filament::getTenant()
        'panel_keys' => true,      // auto panel segment from current panel
        'flush_after_bulk_actions' => true,     // safety net for actions that bypass events
        'flush_after_relation_actions' => true, // attach/detach/sync
        'resource_purge_action' => true,        // per-resource header action (P1)
        'global_search' => [
            'cache' => false, // global search is high-cardinality; opt-in only
        ],
        'dashboard' => [
            'page' => true,
            'widget' => false,
            'navigation_group' => 'System',
            'navigation_icon' => 'heroicon-o-bolt',
            'can_view' => null,   // Gate name / class-string / null = panel access
            'can_purge' => null,  // Gate name / class-string / null = can_view
        ],
    ],
];
```

> **Validation rules** enforced on boot (and by `oomycache:doctor`): `ttl >= 1`, `0 <= ttl_jitter <= 0.5`, `max_payload_bytes >= 0`, `invalidation ∈ {auto, version, tags}`, `cache.relations ∈ {auto, strict, declared, none}`, `payload.mode ∈ {arrays, models}`, tenant/include values valid, classes in resolver/`log_channel` exist, stats sample rate `0..1`. Invalid values → `InvalidConfigurationException` outside production; safe-default fallback + warning log in production. **Jitter is subtract-only:** the effective TTL is always ≤ the requested TTL (never extends a freshness contract).

---

## 2. Per-model configuration (`queryCacheOptions()`)

Declared on the model and merged over the active preset + global config:

```php
final class Product extends Model
{
    use HasQueryCache;

    /**
     * @return array<string, mixed>
     */
    protected function queryCacheOptions(): array
    {
        return [
            'enabled' => true,
            'ttl' => 600,
            'cache_aggregates' => true,
            'cache_pagination' => true,
            'cache_single_records' => false,  // detail pages always hit DB
            'relations' => 'strict',
            'dependencies' => [
                // classes whose writes must also invalidate this model's queries
                Supplier::class,
                PriceList::class,
            ],
            'skip_query_patterns' => ['/\bstock_qty\b/i'], // e.g. never cache stock-sensitive
            'key_salt' => 'catalog-v2',       // domain version, e.g. pricing rules
            'preset' => 'reference',          // optional base preset
        ];
    }
}
```

| Model option | Type | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | bool | `true` | Auto-caching on/off for this model. |
| `ttl` | int\|string | global | Entry lifetime in seconds, or a business-time preset (`'end_of_day'`, `'end_of_week'`, `'end_of_month'`) resolved at write time (timezone-aware; DST-tested). |
| `ttl_jitter` | float | global | Per-model jitter override. |
| `cache_reads` | bool | global | `get/first/find`. |
| `cache_aggregates` | bool | global | `count/exists/sum/avg/min/max`. |
| `cache_pagination` | bool | global | Page items + total count. |
| `cache_single_records` | bool | global | `first/find/firstOrFail/findOrFail/sole`. |
| `relations` | string | global | Dependency-resolution policy (§4). |
| `dependencies` | class-string[] | `[]` | Extra invalidation namespaces (business-logic deps, morph targets). |
| `skip_query_patterns` | string[] | global | Per-model SQL regex bypasses (applied before caching). |
| `negative` / `negative_ttl` | bool/int | global | Per-model negative caching. |
| `key_salt` | string | `null` | Domain version segment, e.g. pricing-rule version. |
| `preset` | string | `null` | Named preset to merge beneath this array. |

`queryCacheOptions()` is the only supported per-model surface: it is discoverable, greppable and cannot be mutated at runtime in surprising ways. A future `#[QueryCache(...)]` attribute can be added as sugar without breaking this contract (P2).

**TTL resolution across the dependency graph (P0):** the effective TTL of a cached entry is the **minimum** of the base model's TTL and the TTL of every eager-loaded/aggregated dependency model, bounded by the global default. This gives a cheap staleness bound for data written outside the package (raw SQL, other services) without per-query configuration. A returned model can never be cached longer than its most volatile dependency. `ttl` presets (`end_of_day` etc.) participate in the minimum by resolving to their absolute seconds first.

### 2.1 P1 config extensions (reserved, additive)

The following keys are planned for v1.x; they are listed now so the v1.0 config/validation schema leaves room for them without breaking changes:

| Key (proposed) | Purpose |
| --- | --- |
| `entities` / per-model `queryCacheEntities()` | Explicit cache-entity DSL (named closures/computed aggregates) reusing version invalidation; per-entity `refreshOn`, `ttl` preset. |
| `invalidation_coalesce` | `['enabled' => false, 'window' => 5, 'queue' => null]` — coalesce invalidation storms from imports/bulk actions; token per namespace; atomic ops; correctness never depends on it. |
| `payload.envelope` | Expose `generatedAt/expiresAt/status/deps` metadata to the Filament UI and `status --explain`; never used for correctness. |
| `payload.chunking` | `['enabled' => false, 'threshold_bytes' => 1_048_576, 'chunk_items' => 1_000, 'chunk_bytes' => 262_144]` — versioned chunk keys for payloads above store limits (Memcached ≈1 MB, DynamoDB ≈400 KB); wrapper + race-safe re-read; never a lazy non-`Collection` object. |
| `maintenance.prune` | `['enabled' => false, 'chunk' => 100, 'max_seconds' => 5, 'sleep_us' => 0]` — GC for `file`/`database` cache stores: expiry-guarded batched delete + keyset pagination; typed refusal for Redis/Memcached (native TTL). `oomycache:prune`; docs recommend `laravel-cache-evict` as an alternative for file/database drivers. |
| `ttl` business presets | `'end_of_day'`, `'end_of_week'`, `'end_of_month'` resolved at write time; timezone from `config('app.timezone')` by default, override per preset. |

---

## 3. Per-query API (highest precedence)

Available on every builder produced by a cacheable model (and statically on the model for namespace operations):

| API | Scope | Description |
| --- | --- | --- |
| `->cacheFor(int\|DateInterval $ttl)` | query | Override TTL; return `$this`. |
| `->cacheForForever()` | query | No expiry; version-invalidated. |
| `->cacheKey(string $suffix)` | query | Append an application-defined discriminator (safe string, hashed if long). |
| `->cacheTags(string\|array $tags)` | query | Extra purge tags (used when tag-enabled; recorded for diagnostics otherwise). |
| `->cacheWith(string ...$dependencies)` | query | Declare additional model namespaces this result depends on. |
| `->dontCache()` | query | Bypass read cache for this query. |
| `->fresh()` | query | Not package-specific, but documented: `->dontCache()` is the explicit bypass. |
| `Model::flushQueryCache()` | model | Bump this model's version namespace (static + instance). |
| `Model::flushQueryCacheFor(array $classes)` | model | Flush multiple namespaces atomically (best-effort). |
| `Model::withoutQueryCache(fn () => ...)` | model | Execute a closure with caching disabled (test helper). |
| `Oomycache::flush(string $scope = 'all')` | facade | Global / tenant / tag purge APIs used by commands and bridge. |

**Precedence:** query API > model `queryCacheOptions()` > model preset > global config > package defaults. Safety guardrails (§1 `safety`) are enforced **after** all levels and cannot be overridden to an unsafe value (e.g. a query cannot opt into caching inside a transaction when `skip_reads_in_transactions` is true). This "guardrail floor" is intentional and documented.

---

## 4. Relation dependency policy (`cache.relations`)

| Value | Behaviour | Use when |
| --- | --- | --- |
| `auto` (default) | Resolve eager loads / withCount / whereHas dependencies; if a dependency cannot be resolved (unresolvable morph, dynamic relation), skip caching that query. | General ERP use. |
| `strict` | Same as `auto`, but *any* unresolved or dynamic relation skips caching (also disables caching for queries with constrained eager-load closures on unsupported PHP versions). | Financial data. |
| `declared` | Never infer; only cache queries whose dependency set is fully declared via `dependencies`/`cacheWith()`. | Maximum control, teams that prefer explicitness. |
| `none` | Disable relation-aware caching; still cache base-model-only queries. | Debugging / migration. |

The resolved dependency list is included in the cache key *and* in the invalidation set. See [`03-architecture.md`](03-architecture.md) §5.

---

## 5. Environment variables (documented, minimal)

| Variable | Default | Purpose |
| --- | --- | --- |
| `OOMYCACHE_ENABLED` | `true` | Global kill switch. |
| `OOMYCACHE_STORE` | `null` | Cache store name. |
| `OOMYCACHE_PREFIX` | `oomycache` | Key prefix. |
| `OOMYCACHE_VERSION` | `1` | Deploy/format version segment. |
| `OOMYCACHE_TTL` | `300` | Default TTL seconds. |
| `OOMYCACHE_TTL_JITTER` | `0.10` | Jitter fraction. |
| `OOMYCACHE_INVALIDATION` | `auto` | `auto\|version\|tags`. |
| `OOMYCACHE_PAYLOAD_MODE` | `models` | `models\|arrays`. |
| `OOMYCACHE_COMPRESSION` | `false` | Enable payload compression. |
| `OOMYCACHE_COMPRESSION_ALGO` | `gzip` | `gzip\|deflate\|zstd`. |
| `OOMYCACHE_LOCKING` | `false` | Stampede single-flight. |
| `OOMYCACHE_STATS` | `false` | Cache-backed stats counters. |
| `OOMYCACHE_DEBUG` | `false` | Structured decision logging. |
| `OOMYCACHE_TENANT_RESOLVER` | `null` | Custom tenant resolver class. |
| `OOMYCACHE_LOG_CHANNEL` | `null` | Dedicated log channel. |

Everything else is code-config only — environment sprawl is intentionally avoided.

---

## 6. Extension points (contracts)

All extension points are interfaces in `src/Contracts/`, bound in the service provider and documented. They are what makes the package "flexible" without touching internals:

| Contract | Responsibility | Why extend it |
| --- | --- | --- |
| `KeyGenerator` | Build cache keys from a `QueryContext` (SQL, bindings, model, deps, tenant, salt). | Custom key strategy, legacy compatibility, hashing algorithm. |
| `QueryClassifier` | Decide cache eligibility per query (`ClassifiableQuery`). | Business-specific rules ("never cache invoice queries"). |
| `DependencyResolver` | Resolve relation names to model classes for invalidation. | Custom relation types / dynamic relations. |
| `InvalidationStrategy` | `VersionInvalidation` (default), `TagInvalidation`, custom. | Different store semantics or org-wide versioning. |
| `PayloadSerializer` | Serialize/unserialize cached queries (models/arrays/compression). | igbinary/msgpack, custom compression, cross-service caches. |
| `TenantResolver` | Return the tenant/panel/user segments for a request. | Non-Filament tenancy (single-DB scopes, custom headers, subdomains). |
| `StatsRepository` | Collect/read counters for UI and commands. | Pulse/Prometheus/custom dashboards. |
| `CacheStoreResolver` | Choose the cache store per model/query. | Split hot/cold stores or read replicas. |
| `WarmableQuery` (P1) | Declare warmable query builders per model/resource. | Deploy warmup and dashboard prefetch. |

Example binding in the host app's `AppServiceProvider`:

```php
use Oomycache\Oomycache\Contracts\TenantResolver;

public function register(): void
{
    $this->app->bind(TenantResolver::class, App\Cache\CompanyTenantResolver::class);
}
```

Contracts are versioned (constructor signature + return types) with the package; breaking changes only in a major release.

---

## 7. Driver-specific defaults (auto-tuned, overridable)

The package inspects the selected store once per request (cached capability probe) and adjusts defaults; each can still be overridden explicitly:

| Capability | array | file | database | redis | memcached |
| --- | --- | --- | --- | --- | --- |
| Version invalidation | ✅ | ✅ | ✅ | ✅ | ✅ |
| Tags | ✅ (test only) | ❌ (version fallback) | ❌ | ✅ | ✅ |
| Atomic locks | ✅ | ✅ | ✅ (DB lock) | ✅ | ✅ |
| Recommended for | tests | small single-server | no-Redis deployments (with `laravel-cache-evict`) | **production default** | production alternative |

`oomycache:doctor` prints the detected matrix and warns when configuration assumes a capability the store lacks (e.g. `invalidation=tags` on file store).

---

## 8. Operational configuration (deploy, warm, purge)

| Task | Recommended approach |
| --- | --- |
| Deploy with unchanged models | Nothing to do; version tokens survive redeploys in shared stores. |
| Deploy with schema/model changes | Bump `OOMYCACHE_VERSION` (or run `oomycache:clear`) in the deploy script; cached payloads are formatted against the new version. |
| `php artisan config:cache` | Fully compatible: no closures in config; class-strings for resolvers. |
| `php artisan optimize` | No special handling; provider registration is lean and conditional. |
| File/database cache stores | Schedule `vendor/bin/...` housekeeping: recommend `laravel-cache-evict` (`cache-evict` command) weekly to reclaim expired entries (see [`research/01`](../research/01-laravel-cache-evict.md)). |
| Redis memory pressure | Document `allkeys-lru` risk: evicting versions/cache randomly is safe for correctness (version token missing → new namespace) but lowers hit ratio; recommend `volatile-lru` only for package keys or dedicated Redis DB. |
| Multi-server | All state lives in the shared cache store; no filesystem state, no in-memory cross-request state. |
| Octane | No service-provider state per request; `memoize_per_request` is scoped and reset; version tokens read from store (optionally short-TTL local cache, invalidated by events in-process). |
| Queue workers | Same invalidation semantics; after-commit tracked per connection. |

---

## 9. Backward-compatibility policy for config

- Additive options are minor releases.
- Renaming/removing a key is a major release with a documented `UPGRADING.md` mapping and a deprecation warning for one minor cycle.
- Unknown keys: ignored with a `oomycache:doctor` warning (never fatal in production).
- Presets are userland-configurable; package-provided preset names (`reference`, `financial`, `dashboard`) are stable in v1.
- Every config key added must have: validation rule, docs entry in this file, default in the skeleton config, and at least one test proving the behaviour it controls.

---

## 10. Configuration checklist for the implementer

- [ ] Publishable `config/oomycache.php` with the exact structure above (comments included; comments are documentation for users).
- [ ] `ConfigValidator` with the rules in §1; wired into provider boot + `oomycache:doctor`.
- [ ] Resolution pipeline implemented as a single `QueryCacheConfig::for(Model, Builder)` service (global → preset → model → query) with unit tests for every precedence edge.
- [ ] Guardrail floor applied after user precedence; tests prove user config cannot disable mandatory safety rules.
- [ ] All contracts in `src/Contracts/` documented here and in README.
- [ ] `oomycache:status` prints the effective merged config for a given model (`--model=`), so users can debug precedence without reading source.
- [ ] No `env()` calls outside `config/oomycache.php` (ArchTest rule).
