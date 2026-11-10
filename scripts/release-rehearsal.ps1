#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Temp-only release rehearsal for the OoMyCache packages.

.DESCRIPTION
    Rehearses the mechanical parts of a release WITHOUT touching any real git
    repository, package source, or remote:

      1. copies packages/OoMyCache and packages/OoMyCacheFilament into a fresh
         temp directory (excluding vendor/, build/, .phpunit.cache/, .git/, and
         the root composer.lock);
      2. git init + initial commit + annotated tag (e.g. v0.1.0-alpha.1) in each
         temp copy, using a throwaway identity from the environment;
      3. validates the tag against composer.json (package name, homepage, no
         explicit version field), the CHANGELOG.md section (version or
         Unreleased), annotated-tag/commit shape and "newest tag" ordering;
      4. runs `composer validate --strict --no-check-lock` inside each temp copy
         with an isolated COMPOSER_HOME/COMPOSER_CACHE_DIR;
      5. parses both .github/workflows/release.yml files and asserts the v* tag
         trigger, the workflow_dispatch dry_run default (true), the job graph and
         release permissions;
      6. checks the bridge core-pin rules (dev-* pins before the first stable
         core tag; a tagged constraint for stable bridge releases), matching
         docs/implementation/release-checklist.md and the bridge workflow.

    Prints a PASS/WARN/FAIL summary and exits non-zero when a FAIL occurred.
    Temp copies are removed on success unless -KeepTemp is passed; they are kept
    on failure (and their path is printed) for debugging.

    Nothing is pushed and no git state is created inside the package
    directories: every git command runs with `-C <temp copy>`.

.PARAMETER Tag
    Annotated tag to create and validate. Must be SemVer with a leading "v".
    Default: v0.1.0-alpha.1

.PARAMETER TempRoot
    Parent directory for the rehearsal. Defaults to
    %TEMP%\oomycache-release-rehearsal-<timestamp>. Must not be inside the
    packages tree.

.PARAMETER KeepTemp
    Keep the temp copies even when the rehearsal passes.

.PARAMETER SkipComposer
    Skip the `composer validate` checks (offline/no-composer machines); the
    checks are reported as SKIP instead of FAIL.

.EXAMPLE
    pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1

