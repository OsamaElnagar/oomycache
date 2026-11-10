---
name: oomycache-development
description: >
  Install, configure and correctly use the OoMyCache Eloquent query cache in a Laravel app:
  opt-in model caching, version-token invalidation, per-query API, tenancy, payload tuning,
  artisan commands, and the optional Filament bridge.
license: MIT
metadata:
  author: Osama Mohammed Elnagar
---

# OoMyCache — agent integration guide

Use this skill when a Laravel application should adopt `osamaelnagar/oomycache` (and optionally
`osamaelnagar/oomycache-filament`). OoMyCache is a **framework-agnostic Laravel package** — Filament is
optional, not required.

## Compatibility

- PHP 8.3+, Laravel 12 or 13
- Current releases are public **alpha** tags (`v0.1.0-alpha.*`). Install with an explicit alpha
  constraint; a plain `composer require osamaelnagar/oomycache` will not select an alpha on a
  default-stability project.
- Core has **no Filament or Livewire dependency**. The bridge package adds Filament integration.

## Install

```bash
# core only (any Laravel app)
composer require osamaelnagar/oomycache:^0.1.0-alpha.1
php artisan vendor:publish --tag=oomycache-config

# optional: Filament 5 bridge (adds the studio page + tenant/invalidation integration)
composer require osamaelnagar/oomycache-filament:^0.1.0-alpha.1
```

For the bridge, register the plugin in the relevant Filament panel:

```php
use Oomycache\Filament\OomycachePlugin;

return $panel->plugin(OomycachePlugin::make());
```

## Minimal integration

```php
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class Product extends Model
{
    use HasQueryCache;
}
```

Every read (`get`, `first`, `paginate`, `count`, sums/aggregates, `pluck`, `value`, eager loads) on
that model goes through the caching builder. No resource/controller changes are needed.

## Per-query API

```php
Product::query()->cacheFor(120)->get();
Product::query()->cacheForForever()->where('is_system', true)->get(); // avoid on database store
Product::query()->dontCache()->get();
Product::query()->cacheKey('catalog-v2')->get();
Product::query()->cacheIf(fn ($rows) => $rows->isNotEmpty())->get();

Product::flushQueryCache();                 // invalidate this model's namespace
Oomycache::flush([Product::class]);         // invalidate specific models
Oomycache::clear();                         // bump the global version token
```

Per-model defaults belong in `queryCacheOptions()`:

```php
public function queryCacheOptions(): array
{
    return [
        'ttl' => 600,
        'cache_aggregates' => true,
        'negative' => false,
        'dependencies' => [Supplier::class],
        'skip_query_patterns' => ['/\bstock_qty\b/i'],
    ];
}
```

## Raw report/dashboard queries

`DB::table()` reads cannot use the Eloquent builder. Cache them explicitly with a stable key and the
tables that must invalidate the entry:

```php
use Oomycache\Oomycache\Facades\Oomycache;
use Illuminate\Support\Facades\DB;

$rows = Oomycache::rememberQuery(
    tables: ['journal_lines', 'journal_entries'],
    key: 'owner-dashboard:'.$year,
    ttl: 120,
    callback: fn (): array => DB::table('journal_lines')->where('fiscal_year_id', $year)->get()
        ->map(fn (object $row): array => (array) $row)->all(),
);

Oomycache::flushTables(['journal_lines', 'journal_entries']); // when writes bypass cacheable models
```

The key is the caller's responsibility (include every filter); tenant segments and the listed tables'
version tokens are added by the package. If listed tables are written through cacheable models, their
writes invalidate the entry automatically; otherwise flush explicitly.

## Pivots and raw writes

- `belongsToMany` on cacheable models is wrapped: `attach`/`detach`/`sync`/`toggle`/pivot updates flush
  both table namespaces (config `cache.pivot_invalidation`, default true).
- `morphToMany`, raw pivot-table writes, `saveQuietly()`/`withoutEvents()` and raw
  `DB::table()->update()/delete()/insert()` still need an explicit `Oomycache::flush([...])` or
  `flushTables([...])`.

## Store choice (including no-Redis hosts)

- Database cache store: every warm read is also a cache-table SELECT. Measure `app_queries` and
  `store_queries` separately before enabling traits.
- Single host without Redis: keep the app on database but use a dedicated file store for OoMyCache:
  `OOMYCACHE_STORE=file` (per-server, not shared across nodes). When stats are enabled, set
  `OOMYCACHE_STATS_STORE=file` too; otherwise stats counters keep using the app's database store.
