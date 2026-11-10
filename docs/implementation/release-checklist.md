# Release Checklist — `osamaelnagar/oomycache` (core + Filament bridge)

> **Applies to:** `osamaelnagar/oomycache` (this package) and the companion
> `osamaelnagar/oomycache-filament` (`packages/OoMyCacheFilament`).
> **Automation:** [`.github/workflows/release.yml`](../../.github/workflows/release.yml) (core),
> [`../../../OoMyCacheFilament/.github/workflows/release.yml`](../../../OoMyCacheFilament/.github/workflows/release.yml) (bridge).
> **Companion runbook:** [`../../../OoMyCacheFilament/RELEASING.md`](../../../OoMyCacheFilament/RELEASING.md).
> **Related:** [`../../README.md`](../../README.md) · [`../../CHANGELOG.md`](../../CHANGELOG.md) ·
> [`security-review.md`](security-review.md) · [`driver-matrix.md`](driver-matrix.md) ·
> [`../plan/05-qa-checklist.md`](../plan/05-qa-checklist.md) · [`../plan/06-roadmap.md`](../plan/06-roadmap.md) ·
> [`../../.github/SECURITY.md`](../../.github/SECURITY.md) · [`../../.github/CONTRIBUTING.md`](../../.github/CONTRIBUTING.md) ·
> [`.github/release.yml`](../../.github/release.yml) · [`.github/workflows/tests.yml`](../../.github/workflows/tests.yml) ·
> [`.github/workflows/update-changelog.yml`](../../.github/workflows/update-changelog.yml)
> **Last reviewed:** 2026-11-10.

This is the operator runbook: layout assumptions, pre-tag gates, exact tag commands, what the automation
does, Packagist behaviour, post-release smoke, rollback/yank and security advisories. A release must be
boring: every step below is either a command or a checkbox.

---

## 0. TL;DR — one release, nine steps

```bash
# 1. CI matrix green on main + dogfood app suite green (section 3)
# 2. Changelog: ## Unreleased section present and accurate (section 3)
# 3. From a clean main checkout of the standalone repo:
git fetch origin && git checkout main && git pull --ff-only
composer test                                     # final local gate
git tag -a v0.1.0-alpha.1 -m "v0.1.0-alpha.1"     # annotated, SemVer
git push origin v0.1.0-alpha.1                    # release.yml: validate -> composer test -> GitHub Release
# 4. Watch validate + test; fix forward with a NEW tag if validation fails
# 5. Verify the GitHub Release body (changelog section + generated notes)
# 6. Verify Packagist indexed the version (section 5)
# 7. Run the fresh-app smoke test (section 7)
# 8. Release the bridge only after the core version is on Packagist (section 6)
# 9. Record/announce, then watch the dogfood app for stale reads (section 9)
```

---

## 1. Repository layout assumption & the one-time split

**Current state (important):** both packages live *inside the OoMyCache dogfood application* at
`packages/OoMyCache` and `packages/OoMyCacheFilament`. Neither directory is a git repository, and the
dogfood app is not a git repository yet. GitHub therefore **cannot execute either release workflow
yet** — they are committed target-state automation for the standalone repositories:

| Source directory | Target repository | Packagist name |
| --- | --- | --- |
| `packages/OoMyCache` | `https://github.com/osamaelnagar/oomycache` | `osamaelnagar/oomycache` |
| `packages/OoMyCacheFilament` | `https://github.com/osamaelnagar/oomycache-filament` | `osamaelnagar/oomycache-filament` |

The split is a **one-time action** and must happen before the first public release. The workflows assume
`CHANGELOG.md`, `composer.json` and `.github/` sit at the standalone repository root (they already do
inside each package directory), and that release tags are cut from `main` (or `master`).

**Option A — the dogfood app becomes/has a git repository (per-package history preserved):**

```bash
# From the dogfood app repository root on main:
git subtree split -P packages/OoMyCache -b oomycache-standalone
git subtree split -P packages/OoMyCacheFilament -b oomycache-filament-standalone

git clone . ../oomycache && cd ../oomycache
git checkout oomycache-standalone
git remote set-url origin https://github.com/osamaelnagar/oomycache.git
git push -u origin HEAD:main
cd ..

git clone . ../oomycache-filament && cd ../oomycache-filament
git checkout oomycache-filament-standalone
git remote set-url origin https://github.com/osamaelnagar/oomycache-filament.git
git push -u origin HEAD:main
```

**Option B — there is no history to preserve: copy the tree and init.**

