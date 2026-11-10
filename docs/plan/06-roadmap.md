# 06 — OoMyCache Implementation & Launch Roadmap

> **Status:** v1.0 proposal. Phase 0 (research) is complete; this document sequences the build.
> **Reads with:** [`05-qa-checklist.md`](05-qa-checklist.md) (gates), [`03-architecture.md`](03-architecture.md) §17 (build order), [`04-filament-compatibility.md`](04-filament-compatibility.md) (bridge scope).

---

## 0. Strategy

- **Vertical slices, not big-bang.** Each phase ends with a working package in the ERP-plus-sandbox loop, green gates and a tagged internal milestone. The package is useful (early alpha) before Filament UI exists.
- **Correctness before features.** Invalidation/tenancy/payload safety land in Phase 1–2. The Filament bridge and UI only start once the core is trustworthy.
- **Dogfood before public launch.** The ERP is the acceptance test: if a page shows stale invoices, we stop and fix the algebra before writing marketing copy.
- **Launch in public increments** (alpha → beta → stable) using the same playbook as the Filament Liquid Glass theme: a real product with a demo, docs and a compelling before/after story.
- **Bridge stays optional.** Core package must be usable by any Laravel app; Filament features are auto-detected and additionally wrapped in a dedicated bridge namespace/package decision (open decision O-2).

**Effort model:** ranges are engineering effort for a small team (Osama + AI agent), assuming the package skeleton is ready. Calendar time depends on how many hours/week are committed.

---

## 1. Milestones at a glance

| Milestone | Phase | Primary artefacts | Exit gate (summary) |
| --- | --- | --- | --- |
| **M0 — Research & blueprint** ✅ | 0 | This dossier (`docs/research/**`, `docs/plan/**`) | User sign-off on plan/01–03 scope; ERP target confirmed |
| **M1 — Foundation slice** | 1 | Config, contracts, version store, trait, caching builder (`get/first`), model-event invalidation, arrays payload, minimal commands | Demo model cached & invalidated in Testbench; core tests + arch/phpstan/pint/type coverage green |
| **M2 — Full query coverage** | 2 | Aggregates, pagination, classification, guardrails, dependency tracking, payload modes, driver matrix, transactions, tenancy contract, events | `.qa` core matrix (plan/05 §3) green on array/file/database; Redis/Memcached green in service lane |
| **M3 — ERP dogfoodable** | 2.5 | Filament bridge (tenant resolver, purge page/actions), E2E suite, benchmark harness, docs v0 | Filament E2E lane green; installed in ERP; warm table pages execute 0 data+count queries; no correctness incidents in observation window; bench numbers recorded |
| **M4 — Public alpha (v0.1.x)** | 3 | Packagist release, README, quickstart, demo app, migration guide skeleton | Fresh install works; docs quickstart validated; CI matrix green incl. Windows |
| **M5 — Public beta (v0.5.x)** | 4 | Filament UI polish (widget, command palette, warmup), docs site extension, community feedback loop | No open critical/high correctness issues; beta users report credible hit-rate wins |
| **M6 — v1.0 stable** | 5 | Freeze feature set, hardening release, launch assets (blog, benchmarks, demo video) | plan/05 release checklist complete; 30-day dogfood window with zero stale-data incidents; user approval to tag/release |

---

## 2. Phase 0 — Research & blueprint ✅ (current)

**Deliverables**

- `docs/research/01..07` deep analyses with evidence.
- `docs/research/00-comparison-matrix.md` landscape + gap analysis.
- `docs/plan/01..06` implementation contracts.
- Skeleton metadata corrected (author email `osamaelngar98@gmail.com`; package name `osamaelnagar/oomycache`).

**Exit criteria**

- [x] All five competitor repos cloned and analysed at pinned commits.
- [x] Laravel 13 cache internals + Filament 5 / Livewire 4 integration surfaces verified against installed vendor code.
- [ ] User signs off on: package name/tagline, core-vs-bridge split, ERP target repo, launch channel plan (see §8 open decisions).

---

## 3. Phase 1 — Foundation vertical slice (M1)

**Goal:** prove the interception + invalidation algebra end-to-end for the simplest read path (`get()`, `first()`), on the array store, with all gates wired.

