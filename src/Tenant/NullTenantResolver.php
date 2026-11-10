<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Tenant;

use Oomycache\Oomycache\Contracts\TenantResolver;

/**
 * Default resolver: a single-tenant/single-panel application with no extra
 * key segments.
 */
final class NullTenantResolver implements TenantResolver
{
    public function resolve(): TenantContext
    {
        return TenantContext::none();
    }
}
