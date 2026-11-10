<?php

declare(strict_types=1);

it('passes with the default configuration', function (): void {
    $this->artisan('oomycache:doctor')
        ->expectsOutputToContain('No configuration issues or warnings.')
        ->assertSuccessful();
});

it('fails when payload.mode is not arrays', function (): void {
    config(['oomycache.payload.mode' => 'models']);

    $this->artisan('oomycache:doctor')
        ->expectsOutputToContain('payload.mode [models] is not implemented')
        ->assertExitCode(1);
});

it('fails when ttl_jitter is out of range', function (): void {
    config(['oomycache.ttl_jitter' => 0.9]);

    $this->artisan('oomycache:doctor')
        ->expectsOutputToContain('ttl_jitter must be between 0 and 0.5')
        ->assertExitCode(1);
});

it('fails when the configured tenant resolver class is missing', function (): void {
    config(['oomycache.tenant.resolver' => 'App\\Missing\\TenantResolver']);

    $this->artisan('oomycache:doctor')
        ->expectsOutputToContain('must be a class implementing')
        ->assertExitCode(1);
});

it('fails when a model preset name is unknown', function (): void {
    config(['oomycache.model_presets' => ['App\\Models\\Product' => 'does-not-exist']]);

    $this->artisan('oomycache:doctor')
        ->expectsOutputToContain('references unknown preset')
        ->assertExitCode(1);
});

it('fails on an invalid compression algorithm', function (): void {
    config(['oomycache.payload.compression.algorithm' => 'zip']);

    $this->artisan('oomycache:doctor')
        ->expectsOutputToContain('must be one of gzip, deflate, zstd')
        ->assertExitCode(1);
});