.EXAMPLE
    pwsh -NoProfile -File packages/OoMyCache/scripts/release-rehearsal.ps1 `
        -Tag v0.1.0 -KeepTemp

.NOTES
    See docs/implementation/release-rehearsal.md for the full check list and the
    boundary between this rehearsal and the real GitHub release workflow.
#>

[CmdletBinding()]
param(
    [string]$Tag = 'v0.1.0-alpha.1',
    [string]$TempRoot,
    [switch]$KeepTemp,
    [switch]$SkipComposer
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$script:startedAt = Get-Date

# ---------------------------------------------------------------------------
# Result bookkeeping
# ---------------------------------------------------------------------------

$script:results = [System.Collections.Generic.List[object]]::new()
$script:passCount = 0
$script:failCount = 0
$script:warnCount = 0
$script:skipCount = 0

function Add-Result {
    param(
        [ValidateSet('PASS', 'WARN', 'FAIL', 'SKIP')]
        [string]$Status,
        [string]$Name,
        [string]$Detail = ''
    )

    $script:results.Add([pscustomobject]@{
        Status = $Status
        Name   = $Name
        Detail = $Detail
    })

    switch ($Status) {
        'PASS' { $script:passCount++ }
        'FAIL' { $script:failCount++ }
        'WARN' { $script:warnCount++ }
        'SKIP' { $script:skipCount++ }
    }

    $line = "[$Status] $Name"
    if ($Detail) {
        $line = "$line — $Detail"
    }
    Write-Host $line
}

function Run-Check {
    param(
        [string]$Name,
        [scriptblock]$Body
    )

    try {
        $result = & $Body
        if ($result -is [hashtable] -and $result.ContainsKey('Status')) {
            Add-Result -Status $result.Status -Name $Name -Detail ([string]($result['Detail'] ?? ''))
        }
        else {
            Add-Result -Status PASS -Name $Name -Detail ([string]$result)
        }
    }
    catch {
        Add-Result -Status FAIL -Name $Name -Detail $_.Exception.Message
    }
}

function Get-FirstMatch {
    param(
        [string]$Text,
        [string]$Pattern
    )

    $match = [regex]::Match($Text, $Pattern)
    if ($match.Success) {
        return $match
    }

    return $null
}

# ---------------------------------------------------------------------------
# Environment / path setup
# ---------------------------------------------------------------------------

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$packageRoot = (Resolve-Path -LiteralPath (Join-Path $scriptDir '..')).Path
$packagesRoot = (Resolve-Path -LiteralPath (Join-Path $packageRoot '..')).Path

$coreSource = Join-Path $packagesRoot 'OoMyCache'
$bridgeSource = Join-Path $packagesRoot 'OoMyCacheFilament'

if (-not (Test-Path -LiteralPath $coreSource)) {
    Write-Host "[FAIL] core package directory not found: $coreSource"
    exit 1
}
if (-not (Test-Path -LiteralPath $bridgeSource)) {
    Write-Host "[FAIL] bridge package directory not found: $bridgeSource"
    exit 1
}

if (-not $TempRoot -or $TempRoot.Trim() -eq '') {
    $TempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('oomycache-release-rehearsal-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
}

$tempFull = [System.IO.Path]::GetFullPath($TempRoot).TrimEnd('\', '/')
$packagesRootFull = $packagesRoot.TrimEnd('\', '/')

if ($tempFull.StartsWith($packagesRootFull, [System.StringComparison]::OrdinalIgnoreCase)) {
    Write-Host "[FAIL] Refusing to use a temp directory inside the packages tree: $tempFull"
    exit 1
}

$tagMatch = Get-FirstMatch -Text $Tag -Pattern '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
$version = $Tag.Substring(1)
$versionCore = $version.Split('+')[0]
$isPrerelease = $versionCore.Contains('-')

Write-Host ''
Write-Host 'OoMyCache — release rehearsal (temp-only)' -ForegroundColor Cyan
Write-Host "  package root : $packageRoot"
Write-Host "  tag          : $Tag (version $version, prerelease=$isPrerelease)"
Write-Host "  temp root    : $tempFull"
Write-Host ''

$gitVersion = (& git --version 2>&1 | Out-String).Trim()
$composerVersion = if (Get-Command composer -ErrorAction SilentlyContinue) { (& composer --version --no-interaction 2>&1 | Select-Object -First 1) } else { $null }
$pythonCommand = $null
foreach ($candidate in @('python', 'python3', 'py')) {
    if (Get-Command $candidate -ErrorAction SilentlyContinue) {
        $probe = & $candidate --version 2>&1
        if ($LASTEXITCODE -eq 0 -and ($probe -match 'Python 3')) {
            $pythonCommand = $candidate
            break
        }
    }
}

Write-Host "  git          : $gitVersion"
Write-Host "  composer     : $(if ($composerVersion) { $composerVersion } else { 'not found' })"
Write-Host "  python       : $(if ($pythonCommand) { $pythonCommand } else { 'not found (PHP fallback will be used for YAML)' })"
Write-Host ''

if (-not $tagMatch) {
    Write-Host "[FAIL] Tag [$Tag] is not SemVer with a leading v (expected vX.Y.Z[-prerelease])." -ForegroundColor Red
    Write-Host ''
    Write-Host 'Summary: 0 passed, 1 failed, 0 warnings (rehearsal FAILED)'
    exit 1
}

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Write-Host '[FAIL] git is not installed or not on PATH; it is required for the temp split/tag rehearsal.' -ForegroundColor Red
    Write-Host ''
    Write-Host 'Summary: 0 passed, 1 failed, 0 warnings (rehearsal FAILED)'
    exit 1
}

# ---------------------------------------------------------------------------
# Copy helper — prunes excluded directories instead of copying then filtering
# (vendor trees are huge; this keeps the rehearsal fast and temp-only)
# ---------------------------------------------------------------------------

function Copy-PackageTree {
    param(
        [string]$Source,
        [string]$Destination,
        [string[]]$ExcludedDirs,
        [string[]]$ExcludedRootFiles = @()
    )

    if (-not (Test-Path -LiteralPath $Destination)) {
        New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    }

    foreach ($item in Get-ChildItem -LiteralPath $Source -Force) {
        if ($item.PSIsContainer) {
            if ($ExcludedDirs -contains $item.Name) {
                continue
            }

            Copy-PackageTree -Source $item.FullName -Destination (Join-Path $Destination $item.Name) `
                -ExcludedDirs $ExcludedDirs -ExcludedRootFiles @()
        }
        else {
            if ($ExcludedRootFiles -contains $item.Name) {
                continue
            }

            Copy-Item -LiteralPath $item.FullName -Destination (Join-Path $Destination $item.Name) -Force
        }
    }
}

# ---------------------------------------------------------------------------
# Git helper — every command is scoped with `-C <temp copy>`, never the source
# ---------------------------------------------------------------------------

