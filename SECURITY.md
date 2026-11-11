# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| `0.1.x` (current stable) | Yes |
| `0.1.0-alpha.*` | No — upgrade to stable |
| Earlier | No |

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability
reporting on the repository:

https://github.com/OsamaElnagar/oomycache/security/advisories/new

Include:

- package version, PHP/Laravel version and cache store,
- a minimal reproduction or proof of concept,
- affected configuration and, if relevant, whether tenancy or the Filament bridge is in use,
- impact assessment (stale data, cross-tenant leakage, cache poisoning, unauthorized purge, etc.).

You can also email `osamaelngar98@gmail.com` with the subject `OoMyCache security`. We will acknowledge
the report as soon as possible and coordinate disclosure.

## Scope

In scope:

- cache-key or payload handling that could leak data across tenants/users/panels,
- stored-payload deserialization or file-store path traversal,
- invalidation bypasses that lead to stale data beyond documented raw/quiet-write limitations,
- authorization bypass around the Filament studio or its purge actions,
- denial-of-service through cache fills, oversized payloads or cache-key growth.

Out of scope:

- the documented requirement to flush explicitly after raw SQL, quiet writes and raw pivot-table
  writes,
- stale reads caused by app code bypassing the documented API,
- shared-cache-store configuration outside the package's control,
- local test/lab scripts and generated assets.

## Security guidance

- Keep the package and the optional bridge on the latest stable release.
- Use a private cache store per app/tenant boundary; never share an unqualified Redis database
  between unrelated applications.
- Keep the Filament purge Gate operator-only and audited.
- Do not cache secrets or user-controlled free-text search results.
- Follow the [production runbook](docs/implementation/production-runbook.md) and
  [security review](docs/implementation/security-review.md).
