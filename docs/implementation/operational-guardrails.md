# Operational Guardrails — Alpha

> Accepted residual risks from the [security review](security-review.md) and the operator actions that
> make them safe. Read this before enabling OoMyCache in an app with writes outside Eloquent or with
> user-controlled filters.

## 1. Writes that bypass invalidation (M2 — accepted for alpha)

OoMyCache invalidates from Eloquent model events and intercepted builder writes. These paths **do not
fire those events** and therefore keep cached reads until TTL expiry (or forever for `cacheForForever`):

| Write path | Example | Required action |
| --- | --- | --- |
| Quiet model writes | `$model->saveQuietly()`, `Model::withoutEvents(...)` | call `Oomycache::flush([$model::class])` after the write |
| Raw query-builder writes | `DB::table('products')->update(...)`, `DB::statement(...)` | flush the affected model class(es) |
| Non-Filament `belongsToMany` pivot writes on cacheable models | `$product->suppliers()->attach($id)` | covered automatically by `CachingBelongsToMany`: both table namespaces flush after attach/detach/sync/toggle |
| `morphToMany` / raw pivot-table writes | `$product->tags()->attach($id)` on a morph relation; `DB::table('pivot')->insert()` | flush both sides explicitly (`Oomycache::flush([...])`) |
| Filament pivot/action writes | attach/detach/reorder/import | covered by the bridge `ActionCalled` listener; custom action names must be added to the invalidator or flushed manually |

`Oomycache::flush([...])` bumps the version token — O(1), no key scans. Background jobs that write via
Eloquent are covered; jobs that use raw SQL must flush explicitly.

## 2. Forever entries on the database store (M4 — accepted for alpha)

`cacheForForever()` on the `database` store writes `expiration = now + 10 years`. `oomycache:prune`
deletes only expired rows, and `oomycache:clear` only bumps the global token — payload rows remain until
manually removed.

- Prefer TTL'd caching on the database store.
- If you use `cacheForForever`, schedule a periodic cleanup of unreachable payloads (version tokens
  make old generations unreachable; they are safe to delete by prefix/old generation).
- Inspect the cache table size periodically (`SELECT COUNT(*), SUM(LENGTH(value)) FROM cache`).

## 3. Cache-fill pressure (M5 — accepted for alpha)

Every distinct binding set is a distinct key. A public endpoint that passes user-controlled search
terms/filter values into cached queries can inflate the cache with unique entries (TTL 300 s default).

- Require authentication/authorization and rate limits on public-facing filter endpoints.
- Keep `cache.search_terms=false` (default) so free-text searches are not cached.
- Consider `cache.negative=false` (default) so empty result sets are not stored.
- Monitor cache row count/bytes and `oomycache:status` hits/misses; lower TTL if pressure grows.

## 4. Destructive studio actions

`oomycache.filament.dashboard.can_purge` is **deny-by-default**. Define a Gate (e.g.
`oomycache.purge`) for trusted operators in `AppServiceProvider::boot()` and point the config at it;
otherwise the invalidate/clear buttons are hidden and the closures refuse to run.

## 5. Pre-alpha operator checklist

- [ ] Redis (preferred) or database cache store configured; `oomycache:status` reports it correctly.
- [ ] `oomycache:doctor` exits 0; configuration has no dead/removed keys.
- [ ] `oomycache:prune` scheduled (database store only), hourly with `withoutOverlapping()`.
- [ ] `can_purge` Gate defined for the studio's destructive actions.
- [ ] Raw/quiet/non-Filament pivot writes audited; flushes added where needed.
- [ ] Public filter endpoints rate-limited; `search_terms`/`negative` left off unless intentional.
- [ ] Octane: runtime-state resets are registered (queue `JobProcessing` + octane request events), but
      run the Octane lane before production; in-memory workers keep version tokens for the request.
- [ ] Memcached: live Redis/Memcached app tests pass; CI memcached lane is non-blocking until PECL
      extension builds have a stable history.
- [ ] Monitor for one week: stats counters, cache-table growth, invalidate-on-write behavior in the ERP.

## 6. Emergency procedures

- **Suspected stale reads:** call `Oomycache::flush([$model])` for the affected models (or the studio's
  invalidate action) — no app restart required; keys are version-scoped.
- **Cache table/Redis pressure:** bump the global token (`oomycache:clear` or studio purge), then prune
  expired database rows; old payload generations become unreachable immediately.
- **Rollback:** `OOMYCACHE_ENABLED=false` disables caching without removing the trait; package upgrades
  are safe because keys include the payload `version` (bump it if the payload shape changes).
