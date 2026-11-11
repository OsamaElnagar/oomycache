# OoMyCache

OoMyCache is a Laravel package: transparent, correctness-first Eloquent query caching with
version-token invalidation and an optional Filament 5 bridge.

## Package conventions

- Keep the **core framework-agnostic**. Never add Filament/Livewire dependencies to
  `packages/OoMyCache`; Filament integration belongs in `packages/OoMyCacheFilament`.
- Preserve the existing service-provider shape and public API. Prefer Laravel-native package APIs.
- Keep package names, namespaces, Composer metadata, documentation and examples aligned with
  `osamaelnagar/oomycache` (core) and `osamaelnagar/oomycache-filament` (bridge).
- Add only the files/dependencies needed for the behavior being implemented.
- Every behavior change needs a Pest test through public APIs, service-provider wiring, commands or
  the builder. Keep the package gates green.
- Update the bundled Boost skill (`resources/boost/skills/oomycache-development/SKILL.md`) and docs
  when public behavior/config changes.

## Architecture map

- `src/Builder/QueryCacheBuilder.php` + `src/Builder/QueryCacheQueryBuilder.php` — caching builders.
- `src/Cache/QueryCacheEngine.php` — classify, key, cache/read, TTL, version tokens, payload decode.
- `src/Cache/CacheVersionStore.php` — per-connection+table version namespaces.
- `src/Invalidation/InvalidationManager.php` — Eloquent events → version bumps (afterCommit aware).
- `src/Payload/*` — arrays-JSON codec, compression, chunking.
- `src/Relations/CachingBelongsToMany.php` + `CachingMorphToMany.php` — pivot attach/detach/sync
  invalidation for non-polymorphic and polymorphic many-to-many relations.
- `src/Query/*` — fingerprinting, dependency recording, eager-load resolution.
- `Oomycache::rememberQuery()` / `flushTables()` — raw DB::table() report caching by explicit tables.
- `src/Tenant/*`, `src/Contracts/TenantResolver.php` — tenant/panel/user key segments, fail-closed.
- `config/oomycache.php` — the complete config surface; validate with `oomycache:doctor`.
- `src/Console/Commands/*` — status, clear, doctor, bench, prune.

## Quick commands

- Full validation: `composer test`
- Format: `composer lint`
- Format check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest: `composer test:unit`
- Workbench build/server: `composer build`, `composer serve`

Run from `packages/OoMyCache`. The app-level dogfood suite lives in the host app
(`php artisan test --compact`).

## Agent skill

`resources/boost/skills/oomycache-development/SKILL.md` is the adoption guide shipped to Laravel Boost
agents. Keep it focused on installing/configuring OoMyCache in an app, not package internals.

## Boundaries

- Do not commit `composer.lock`, `vendor/`, `build/` or PHPUnit caches.
- Do not claim Filament is required; the core works in any Laravel app.
- Do not soften the correctness model: invalidations are version-token based; raw/quiet writes and raw
  pivot-table writes need an explicit flush, and `Cache::flush()` is never used. `belongsToMany`,
  `morphToMany` and `morphedByMany` writes on cacheable models are covered by the caching relations.
- Before tagging, follow `docs/implementation/release-checklist.md` and run
  `scripts/release-rehearsal.ps1`.