| Workstream | Deliverables |
| --- | --- |
| Package plumbing | Publishable `config/oomycache.php` (minimal v0 keys), `QueryCacheable` contract, `QueryCacheConfig` resolution (global→preset→model→query), config validator, `Oomycache` manager, contract bindings, skeleton commands removed/renamed. |
| Cache core | `QueryContext`, `CacheDecision`/reason enum, canonical binding serialiser, `KeyGenerator` (xxh128 + fallback), `VersionStore`, `InvalidationManager` (after-commit), capability probe. |
| Eloquent slice | `HasQueryCache` trait + `QueryCacheBuilder` overriding `get()`/`first()`; `dontCache`/`cacheFor`; classifier with guardrails (transaction, lock, streaming, search, unhashable binding). |
| Payload | `ArrayPayload` encode/decode with relation tree for eager-loaded results (single-level first), corrupt/size guards, payload format version. |
| Invalidation | Wildcard model event listeners (`saved/deleted/restored/forceDeleted`), version bump, after-commit; mass-write overrides (`update/delete/increment/decrement`). |
| Tests | Unit: key/canonicaliser/config/classifier/version store; Feature: get/first hit-miss, save/delete/mass-write invalidation, transaction rollback/commit, arrays round-trip; extend ArchTest. |
| Docs | README quickstart skeleton, config reference start, invalidation model explanation start. |

**Exit gate**

- [ ] `composer test` green on PHP 8.5 + lowest PHP lane.
- [ ] Demo Testbench model: second `get()` executes 0 queries; after `save()`/`Model::where()->update()`/rollback/commit, data correctness proven by tests.
- [ ] `oomycache:status` prints config + version tokens + cacheable models; `oomycache:clear` bumps token.
- [ ] No Laravel 12/13 incompatibility in CI prefer-lowest lane.

**Effort:** 3–5 engineering days.

---

## 4. Phase 2 — Full query coverage & correctness (M2)

**Goal:** complete the P0 feature set in plan/01 without the Filament UI; this is the package's "engine complete" point.

| Workstream | Deliverables |
| --- | --- |
| Reads | `count/exists/doesntExist/sum/avg/min/max`, `value/pluck`, `sole/firstOrFail/find/findOrFail/findMany`, `paginate/simplePaginate/cursorPaginate` with count caching + paginator rebuild, Filament's default `toBase()->getCountForPagination()` total cached via the base-builder interception, negative caching (P1), `cacheIf()` conditional caching. |
| Classification | Policy-driven classifier complete; `relations` modes; search-pattern skipping; payload-size guard; `QueryCacheBypassed` reason events; explain output. |
| Large payloads | Chunked payload protocol (versioned chunk keys, wrapper, race-safe re-read, old-version cleanup), compression wrapping chunks, driver-limit awareness (P1). |
| Stampede | Optional single-flight lock on hot-key misses with unlocked fallback; deterministic TTL jitter that never extends TTL (P1). |
| Dependencies | `DependencyRecorder` overrides (`withCount/withSum/withAvg/withMin/withMax/withExists`, `has/whereHas/whereRelation/whereBelongsTo`), nested relation resolution, closure fingerprinter, morph policy, `dependencies`/`cacheWith`. |
| Payload | `models` mode (internal serialisation + allow-list), arrays mode relation fidelity (pivots, morphs), compression, per-request memo. |
| Stores | Driver matrix support + tests (array/file/database; Redis/Memcached service lane), tags-as-optimisation, capability probing + doctor warnings, failure fallbacks. |
| Tenancy | `TenantResolver` contract + default auto-detection hook for Filament (without bridge UI), tenant/panel/user segments, adversarial isolation tests. |
| Safety | Transaction reads bypass, after-commit invalidation, invalidation-failure policy, config validation, kill switch, console handling. |
| Observability | Events, `status`, `doctor`, `bench`, `clear --model/--tenant`; stats collector scaffold (off by default). |
| Docs | Full README + docs pages: installation, configuration reference, invalidation model, safety/limitations, driver guide, upgrade guide skeleton. |

**Exit gate**

- [ ] Full plan/05 §3 core matrix green (all P0 + agreed P1 rows).
- [ ] Driver matrix green; Redis tag/no-tag and database/file fallbacks verified.
- [ ] Cross-tenant adversarial tests green; no P0 security finding open.
- [ ] Benchmark shows ≥70% DB-query reduction on a seeded 100k-row Filament-like table; cold-path regression < 3%.
- [ ] `oomycache:doctor` validates a realistic production config and flags known traps.

**Effort:** 7–10 engineering days.

---

## 5. Phase 3 — Filament bridge & ERP dogfood (M3)

**Goal:** make the package feel native in Filament and prove it on the heavy ERP. Bridge scope is defined by plan/04.

