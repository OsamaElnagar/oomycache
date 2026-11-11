<p align="center">
  <img src="https://cdn.jsdelivr.net/gh/OsamaElnagar/oomycache@main/art/cover.jpg" alt="OoMyCache — transparent Eloquent query caching for Laravel: 0 app queries warm, O(1) table-keyed invalidation, any cache store" width="100%">
</p>

<h1 align="center">OoMyCache</h1>

<p align="center">
  <strong>Transparent, correctness-first Eloquent query caching for Laravel.</strong><br>
  Any app · any cache store · version-token invalidation · Filament bridge optional.
</p>

<p align="center">
  <a href="https://packagist.org/packages/osamaelnagar/oomycache"><img src="https://img.shields.io/packagist/v/osamaelnagar/oomycache?style=flat-square&color=22d3a7" alt="Latest version"></a>
  <a href="https://packagist.org/packages/osamaelnagar/oomycache"><img src="https://img.shields.io/packagist/dt/osamaelnagar/oomycache?style=flat-square&color=14b8a6" alt="Total downloads"></a>
  <a href="https://packagist.org/packages/osamaelnagar/oomycache"><img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4?style=flat-square" alt="PHP 8.3+"></a>
  <a href="https://packagist.org/packages/osamaelnagar/oomycache"><img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-ff2d20?style=flat-square" alt="Laravel 12 / 13"></a>
  <a href="https://packagist.org/packages/osamaelnagar/oomycache-filament"><img src="https://img.shields.io/badge/Filament-optional-6f7dff?style=flat-square" alt="Filament bridge optional"></a>
  <a href="https://github.com/OsamaElnagar/oomycache/stargazers"><img src="https://img.shields.io/github/stars/OsamaElnagar/oomycache?style=flat-square&color=ff9bd6" alt="GitHub stars"></a>
  <a href="https://github.com/OsamaElnagar/oomycache/actions/workflows/tests.yml"><img src="https://github.com/OsamaElnagar/oomycache/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-22d3a7?style=flat-square" alt="MIT license"></a>
</p>

> **Status:** public alpha (`v0.1.0-alpha.*`). Core engine, tenancy, payload hardening and the
> optional Filament bridge are implemented and tested; see [`docs/implementation`](docs/implementation).

OoMyCache makes repeated Eloquent reads disappear — transparently — with a correctness-first
invalidation model that works in any Laravel application: APIs, queues, dashboards, back offices,
and Filament panels (via the optional bridge).

```php
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class Product extends Model
{
    use HasQueryCache;
}
```

That's it. Every plain Eloquent query on `Product` now goes through the caching builder. No resource
changes, no base-class changes, no fork. Filament resources, tables, widgets and relation managers get
the same benefit when you install the optional bridge — the core package itself has **zero Filament
dependency**.

## Why another model cache?

| Feature | OoMyCache |
| --- | --- |
| Transparent Eloquent caching | ✅ `newEloquentBuilder()` + `newBaseQueryBuilder()` subclasses; no framework forks |
| Filament paginator COUNT | ✅ cached via the base query builder — any Laravel paginator benefits; the Filament bridge is optional |
| Invalidation | ✅ version-token namespaces keyed by **connection + table**; O(1) on Redis/Memcached/database/file/array |
| Relationships | ✅ eager loads, `withCount`/`withSum`/`withExists`, `whereHas`, join/subquery tables recorded as dependencies |
| Multi-tenant / multi-panel safe | ✅ tenant + panel segments, fail-closed |
| Transactions | ✅ reads bypass cache inside transactions; invalidation deferred to `afterCommit` |
| Laravel 13 serialisation defaults | ✅ arrays-JSON payload by default (`cache.serializable_classes=false` safe); models opt-in with allow-list |
| Payload hardening | ✅ optional gzip/deflate/zstd compression envelope, transparent chunking above `chunk_bytes`, per-request decoded-payload memo, negative caching with short TTL |
| Maintenance | ✅ `oomycache:prune` for database cache stores: keyset-ordered, expiry-guarded, never touches `cache_locks` |
| Filament-aware | ✅ zero Filament dependency in core; first-party companion bridge (studio page, tenants, invalidation) |

## Installation

```bash
composer require osamaelnagar/oomycache:^0.1.0-alpha.1
php artisan vendor:publish --tag=oomycache-config
```

Add the trait to the models you want cached. That is opt-in by design: un-audited models stay
uncached. Recommended defaults are production-safe out of the box; see `config/oomycache.php`.

### No Redis? Dedicated file store

