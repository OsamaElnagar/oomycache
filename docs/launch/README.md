# OoMyCache — Launch Assets

> **Status:** draft assets for the `v0.1.0-alpha.1` launch (2026-11-10). Not published yet.
> **Audience:** the maintainer/operator preparing the alpha release, and the developers receiving it.
> **Rule for every asset here:** only claims that trace to a measured or reviewed document; no
> invented URLs, numbers or public demo links.

---

## 1. Assets

| Asset | Audience | Use | Status |
| --- | --- | --- | --- |
| [`alpha-announcement.md`](alpha-announcement.md) | Filament/Laravel developers | Launch post draft: problem, 3 differentiators, measured cold/warm numbers, install snippets, conditional-alpha caveats, feedback ask. | Draft — re-check numbers/blockers on the release commit before posting. |
| [`demo-walkthrough.md`](demo-walkthrough.md) | Demo presenter / recording | 5–10 minute script for the dogfood ERP: login, cold vs warm Filament table, Query Cache studio + gate, relation-manager pivot invalidation, front office, CLI `bench`/`status`/`prune`/`doctor`. | Draft — numbers from the 2026-11-09 reference run. |
| `README.md` (this file) | Maintainer | Asset index, claim ledger, pre-publish checklist. | Living. |

Launch sequencing (alpha → beta → stable) and the exact tag commands live in
[`../implementation/release-checklist.md`](../implementation/release-checklist.md) and
[`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md) §5.

---

## 2. Pre-publish checklist

Do these in order; each one has a document of record.

- [ ] **Release gates green on the tagged commit** — [`../implementation/release-checklist.md`](../implementation/release-checklist.md) §3: CI matrix, `composer test` (PHPStan L7 + Pint + 100% type coverage + Pest), dogfood `php artisan test --compact`, `composer audit`.
- [ ] **Residual-risk acceptance** — [`../implementation/operational-guardrails.md`](../implementation/operational-guardrails.md): M2/M4/M5 guardrails accepted for alpha (raw/quiet/pivot writes, forever rows, cache-fill pressure) and the operator checklist is complete.- [ ] **Security review blockers** — [`../implementation/security-review.md`](../implementation/security-review.md) §3.1/§6: H1 purge gate fixed and deny-by-default, H2 runtime-state reset registered, H3 bridge resolver override; M2/M4/M5 residual guidance accepted for alpha (single-tenant, FPM or queue/Octane, raw/quiet/pivot writes documented).
- [ ] **Driver matrix current** — [`../implementation/driver-matrix.md`](../implementation/driver-matrix.md): array/file/database/Redis evidence; Memcached/Octane/MySQL/PostgreSQL Eloquent coverage explicitly listed as gaps (do not upgrade those claims in the announcement).
- [ ] **Re-capture the numbers on the release commit** — `php artisan oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json` (also Supplier, PurchaseOrder) and update the tables in [`alpha-announcement.md`](alpha-announcement.md) §"Measured" if they moved materially. Quote the invariant (**0 app queries warm**); milliseconds are machine-dependent.
- [ ] **Install snippets match the tagged versions** — core [`../../README.md`](../../README.md), bridge [`../../../OoMyCacheFilament/README.md`](../../../OoMyCacheFilament/README.md); no `@dev` constraints for a public alpha.
- [ ] **No invented URLs** — the only external URLs used are the package/composer metadata ones: `github.com/osamaelnagar/oomycache`, `github.com/osamaelnagar/oomycache-filament`, `packagist.org/packages/osamaelnagar/oomycache`. There is **no public demo URL yet** — do not add one until the demo app is deployed.
- [ ] **Validate documentation links** — every relative link in `docs/launch/**` and `docs/README.md` resolves (this was machine-checked when the assets were written; re-run after edits).
- [ ] **Record the demo** — follow [`demo-walkthrough.md`](demo-walkthrough.md); capture the cold/warm difference and the studio gate on screen.
- [ ] **Publish, then watch** — alpha post per [`../plan/06-roadmap.md`](../plan/06-roadmap.md) §6.1; monitor the dogfood app for stale reads for a week (`oomycache:status` hit/miss/invalidations, hourly `oomycache:prune`, `oomycache:doctor` in bug reports).

---

## 3. Claim ledger (where every number comes from)

| Claim used in the assets | Source |
| --- | --- |
| Product cold 22.8 ms / 1 app query → warm 7.7 ms / 0 app queries; Supplier 20.3→5.4 ms; PurchaseOrder 24.0→7.6 ms (database store, limit 50, 3 warm samples) | [`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md) §2 |
| Warm Filament `/admin/products` render ≈0.50–0.70 s vs cold ≈0.77 s, 0 ERP-table queries asserted | same §2 notes; test named in [`../implementation/phase-1-dogfood.md`](../implementation/phase-1-dogfood.md) §3 |
| Front-office warm renders ≈0.22–0.38 s | [`../implementation/phase-2-progress.md`](../implementation/phase-2-progress.md) §2 |
| 12 cacheable models in the dogfood app | [`../implementation/phase-1-dogfood.md`](../implementation/phase-1-dogfood.md) §4 |
| Prune reference run: scanned 136 / expired 122, deleted in 469 ms; 23 cache rows ≈118 KB after prune | [`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md) §3 |
| Core suite 60 tests / 228 assertions; dogfood suite 161 tests / 578 assertions | [`../implementation/benchmark-and-launch.md`](../implementation/benchmark-and-launch.md) §1 |
| `can_purge` deny-by-default semantics and gate snippet | [`../../../OoMyCacheFilament/README.md`](../../../OoMyCacheFilament/README.md) §Security; [`../implementation/security-review.md`](../implementation/security-review.md) §3.1 |
| Conditional-alpha conditions (single-tenant, FPM or queue/Octane, raw/quiet/pivot guidance, Memcached/Octane gaps) | [`../implementation/security-review.md`](../implementation/security-review.md) (header verdict, §3.1, §6); [`../implementation/driver-matrix.md`](../implementation/driver-matrix.md) §5 |

If a feature lands after this file was written, update the ledger row first — then the asset.

---

## 4. What the assets deliberately do **not** claim

- ❌ "Stable" or "production-ready for multi-tenant billing" — this is the conditional alpha.
- ❌ Memcached/Octane/MySQL/PostgreSQL cache-store behaviour is verified — only Redis is live-verified; MySQL/PostgreSQL have database-cache-store smoke evidence; Memcached and Octane are CI gaps.
- ❌ A public demo URL — none is deployed yet.
- ❌ A specific hit ratio — hit ratio depends on the app's access pattern; only query-count/latency measurements are quoted.
- ❌ A guaranteed cold-path performance number — cold timings include cache fills and vary by machine.

## 5. Related documents

- [Launch readiness + benchmarks](../implementation/benchmark-and-launch.md) · [Security review](../implementation/security-review.md) · [Release checklist](../implementation/release-checklist.md) · [Driver matrix](../implementation/driver-matrix.md) · [Phase 2 progress](../implementation/phase-2-progress.md)
- [QA checklist](../plan/05-qa-checklist.md) · [Roadmap (alpha → beta → stable)](../plan/06-roadmap.md) · [Package README](../../README.md) · [Bridge README](../../../OoMyCacheFilament/README.md)
