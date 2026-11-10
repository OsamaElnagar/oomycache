# Release Rehearsal — temp-only split, tag and metadata dry run

> **Script:** [`../../scripts/release-rehearsal.ps1`](../../scripts/release-rehearsal.ps1) ·
> **Real release flow:** [`release-checklist.md`](release-checklist.md) ·
> [`.github/workflows/release.yml`](../../.github/workflows/release.yml) ·
> [`.github/workflows/tests.yml`](../../.github/workflows/tests.yml) ·
> **Package:** `osamaelnagar/oomycache` (+ `osamaelnagar/oomycache-filament`)

The rehearsal is a **safe, offline, temp-only** dry run of the mechanical parts of a release: it copies
both package directories into a throwaway directory, turns each copy into a fresh git repository,
creates the release tag, and asserts everything the release workflow will later assert (tag shape,
composer identity, CHANGELOG section, bridge pin rules, workflow triggers). It exists so that the first
real `git push origin v0.1.0-alpha.1` is not the first time the metadata is exercised.

```bash
pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1
```

---

## 1. What it proves — and what it does not

| Proves (offline, in temp copies) | Does **not** prove |
| --- | --- |
| Both package trees split cleanly into a standalone repository root (composer.json, CHANGELOG, `LICENSE.md`, `.github/` present; no `vendor/`, `build/`, `.phpunit.cache/`, `composer.lock` leak) | GitHub runners, action versions, `GITHUB_TOKEN` permissions, or branch protection |
| `git init` + initial commit + **annotated** tag work, the tag points at HEAD, is the newest `v*` tag, and the temp repo has **no remote** | That the tag is pushed to the real repository (the script never pushes) |
| The tag matches the package identity: `composer.json` name/homepage, no explicit `version` field, PHP `^8.3` constraint, autoload namespace | Composer/Packagist resolution of the published metadata, Packagist indexing or webhook delivery |
| `CHANGELOG.md` has a non-empty promotable section (exact version, else `Unreleased`) — same rule the workflow uses | GitHub Release creation, release-note formatting, or the post-release changelog promotion workflow |
| `composer validate --strict --no-check-lock` succeeds in each split copy with an isolated `COMPOSER_HOME` | `composer test` execution, the PHP/Laravel CI matrix, or dependency installation |
| Both `.github/workflows/release.yml` files parse and declare: `v*` tag trigger, `workflow_dispatch` with `dry_run` default `true`, `validate`/`test`/`release` jobs, `release` job `contents: write`, a `test` job running `composer test`, and a validate job guarding tag + CHANGELOG | The workflow actually running, YAML expressions evaluating, or GitHub's `on:` semantics |
| The bridge pin rules from [`release-checklist.md`](release-checklist.md) §2: `dev-*` pins are allowed for prerelease tags only (warning) and **rejected** for stable tags; the bridge workflow clones the core for its path dependency | That Packagist has a core version for the bridge's constraint to resolve against |
| The package directories have no git state to clobber, and the rehearsal temp directory is never inside the packages tree | Anything under real `.git/` because none exists — the script refuses to run if one appears |

Everything happens in `%TEMP%`; nothing is pushed, no remote is configured, and no file inside
`packages/` is written. The only network-shaped actions are avoided entirely — `composer validate`
does not install anything, and the workflow YAML is parsed from local files.

---

## 2. Prerequisites

| Tool | Needed for | If missing |
| --- | --- | --- |
| PowerShell 7 (`pwsh`) | running the script | use `pwsh` from the Herd/PowerShell install (Windows PowerShell 5.1 is not targeted) |
| `git` 2.28+ | temp repositories, annotated tags, versionsort | the script FAILs early with a clear message |
| `composer` 2.x | `composer validate --strict` per split copy | use `-SkipComposer` to run the metadata/git checks only (reported as SKIP) |
| Python 3 + PyYAML | primary workflow YAML parser | falls back to PHP + `symfony/yaml` from `packages/OoMyCache/vendor` or the app's `vendor/` |
| PHP | fallback YAML parser only | FAIL "No YAML parser available" (refuses to guess the trigger contract) |

The script sets throwaway git author/committer identity (`OoMyCache Release Rehearsal
<rehearsal@example.invalid>`) and isolated `COMPOSER_HOME` / `COMPOSER_CACHE_DIR` under the temp
directory, then restores the previous process environment on exit. It does not read or modify your
global git config.

---

## 3. Usage

```bash
# Default: rehearse v0.1.0-alpha.1 and clean up on success
pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1

# Rehearse the first stable tag instead (expected to FAIL until the bridge core pin is changed)
pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1 -Tag v0.1.0

# Keep the temp copies for inspection (composer.json, .git, CHANGELOG checks)
pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1 -KeepTemp

# Pin a specific temp location / skip composer on a machine without it
pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1 -TempRoot C:\temp\oomycache-rehearsal -SkipComposer
```

Parameters: `-Tag` (SemVer with `v` prefix, default `v0.1.0-alpha.1`), `-TempRoot`, `-KeepTemp`,
`-SkipComposer`.

**Exit codes:** `0` = rehearsal passed (no FAIL; WARN/SKIP are allowed and counted); `1` = at least one
FAIL or a fatal setup problem. Failed rehearsals keep the temp directory and print its path so the
copy can be inspected.

---

## 4. What each check means

