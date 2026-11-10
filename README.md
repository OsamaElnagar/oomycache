# OoMyCache

**Eloquent query caching for Laravel — built for Filament-scale data.**

[![Packagist](https://img.shields.io/badge/packagist-osamaelnagar%2Foomycache-blue)](https://packagist.org/packages/osamaelnagar/oomycache)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20)](https://laravel.com/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

> **Status:** Phase 1 (core engine) complete; Phase 2 tenancy + payload hardening complete; the Filament
> bridge is extracted into the `osamaelnagar/oomycache-filament` companion package with its own CI lane.
> Chunked payloads, cache entities and launch automation are next (see `docs/plan/06-roadmap.md`).

OoMyCache makes repeated Eloquent reads disappear — transparently — with a correctness-first
invalidation model designed for multi-tenant admin panels:

```php
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class Product extends Model
{
    use HasQueryCache;
}
```

That's it. Filament resources, tables, widgets, relation managers, route bindings and every plain
Eloquent query on `Product` now go through the caching builder. No resource changes.

## Why another model cache?

| Feature | OoMyCache |
| --- | --- |
| Transparent Eloquent caching | ✅ `newEloquentBuilder()` + `newBaseQueryBuilder()` subclasses; no framework forks |
| Filament paginator COUNT | ✅ cached via the base query builder (default Filament pagination calls `toBase()->getCountForPagination()`) |
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
composer require osamaelnagar/oomycache
php artisan vendor:publish --tag=oomycache-config
```

Add the trait to the models you want cached. That is opt-in by design: un-audited models stay
uncached. Recommended defaults are production-safe out of the box; see `config/oomycache.php`.

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
| `oomycache:bench {model}` | cold vs warm app-query counts and timings for a cacheable model (`--limit`, `--samples`, `--json`) |
| `oomycache:prune` | delete expired database-cache rows (keyset-ordered, expiry-guarded; `--dry-run`, `--chunk`, `--max-seconds`; schedule hourly) |

## Filament compatibility

Core has **no** Filament or Livewire dependency. The package works with Filament 5 / Livewire 4 today
through the two builder subclasses, and the compatibility contract (tenants, pagination COUNT,
invalidation matrix, bridge UX) is documented in [`docs/plan/04-filament-compatibility.md`](docs/plan/04-filament-compatibility.md).

The Phase-2 bridge is a standalone companion package, [`osamaelnagar/oomycache-filament`](https://github.com/osamaelnagar/oomycache-filament) (`packages/OoMyCacheFilament`), which adds:

- a Filament-aware **tenant resolver**: the current panel is part of every cache key, and tenancy
  active without a resolved tenant **fails closed** (query runs uncached);
- an `/admin/query-cache` **Query Cache studio** page: store status, cacheable models + version
  tokens, live stats counters, selective model invalidation and a global purge action;
- an `ActionCalled` **invalidator** for Filament actions that bypass Eloquent events
  (attach/detach/associate/dissociate/reorder/import and the bulk mass branches).

```bash
composer require osamaelnagar/oomycache osamaelnagar/oomycache-filament
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
  become unreachable. No tags required on any driver.
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
```

The package was dogfooded in a real Filament 5 ERP app (4 resources, relation managers, select-heavy
forms, custom page/actions, widgets, plain-Livewire front-office pages) with 3,000 products /
2,500 purchase orders / 5,000 invoices; warm Filament list pages execute **zero** ERP-table queries.
See [`docs/implementation/phase-1-dogfood.md`](docs/implementation/phase-1-dogfood.md).

## Documentation

- [`docs/README.md`](docs/README.md) — research & design dossier (competitor analysis + blueprint)
- [`docs/plan/03-architecture.md`](docs/plan/03-architecture.md) — key composition, invalidation algebra, ADRs
- [`docs/plan/05-qa-checklist.md`](docs/plan/05-qa-checklist.md) — release gates

## License

MIT. See [LICENSE.md](LICENSE.md).
