# OoMyCache — Research & Design Dossier

> **Codename:** OoMyCache (`osamaelnagar/oomycache`)
> **Status:** Phase 0 (research) — complete · blueprint (`plan/01`–`plan/06`) — complete and audited · Phase 1 (core engine + Filament/Livewire dogfood) — complete, green · **Phase 2 (tenancy core + Filament bridge extracted to `packages/OoMyCacheFilament` + EnvKit live validation) — complete slice; launch-readiness items remain.**
> **Delivered:** 2026-11-10 · 26 documents · ≈6560 lines · every competitor claim traceable to a pinned commit · launch assets, security review, driver matrix and release rehearsal included.

### Implementation

| File | Subject |
| --- | --- |
| [`implementation/phase-1-dogfood.md`](implementation/phase-1-dogfood.md) | What shipped in Phase 1, dogfood fixtures, verification evidence and the honest Phase-2 gap list. |
| [`implementation/phase-2-progress.md`](implementation/phase-2-progress.md) | Tenancy core, Filament bridge companion package, EnvKit live dumps/HTTP validation and current test totals. |
| [`implementation/benchmark-and-launch.md`](implementation/benchmark-and-launch.md) | Launch-readiness snapshot: 3-model benchmarks, payload/memory/ops notes, honest gaps and alpha→beta→stable sequence. |
| [`implementation/driver-matrix.md`](implementation/driver-matrix.md) | Local + CI cache-store/DB driver coverage, EnvKit recipes and known gaps (Memcached, Octane, Eloquent-on-MySQL/PG). |
| [`implementation/security-review.md`](implementation/security-review.md) | Launch security/edge-case review: key collisions, tenant leakage, stale reads, payload safety, DoS, commands, bridge risks. |
| [`implementation/release-checklist.md`](implementation/release-checklist.md) | Split-to-standalone-repo commands, version pinning, tag/release workflow, post-release smoke, rollback/yank. |
| [`implementation/release-rehearsal.md`](implementation/release-rehearsal.md) | Temp-only git split/tag rehearsal: 23 assertions (metadata, CHANGELOG, composer, workflow YAML, bridge pin), PASS/FAIL and safety model. |
| [`implementation/config-reference.md`](implementation/config-reference.md) | Every config key the code actually reads, removed/roadmap keys and the doctor validation rules. |
| [`implementation/operational-guardrails.md`](implementation/operational-guardrails.md) | Accepted M2/M4/M5 risks: raw/quiet/pivot writes, forever rows, cache-fill pressure, operator checklist. |

### Launch assets

| File | Subject |
| --- | --- |
| [`launch/README.md`](launch/README.md) | Launch asset index: pre-publish checklist, claim ledger (source for every number) and the claims the assets deliberately avoid. |
| [`launch/alpha-announcement.md`](launch/alpha-announcement.md) | Draft `v0.1.0-alpha.1` announcement for Filament/Laravel developers: problem, three differentiators, measured cold/warm numbers, install snippets, conditional-alpha caveats and feedback ask. |
| [`launch/demo-walkthrough.md`](launch/demo-walkthrough.md) | 5–10 minute demo script for the dogfood ERP: Filament table cold vs warm, Query Cache studio + purge gate, relation-manager invalidation, front office and CLI commands. |

This folder is the decision record for the package. It is intentionally evidence-based: every claim about a competitor is traceable to source code (path:line), and every design decision in `plan/` links back to research findings and to the constraints of our target environment.

---

## 1. What is in this dossier

### Research — competitor & platform analysis

| File | Subject | Answers |
| --- | --- | --- |
| [`research/00-comparison-matrix.md`](research/00-comparison-matrix.md) | Combined comparison | What does each package do, how healthy is it, which ideas do we adopt/avoid, where is the market gap? |
| [`research/01-laravel-cache-evict.md`](research/01-laravel-cache-evict.md) | `vectorial1024/laravel-cache-evict` | Cache-store housekeeping/eviction for file & database stores (not a query cache) — what operations ideas can we reuse? |
| [`research/02-laravel-cacheable-model.md`](research/02-laravel-cacheable-model.md) | `elipzis/laravel-cacheable-model` | Automatic query-based model cache — architecture, keying, invalidation, gaps. |
| [`research/03-laravel-model-caching.md`](research/03-laravel-model-caching.md) | `mike-bronner/laravel-model-caching` | The mature benchmark competitor — full architecture, every config option, edge cases, fragility. |
| [`research/04-smart-cache.md`](research/04-smart-cache.md) | `iazaran/smart-cache` | General-purpose cache optimization (compression, chunking, SWR, tags, dashboard, commands) — strategies to graft. |
| [`research/05-laracache.md`](research/05-laracache.md) | `mostafaznv/laracache` | Explicit cache entities refreshed on model events — DSL, queue/debounce, commands. |
| [`research/07-laravel-cache-internals.md`](research/07-laravel-cache-internals.md) | Laravel 13 cache layer | Store/driver matrix, tags, locks, SWR, serialization, Octane, performance envelopes. |

### Plan — the package we are going to build