function Invoke-Git {
    param(
        [string]$Repo,
        [string[]]$GitArgs,
        [string[]]$Config = @(),
        [switch]$AllowFailure
    )

    $arguments = @('-C', $Repo)
    foreach ($entry in $Config) {
        $arguments += @('-c', $entry)
    }
    $arguments += $GitArgs

    $output = & git @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $text = (($output | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine).Trim()

    if (-not $AllowFailure -and $exitCode -ne 0) {
        throw "git $($GitArgs -join ' ') failed (exit $exitCode): $text"
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Output   = $text
    }
}

# ---------------------------------------------------------------------------
# CHANGELOG helper — mirrors the release workflow: exact version section first,
# then the Unreleased section; both "[x.y.z]" and "x.y.z" heading styles work.
# ---------------------------------------------------------------------------

function Get-ChangelogSection {
    param(
        [string]$ChangelogPath,
        [string]$Version
    )

    if (-not (Test-Path -LiteralPath $ChangelogPath)) {
        throw "CHANGELOG.md is missing at $ChangelogPath"
    }

    $lines = Get-Content -LiteralPath $ChangelogPath
    $headings = [System.Collections.Generic.List[object]]::new()

    for ($index = 0; $index -lt $lines.Count; $index++) {
        $line = $lines[$index]
        if ($line -match '^##\s+(.+?)\s*$') {
            $title = $Matches[1]
            $title = $title -replace '\[', '' -replace '\]', ''
            $title = $title -replace '\s+-\s+.*$', ''
            $headings.Add([pscustomobject]@{
                Index = $index
                Title = $title.Trim()
            })
        }
    }

    $versionWithoutBuild = $Version.Split('+')[0]
    $candidateTitles = @($version, $versionWithoutBuild, 'Unreleased')

    foreach ($candidate in $candidateTitles) {
        $heading = $headings | Where-Object { $_.Title -ieq $candidate } | Select-Object -First 1
        if ($null -eq $heading) {
            continue
        }

        $sectionLines = [System.Collections.Generic.List[string]]::new()
        $nextIndex = $lines.Count
        foreach ($later in $headings) {
            if ($later.Index -gt $heading.Index) {
                $nextIndex = $later.Index
                break
            }
        }

        for ($index = $heading.Index + 1; $index -lt $nextIndex; $index++) {
            $sectionLines.Add($lines[$index])
        }

        $text = ($sectionLines -join [Environment]::NewLine).Trim()
        if ($text.Length -gt 0) {
            return [pscustomobject]@{
                Source  = $candidate
                Heading = $heading.Title
                Length  = $text.Length
                Text    = $text
            }
        }
    }

    $available = ($headings | ForEach-Object { $_.Title }) -join ', '
    throw "CHANGELOG.md has no non-empty section for [$version] or [Unreleased] (headings: $available)"
}

# ---------------------------------------------------------------------------
# YAML validation helper — python + PyYAML when available, PHP + symfony/yaml
# as an offline fallback (both vendored configs already ship symfony/yaml).
# Returns a list of @{ Name; Ok; Detail } checks, throws when no parser works.
# ---------------------------------------------------------------------------

function Get-WorkflowChecks {
    param(
        [string[]]$WorkflowPaths,
        [string]$TempDirectory
    )

    $pythonScript = @'
import json
import os
import sys

import yaml

checks = []

def add(name, ok, detail=""):
    checks.append({"name": name, "ok": bool(ok), "detail": str(detail)})

for path in sys.argv[1:]:
    package_name = os.path.basename(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(path)))))
    prefix = os.path.basename(path) + " (" + package_name + ")"
    try:
        with open(path, encoding="utf-8") as handle:
            document = yaml.safe_load(handle)
    except Exception as exc:  # noqa: BLE001 - surfaced as a failed check
        add(prefix + ": YAML parse", False, str(exc))
        continue

    if not isinstance(document, dict):
        add(prefix + ": YAML document", False, "top-level YAML document is not a mapping")
        continue

    # PyYAML implements YAML 1.1 and parses the `on:` key as boolean True.
    normalized = {("on" if key is True else key): value for key, value in document.items()}

    add(prefix + ": has on/jobs", "on" in normalized and "jobs" in normalized, "top-level keys=" + ",".join(sorted(map(str, normalized.keys()))))

    triggers = normalized.get("on") or {}
    push_tags = ((triggers.get("push") or {}).get("tags")) or []
    add(prefix + ": push tags include v*", "v*" in push_tags, "push.tags=" + json.dumps(push_tags))

    dispatch = triggers.get("workflow_dispatch") or {}
    dry_run = ((dispatch.get("inputs") or {}).get("dry_run")) or {}
    add(prefix + ": workflow_dispatch dry_run default true", dry_run.get("default") is True, "dry_run.default=" + json.dumps(dry_run.get("default")))
    add(prefix + ": workflow_dispatch tag input", "tag" in (dispatch.get("inputs") or {}), "inputs=" + json.dumps(sorted((dispatch.get("inputs") or {}).keys())))

    jobs = normalized.get("jobs") or {}
    add(prefix + ": validate/test/release jobs", all(name in jobs for name in ("validate", "test", "release")), "jobs=" + json.dumps(sorted(jobs.keys())))

    release_job = jobs.get("release") or {}
    add(prefix + ": release job contents:write", ((release_job.get("permissions") or {}).get("contents")) == "write", "permissions=" + json.dumps(release_job.get("permissions")))

    test_job = jobs.get("test") or {}
    test_runs = "\n".join(str(step.get("run", "")) for step in (test_job.get("steps") or []))
    add(prefix + ": test job runs composer test", "composer test" in test_runs, "composer test present=" + str("composer test" in test_runs).lower())

    validate_job = jobs.get("validate") or {}
    validate_runs = "\n".join(str(step.get("run", "")) for step in (validate_job.get("steps") or []))
    add(prefix + ": validate job checks tag + CHANGELOG", ("CHANGELOG.md" in validate_runs) and ("rev-parse" in validate_runs or "git tag" in validate_runs), "tag/changelog guards present=" + str(("CHANGELOG.md" in validate_runs)).lower())