- Redis/Memcached for multi-node; array for tests only.

## How invalidation works (do not fight it)

- Keys embed a version token per **connection + table**, plus the global token, payload version and
  (when configured) tenant/panel/user segments.
- Eloquent model events and OoMyCache's builder write methods bump the token — O(1), no key scans.
- A related write invalidates recorded relationships (`withCount`, `whereHas`, joins, eager loads).
- **Writes outside Eloquent do not invalidate automatically:** `saveQuietly()`,
  `Model::withoutEvents()`, raw `DB::table()->update()/delete()/insert()`, `morphToMany` and raw pivot
  writes need an explicit `Oomycache::flush([...])` or `flushTables([...])`.
- Reads inside a transaction bypass the cache; writes inside a transaction invalidate after commit
  (both defaults are configurable under `safety`).
- Never call `Cache::flush()` to invalidate OoMyCache. Use `oomycache:clear` / `Oomycache::clear()`.

## Commands

```bash
php artisan oomycache:status     # store, capabilities, cacheable models, tokens, stats
php artisan oomycache:doctor     # validates configuration; exits 1 on invalid values
php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json  # isolated keys; use --clear only to wipe the app cache
php artisan oomycache:prune      # database store only; expiry-guarded, cache_locks untouched
php artisan oomycache:clear      # global version bump (never Cache::flush)
```

Run `oomycache:doctor` after changing configuration. Run `oomycache:bench` before/after adoption to
prove warm reads execute **0 application queries**; on the database store it also reports the
cache-store statements separately (`store_queries`).

## Configuration highlights

Read `config/oomycache.php` and `docs/implementation/config-reference.md` instead of guessing.

- `enabled`, `store`, `prefix`, `version`, `ttl`, `ttl_jitter`
- `cache.*` — which read shapes are cached; keep `search_terms=false` unless intentional
- `safety.*` — transaction guards, mass-write interception, failure logging
- `payload.*` — arrays JSON (the only mode), compression, chunking caps, per-request memo
- `tenant.resolver` — class implementing `Oomycache\Oomycache\Contracts\TenantResolver`; required for
  multi-tenant queue/console/API entrypoints that are outside a Filament panel
- `filament.dashboard.can_purge` — **deny-by-default** Gate required for the bridge's destructive
  actions; `can_view` optionally restricts the studio page

## Filament bridge (optional)

- `{panel-path}/query-cache` studio (e.g. `/admin/query-cache`, or `/query-cache` when the panel path is `''`): store status, cacheable models + version tokens, stats counters,
  selective invalidation and a global purge (both gated by `can_purge`).
- Tenant resolver: panel id in every key; panel tenancy without a resolved tenant fails closed.
- `ActionCalled` invalidator for attach/detach/associate/dissociate/reorder/import and bulk actions.
- The bridge only binds its resolver when no `oomycache.tenant.resolver` is configured; apps with
  queue/API tenancy must configure their own resolver.

## Verify the integration

```bash
composer test                         # package CI (Pint + PHPStan + 100% types + Pest)
php artisan oomycache:doctor
php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json  # isolated keys; use --clear only to wipe the app cache
php artisan test --compact            # app suite
```

Expect `warm` to report **0 app queries** and invalidation to be version-token based. On the database
store the JSON also reports `store_queries` separately: 0 app queries is not 0 SQL statements.
Empty results are not cached unless `cache.negative=true`; they show up as `empty_bypasses` in stats.

## Anti-patterns

- Caching models without the trait and expecting them to be cached.
- Using raw/quiet/pivot writes and assuming invalidation happened.
- Caching free-text search queries (`cache.search_terms=true`) or user-controlled unique filters
  without rate limiting — that is a cache-fill DoS vector.
- `cacheForForever()` on the database store: rows are ~10-year TTL and `prune` only removes expired
  rows.
- Invalidating with `Cache::flush()` (flushdb on Redis, ignores prefixes).
- Treating OoMyCache as Filament-only: the core is a normal Laravel package and the bridge is opt-in.

## References

- Package README: integrations and feature table
- `docs/implementation/config-reference.md`: every key the code reads
- `docs/implementation/operational-guardrails.md`: raw/quiet/pivot writes, forever rows, DoS
- `docs/implementation/security-review.md`: residual risks and launch conditions
- `docs/implementation/benchmark-and-launch.md`: measured cold/warm numbers