| Workstream | Deliverables |
| --- | --- |
| Bridge boot | Optional auto-detected bridge service provider / plugin `OoMyCachePlugin`; Filament/Livewire version guard; tenant resolver from `Filament::getTenant()`; per-panel config. |
| Invalidation safety net | Bridge flushes after Filament bulk actions and relation-manager attach/detach/sync where core events cannot see them; verified against installed Filament 5.11 source. |
| UI | Cache page (status, model/version table, purge buttons with policy), per-resource purge action trait, stats widget (P1); theme-agnostic (Filament components only) so it renders correctly under `filament-liquid-glass`. Filament 5.11 has no command palette, so no palette integration is planned. |
| Authorization | Gate/permission wiring for viewing/purging; tests for unauthorized access. |
| E2E tests | plan/05 §4 F1–F18 scoped to v0: index/pagination/search/filter/bulk actions/tenant switch/widgets/purge authorization/custom theme. Filament test lane in CI. |
| ERP dogfood | Install in the ERP project (confirm target), enable on a small set of heavy resources + dashboard widgets, add `oomycache:bench` baseline, monitor Laravel logs/Telescope and hit/miss stats; iterative fixes. |
| Docs | Filament cookbook, performance story with real ERP numbers, migration guide from `mike-bronner/laravel-model-caching`. |

**Exit gate**

- [ ] Filament E2E lane green on Filament 5.11 + Livewire 4.4.
- [ ] ERP observation window (≥ 2 weeks / defined traffic) with **zero** stale-data and zero tenant-isolation incidents.
- [ ] Measured ERP improvements: query count and p95 page latency for at least 3 heavy resources + 2 widgets documented.
- [ ] Cache management UI usable by a panel operator without CLI access; purge requires authorization.
- [ ] No Filament classes referenced by core; bridge removable without breaking core.

**Effort:** 5–7 engineering days + observation window (calendar time).

---

## 6. Phase 4 — Public alpha → beta launch

### 6.1 v0.1.x alpha (after M2)

Purpose: get early technical feedback and validate install DX; limited announcement in personal channels/Filament Discord.

Checklist:

- [ ] Packagist name/repo live (`osamaelnagar/oomycache`), README badges, LICENSE, security policy, issue templates (skeleton has them).
- [ ] `config/oomycache.php` publishable + documented; quickstart tested in a fresh Laravel 13 app.
- [ ] `oomycache:doctor` in the README quickstart; clear "not for financial data without reading limitations" section.
- [ ] Demo repo (small Laravel + Filament app with seeded heavy data) — P1.
- [ ] Changelog from v0.1.0; GitHub release automation (skeleton `release.yml`/`update-changelog.yml`) verified.
- [ ] Semver policy: v0.x breaks allowed with docs; v1.0 freezes config/builder API.

### 6.2 v0.5.0 beta (after M3)

Purpose: broader adoption with the Filament story. This is the launch moment comparable to the Liquid Glass theme launch.

Launch assets:

| Asset | Detail |
| --- | --- |
| README hero | One-paragraph promise, before/after query counts from the ERP, 30-second quickstart, Filament section, safety section, benchmark table. |
| Docs | Install/config/invalidation/Filament cookbook/limitations/upgrade; searchable static site (P1) or well-structured GitHub docs. |
| Demo app | Publicly reachable Filament panel with seeded large dataset + cache debug widget; maybe deployed on a free host with SQLite/Redis. |
| Benchmark post | "How we removed N% of the DB queries from a Filament ERP" — reproducible numbers, honest caveats. |
| Launch posts | Laravel News submission, Filament community Discord/GitHub discussions, X thread, Reddit r/laravel, Laracasts forum, dev.to. Cross-post from the Liquid Glass audience. |
| Video/GIF | 60–90s: table page before/after, cache page, purge flow (dark/light theme) — reuses the custom theme story. |
| Support | Issue templates requesting app/Laravel/Filament versions, store, config snippet, `oomycache:doctor` output; response SLA documented. |

### 6.3 v1.0.0 stable (after M5)

- Feature freeze: no new config keys, no API breaks without major.
- 30-day dogfood window + beta feedback triage; all critical/high issues closed.
- Independent code review pass (security + Laravel idiom) before tagging.
- Tag/release only with explicit user approval (skeleton `package-release` skill rule).

---

## 7. Phase 5 — Post-1.0 roadmap (candidates)

| Candidate | Value | Trigger to schedule |
| --- | --- | --- |
| Explicit cache-entity DSL (laracache-inspired: named closures for dashboard aggregates) | Escape hatch beyond builder queries | ≥3 user requests |
| Filament 4 / Livewire 3 compatibility lane | Wider adoption | Filament 4 users ask |
| `laravel/pulse` card + Prometheus metrics exporter | Observability | stats adoption proves useful |
| Warmup jobs per Filament resource / panel | Cold-start after deploy/bulk import | ERP needs it |
| Chunked large-payload store + adaptive TTL | Very large relations | bench shows payload ceiling issues |
| Read/write replica consistency mode | Scale | multi-DB user request |
| DB-write detector (statement listener in debug mode) | Raw-SQL safety | common support issue |
| Relationship preloading hints per Filament resource | DX | dogfood shows repeated N+1 |

---

## 8. Open decisions (need user input before/at start of Phase 1)

