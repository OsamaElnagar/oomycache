# OoMyCache — 5–10 minute demo walkthrough

> **Purpose:** a repeatable script for demoing OoMyCache in the dogfood ERP (Laravel 13.40, Filament
> 5.11, Livewire 4.4, PHP 8.5.12, EnvKit site `filament-custom-pages.test`). Use it for the alpha
> recording, conference/community demos, or a live pairing session.
> **Numbers are from** [`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md)
> §2–§3 (measured 2026-11-09, database cache store, seeded dataset: 3,000 products / 2,500 purchase
> orders / 5,000 invoices / 7,000 PO lines / 6,000 stock levels).

**Total runtime:** ~9 minutes (trim §6–§7 to land at 5).
**Audience:** Filament/Laravel developers who know Eloquent and Filament tables.
**One-line message:** *add one trait and warm Filament pages execute zero app-table queries — with
O(1), tenant-safe invalidation.*

---

## 0. Before you hit record (not part of the video)

1. **Start the local stack** (EnvKit; the app uses SQLite, so only web + PHP are required):
   `envkit start nginx php-fpm` — open `https://filament-custom-pages.test` once to confirm TLS/app boot.
2. **App is installed and seeded** (once per machine):
   ```bash
   cd D:\Herd\filament-custom-pages
   composer install && npm install && npm run build   # if the checkout is fresh
   php artisan migrate --seed                          # users, demo rows
   php artisan db:seed --class=ErpDogfoodSeeder        # heavy ERP dataset (~seconds)
   ```
3. **Demo env values** in `.env`: `APP_ENV=local`, `CACHE_STORE=database`, `OOMYCACHE_STATS=true`
   (stats counters power the studio's hit/miss panel); then `php artisan config:clear`.
4. **Open tabs:** `/admin/products`, `/admin/query-cache`, `/erp/products`; one terminal in the app
   root with a large font.
5. **Login:** the local-only QA helper `https://filament-custom-pages.test/_studio/login?to=/admin/products`
   (it logs in the first user; it 404s outside the `local` environment). Fallback: `/admin/login` with
   `test@example.com` / `password` (the `DatabaseSeeder` user).
6. **Reset to a cold start right before the first take:** `php artisan oomycache:clear` (version-token
   bump — it never calls `Cache::flush()`), then refresh `/admin/products` once so the first visible
   cold render is yours.

---

## 1. Login + the problem (0:00–0:30)

**Show:** the logged-in Filament panel, then the Product table.

**Say:** "This is a real Filament 5 ERP: 3,000 products, 2,500 purchase orders, 5,000 invoices,
7,000 PO lines. Every render of this page runs the table query, eager loads, the
`getCountForPagination()` total and the relationship counts. Between writes, most of that is the same
data. OoMyCache makes it cacheable by adding one trait to the model."

**Expected:** `/admin/products` renders the seeded table (≈508 KB HTML; cold ≈0.77 s on the reference
machine, timings vary).

---

## 2. Cold vs warm Product table (0:30–2:00)

**Show:** terminal: `php artisan oomycache:clear` → browser: hard-refresh `/admin/products` → refresh again.

**Say:** "The command bumped the global version token, so this first load is cold: one app query plus
the count and the eager loads. The refresh is warm: the page is served from the query cache and
executes **0 ERP-table queries** — the automated render test asserts exactly that, and the paginator
total is cached too. In the benchmark, a Product read goes from 22.8 ms cold / 1 app query to
**7.7 ms warm / 0 app queries** on the database store. Each warm read still costs two cache-table
reads (payload + version token); Redis or file stores remove that."

**Expected:** cold render ≈0.77 s, warm ≈0.50–0.70 s on the reference run; studio stats hits rise.
**Tip:** if you want visible query-count proof on screen, run `php artisan oomycache:bench ...` in §7
rather than installing a toolbar.

---

## 3. The Query Cache studio (2:00–3:30)

**Show:** sidebar **ERP → Query Cache** (`/admin/query-cache`).

**Say:** "The companion `osamaelnagar/oomycache-filament` adds this studio page. It shows the effective
store (`database`), taggable/lockable capabilities, payload mode (`arrays`), TTL, the global version
token, and the **12 cacheable models** with their per-table version tokens. The counters are opt-in
windowed stats — hits, misses, bypasses, invalidations, clears — so you can see the cache working
before you trust it. It is Filament-components-only, so custom panel themes apply unchanged."

**Expected:** page loads in ≈0.30 s; status + model tokens + live counters visible.
**Say also:** "In production this is where you watch the dogfood rollout: `oomycache:status` prints the
same snapshot for CLI-only operators."

---

## 4. Invalidate + clear — with the purge gate (3:30–4:45)

**Show:** studio header actions.

**Say:** "Both destructive actions are **deny-by-default**: they need a Gate named in
`oomycache.filament.dashboard.can_purge`. This demo app defines `oomycache.purge` and points the config
at it, so we see the buttons; in an app without that gate they are hidden and the closures refuse to
run."

**Do:**
1. **Invalidate selected model** → pick `App\Models\Product` → confirm. Expected: success notification
   and the Product version token changes in the table (O(1) token bump; other models untouched).
2. Go to `/admin/products`: it re-queries once (cold) and warms again.
3. **Clear query cache** → confirm. Expected: global token bumps, so every namespace is invalidated at
   once; `oomycache:status` shows the new global token. No `Cache::flush()`/`flushdb()` involved.

**Say:** "Nothing here scans keys or flushes a store — invalidation is a token bump, which is why it is
safe on shared Redis."

---

## 5. Relation-manager attach/detach invalidation (4:45–6:15)

**Show:** `/admin/products` → note the **Suppliers** count column for one product → **Edit** that
product → **Suppliers** tab → **Attach** a supplier (fill the pivot fields: supplier SKU, lead time,
unit cost) → save → then **Detach** a different supplier row → go back to the list.

**Say:** "Attach/detach writes the pivot table, not the product row — a plain Eloquent cache would go
stale for the TTL. The bridge listens to Filament's `ActionCalled` event and flushes the affected
model namespaces for attach/detach/associate/dissociate/reorder/import and bulk actions. So the count
column updates on the very next render. This is covered by three real end-to-end tests against this
relation manager."

**Expected:** supplier count changes immediately (no 300 s TTL wait); no stale list page.
**Caveat to mention:** "If your app attaches pivots outside Filament actions (or uses
`saveQuietly()`/raw SQL), call `flushQueryCache()`/`Oomycache::flush()` yourself — that residual is
documented in the security review."

---

## 6. Front office — plain Livewire, no Filament (6:15–7:15)

**Show:** `/erp/products`; search, filter by category, change sort, page 2.

**Say:** "Same package, no Filament: a plain Livewire 4 catalog component. The paginated catalog query
is cached; search-term queries deliberately bypass the cache by default. Warm renders are ≈0.22–0.38 s
in the reference run, and the front-office test asserts a warm render executes zero ERP-table queries."

**Optional:** open `/erp/stock` (polled KPI dashboard) and `/erp/suppliers` (searchable directory with
counts) to show aggregates are cached too.

---

## 7. CLI proof + operations (7:15–9:00)

**Show:** terminal in `D:\Herd\filament-custom-pages`.

```bash
php artisan oomycache:status
# store=database, payload=arrays, ttl=300, store capabilities, 12 cacheable models + version tokens,
# windowed stats (hits/misses/bypasses/invalidations/clears)

php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json
# {"model":"App\\Models\\Product","limit":50,
#  "cold":{"queries":7,"app_queries":1,"ms":22.818},
#  "warm_avg_ms":7.675,"warm_avg_app_queries":0,
#  "warm":[{"queries":2,"app_queries":0,"ms":7.809}, ...]}

php artisan oomycache:prune --dry-run
# database store only: scanned/expired counts; `--dry-run` deletes nothing.
# Reference live run: scanned 136, expired 122; the real run deleted 122 in 469 ms.

php artisan oomycache:doctor
# store support, payload mode and known-trap diagnostics for the current config
```

**Say:** "`bench` is the honest shape of the win: cold = 1 app query + cache bookkeeping; warm =
**0 app queries** and 2 cache-table reads. Run `prune` hourly on the database store (Redis needs no
pruning); `doctor` checks the store/config before you ship. The same numbers for Supplier and
PurchaseOrder are in the benchmark doc: 20.3→5.4 ms and 24.0→7.6 ms warm."

---

## 8. Close (9:00–9:30)

**Say:** "One trait, zero resource changes, warm Filament pages with zero app queries, O(1)
table-keyed invalidation, and a Filament studio with deny-by-default purge. It is **alpha and
conditional**: best on a single-tenant FPM deployment, set the purge gate, flush manually after raw /
quiet / non-Filament pivot writes, and note that Memcached and Octane CI lanes are still gaps. If you
want to try it on one heavy resource, here is the announcement and what feedback we need."

**Show:** the alpha announcement draft.

---

## Fallbacks & don'ts

| Situation | Fallback |
| --- | --- |
| Studio stats all zero | `OOMYCACHE_STATS=true` in `.env`, `php artisan config:clear`, reload; stats are opt-in. |
| Warm page still shows old data | Confirm you did not write through raw SQL/`withoutEvents`; `php artisan oomycache:clear` for a clean demo state. |
| `/_studio/login` returns 404 | It is local-only (`APP_ENV=local`), or use `/admin/login` with `test@example.com` / `password`. |
| Bench timings differ from the doc | Expected — quote the **0 app queries** invariant and label milliseconds as machine-dependent. |
| Empty ERP tables | Re-run `php artisan db:seed --class=ErpDogfoodSeeder` (chunked, seconds). |

**Don'ts:** don't run this against a production/shared database; don't claim Memcached/Octane/MySQL/
PostgreSQL cache-store behaviour is verified (only connectivity/smoke — see
[`../implementation/driver-matrix.md`](../implementation/driver-matrix.md)); don't say "stable" — this
is the conditional alpha; don't invent a public demo URL (none is published yet).

---

## Keep claims consistent with

- [`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md) — all numbers, gaps, launch sequence
- [`../implementation/security-review.md`](../implementation/security-review.md) — conditional-alpha caveats
- [`../implementation/phase-2-progress.md`](../implementation/phase-2-progress.md) — live page timings, bridge features
- [`../implementation/driver-matrix.md`](../implementation/driver-matrix.md) — what is and is not verified per driver
- [`../../README.md`](../../README.md) / [`../../../OoMyCacheFilament/README.md`](../../../OoMyCacheFilament/README.md) — install/usage truth