The package can use a different store from the rest of the application. On a single host with a writable
`storage/` directory, keep Laravel's app cache on `database` but put OoMyCache on `file`:

```dotenv
CACHE_STORE=database
OOMYCACHE_STORE=file
```

This removes the cache-table SELECTs that a database-backed query cache adds to every warm read. If
stats are enabled, set `OOMYCACHE_STATS_STORE=file` too (it defaults to `OOMYCACHE_STORE` in the
current release). File cache is per-server and not shared across load-balanced nodes; benchmark it on
the deployment host before enabling model traits, using the `app_queries`/`store_queries` split from
`oomycache:bench`. Because versioned invalidation leaves old payload files behind, schedule a periodic
`php artisan cache:clear file` when the `file` store is dedicated to OoMyCache. Redis/Memcached remain
the right choice for multi-node deployments; `array` is for tests only. See
[`docs/implementation/store-selection.md`](docs/implementation/store-selection.md) for the full
trade-off table, including Hostinger-style shared hosting.

## Usage

```php
// all reads on opted-in models are cached automatically
$products = Product::query()->with('category')->where('is_active', true)->paginate(25);

// per-query control
Product::query()->cacheFor(120)->get();
Product::query()->cacheForForever()->where('is_system', true)->get();
Product::query()->dontCache()->get();
Product::query()->cacheKey('catalog-v2')->get();
Product::query()->cacheIf(fn ($result) => $result->isNotEmpty())->get();

// namespace operations (O(1) version bumps, never touches other cache keys)
Product::flushQueryCache();
Oomycache::flush([Product::class, Category::class]);
Oomycache::clear();
```

Many-to-many pivots are covered automatically for cacheable models: the package wraps
`belongsToMany` and flushes both table namespaces after `attach`, `detach`, `sync`, `toggle` and
pivot updates (disable with `OOMYCACHE_PIVOT_INVALIDATION=false`). `morphToMany` and raw pivot-table
writes still need an explicit flush.

### Caching raw report/dashboard queries

`DB::table()` reports cannot use the Eloquent builder. Use `rememberQuery()` with an explicit key and
table dependency list:

```php
use Oomycache\Oomycache\Facades\Oomycache;
use Illuminate\Support\Facades\DB;

$rows = Oomycache::rememberQuery(
    tables: ['journal_lines', 'journal_entries'],
    key: 'owner-dashboard:'.$year,
    ttl: 120,
    callback: fn (): array => DB::table('journal_lines')
        ->where('fiscal_year_id', $year)
        ->orderBy('date')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all(),
);

// the caller owns the key: include every filter that changes the result
// invalidate explicitly when those tables are written outside cacheable models
Oomycache::flushTables(['journal_lines', 'journal_entries']);
```

The key includes tenant segments and the version tokens of all listed tables. If the listed tables are
written through cacheable Eloquent models, those writes invalidate the entry automatically; otherwise
call `flushTables()` after the write path. Prefer returning arrays/scalars; raw result objects are
normalized to arrays.

Per-model overrides:

```php
public function queryCacheOptions(): array
{
    return [
        'ttl' => 600,
        'cache_aggregates' => true,
        'dependencies' => [Supplier::class], // extra invalidation namespaces
        'skip_query_patterns' => ['/\bstock_qty\b/i'],
    ];
}
```

## Artisan commands

| Command | Purpose |
| --- | --- |
| `oomycache:status` | configuration, store capabilities, cacheable models, version tokens, optional stats counters |
| `oomycache:clear [--model=…]` | invalidate one/many models (or the whole namespace) |
| `oomycache:doctor` | diagnose store support, payload mode, common traps |
| `oomycache:bench {model}` | cold vs warm app-query counts, separate cache-store statement counts, and timings for a cacheable model (`--limit`, `--samples`, `--json`); isolated benchmark keys, memo reset between samples, no global cache clear unless `--clear` |
| `oomycache:prune` | delete expired database-cache rows (keyset-ordered, expiry-guarded; `--dry-run`, `--chunk`, `--max-seconds`; schedule hourly) |

## Filament compatibility

Core has **no** Filament or Livewire dependency. The package works with Filament 5 / Livewire 4 today
through the two builder subclasses, and the compatibility contract (tenants, pagination COUNT,
invalidation matrix, bridge UX) is documented in [`docs/plan/04-filament-compatibility.md`](docs/plan/04-filament-compatibility.md).