| # | Decision | Recommendation |
| --- | --- | --- |
| O-1 | Final package name/tagline (currently `osamaelnagar/oomycache` / "OoMyCache") | Keep short name; tagline "Eloquent query caching built for Filament-scale data". |
| O-2 | Filament bridge packaging: (a) classes inside the same package auto-detected, (b) separate `osamaelnagar/oomycache-filament` package | **(a) for v1** — bridge sub-namespace + `suggest` + isolated dev-dependency test lane (plan/04 §8); extract to (b) later if the bridge grows or the core needs a leaner dependency surface. |
| O-3 | ERP target repo for dogfooding | Confirm which project (e.g. `erpsaas`) is the acceptance environment. |
| O-4 | Launch channel mix and timing | Follow the Liquid Glass playbook: Filament community first, Laravel News second. |
| O-5 | Primary production store guidance | Redis recommended; database/file supported via version namespaces; document `laravel-cache-evict` for housekeeping. |
| O-6 | Package versioning tooling | Git tags + skeleton changelog workflow (already present). |

---

## 9. Risk register (top 10)

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Stale-data bug reaches ERP/production | Medium | Critical (financial) | Phase 2 gate + adversarial matrix + version algebra tests + 2-week dogfood + hotfix policy; document "never cache inside transactions" |
| Cross-tenant leak via key composition | Low-Medium | Critical (security) | Tenant segment mandatory when tenanting detected; adversarial tests; security review before beta; fail-closed on unresolved tenant |
| Laravel 13 `serializable_classes` or cache internals change | Medium | High | arrays default; internal allow-list for models; capability probe; CI against Laravel 14 nightly |
| Filament 6/Livewire 5 breaking integration | Medium | High | bridge isolated; version guard; core unaffected; dual support lanes |
| Payload size/serialisation causes memory/latency regression | Medium | Medium | size guard, compression, pagination guidance, benchmark gates |
| Invalidation storms in write-heavy ERP | Medium | Medium | O(1) bumps; optional coalescing/debounce (laracache-inspired) P1; after-commit batching |
| Query classification caches low-hit-ratio search queries | Medium | Low-Medium | skip search by default; stats show hit ratio; config patterns |
| Low adoption / unclear differentiation | Medium | Medium | Filament-native story + ERP numbers; launch assets; comparison table in README |
| Maintenance/support burden for one maintainer | Medium | Medium | narrow v1 feature surface, `doctor`, issue templates, docs, GitHub Discussions |
| Framework-package conflict (other cache packages) | Low | High | coexist detection in doctors; fail fast; migration guide |

---

## 10. Phase exit checklists & release process

Each phase ends with:

1. Demo in Testbench/app with the newly added capability and a benchmark note.
2. `composer test` green; CI matrix green including Windows.
3. Plan/05 gates for the touched rows checked off in the PR description.
4. Docs updated in the same PR (no "docs later" for user-facing behaviour).
5. CHANGELOG entry + milestone tag in git (internal `v0.x.y` patches as needed, public tags only at M4/M5/M6).

Release process (per skeleton `package-release` skill):

1. Review `CHANGELOG.md`, generated release notes, open diff.
2. Run `composer test` and the full compatibility matrix.
3. Confirm no unapproved dependency changes.
4. Prepare version/tag commands; **do not tag/push/publish without explicit user approval**.
5. After release: Packagist webhook check, demo app update, announcement (if launch milestone), 72h incident watch.

---

## 11. Success metrics (reviewed at M4/M5/M6)

| Metric | Target | Source |
| --- | --- | --- |
| DB query reduction on Filament ERP pages | ≥ 70% for opted-in resources | `oomycache:bench` + query log |
| Warm p95 latency improvement (ERP) | measurable, documented per resource/widget | ERP instrumentation |
| Stale-data incidents | 0 in dogfood window; 0 critical after release | issue tracker / logs |
| Tenant-leak findings | 0 | security tests + review |
| Install time to first cached query | ≤ 5 min | quickstart validation |
| Compatibility matrix | 100% green incl. Windows | CI |
| Packagist installs after beta launch | first 100 in week 1, 500+ in month 1 (aspirational) | Packagist |
| Community issues with actionable info | ≥ 80% include doctor output | issue templates |

---

## 12. Immediate next steps after user sign-off

1. Resolve §8 open decisions (name/tagline, bridge packaging, ERP target).
2. Create the Phase-1 implementation task list from plan/03 §17 with a dependency DAG; open a feature branch.
3. Build the foundation slice (M1) with tests-first; daily `composer test`.
4. Wire the demo model/route in the Filament app for manual verification.
5. Review M1 with the user, then proceed to Phase 2.

**No implementation code should be written before the user confirms plan/01–03 scope and the open decisions in §8; the research phase is complete and this is the contract.**