```bash
mkdir -p /tmp/oomycache && rsync -a --exclude vendor --exclude .phpunit.cache --exclude build \
  packages/OoMyCache/ /tmp/oomycache/
cd /tmp/oomycache && git init -b main && git add . \
  && git commit -m "Extract OoMyCache core from the dogfood app" \
  && git remote add origin https://github.com/osamaelnagar/oomycache.git \
  && git push -u origin main

# Bridge: same commands with packages/OoMyCacheFilament -> oomycache-filament.
```

After the split (both repositories): branch protection on `main` (tags from `main`, required CI);
submit the repository at <https://packagist.org/packages/submit> and enable the Packagist webhook
(section 5); copy the core `.github/release.yml` categories and the `update-changelog` workflow into the
bridge repo; add `docs/ export-ignore` to `.gitattributes` if the internal dossier must not ship in the
dist tarball. Keep the dogfood app on the local `path` repositories until the first tag, then switch it
to the published packages.

---

## 2. Versioning & pinning between core and bridge

SemVer. Before `1.0`, minor releases may include breaking changes — say so in the changelog. Prerelease
suffixes `-alpha.N` / `-beta.N` / `-rc.N` mark GitHub pre-releases and are **not** installable through a
plain `^0.1` constraint (Composer excludes prereleases unless the constraint opts in).

| Phase | Bridge `require` for `osamaelnagar/oomycache` | Rule |
| --- | --- | --- |
| Now (core has no tag) | `dev-main \|\| dev-master \|\| ^0.1` | Path repository in the dogfood app/bridge; bridge prerelease tags warn, stable tags are rejected by the workflow |
| First core tag (`v0.1.0-alpha.1`) | keep `dev-*` for the bridge alpha run, or `^0.1.0-alpha.1` (prerelease-aware) | A bare `^0.1` does **not** match a prerelease core version |
| First stable core tag (`v0.1.0`) | `^0.1` (remove `dev-*`) | Bridge stable tag requires a tagged core constraint; workflow enforces |
| Core `v1.0.0` | `^1.0` | Bump deliberately with the bridge's own major |

Release order is always **core → wait for Packagist → bridge**. The bridge release workflow fails a
stable tag while the core pin still contains `dev-*`, and its test job clones the core repository
(`https://github.com/osamaelnagar/oomycache.git`) next to the checkout, using the newest core tag when
one exists, so the path-repository setup keeps working during the transition.

---

## 3. Pre-tag gates (core)

Run from the core package root on the commit that will be tagged:

| Gate | Command / evidence | Blocking |
| --- | --- | --- |
| CI matrix on `main` | [`.github/workflows/tests.yml`](../../.github/workflows/tests.yml): PHP 8.3–8.5 × Laravel 12/13 × Ubuntu/Windows, `prefer-lowest` + `prefer-stable` | Yes |
| Full local gate | `composer validate --strict` then `composer test` (PHPStan L7 + Pint + 100% type coverage + Pest) | Yes |
| Dogfood integration | `php artisan test --compact` in the dogfood app (Filament resources/managers/pages/widgets, Livewire front office, tenancy, Redis) | Yes |
| Cache observability | `php artisan oomycache:status`, `oomycache:bench "App\Models\Product" --limit=50 --samples=3 --json`, `oomycache:prune --dry-run` | Yes |
| Changelog | `CHANGELOG.md` has an accurate `## Unreleased` section — see the normalization note below | Yes |
| Docs & evidence | [`security-review.md`](security-review.md) has no unresolved high/critical finding; [`driver-matrix.md`](driver-matrix.md) is current; all relative doc links resolve | Yes |
| Install docs | `README.md` install instructions match the versions being tagged (no `@dev` for stable) | Yes |
| License/metadata | `LICENSE.md` present; `composer.json` has no `version` field (the tag supplies it) | Yes |
| Security | `composer audit` clean; [`../../.github/SECURITY.md`](../../.github/SECURITY.md) contact current | Yes |

QA detail: [`../plan/05-qa-checklist.md`](../plan/05-qa-checklist.md); launch-readiness evidence:
[`benchmark-and-launch.md`](benchmark-and-launch.md).

