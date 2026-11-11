<#
.SYNOPSIS
    Benchmark OoMyCache warm reads across cache stores in a Laravel application.

.DESCRIPTION
    Runs `php artisan oomycache:bench` once per store and prints app-query, cache-store-query,
    total-query and timing columns. The script does not edit .env or config files.

    Run it from the Laravel application root, with config cache disabled:
        php artisan config:clear
        pwsh vendor/osamaelnagar/oomycache/scripts/benchmark-stores.ps1 "App\Models\Product"

.PARAMETER Model
    Fully-qualified cacheable model class (must use HasQueryCache).

.PARAMETER Limit
    Rows fetched per query.

.PARAMETER Samples
    Warm samples per store.

.PARAMETER Stores
    Stores to test. Defaults to array, file, database, redis, memcached.

.PARAMETER WithStats
    Benchmark with OOMYCACHE_STATS=true. Off by default so store reads are not polluted by stats.

.PARAMETER JsonPath
    Optional file to write the raw JSON results to.
#>
param(
    [Parameter(Position = 0)]
    [string] $Model = 'App\Models\Product',

    [int] $Limit = 50,
    [int] $Samples = 5,
    [string[]] $Stores = @('array', 'file', 'database', 'redis', 'memcached'),
    [switch] $WithStats,
    [string] $JsonPath = ''
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path 'artisan')) {
    throw 'Run this script from the Laravel application root (artisan not found).'
}

if (Test-Path 'bootstrap/cache/config.php') {
    throw 'Config cache is active. Run `php artisan config:clear` first; env overrides are ignored with cached config.'
}

$results = @()

# `pwsh -File script.ps1 ... -Stores file,redis` passes a single comma-joined
# string on the command line. Normalize it before looping.
if ($Stores.Count -eq 1 -and $Stores[0] -match ',') {
    $Stores = $Stores[0] -split ','
}

foreach ($store in $Stores) {
    $env:OOMYCACHE_STORE = $store
    $env:OOMYCACHE_STATS = if ($WithStats) { 'true' } else { 'false' }

    Write-Host "Benchmarking [$store]..." -ForegroundColor Cyan
    $raw = & php artisan oomycache:bench $Model --limit=$Limit --samples=$Samples --json 2>$null
    $exit = $LASTEXITCODE

    if ($exit -ne 0 -or [string]::IsNullOrWhiteSpace($raw)) {
        $results += [pscustomobject]@{
            Store = $store
            Status = 'ERROR'
            ColdApp = ''
            WarmApp = ''
            WarmStore = ''
            WarmTotal = ''
            WarmMs = ''
        }

        continue
    }

    try {
        $data = ($raw | Out-String) | ConvertFrom-Json
        $results += [pscustomobject]@{
            Store = $store
            Status = 'ok'
            ColdApp = $data.cold.app_queries
            WarmApp = $data.warm_avg_app_queries
            WarmStore = $data.warm_avg_store_queries
            WarmTotal = $data.warm_avg_total_queries
            WarmMs = $data.warm_avg_ms
        }
    } catch {
        $results += [pscustomobject]@{
            Store = $store
            Status = 'PARSE_ERROR'
            ColdApp = ''
            WarmApp = ''
            WarmStore = ''
            WarmTotal = ''
            WarmMs = ''
        }
    }
}

Remove-Item Env:OOMYCACHE_STORE, Env:OOMYCACHE_STATS -ErrorAction SilentlyContinue

$results | Format-Table -AutoSize

if ($JsonPath -ne '') {
    $results | ConvertTo-Json -Depth 5 | Set-Content -Path $JsonPath -Encoding utf8
    Write-Host "Wrote raw results to $JsonPath"
}

Write-Host ''
Write-Host 'Interpretation: app queries are eliminated DB reads; store queries are the cache store cost.' -ForegroundColor Gray
Write-Host 'Database store adds cache-table SQL; file/Redis/Memcached show 0 store queries.' -ForegroundColor Gray
