<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Contracts;

use Oomycache\Oomycache\Tenant\TenantContext;

/**
 * Resolves the tenant/panel (and optionally user) segments that must be part
 * of every cache key.
 *
 * The core package is Filament-free; the Filament bridge (or an application)
 * provides a resolver that reads the current panel/tenant. A resolver can
 * fail closed when tenancy is active but no tenant can be resolved.
 */
interface TenantResolver
{
    public function resolve(): TenantContext;
}