The Phase-2 bridge is a standalone companion package, [`osamaelnagar/oomycache-filament`](https://github.com/osamaelnagar/oomycache-filament) (`packages/OoMyCacheFilament`), which adds:

- a Filament-aware **tenant resolver**: the current panel is part of every cache key, and tenancy
  active without a resolved tenant **fails closed** (query runs uncached);
- a `{panel-path}/query-cache` **Query Cache studio** page (e.g. `/admin/query-cache`, or `/query-cache` on a panel with path `''`): store status, cacheable models + version
  tokens, live stats counters, selective model invalidation and a global purge action;
- an `ActionCalled` **invalidator** for Filament actions that bypass Eloquent events
  (attach/detach/associate/dissociate/reorder/import and the bulk mass branches).

```bash
composer require osamaelnagar/oomycache:^0.1.0-alpha.1 osamaelnagar/oomycache-filament:^0.1.0-alpha.1
```

```php
// app/Providers/Filament/AdminPanelProvider.php
use Oomycache\Filament\OomycachePlugin;

return $panel
    // ...
    ->plugin(OomycachePlugin::make());
```

## Verified in the dogfood app

- Filament 5 resources/tables/widgets/custom page warm renders: **0 ERP-table queries** (includes paginator totals).
- Filament relation-manager `AttachAction`/`DetachAction` on a belongsToMany relation invalidate cached aggregates (real E2E tests).
- Plain Livewire 4 front-office pages warm renders: 0 ERP-table queries.
- Database, file, **real Redis** and **real Memcached** cache stores validated; Laravel 13 `serializable_classes=false` payload safety proven.
- `oomycache:bench` on the seeded app: cold 41.4 ms / 1 app query vs warm 20.3 ms / **0 app queries** (database store).

See [`docs/implementation/phase-2-progress.md`](docs/implementation/phase-2-progress.md).

## Safety model

- **Opt-in per model.** No trait, no caching.
- **Versioned invalidation.** A write bumps the table's version token; all cache keys embedding it
  become unreachable. No tags required on any driver. Cacheable models' `belongsToMany` pivot
  operations flush both sides automatically.
- **Explicit raw-query invalidation.** `rememberQuery()` entries are versioned by the tables you list;
  `flushTables()` covers write paths that do not go through cacheable models.
- **Fail-open reads, loud invalidation.** Cache outages fall back to the DB; failed invalidations are
  logged/thrown instead of silently serving stale data.
- **Transaction-aware.** Reads inside a transaction bypass the cache; writes invalidate after commit.
- **No global flush.** `oomycache:clear` bumps a version token; it never calls `Cache::flush()` (which
  on Redis is `flushdb()` and ignores prefixes).
- **Arrays payload by default.** Strings (not PHP objects) go into the cache store, so Laravel 13's
  default `cache.serializable_classes=false` policy cannot break payloads.

## Testing

```bash
composer test        # pint + phpstan + 100% type coverage + Pest

# local real-driver lanes (services must be running)
OOMYCACHE_TEST_DRIVER=redis     vendor/bin/pest tests/Feature/RealCacheDriverTest.php
OOMYCACHE_TEST_DRIVER=memcached vendor/bin/pest tests/Feature/RealCacheDriverTest.php
OOMYCACHE_TEST_DB=mysql DB_HOST=127.0.0.1 DB_DATABASE=oomycache_smoke DB_USERNAME=root \
  vendor/bin/pest tests/Feature/DatabaseDriverTest.php
```

The package was dogfooded in a real Filament 5 ERP app (4 resources, relation managers, select-heavy
forms, custom page/actions, widgets, plain-Livewire front-office pages) with 3,000 products /
2,500 purchase orders / 5,000 invoices; warm Filament list pages execute **zero** ERP-table queries.
See [`docs/implementation/phase-1-dogfood.md`](docs/implementation/phase-1-dogfood.md).

## For AI agents (Laravel Boost)

The package ships a Laravel Boost skill at
[`resources/boost/skills/oomycache-development/SKILL.md`](resources/boost/skills/oomycache-development/SKILL.md).
When the package is installed in a Laravel app, Boost-aware agents discover it automatically and get
installation, configuration, per-query API, invalidation, command, tenancy and anti-pattern guidance —
including the Filament bridge being optional. The package-level [`AGENTS.md`](AGENTS.md) documents the
maintenance conventions for agents working on OoMyCache itself.

## Documentation

- [`docs/README.md`](docs/README.md) — research & design dossier (competitor analysis + blueprint)
- [`docs/plan/03-architecture.md`](docs/plan/03-architecture.md) — key composition, invalidation algebra, ADRs
- [`docs/plan/05-qa-checklist.md`](docs/plan/05-qa-checklist.md) — release gates

## License

MIT. See [LICENSE.md](LICENSE.md).