print(json.dumps({"checks": checks}))
'@

    $phpScript = @'
<?php
use Symfony\Component\Yaml\Yaml;

require $argv[1];

$checks = [];
$add = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$paths = array_slice($argv, 2);
foreach ($paths as $path) {
    $prefix = basename($path);
    try {
        $document = Yaml::parseFile($path);
    } catch (Throwable $exception) {
        $add($prefix . ': YAML parse', false, $exception->getMessage());
        continue;
    }

    if (! is_array($document)) {
        $add($prefix . ': YAML document', false, 'top-level YAML document is not a mapping');
        continue;
    }

    if (isset($document[true]) && ! isset($document['on'])) {
        $document['on'] = $document[true];
    }

    $add($prefix . ': has on/jobs', isset($document['on'], $document['jobs']), 'top-level keys=' . implode(',', array_keys($document)));

    $triggers = $document['on'] ?? [];
    $pushTags = $triggers['push']['tags'] ?? [];
    $add($prefix . ': push tags include v*', in_array('v*', $pushTags, true), 'push.tags=' . json_encode($pushTags));

    $dispatch = $triggers['workflow_dispatch'] ?? [];
    $dryRun = $dispatch['inputs']['dry_run'] ?? [];
    $add($prefix . ': workflow_dispatch dry_run default true', ($dryRun['default'] ?? null) === true, 'dry_run.default=' . json_encode($dryRun['default'] ?? null));
    $add($prefix . ': workflow_dispatch tag input', isset($dispatch['inputs']['tag']), 'inputs=' . json_encode(array_keys($dispatch['inputs'] ?? [])));

    $jobs = $document['jobs'] ?? [];
    $add($prefix . ': validate/test/release jobs', isset($jobs['validate'], $jobs['test'], $jobs['release']), 'jobs=' . json_encode(array_keys($jobs)));

    $releaseJob = $jobs['release'] ?? [];
    $add($prefix . ': release job contents:write', ($releaseJob['permissions']['contents'] ?? null) === 'write', 'permissions=' . json_encode($releaseJob['permissions'] ?? null));

    $testRuns = '';
    foreach ($jobs['test']['steps'] ?? [] as $step) {
        $testRuns .= ($step['run'] ?? '') . "\n";
    }
    $add($prefix . ': test job runs composer test', str_contains($testRuns, 'composer test'), 'composer test present=' . (str_contains($testRuns, 'composer test') ? 'true' : 'false'));

    $validateRuns = '';
    foreach ($jobs['validate']['steps'] ?? [] as $step) {
        $validateRuns .= ($step['run'] ?? '') . "\n";
    }
    $add($prefix . ': validate job checks tag + CHANGELOG', str_contains($validateRuns, 'CHANGELOG.md') && (str_contains($validateRuns, 'rev-parse') || str_contains($validateRuns, 'git tag')), 'changelog guard present=' . (str_contains($validateRuns, 'CHANGELOG.md') ? 'true' : 'false'));
}