**Changelog normalization:** both `CHANGELOG.md` files currently carry a working `## Unreleased`
heading, so **no edit is needed** (this task's normalization only applies if the heading is missing).
The release workflow prefers an exact `## [X.Y.Z]` section, falls back to `## Unreleased`, and accepts
both heading styles. Do not hand-rewrite release notes after publishing: the core
[`update-changelog` workflow](../../.github/workflows/update-changelog.yml) promotes the changelog after
the GitHub Release is published.

---

## 4. Tag and publish (core)

```bash
cd packages/OoMyCache                  # or the standalone clone
git fetch origin && git checkout main && git pull --ff-only
composer validate --strict && composer test
git status --short                     # must be empty

git tag -a v0.1.0-alpha.1 -m "v0.1.0-alpha.1"   # annotated tag (metadata survives on GitHub/Packagist)
git push origin v0.1.0-alpha.1                   # triggers release.yml
```

`release.yml` runs three jobs:

1. **validate** — tag is SemVer, is the newest `v*` tag, is an ancestor of `main`; `composer.json`
   name/homepage match the repository; no `version` field mismatch; `CHANGELOG.md` is promotable
   (version section or `Unreleased`); no GitHub Release exists for the tag.
2. **test** — full `composer test` on one runner (PHP 8.3). The version/OS matrix lives in
   [`tests.yml`](../../.github/workflows/tests.yml), which is a pre-tag gate, not part of the release job.
3. **release** — builds the body as `<changelog section>` + `releases/generate-notes` auto-generated
   notes, then `gh release create --verify-tag`: `--prerelease` for `-alpha/-beta/-rc` tags, `--latest`
   for stable tags. The workflow refuses to overwrite an existing release.

Rehearse first with the dry run (validates + runs tests, publishes nothing):

```bash
gh workflow run release.yml -f tag=v0.1.0-alpha.1 -f dry_run=true
gh run watch
```

If validation fails, **fix forward with a new version** — never delete and re-push a public tag.

---

## 5. Packagist auto-update

Packagist derives versions from git tags (`dev-main` from branches); it does not read a `composer.json`
version field. One-time setup per repository: submit the repo at
<https://packagist.org/packages/submit> and enable the Packagist GitHub webhook
(`https://packagist.org/api/update-package`). Afterwards a tag push is indexed automatically, usually
within a minute.

```bash
# Version list should contain the new tag (within a minute or two):
curl -s https://repo.packagist.org/p2/osamaelnagar/oomycache.json \
  | jq -r '.packages["osamaelnagar/oomycache"][].version' | head -n 5
curl -s https://repo.packagist.org/p2/osamaelnagar/oomycache-filament.json \
  | jq -r '.packages["osamaelnagar/oomycache-filament"][].version' | head -n 5

# Force an update if the webhook is delayed (requires a Packagist API token):
curl -s -X POST https://packagist.org/api/update-package \
  -d "username=osamaelnagar" -d "apiToken=$PACKAGIST_API_TOKEN" \
  -d '{"repository":"https://github.com/osamaelnagar/oomycache"}' | jq .
```

Consumers may cache the previous version list; after a yank/re-release ask them to
`composer clear-cache`. The bridge must consume the core from Packagist (not the `../OoMyCache` path
repository) in published versions.

---

## 6. Bridge release (after the core is indexed)

Full runbook: [`../../../OoMyCacheFilament/RELEASING.md`](../../../OoMyCacheFilament/RELEASING.md).

```bash
cd packages/OoMyCacheFilament
git fetch origin && git checkout main && git pull --ff-only
composer validate --strict && composer test
git tag -a v0.1.0-alpha.1 -m "v0.1.0-alpha.1"
git push origin v0.1.0-alpha.1
```

The bridge workflow additionally validates the core pin (stable tags must not use `dev-*`) and clones
the core next to the checkout for the test job. Never tag the bridge with a core pin that cannot
resolve — the release's composer install will fail and so will every consumer install.

---

## 7. GitHub Release checklist

The workflow creates the release; verify, don't recreate:

- [ ] Title is the tag; the release points at the intended commit.
- [ ] Body starts with the version heading + changelog section, followed by the auto-generated
      "What's Changed" notes.
- [ ] Grouping uses [`.github/release.yml`](../../.github/release.yml) categories
      (Breaking/Enhancements/Fixes/Docs/Dependencies/Maintenance). The bridge repo does not have this file
      yet — copy it when the bridge repo is created.
- [ ] Prerelease tags are marked "Pre-release"; stable tags are marked "Latest".
- [ ] After publishing, the core `update-changelog` workflow commits the promoted `CHANGELOG.md`; the
      bridge repo has no equivalent workflow yet — add the same action set there.
- [ ] Announce with the same changelog text (no hand-written divergence).

---

## 8. Post-release smoke (fresh Laravel app)

```bash
composer create-project laravel/laravel oomycache-smoke && cd oomycache-smoke

# Core
composer require osamaelnagar/oomycache:^0.1
php artisan vendor:publish --tag=oomycache-config
# use Oomycache\Oomycache\Concerns\HasQueryCache; on one model, then:
php artisan oomycache:status
php artisan oomycache:doctor
php artisan oomycache:bench "App\Models\User" --limit=10 --samples=2   # warm app_queries must be 0
php artisan oomycache:prune --dry-run
php artisan oomycache:clear --model="App\Models\User"

# Filament bridge (only for a Filament 5 app)
composer require filament/filament:^5.0 osamaelnagar/oomycache-filament:^0.1
# ->plugin(OomycachePlugin::make()) on a panel; php artisan filament:make-user; open the Query Cache page
```

Exit criteria: `composer require` resolves without `dev-*`, config publishes, `status/doctor/bench/prune`
behave, a warm read is served from cache, the bridge page renders status/cacheable models and its
invalidate/purge actions work.

---

## 9. Rollback / yank

Published tags are immutable; **prefer a follow-up patch release**. Yank only for a broken or unsafe
artifact that must not be installable.

```bash
# Delete the GitHub Release (and optionally the remote tag):
gh release delete v0.1.0-alpha.1 --yes
git push origin :refs/tags/v0.1.0-alpha.1

# Force Packagist to refresh its metadata (or wait for the webhook), see section 5.
```

- Packagist drops the version on the next update; consumers that already resolved it keep it in
  `composer.lock` until they update — publish `vX.Y.Z+1` and tell them to upgrade.
- Re-pushing a tag that was already consumed breaks release integrity (GitHub keeps tag object caches,
  Packagist may keep the dist zip). Only delete-and-recreate when nothing consumed it (same hour, not
  yet listed on Packagist).
- A broken bridge can often be fixed with a bridge patch release; never change the pinned core
  constraint inside an already-published version.
- Keep `composer.lock` files out of both library repositories (they are gitignored).

---

## 10. Security advisories

1. Private report through GitHub → repository → **Security** → **Advisories** → *Report a vulnerability*
   (also reachable via the email in [`../../.github/SECURITY.md`](../../.github/SECURITY.md)).
2. Confirm the affected versions against the git tags, fix on a private branch, run the full gate
   (section 3), then publish the GitHub Security Advisory and request a CVE through GitHub's flow.
3. Tag the fixed release first, then publish the advisory; reference the advisory ID in the release
   notes. Packagist mirrors GitHub advisories — confirm `composer audit` reports the vulnerable range:

   ```bash
   composer audit
   curl -s https://packagist.org/api/security-advisories/ | jq '.advisories["osamaelnagar/oomycache"]'
   ```

4. If the bridge is affected, publish a **separate** advisory for
   `osamaelnagar/oomycache-filament` with its own affected range (reference the core advisory ID when
   the root cause is in the core).
5. Add a `SECURITY.md` pointer in the bridge repository if it does not have one yet.

---

## 11. Assumptions & open gaps

| # | Assumption / gap | Consequence |
| --- | --- | --- |
| 1 | Both packages live inside the dogfood app and are not standalone git repos | The committed workflows are target-state; GitHub runs them only after the split in section 1 |
| 2 | The release workflows have never run (no repository/tags yet) | The first real release must be rehearsed with `gh workflow run release.yml -f tag=… -f dry_run=true` |
| 3 | The bridge repo lacks `.github/release.yml` and the `update-changelog` workflow | Copy the core files when the bridge repo is created, otherwise bridge notes are flat and its changelog is promoted manually |
| 4 | Bridge `composer.json` still uses a `../OoMyCache` path repository and `dev-main` pin | Must become a Packagist constraint before the first stable bridge release (workflow enforces) |
| 5 | No signed tags / SBOM / provenance | Optional hardening: `git tag -s`, attach an SBOM artifact to the release |
| 6 | Packagist submission and the webhook are manual one-time actions | Track them as setup tasks when the repos are created |
| 7 | The bridge `tests.yml` clones the core with `--depth=1` on `main` | Update it to the tag-aware clone used by the release workflow when the dev pin is removed |

**Local validation of the workflow files** (run before merging workflow edits in either repository):

```bash
python - <<'PY'
import yaml
with open(".github/workflows/release.yml", encoding="utf-8") as fh:
    doc = yaml.safe_load(fh)
assert "jobs" in doc          # PyYAML (YAML 1.1) parses the `on:` key as boolean True
print("release.yml: YAML OK")
PY
```
