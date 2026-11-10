<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tests\Fixtures;

use Oomycache\Oomycache\Contracts\TenantResolver;
use Oomycache\Oomycache\Tenant\TenantContext;

/**
 * Mutable resolver used by package tests to simulate tenant/panel switches
 * without booting Filament.
 */
final class MutableTenantResolver implements TenantResolver
{
    public function __construct(private TenantContext $context = new TenantContext) {}

    public function resolve(): TenantContext
    {
        return $this->context;
    }

    public function tenant(string $id, string $panel = 'admin'): self
    {
        $this->context = TenantContext::for(['tenant' => $id, 'panel' => $panel]);

        return $this;
    }

    public function panel(string $panel): self
    {
        $this->context = TenantContext::for(['panel' => $panel]);

        return $this;
    }

    public function failClosed(string $reason = 'tenant-unresolved'): self
    {
        $this->context = TenantContext::failClosed($reason);

        return $this;
    }

    public function none(): self
    {
        $this->context = TenantContext::none();

        return $this;
    }
}
