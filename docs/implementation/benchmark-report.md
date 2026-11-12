# Benchmark report

> **Snapshot:** 2026-11-12 · PHP 8.5 · Laravel 13 · Filament 5 · SQLite · dedicated file cache store ·
> warm cache inside its TTL. The application code was identical in both runs; only
> `OOMYCACHE_ENABLED` was toggled. Measurements were taken against a copy of a production-like
> database, never a live database.

## Dataset

A 62-model ERP application with production-like data:

| Table | Rows |
| --- | ---: |
| `audit_logs` | 12,657 |
| legacy tables | 8,652 |
| `journal_lines` | 452 |
| `accounts` | 276 |
| `employees` | 128 |
| `farms` | 25 |

The workload was 1,040 operations through real Eloquent models and the real Filament HTTP stack:
list pagination, eager-loaded detail pages, dashboard aggregates, master-data lists, server-side
select search, authenticated Filament page loads and read-then-write churn.

## Headline

**4,291 → 1,784 SQL statements (−58.4%) · DB time 2,564 → 1,239 ms (−51.7%) · 99.8% warm hit rate
(2,456 hits / 4 misses).**

On the read-only business workloads (the first five rows below): **−69% SQL, −75% DB time and −68%
wall time**.

## Per workload

| Workload | Operations | SQL without cache | SQL with cache | Reduction | Wall time without → with |
| --- | ---: | ---: | ---: | ---: | ---: |
| Audit-log list pagination | 200 | 400 | **0** | **100%** | 702 → 295 ms |
| Legacy-custody list pagination | 100 | 200 | **0** | **100%** | 416 → 135 ms |
| Journal entries + eager lines + paginator | 100 | 300 | **0** | **100%** | 2,360 → 242 ms |
| Dashboard aggregate bundle | 100 | 800 | 500 | 37% | 1,006 → 518 ms |
| Master-data lists | 300 | 900 | 300 | 67% | 1,997 → 882 ms |
| Server-side select search (term-dependent) | 200 | 200 | 200 | 0% | not meaningful |
| Heavy Filament page loads (10 pages × 4) | 40 | 791 | **184** | **77%** | render-bound |
| Read-then-write churn | 100 | 700 | 600 | 14% | not a target |

## How to read the numbers

- **List/detail reads are the win.** Warm pagination and eager-loaded pages execute zero application
  queries for cached data. The cache store itself may still cost statements on database-backed stores;
  Redis, file and Memcached do not add SQL.
- **Dashboard aggregates improve only where they are cached.** Raw `DB::table()` aggregates are not
  cached unless they are wrapped with an explicit `rememberQuery()` table list and every writer is
  proven to invalidate it.
- **Term-dependent search is intentionally not cached** with the default
  `cache.search_terms=false`. Caching user LIKE patterns is a cache-fill and high-cardinality risk.
- **Read-then-write screens are not the target.** Every write bumps the table version; the package is
  a read cache, not a write optimizer.
- **Filament page wall time is Livewire-render-bound.** Quote SQL and DB-time reductions for Filament
  surfaces, not total response milliseconds.
- **These are one application's numbers.** Store choice, host, dataset, payload size, TTL and query
  shape all matter. Re-run the benchmark on the deployment host before sizing an expected gain.

## Reproduce

```bash
php artisan config:clear

# Toggle OOMYCACHE_ENABLED between runs; keep the same dataset, store and warm cache.
php artisan oomycache:bench "App\\Models\\Product" --limit=50 --samples=5 --json
php artisan oomycache:bench --all --limit=50 --samples=3 --json

# Application-level measurement: count queries and DB time for the same real requests
# with the cache disabled and enabled; keep a warm TTL window for the enabled run.
```

See [production-runbook.md](production-runbook.md) for host benchmarking and rollout advice.