| Group | Checks | Reading it |
| --- | --- | --- |
| Safety | no `.git` in the package dirs; temp path outside `packages/`; no git remote in temp copies | a FAIL here means the rehearsal would not be temp-only — stop and inspect |
| Split shape | copy excludes `vendor/`, `build/`, `.phpunit.cache/`, `node_modules/`, `.git/`, root `composer.lock`; required release files exist at the split root | a FAIL means the package tree is not ready to become a standalone repo |
| Git rehearsal | branch `main`, exactly one commit, annotated tag on HEAD, clean working tree, tag is newest `v*` (`versionsort.suffix=-`, prerelease-aware), no remote | a FAIL here mirrors the `validate` job's tag/branch/ordering checks |
| Composer identity | name = `osamaelnagar/oomycache` / `…-filament`, homepage matches, no `version` field, `require.php` allows 8.3, PSR-4 namespace present | this is the metadata Packagist will publish |
| CHANGELOG | non-empty exact-version section, else non-empty `Unreleased` section (heading styles `## [x.y.z]` and `## x.y.z` both accepted) | the release body source; `Unreleased` is reported as "will be promoted" |
| Composer validate | `composer validate --strict --no-check-lock` exits 0 per split copy | mirrors the manifest validity the workflow assumes |
| Workflow YAML | `push.tags` contains `v*`; `workflow_dispatch.inputs.dry_run.default == true`; `tag` input exists; `validate`/`test`/`release` jobs; `release` job `contents: write`; test job runs `composer test`; validate job guards tag + `CHANGELOG.md` | protects the release automation contract; a FAIL means the workflow file drifted |
| Bridge pin rules | core requirement exists, is not `*`; `dev-*` only allowed for prerelease tags (WARN) and rejected for stable tags (FAIL); bridge workflow clones the core and pins the newest core tag | the rule documented in `release-checklist.md` §2 and enforced by the bridge release workflow |

Example of the WARN that is expected today (core not yet tagged):

```text
[WARN] bridge: dev-* core pin allowed only while the tag is a prerelease — prerelease tag
[v0.1.0-alpha.1] with dev-* core pin [dev-main || dev-master || ^0.1]; pin ^0.1 before the first
stable bridge release
```

The WARN becomes a FAIL as soon as `-Tag v0.1.0` (stable) is rehearsed: that is the gate that forces the
bridge `composer.json` pin to move to a tagged constraint before the first stable bridge release.

---

## 5. Expected output (reference run)

```text
OoMyCache — release rehearsal (temp-only)
  tag          : v0.1.0-alpha.1 (version 0.1.0-alpha.1, prerelease=True)
[PASS] source: no .git inside package directories (nothing to clobber) — no source git state
[PASS] core: temp tree copied without vendor/cache artifacts — files=129 ...
[PASS] core: split tree has the release-relevant files at the root — 7 release files present
[PASS] core: annotated tag v0.1.0-alpha.1 created on HEAD — annotated tag on 3a52067
[PASS] core: tag is the newest v* tag (versionsort, prerelease-aware) — newest=v0.1.0-alpha.1
[PASS] core: CHANGELOG has a promotable section for v0.1.0-alpha.1 — source=Unreleased chars=2994 ...
[PASS] core: composer validate --strict (isolated COMPOSER_HOME) — exit=0 (valid)
... bridge package: same checks ...
[PASS] release workflows: YAML parses and triggers/dry-run/permissions are correct — 16 workflow assertions across 2 repositories
[WARN] bridge: dev-* core pin allowed only while the tag is a prerelease — ...
[PASS] bridge: release workflow clones the core for its path dependency — core clone + newest-tag checkout present

Summary:
  passed   : 23
  failed   : 0
  warnings : 1
  skipped  : 0
  elapsed  : 11.3s
  temp     : cleaned up (pass; use -KeepTemp to inspect)

Release rehearsal PASSED for v0.1.0-alpha.1 (no real repository, remote or package file was modified).
```

Negative runs behave as designed: `-Tag 0.1.0` fails immediately (not SemVer with `v`), and
`-Tag v0.1.0` fails only the bridge pin check (stable tag + `dev-*` core pin) — both exit `1`.

---

## 6. How it complements the real flow

1. **Before tagging:** run the rehearsal after changing `composer.json`, `CHANGELOG.md` or either
   `release.yml` — it catches the metadata/tag/trigger drift without touching GitHub.
2. **Real gate:** still run `composer test` locally and wait for the full CI matrix
   ([`tests.yml`](../../.github/workflows/tests.yml)), then follow
   [`release-checklist.md`](release-checklist.md) sections 3–4 (tag push → workflow `validate` → `test`
   → GitHub Release).
3. **After the split:** the script keeps working from either package location because it copies both
   sibling directories relative to its own path — it does not require the packages to remain inside the
   dogfood app.
4. **Maintenance:** the workflow assertions mirror the `validate` job contract. When the release
   workflow gains a new invariant, add a corresponding check here (and update the table above) so the
   rehearsal and the automation cannot drift apart.

---

## 7. Safety model (why this cannot break a release)

- Every git command is executed as `git -C <temp copy> …`; the script **refuses to run** if a package
  directory contains a `.git` directory, and enforces that the temp root is outside `packages/`.
- Temp copies are created by a pruning copy (excluded directories are never entered), so `vendor/`
  trees are not even listed.
- No remote is ever added; a check asserts `git remote -v` is empty in every temp copy.
- Composer runs with a temp `COMPOSER_HOME`/`COMPOSER_CACHE_DIR` and only `validate`
  (`--no-check-lock`), so no install/lock/network side effects.
- The source package files are read-only inputs; the only files the rehearsal writes are inside its temp
  directory (removed on success unless `-KeepTemp`, kept on failure for debugging).
- Environment variables set by the script are restored in a `finally` block.
