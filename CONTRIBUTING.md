# Contributing

Thanks for helping improve OoMyCache. The package is intentionally conservative about correctness:
lost invalidation is worse than a cache miss.

## Setup

```bash
composer install
composer prepare
cp phpunit.xml.dist phpunit.xml   # if needed locally
composer test
```

The normal suite is cache-inert and uses isolated SQLite. External-driver lanes are opt-in:

```bash
OOMYCACHE_TEST_DRIVER=redis vendor/bin/pest tests/Feature/RealCacheDriverTest.php
OOMYCACHE_TEST_DRIVER=memcached vendor/bin/pest tests/Feature/RealCacheDriverTest.php
OOMYCACHE_TEST_DB=mysql vendor/bin/pest tests/Feature/DatabaseDriverTest.php
```

## Pull requests

- Keep one logical change per PR.
- Add a Pest test for every behavior change; include failure modes (corrupt payload, transaction,
  dependency write, store failure).
- Preserve the public API and version-token invalidation model. Do not add tag dependencies.
- Update `CHANGELOG.md`, README/docs and the bundled Boost skill when behavior or config changes.
- Run `composer test`; CI also runs the PHP/Laravel matrix and real-driver jobs.
- Do not commit `vendor/`, `build/`, `.phpunit.cache/` or local cache/lab artifacts.

## Coding conventions

- PHP 8.3+, `declare(strict_types=1)`, explicit types and PHPDoc shapes.
- Follow existing package structure and service-provider style.
- Prefer small, explicit classes over helpers; no vendor forks.
- Preserve fail-open cache reads and loud invalidation failures.
- One invalidation notification per logical Eloquent write chain; tokens still bump per flush.

## Releases

Release tags are cut from `main`; see `docs/implementation/release-checklist.md` and the package
release workflow. Never move a published tag; cut a new patch instead.

## Security

Do not open a public issue for a vulnerability; use the process in [SECURITY.md](SECURITY.md).
