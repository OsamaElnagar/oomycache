<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turn the package off without touching code. Reads run directly against
    | the database, no invalidation listeners fire and the bridge reports a
    | disabled state.
    |
    */

    'enabled' => env('OOMYCACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache store & namespace
    |--------------------------------------------------------------------------
    |
    | store: null = Laravel's default cache store; or a store name.
    | prefix: logical key prefix for everything the package writes.
    | version: payload/deploy version baked into every key. Bump it when the
    |          cached payload format or the cached model shape changes.
    |
    */

    'store' => env('OOMYCACHE_STORE'),
    'prefix' => env('OOMYCACHE_PREFIX', 'oomycache'),
    'version' => env('OOMYCACHE_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | TTL defaults
    |--------------------------------------------------------------------------
    |
    | ttl: default entry lifetime in seconds.
    | ttl_jitter: fraction of the TTL removed at random per entry (0–0.5) to
    |             avoid synchronized expiry. Jitter never extends the TTL.
    |
    */

    'ttl' => (int) env('OOMYCACHE_TTL', 300),
    'ttl_jitter' => (float) env('OOMYCACHE_TTL_JITTER', 0.10),

    /*
    |--------------------------------------------------------------------------
    | Invalidation strategy
    |--------------------------------------------------------------------------
    |
    | Invalidation is always version-token based (connection + table). Tag
    | flushing is not implemented and there is no strategy switch: correctness
    | never depends on the cache store supporting tags.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Automatic caching policy
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'reads' => true,
        'aggregates' => true,
        'pagination' => true,
        'single_records' => true,
        'value_and_pluck' => true,
        'negative' => false,
        'negative_ttl' => 10,
        'search_terms' => false,
        'skip_query_patterns' => [],
        'skip_models' => [],

        // Invalidate both table namespaces when a cacheable model's
        // belongsToMany pivot is attached/detached/synced. Pivot writes do not
        // fire Eloquent model events; this closes that invalidation hole.
        'pivot_invalidation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety & correctness guardrails
    |--------------------------------------------------------------------------
    */

    'safety' => [
        'skip_reads_in_transactions' => true,
        'invalidate_after_commit' => true,
        'intercept_mass_writes' => true,
        'skip_in_console' => false,
        'fail_silently' => true,
        'log_invalidation_failures' => true,
        'max_payload_bytes' => 1_048_576,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload representation
    |--------------------------------------------------------------------------
    */

    'payload' => [
        // Only the arrays-JSON codec is implemented. Set to anything other
        // than "arrays" and oomycache:doctor fails; there is no models codec.
        'mode' => env('OOMYCACHE_PAYLOAD_MODE', 'arrays'),
        'compression' => [
            'enabled' => env('OOMYCACHE_COMPRESSION', false),
            'threshold_bytes' => 65_536,

            /*
            |------------------------------------------------------------------
            | Decompression safety cap
            |------------------------------------------------------------------
            |
            | Maximum size (bytes) a compressed payload may expand to. Enforced
            | during decompression (max_length where the zlib function supports
            | it) and after decompression; oversized/bomb payloads raise
            | UnexpectedValueException, which the engine treats as a corrupt
            | miss. Payloads above this cap are also never compressed on write.
            | 0 disables the cap.
            |
            */

            'max_bytes' => (int) env('OOMYCACHE_COMPRESSION_MAX_BYTES', 2_097_152),
            'algorithm' => env('OOMYCACHE_COMPRESSION_ALGO', 'gzip'),
        ],

        /*
        |----------------------------------------------------------------------
        | Chunked payloads
        |----------------------------------------------------------------------
        |
        | Encoded+compressed payloads larger than chunk_bytes are split into
        | `{$key}:c:{$index}` keys with a manifest under `{$key}`. All keys
        | share the same TTL and are read back in one batch.
        |
        | max_chunks guards runaway entries: when a payload would need more
        | chunks than this, the query is executed but not cached (a
        | `payload-too-large` QueryCacheBypassed event is dispatched).
        |
        | max_total_bytes is the M1 hard cap for the reconstructed payload: a
        | stored payload above it always bypasses, even when max_chunks would
        | allow it; on read a manifest declaring a larger size is treated as
        | corrupt and cleaned up. 0 disables the cap.
        |
        | With chunking enabled the effective stored-payload ceiling is
        | chunk_bytes × max_chunks, further capped by max_total_bytes. Disable
        | chunking to restore the simple safety.max_payload_bytes cap.
        |
        */

        'chunking' => [
            'enabled' => env('OOMYCACHE_CHUNKING', true),
            'chunk_bytes' => (int) env('OOMYCACHE_CHUNK_BYTES', 262_144),
            'max_chunks' => (int) env('OOMYCACHE_MAX_CHUNKS', 64),
            'max_total_bytes' => (int) env('OOMYCACHE_CHUNK_MAX_TOTAL_BYTES', 2_097_152),
        ],

        'memoize_per_request' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy & user scoping
    |--------------------------------------------------------------------------
    */

    'tenant' => [
        // Class implementing Oomycache\Oomycache\Contracts\TenantResolver.
        // Filament apps normally leave this null and use the bridge resolver.
        'resolver' => env('OOMYCACHE_TENANT_RESOLVER'),

        // Include the authenticated user id (hashed) as a key segment; only
        // used by the Filament bridge resolver.
        'include_user' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    */

    'events' => [
        'enabled' => true,
        'hits' => true,
        'misses' => true,
        'invalidations' => true,
    ],

    'stats' => [
        'enabled' => (bool) env('OOMYCACHE_STATS', false),
        'store' => env('OOMYCACHE_STATS_STORE'),
        'ttl' => 3600,
        'sample_rate' => 1.0,
    ],

    'commands' => [
        'register' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    |
    | oomycache:prune only supports database cache stores. The command is
    | keyset-ordered and expiry-guarded: a row refreshed by another request
    | between scan and delete is never removed. Schedule it e.g. hourly:
    |
    |   Schedule::command('oomycache:prune')->hourly()->withoutOverlapping();
    |
    | sleep_us adds a micro-pause between keyset pages to reduce lock pressure
    | on busy cache tables.
    |
    */

    'maintenance' => [
        'prune' => [
            'chunk' => 100,
            'max_seconds' => 5.0,
            'sleep_us' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Named presets (P1)
    |--------------------------------------------------------------------------
    */

    'presets' => [
        'reference' => [
            'ttl' => 3600,
            'cache_aggregates' => true,
        ],
        'financial' => [
            'ttl' => 60,
            'cache_single_records' => true,
            'negative' => false,
        ],
        'dashboard' => [
            'ttl' => 120,
            'cache_aggregates' => true,
        ],
    ],

    'model_presets' => [],

    /*
    |--------------------------------------------------------------------------
    | Filament bridge (inert unless Filament is installed; P2)
    |--------------------------------------------------------------------------
    */

    'filament' => [
        'dashboard' => [
            // Gate names read by the companion bridge package. can_purge is
            // deny-by-default: define the gate before destructive actions
            // become visible. can_view optionally restricts the studio page.
            'can_view' => null,
            'can_purge' => null,

            // Nav placement for the studio page. Keep null to inherit the
            // panel's default group; set a string to place it explicitly, and
            // set sort to a lower number to move it higher.
            'navigation_label' => 'Query Cache',
            'navigation_group' => null,
            'navigation_sort' => null,
        ],
    ],

];
