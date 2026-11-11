#!/usr/bin/env bash
# Benchmark OoMyCache warm reads across cache stores in a Laravel application.
#
# Run from the Laravel application root, with config cache disabled:
#   php artisan config:clear
#   vendor/osamaelnagar/oomycache/scripts/benchmark-stores.sh "App\\Models\\Product"
#
# Usage: benchmark-stores.sh [model] [limit] [samples] [store...]
set -euo pipefail

MODEL="${1:-App\\Models\\Product}"
LIMIT="${2:-50}"
SAMPLES="${3:-5}"
shift $(( $# >= 3 ? 3 : $# )) || true
STORES=("$@")

if [ ${#STORES[@]} -eq 0 ]; then
    STORES=(array file database redis memcached)
fi

if [ ! -f artisan ]; then
    echo "Run this script from the Laravel application root (artisan not found)." >&2
    exit 1
fi

if [ -f bootstrap/cache/config.php ]; then
    echo "Config cache is active. Run 'php artisan config:clear' first; env overrides are ignored with cached config." >&2
    exit 1
fi

printf '%-12s %-10s %-10s %-10s\n' "store" "warm_app" "warm_store" "warm_ms"
printf '%-12s %-10s %-10s %-10s\n' "------" "--------" "----------" "-------"

for store in "${STORES[@]}"; do
    output="$(OOMYCACHE_STORE="$store" OOMYCACHE_STATS=false php artisan oomycache:bench "$MODEL" --limit="$LIMIT" --samples="$SAMPLES" --json 2>/dev/null || true)"

    if [ -z "$output" ]; then
        printf '%-12s %-10s %-10s %-10s\n' "$store" "ERROR" "-" "-"
        continue
    fi

    app="$(printf '%s' "$output" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["warm_avg_app_queries"] ?? "-";' 2>/dev/null || echo '-')"
    store_q="$(printf '%s' "$output" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["warm_avg_store_queries"] ?? "-";' 2>/dev/null || echo '-')"
    total_q="$(printf '%s' "$output" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["warm_avg_total_queries"] ?? "-";' 2>/dev/null || echo '-')"
    ms="$(printf '%s' "$output" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["warm_avg_ms"] ?? "-";' 2>/dev/null || echo '-')"

    printf '%-12s %-10s %-10s %-10s (total=%s)\n' "$store" "$app" "$store_q" "$ms" "$total_q"
done

echo
echo "Interpretation: app queries are eliminated DB reads; store queries are the cache store cost."
echo "Database store adds cache-table SQL; file/Redis/Memcached show 0 store queries."