echo json_encode(['checks' => $checks]);
'@

    $json = $null

    if ($pythonCommand) {
        $helperPath = Join-Path $TempDirectory 'validate-release-workflows.py'
        Set-Content -LiteralPath $helperPath -Value $pythonScript -Encoding utf8

        $output = & $pythonCommand $helperPath @WorkflowPaths 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Python YAML validation failed (exit $LASTEXITCODE): $(($output | Out-String).Trim())"
        }
        $json = ($output | Out-String).Trim()
    }
    else {
        $autoloadCandidates = @(
            (Join-Path $packagesRoot 'OoMyCache/vendor/autoload.php'),
            (Join-Path $packagesRoot '../vendor/autoload.php')
        )
        $autoload = $autoloadCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

        if ($autoload) {
            $helperPath = Join-Path $TempDirectory 'validate-release-workflows.php'
            Set-Content -LiteralPath $helperPath -Value $phpScript -Encoding utf8

            $phpCandidates = @('php')
            foreach ($candidate in $phpCandidates) {
                if (-not (Get-Command $candidate -ErrorAction SilentlyContinue)) {
                    continue
                }

                $phpVersion = & $candidate --version 2>&1
                if ($LASTEXITCODE -ne 0) {
                    continue
                }

                $output = & $candidate $helperPath $autoload @WorkflowPaths 2>&1
                if ($LASTEXITCODE -ne 0) {
                    throw "PHP YAML validation failed (exit $LASTEXITCODE): $(($output | Out-String).Trim())"
                }
                $json = ($output | Out-String).Trim()
                break
            }
        }
    }

    if (-not $json) {
        throw 'No YAML parser available: install Python 3 + PyYAML, or run with the package vendor/ (symfony/yaml) and PHP. Refusing to guess the release workflow triggers.'
    }

    $parsed = $json | ConvertFrom-Json
    $checks = [System.Collections.Generic.List[object]]::new()
    foreach ($check in $parsed.checks) {
        $checks.Add([pscustomobject]@{
            Name   = [string]$check.name
            Ok     = [bool]$check.ok
            Detail = [string]$check.detail
        })
    }

    return $checks
}

# ---------------------------------------------------------------------------
# Rehearsal body
# ---------------------------------------------------------------------------

$script:environmentBackup = @{}
$script:tempKept = $false
$script:finalExitCode = 1

