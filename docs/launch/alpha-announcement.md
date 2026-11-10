# OoMyCache — alpha announcement (draft)

> **Draft for `v0.1.0-alpha.1`.** Internal launch asset, not yet published. Before posting: re-run the
> checks in [`README.md`](README.md), confirm the security-review conditional-alpha items in
> [`../implementation/security-review.md`](../implementation/security-review.md), and re-capture the
> benchmark numbers on the release commit ([`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md) §2).
> All numbers below are measured on the dogfood ERP; timing varies with OS cache/opcache.

**Audience:** Laravel + Filament developers running data-heavy admin panels.
**Channel suggestions:** Filament community Discord / GitHub discussions first, then Laravel News,
Reddit r/laravel, dev.to (see [`../plan/06-roadmap.md`](../plan/06-roadmap.md) §6.1).

---

## OoMyCache (alpha): make repeated Eloquent reads disappear — without replacing Eloquent

Filament list pages, widgets and relation managers re-run the same heavy queries on every render:
the table query, eager loads, the paginator `COUNT`, `withCount` aggregates, dashboard sums. Most of
it is the same data over and over between writes.

OoMyCache is an opt-in Eloquent query cache with a correctness-first invalidation model. Add one trait
to a model and its existing queries — including everything Filament builds — become cacheable. No
resource changes, no forked framework internals, no tags required.

```php
use Oomycache\Oomycache\Concerns\HasQueryCache;

final class Product extends Model
{
    use HasQueryCache;
}
```

### Three things that make it different

1. **Transparent Eloquent caching — Filament included.** The package subclasses Eloquent's builder
   (`newEloquentBuilder()` + `newBaseQueryBuilder()`), so `get`, `first`, `paginate`, aggregates,
   `withCount` and Filament's default `toBase()->getCountForPagination()` total all go through the
   cache. Filament resources, tables and widgets need no changes.
2. **Table-keyed O(1) invalidation.** A write bumps a version token for the model's
   `connection + table`; every cache key embedding that token becomes unreachable. No key scans, no
   tag dependency, no `Cache::flush()` — it works on database, file, Redis, Memcached and array stores,
   and writes invalidate after commit. Relationship/eager-load/`whereHas`/join dependencies are
   versioned too, so a related write invalidates the cached parent query.
3. **Filament studio + tenant-safe keys, as an optional bridge.** The companion
   [`osamaelnagar/oomycache-filament`](https://github.com/osamaelnagar/oomycache-filament) adds an
   `/admin/query-cache` studio page (store status, cacheable models + version tokens, live
   hit/miss/bypass stats, selective invalidation, global purge), a Filament tenant resolver that puts
   the panel/tenant in every key and **fails closed** when tenancy is active but unresolved, and an
   `ActionCalled` invalidator for attach/detach/reorder/import and bulk actions that bypass Eloquent
   model events. Core has **zero** Filament/Livewire dependencies.

### Measured on the dogfood ERP (Filament 5.11 / Laravel 13.40 / PHP 8.5.12)

`php artisan oomycache:bench {model} --limit=50 --samples=3 --json`, `database` cache store, seeded
dataset (3,000 products, 2,500 purchase orders, 5,000 invoices):

| Model | Cold | Warm average | Warm wins |
| --- | --- | --- | --- |
| `App\Models\Product` | 1 app query · 22.8 ms | **0 app queries** · **7.7 ms** | −1 query, −66% latency |
| `App\Models\Supplier` | 1 app query · 20.3 ms | **0 app queries** · **5.4 ms** | −1 query, −73% latency |
| `App\Models\PurchaseOrder` | 1 app query · 24.0 ms | **0 app queries** · **7.6 ms** | −1 query, −68% latency |

On the `database` store each warm read still costs 2 cache-table reads (payload + version token); Redis
or file stores remove that overhead. In the dogfood app, Filament `/admin/products` renders in ≈0.50–0.70 s
warm vs ≈0.77 s cold, and the automated render test asserts **0 ERP-table queries** warm — the paginator
COUNT is cached too. Plain Livewire front-office pages show the same behaviour (warm renders ≈0.22–0.38 s).

### Install (core)

```bash
composer require osamaelnagar/oomycache
php artisan vendor:publish --tag=oomycache-config

# add the trait to the models you want cached; caching stays opt-in
```

Full quickstart, per-query controls (`cacheFor`, `dontCache`, `cacheIf`, …) and commands are in the
[package README](../../README.md). Recommended production store: Redis; the database store works but
needs the hourly `oomycache:prune` housekeeping.

### Install (optional Filament bridge)

```bash
composer require osamaelnagar/oomycache-filament
```

```php
// app/Providers/Filament/AdminPanelProvider.php
use Oomycache\Filament\OomycachePlugin;

return $panel
    // ...
    ->plugin(OomycachePlugin::make());
```

The studio's destructive actions are **deny-by-default**: configure a Gate or the buttons stay hidden
and the actions refuse to run.

```php
// AppServiceProvider::boot()
Gate::define('oomycache.purge', fn (User $user): bool => $user->is_admin);
config(['oomycache.filament.dashboard.can_purge' => 'oomycache.purge']);
```

### Alpha status: conditional, read this first

This is an **alpha**. The security review's verdict is *conditional*: suitable for a single-tenant
deployment on FPM (or queue/Octane workers, now that per-request/per-job runtime-state resets are
registered) with the caveats below. It is **not** a "drop it on a multi-tenant billing system" release
yet — beta/stable require the remaining medium driver/observability items.

- **Single-tenant first.** Tenant/panel segments and fail-closed behaviour are tested, but multi-tenant
  queue/console/API entrypoints must resolve tenants themselves (configure `oomycache.tenant.resolver`
  and fail closed when no tenant is known); see [`../../README.md`](../../README.md) and
  [`../../../OoMyCacheFilament/README.md`](../../../OoMyCacheFilament/README.md).
- **Set the purge gate.** `can_purge` is deny-by-default in the studio; define the gate for operators
  who may invalidate/clear.
- **Raw, quiet and non-Filament pivot writes bypass invalidation.** `saveQuietly()`/
  `withoutEvents()`, raw `DB::table()`/`DB::statement()` writes and `attach()`/`detach()` outside a
  Filament action are not observed by model events — call `flushQueryCache()` /
  `Oomycache::flush()` after them, or accept stale reads bounded by the TTL. Filament actions are
  covered by the bridge's `ActionCalled` invalidator.
- **Avoid `cacheForForever()` on the database store** — those rows are ~10-year TTLs and
  `oomycache:prune` only removes expired rows; use TTLs (or Redis) instead.
- **Driver gaps:** Memcached and Octane CI lanes are not green yet (Redis, MySQL and PostgreSQL have
  live smoke evidence; the nightly driver-matrix workflow exists but is not running in CI until the
  package repo split). See [`../implementation/driver-matrix.md`](../implementation/driver-matrix.md).
- **Search queries bypass the cache by default**, and v0.x may change config keys/API before v1.0.

### Feedback wanted (5–10 Filament developers)

Try it on one heavy resource or widget and tell us:

1. Does the warm page render with zero app-table queries for your resource? (Query log or
   `oomycache:bench`).
2. Any stale read after a write — especially raw/quiet/pivot writes and queue workers?
3. Any Filament/Livewire edge case: relation managers, action modals, bulk actions, polling, widgets.
4. The `oomycache:status` / `oomycache:doctor` output plus your Laravel/Filament/store versions.

Open an issue at [`github.com/osamaelnagar/oomycache`](https://github.com/osamaelnagar/oomycache)
(or the companion repo for bridge issues). Report security concerns privately per
[`../../.github/SECURITY.md`](../../.github/SECURITY.md). Alpha installs are listed on
[Packagist](https://packagist.org/packages/osamaelnagar/oomycache) once the `v0.1.0-alpha.1` tag is
indexed.

*OoMyCache is MIT-licensed. Read the measured benchmarks, launch checklist and honest gaps in the
[launch index](README.md).*
