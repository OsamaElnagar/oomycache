<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Console\Commands;

use Illuminate\Console\Command;
use Oomycache\Oomycache\Cache\QueryCacheEngine;
use Oomycache\Oomycache\Contracts\TenantResolver;

class DoctorCommand extends Command
{
    protected $signature = 'oomycache:doctor';

    protected $description = 'Diagnose OoMyCache configuration and store capabilities.';

    public function handle(QueryCacheEngine $engine): int
    {
        $status = $engine->status();
        $issues = $this->configIssues();
        $warnings = [];

        $this->components->info('OoMyCache doctor');

        $this->table(['Check', 'Result'], [
            ['package enabled', $status['enabled'] ? 'OK' : 'DISABLED'],
            ['cache store', (string) $status['store']],
            ['store class', $status['capabilities']['store']],
            ['payload mode', (string) $status['payload_mode'].($status['payload_mode'] === 'arrays' ? ' (Laravel 13 default safe)' : '')],
            ['store tags', $status['capabilities']['taggable'] ? 'supported (not used)' : 'not supported (version tokens only — safe)'],
            ['store locks', $status['capabilities']['lockable'] ? 'supported' : 'not supported'],
            ['payload compression', ($status['payload_compression']['enabled'] ?? false) ? 'enabled' : 'disabled'],
            ['payload chunking', ($status['payload_chunking']['enabled'] ?? false) ? 'enabled' : 'disabled'],
            ['cacheable models', (string) count($engine->cacheableModels())],
        ]);

        foreach ($issues as $issue) {
            $this->components->error($issue);
        }

        if (! $status['enabled']) {
            $warnings[] = 'OoMyCache is disabled (OOMYCACHE_ENABLED=false).';
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($issues !== []) {
            return self::FAILURE;
        }

        if ($warnings === []) {
            $this->components->info('No configuration issues or warnings.');
        }

        return self::SUCCESS;
    }

    /**
     * Validation for settings that would otherwise fail silently at runtime.
     *
     * @return list<string>
     */
    private function configIssues(): array
    {
        $issues = [];

        $payloadMode = (string) config('oomycache.payload.mode', 'arrays');

        if ($payloadMode !== 'arrays') {
            $issues[] = "payload.mode [{$payloadMode}] is not implemented; only the arrays-JSON codec is supported.";
        }

        $ttl = (int) config('oomycache.ttl', 300);

        if ($ttl < 1) {
            $issues[] = "ttl must be >= 1 second; got [{$ttl}].";
        }

        $jitter = (float) config('oomycache.ttl_jitter', 0.1);

        if ($jitter < 0.0 || $jitter > 0.5) {
            $issues[] = "ttl_jitter must be between 0 and 0.5; got [{$jitter}].";
        }

        $compression = (array) config('oomycache.payload.compression', []);
        $algorithm = (string) ($compression['algorithm'] ?? 'gzip');

        if (! in_array($algorithm, ['gzip', 'deflate', 'zstd'], true)) {
            $issues[] = "payload.compression.algorithm [{$algorithm}] must be one of gzip, deflate, zstd.";
        }

        foreach (['threshold_bytes', 'max_bytes'] as $key) {
            $value = (int) ($compression[$key] ?? 0);

            if ($value < 0) {
                $issues[] = "payload.compression.{$key} must be >= 0; got [{$value}].";
            }
        }

        $chunking = (array) config('oomycache.payload.chunking', []);

        if ((int) ($chunking['chunk_bytes'] ?? 0) < 1) {
            $issues[] = 'payload.chunking.chunk_bytes must be >= 1.';
        }

        if ((int) ($chunking['max_chunks'] ?? 0) < 1) {
            $issues[] = 'payload.chunking.max_chunks must be >= 1.';
        }

        if ((int) ($chunking['max_total_bytes'] ?? 0) < 0) {
            $issues[] = 'payload.chunking.max_total_bytes must be >= 0 (0 disables the cap).';
        }

        $stats = (array) config('oomycache.stats', []);

        if ((bool) ($stats['enabled'] ?? false)) {
            $sampleRate = (float) ($stats['sample_rate'] ?? 1.0);

            if ($sampleRate <= 0.0 || $sampleRate > 1.0) {
                $issues[] = "stats.sample_rate must be in (0, 1]; got [{$sampleRate}].";
            }
        }

        $resolver = config('oomycache.tenant.resolver');

        if (is_string($resolver) && $resolver !== '') {
            if (! class_exists($resolver) || ! is_subclass_of($resolver, TenantResolver::class)) {
                $issues[] = "tenant.resolver [{$resolver}] must be a class implementing ".TenantResolver::class.'.';
            }
        }

        $presets = (array) config('oomycache.presets', []);
        $modelPresets = (array) config('oomycache.model_presets', []);

        foreach ($modelPresets as $model => $presetName) {
            if (! is_string($presetName) || ! array_key_exists($presetName, $presets)) {
                $issues[] = "model_presets[{$model}] references unknown preset [{$presetName}].";
            }
        }

        return $issues;
    }
}