try {
    # ---- safety: the source package dirs must not be git repositories ------
    Run-Check -Name 'source: no .git inside package directories (nothing to clobber)' -Body {
        $coreGit = Test-Path -LiteralPath (Join-Path $coreSource '.git')
        $bridgeGit = Test-Path -LiteralPath (Join-Path $bridgeSource '.git')
        if ($coreGit -or $bridgeGit) {
            throw "Unexpected .git directory in a source package (core=$coreGit bridge=$bridgeGit); refusing to rehearse."
        }
        'no source git state'
    }

    # ---- isolated environment for the temp copies --------------------------
    foreach ($name in @('GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL', 'GIT_TERMINAL_PROMPT', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_PROCESS_TIMEOUT')) {
        $script:environmentBackup[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }

    $env:GIT_AUTHOR_NAME = 'OoMyCache Release Rehearsal'
    $env:GIT_AUTHOR_EMAIL = 'rehearsal@example.invalid'
    $env:GIT_COMMITTER_NAME = 'OoMyCache Release Rehearsal'
    $env:GIT_COMMITTER_EMAIL = 'rehearsal@example.invalid'
    $env:GIT_TERMINAL_PROMPT = '0'
    $env:COMPOSER_HOME = Join-Path $tempFull 'composer-home'
    $env:COMPOSER_CACHE_DIR = Join-Path $tempFull 'composer-cache'
    $env:COMPOSER_PROCESS_TIMEOUT = '600'

    New-Item -ItemType Directory -Path $tempFull -Force | Out-Null
    New-Item -ItemType Directory -Path $env:COMPOSER_HOME -Force | Out-Null
    New-Item -ItemType Directory -Path $env:COMPOSER_CACHE_DIR -Force | Out-Null

    $repositories = @(
        [pscustomobject]@{
            Label            = 'core'
            Source           = $coreSource
            Directory        = (Join-Path $tempFull 'oomycache')
            ExpectedName     = 'osamaelnagar/oomycache'
            ExpectedHomepage = 'https://github.com/osamaelnagar/oomycache'
            ExpectedAutoload = 'Oomycache\Oomycache\'
        },
        [pscustomobject]@{
            Label            = 'bridge'
            Source           = $bridgeSource
            Directory        = (Join-Path $tempFull 'oomycache-filament')
            ExpectedName     = 'osamaelnagar/oomycache-filament'
            ExpectedHomepage = 'https://github.com/osamaelnagar/oomycache-filament'
            ExpectedAutoload = 'Oomycache\Filament\'
        }
    )

    foreach ($repository in $repositories) {
        $label = $repository.Label
        Write-Host ''
        Write-Host "— $label package —" -ForegroundColor DarkCyan

        Run-Check -Name "${label}: temp tree copied without vendor/cache artifacts" -Body {
            Copy-PackageTree -Source $repository.Source -Destination $repository.Directory `
                -ExcludedDirs @('vendor', 'build', '.phpunit.cache', '.git', 'node_modules', '.idea') `
                -ExcludedRootFiles @('composer.lock')

            $forbidden = @('vendor', 'build', '.phpunit.cache', 'node_modules') | Where-Object {
                Test-Path -LiteralPath (Join-Path $repository.Directory $_)
            }
            if ($forbidden.Count -gt 0) {
                throw "excluded path(s) were copied: $($forbidden -join ', ')"
            }

            $fileCount = (Get-ChildItem -LiteralPath $repository.Directory -Recurse -File -Force).Count
            "files=$fileCount destination=$($repository.Directory)"
        }

        Run-Check -Name "${label}: split tree has the release-relevant files at the root" -Body {
            $expected = @('composer.json', 'CHANGELOG.md', 'README.md', 'LICENSE.md', '.gitattributes', '.github/workflows/release.yml', '.github/workflows/tests.yml')
            $missing = $expected | Where-Object { -not (Test-Path -LiteralPath (Join-Path $repository.Directory $_)) }
            if ($missing.Count -gt 0) {
                throw "missing from the split copy: $($missing -join ', ')"
            }
            "$($expected.Count) release files present"
        }

        Run-Check -Name "${label}: temp copy initialised as a git repository on main" -Body {
            $init = Invoke-Git -Repo $repository.Directory -GitArgs @('init', '-b', 'main') -AllowFailure
            if ($init.ExitCode -ne 0) {
                # Older git without `-b`: init then point HEAD at main.
                Invoke-Git -Repo $repository.Directory -GitArgs @('init') | Out-Null
                Invoke-Git -Repo $repository.Directory -GitArgs @('symbolic-ref', 'HEAD', 'refs/heads/main') | Out-Null
            }

            Invoke-Git -Repo $repository.Directory -GitArgs @('add', '-A') | Out-Null
            Invoke-Git -Repo $repository.Directory -GitArgs @('commit', '-m', "chore: release rehearsal $Tag") | Out-Null

            $branch = Invoke-Git -Repo $repository.Directory -GitArgs @('rev-parse', '--abbrev-ref', 'HEAD')
            if ($branch.Output.Trim() -ne 'main') {
                throw "expected branch main, got [$($branch.Output.Trim())]"
            }
            $commitCount = Invoke-Git -Repo $repository.Directory -GitArgs @('rev-list', '--count', 'HEAD')
            "branch=main commits=$($commitCount.Output.Trim())"
        }

        Run-Check -Name "${label}: annotated tag $Tag created on HEAD" -Body {
            Invoke-Git -Repo $repository.Directory -GitArgs @('tag', '-a', $Tag, '-m', $Tag) | Out-Null

            $tagType = Invoke-Git -Repo $repository.Directory -GitArgs @('cat-file', '-t', "refs/tags/$Tag")
            if ($tagType.Output.Trim() -ne 'tag') {
                throw "tag [$Tag] is not annotated (git cat-file -t returned [$($tagType.Output.Trim())])"
            }

            $tagCommit = Invoke-Git -Repo $repository.Directory -GitArgs @('rev-parse', "$Tag^{commit}")
            $head = Invoke-Git -Repo $repository.Directory -GitArgs @('rev-parse', 'HEAD')
            if ($tagCommit.Output.Trim() -ne $head.Output.Trim()) {
                throw "tag [$Tag] does not point at HEAD"
            }

            $status = Invoke-Git -Repo $repository.Directory -GitArgs @('status', '--short')
            if ($status.Output.Trim()) {
                throw "temp working tree is dirty after tagging: $($status.Output)"
            }

            "annotated tag on $($head.Output.Trim().Substring(0, 7))"
        }

        Run-Check -Name "${label}: tag is the newest v* tag (versionsort, prerelease-aware)" -Body {
            $newest = Invoke-Git -Repo $repository.Directory -Config @('versionsort.suffix=-') -GitArgs @('tag', '--list', 'v*', '--sort=-v:refname')
            $first = ($newest.Output -split '\r?\n' | Where-Object { $_.Trim() } | Select-Object -First 1)
            if ($first -ne $Tag) {
                throw "expected [$Tag] as newest tag, got [$first]"
            }
            "newest=$first"
        }

        Run-Check -Name "${label}: no git remote configured (rehearsal cannot push)" -Body {
            $remotes = Invoke-Git -Repo $repository.Directory -GitArgs @('remote', '-v')
            if ($remotes.Output.Trim()) {
                throw "temp copy unexpectedly has a remote: $($remotes.Output)"
            }
            'no remote'
        }

        Run-Check -Name "${label}: composer.json identity matches the tag target" -Body {
            $composerPath = Join-Path $repository.Directory 'composer.json'
            $composer = Get-Content -LiteralPath $composerPath -Raw | ConvertFrom-Json

            if ([string]$composer.name -ne $repository.ExpectedName) {
                throw "composer.json name is [$($composer.name)], expected [$($repository.ExpectedName)]"
            }
            if ([string]$composer.homepage -ne $repository.ExpectedHomepage) {
                throw "composer.json homepage is [$($composer.homepage)], expected [$($repository.ExpectedHomepage)]"
            }
            if ($null -ne $composer.PSObject.Properties['version'] -and [string]$composer.version) {
                throw "composer.json declares version [$($composer.version)]; Composer packages must derive the version from the tag"
            }

            $phpConstraint = [string]$composer.require.php
            if ($phpConstraint -notmatch '8\.3') {
                throw "require.php is [$phpConstraint]; expected it to allow PHP 8.3+"
            }

            $autoloadKeys = @($composer.autoload.'psr-4'.PSObject.Properties.Name)
            $expectedAutoload = $repository.ExpectedAutoload
            if ($autoloadKeys -notcontains $expectedAutoload) {
                throw "autoload psr-4 keys [$($autoloadKeys -join ', ')] do not contain [$expectedAutoload]"
            }

            "name=$($composer.name) php=$phpConstraint"
        }

        Run-Check -Name "${label}: CHANGELOG has a promotable section for $Tag" -Body {
            $section = Get-ChangelogSection -ChangelogPath (Join-Path $repository.Directory 'CHANGELOG.md') -Version $version
            $note = if ($section.Heading -ieq 'Unreleased') { 'Unreleased will be promoted' } else { 'version section present' }
            "source=$($section.Heading) chars=$($section.Length) ($note)"
        }

        if ($SkipComposer) {
            Add-Result -Status SKIP -Name "${label}: composer validate --strict" -Detail 'skipped via -SkipComposer'
        }
        else {
            Run-Check -Name "${label}: composer validate --strict (isolated COMPOSER_HOME)" -Body {
                if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
                    throw 'composer is not installed or not on PATH'
                }

                $output = & composer --working-dir="$($repository.Directory)" validate --strict --no-check-lock --no-interaction 2>&1
                $exitCode = $LASTEXITCODE
                $text = (($output | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine).Trim()

                if ($exitCode -ne 0) {
                    throw "composer validate failed (exit $exitCode): $text"
                }

                $note = if ($text -match 'could not detect the root package') { 'root version not inferred from tag (informational)' } else { 'valid' }
                "exit=0 ($note)"
            }
        }
    }

    # ---- workflow YAML checks (both release workflows) ---------------------
    Write-Host ''
    Write-Host '— release workflow YAML —' -ForegroundColor DarkCyan

    Run-Check -Name 'release workflows: YAML parses and triggers/dry-run/permissions are correct' -Body {
        $workflowPaths = @(
            (Join-Path $coreSource '.github/workflows/release.yml'),
            (Join-Path $bridgeSource '.github/workflows/release.yml')
        )
        $checks = Get-WorkflowChecks -WorkflowPaths $workflowPaths -TempDirectory $tempFull

        $failedChecks = $checks | Where-Object { -not $_.Ok }
        foreach ($check in $checks) {
            $status = if ($check.Ok) { 'PASS' } else { 'FAIL' }
            Write-Host "    [$status] $($check.Name) — $($check.Detail)"
        }

        if ($failedChecks.Count -gt 0) {
            throw "$($failedChecks.Count) workflow YAML check(s) failed"
        }

        "$($checks.Count) workflow assertions across 2 repositories"
    }

    # ---- bridge core-pin rules ---------------------------------------------
    Write-Host ''
    Write-Host '— bridge pin rules —' -ForegroundColor DarkCyan

    Run-Check -Name 'bridge: core requirement exists and is not a wildcard' -Body {
        $bridgeComposer = Get-Content -LiteralPath (Join-Path $bridgeSource 'composer.json') -Raw | ConvertFrom-Json
        $coreRequirement = $bridgeComposer.require.'osamaelnagar/oomycache'
        if (-not $coreRequirement) {
            throw 'bridge composer.json does not require osamaelnagar/oomycache'
        }
        if ([string]$coreRequirement -eq '*') {
            throw 'bridge core requirement is a wildcard (*); pin the core package'
        }
        "require osamaelnagar/oomycache = [$coreRequirement]"
    }

    Run-Check -Name 'bridge: dev-* core pin allowed only while the tag is a prerelease' -Body {
        $bridgeComposer = Get-Content -LiteralPath (Join-Path $bridgeSource 'composer.json') -Raw | ConvertFrom-Json
        $coreRequirement = [string]$bridgeComposer.require.'osamaelnagar/oomycache'

        if ($coreRequirement -match 'dev-') {
            if ($isPrerelease) {
                return @{
                    Status = 'WARN'
                    Detail = "prerelease tag [$Tag] with dev-* core pin [$coreRequirement]; pin ^0.1 before the first stable bridge release"
                }
            }

            throw "stable bridge release [$Tag] must not pin the core as [$coreRequirement]; use a tagged constraint (e.g. ^0.1)"
        }

        "tagged core constraint [$coreRequirement]"
    }

    Run-Check -Name 'bridge: release workflow clones the core for its path dependency' -Body {
        $workflow = Get-Content -LiteralPath (Join-Path $bridgeSource '.github/workflows/release.yml') -Raw
        if ($workflow -notmatch 'osamaelnagar/oomycache\.git') {
            throw 'bridge release workflow does not clone osamaelnagar/oomycache for the path repository'
        }
        if ($workflow -notmatch 'LATEST_CORE_TAG') {
            throw 'bridge release workflow does not pin the newest core tag when one exists'
        }
        'core clone + newest-tag checkout present'
    }

    Run-Check -Name 'release-checklist documents the pinning rules and split commands' -Body {
        $checklist = Join-Path $coreSource 'docs/implementation/release-checklist.md'
        if (-not (Test-Path -LiteralPath $checklist)) {
            throw "release-checklist.md is missing at $checklist"
        }
        $text = Get-Content -LiteralPath $checklist -Raw
        foreach ($needle in @('git subtree split', 'dev-main', '^0.1', 'Packagist', 'Security Advisory')) {
            if (-not $text.Contains($needle)) {
                throw "release-checklist.md does not mention [$needle]"
            }
        }
        'split + pinning + Packagist + advisory guidance present'
    }
}
catch {
    Add-Result -Status FAIL -Name 'rehearsal aborted' -Detail $_.Exception.Message
}
finally {
    # Restore process environment variables.
    foreach ($name in $script:environmentBackup.Keys) {
        [Environment]::SetEnvironmentVariable($name, $script:environmentBackup[$name], 'Process')
    }

    if ($script:failCount -eq 0 -and -not $KeepTemp) {
        if (Test-Path -LiteralPath $tempFull) {
            Remove-Item -LiteralPath $tempFull -Recurse -Force -ErrorAction SilentlyContinue
        }
        $script:tempKept = $false
    }
    else {
        $script:tempKept = Test-Path -LiteralPath $tempFull
    }

    # ---- summary -----------------------------------------------------------
    $elapsed = [Math]::Round(((Get-Date) - $script:startedAt).TotalSeconds, 1)
    Write-Host ''
    Write-Host 'Summary:' -ForegroundColor Cyan
    Write-Host "  passed   : $script:passCount"
    Write-Host "  failed   : $script:failCount"
    Write-Host "  warnings : $script:warnCount"
    Write-Host "  skipped  : $script:skipCount"
    Write-Host "  elapsed  : $($elapsed)s"

    if ($script:tempKept -and (Test-Path -LiteralPath $tempFull)) {
        Write-Host "  temp kept: $tempFull"
    }
    else {
        Write-Host '  temp     : cleaned up (pass; use -KeepTemp to inspect)'
    }

    if ($script:failCount -gt 0) {
        Write-Host ''
        Write-Host 'Release rehearsal FAILED — fix the [FAIL] checks above before tagging.' -ForegroundColor Red
        $script:finalExitCode = 1
    }
    else {
        Write-Host ''
        Write-Host "Release rehearsal PASSED for $Tag (no real repository, remote or package file was modified)." -ForegroundColor Green
        $script:finalExitCode = 0
    }
}

exit $script:finalExitCode