| File | Subject | Answers |
| --- | --- | --- |
| [`plan/01-features.md`](plan/01-features.md) | Feature blueprint | What ships in v1 vs later, what differentiates us, what we deliberately refuse to do. |
| [`plan/02-configuration.md`](plan/02-configuration.md) | Configuration & flexibility | Full config schema, per-model overrides, builder API, extension points, precedence. |
| [`plan/03-architecture.md`](plan/03-architecture.md) | Architecture & considerations | Hooking strategy, key composition, invalidation algebra, drivers, tenancy, safety, performance, risks. |
| [`plan/04-filament-compatibility.md`](plan/04-filament-compatibility.md) | Filament 5 / Livewire 4 compatibility | Verified integration surface, tenant safety, invalidation matrix, bridge UX, compatibility contract, test plan. |
| [`plan/05-qa-checklist.md`](plan/05-qa-checklist.md) | QA & release gates | Test pyramid, adversarial test matrix, benchmark thresholds, compatibility CI, release checklist. |
| [`plan/06-roadmap.md`](plan/06-roadmap.md) | Implementation & launch roadmap | Phases, milestones, deliverables, dogfooding on the ERP, beta/launch plan. |

> The Filament/Livewire platform research that would have been a separate `research/06` annex is consolidated directly in `plan/04` (§0–§11) with vendor `path:line` evidence; keeping it in one authoritative document avoids two drifting copies.

---

## 2. Sources analysed (frozen references)

All competitors were cloned into `%TEMP%\laravel-cache-research` and analysed at the commits below. Reports and this dossier are only valid for these commits; re-run the analysis when upstream moves.

| Repo | Commit | Latest tag analysed | License |
| --- | --- | --- | --- |
| https://github.com/Vectorial1024/laravel-cache-evict | `f41952a` | 2.1.2 | MIT |
| https://github.com/elipZis/laravel-cacheable-model | `8fa6e4a` | v0.6.0 | MIT |
| https://github.com/mike-bronner/laravel-model-caching | `84d5b2e` | 13.2.2 | MIT |
| https://github.com/iazaran/smart-cache | `c526854` | 1.17.0 | MIT |
| https://github.com/mostafaznv/laracache | `2c18cf3` | 3.1.0 | MIT |

Health snapshot (LaraPlugins, 2026-11): model-caching ≈ 393k downloads; cacheable-model ≈ 63k; laracache ≈ 47k; smart-cache ≈ 24k; cache-evict ≈ 22k. All five are "healthy" except cache-evict ("medium"). None has a published security advisory.

---

## 3. Target environment (verified locally)

| Component | Version | Why it matters |
| --- | --- | --- |
| Package skeleton | `osamaelnagar/oomycache` at `packages/OoMyCache` | Laravel package skeleton the Laravel installer generated: Pest 5 + Orchestra Testbench, PHPStan level 7, 100% type coverage, arch tests, CI matrix PHP 8.3–8.5 × Laravel 12–13 × Ubuntu/Windows. |
| PHP (package constraint) | `^8.3` | Baseline syntax/API discipline for the package. |
| PHP (dogfood app) | 8.5 | First consumer's runtime. |
| Laravel (package) | `illuminate/support ^12.0 \|\| ^13.0` | Support matrix the plan must honour. |
| Laravel (dogfood app) | 13.40.0 | Source of truth for framework internals analysis. |
| Filament | 5.11.2 | #1 compatibility target (ERP admin panel). |
| Livewire | 4.4.8 | Filament's rendering engine; drives query re-execution. |
| Filament theme | `packages/filament-liquid-glass` (custom package, ~140 installs) | Any Filament UI we ship must survive custom themes and dark/light tokens. |

---

## 4. Methodology

1. **Clone & freeze** each competitor at a known commit; record tag, license, health.
2. **Read the source, not the marketing.** Architecture, cache-key construction, invalidation paths, config surface, tests and recent regression commits are all inspected; claims carry `path:line` references.
3. **Map every idea to a verdict** — Adopt / Adapt / Avoid — with payoff and effort, so the plan can be prioritised rather than "collect everything".
4. **Verify platform behaviour against the installed vendor tree**, not blog posts: Filament, Livewire and Laravel source in this app are the authority for compatibility claims.
5. **Design for the ERP first.** Correctness (no stale financial data, no cross-tenant leak) outranks hit ratio; performance claims must survive pagination, filters, widgets and relation managers.

---

## 5. Non-negotiables carried into every plan document

- **Core package has zero Filament/Livewire dependencies.** Filament compatibility is achieved through optional, auto-detected integration code (and, later, a companion bridge package) — never by making the core depend on a UI framework.
- **No vendored/forked framework internals.** We extend documented Eloquent extension points only (`newEloquentBuilder`, model events, cache contracts), so the package can follow Laravel 12/13/14 without a fork.
- **Safe by default for multi-tenant ERPs.** Tenant/panel identity is part of every cache key; caching is opt-in per model; transaction and write paths invalidate synchronously (or after-commit) rather than "eventually".
- **Works on every Laravel cache driver.** Versioned cache namespaces give O(1) invalidation on Redis, Memcached, database, file and array stores; tags are an optimisation, never a requirement.
- **Observable and operable.** Commands, events, health checks, a Filament dashboard and a documented operational model (warm, purge, deploy) are part of v1 scope, not an afterthought.

---

## 6. How to use this dossier

1. Read `research/00-comparison-matrix.md` for the landscape and the gap.
2. Read `plan/01-features.md` → `plan/06-roadmap.md` in order; they are the build contract.
3. During implementation, keep `plan/05-qa-checklist.md` open: a phase is done only when its gates pass.
4. If a competitor changes, re-run the relevant `research/NN` report and update the matrix; design docs that depend on the finding should be re-validated.

> These documents are internal engineering artifacts. If we do not want to ship them in the public package repository, move this folder out of the published tree (or add `docs/` to `.gitattributes export-ignore`) before v1.0.
