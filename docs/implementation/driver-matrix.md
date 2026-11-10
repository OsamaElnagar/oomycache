# OoMyCache — Driver Matrix & CI Lanes

> **Date:** 2026-11-10 · **Package:** `osamaelnagar/oomycache` (`packages/OoMyCache`) · **App:** `filament-custom-pages` (Laravel 13.40, PHP 8.5.12, EnvKit)
> **Workflow:** [`../../.github/workflows/driver-matrix.yml`](../../.github/workflows/driver-matrix.yml) · **Related:** [`benchmark-and-launch.md`](benchmark-and-launch.md) (§4 launch checklist), [`../plan/05-qa-checklist.md`](../plan/05-qa-checklist.md) (§7 compatibility lanes), [`../plan/06-roadmap.md`](../plan/06-roadmap.md) (§6.2 beta).

This document defines the driver matrix: what each store/database driver has actually been proven against, what the nightly CI lanes prove, and how to reproduce each lane locally with EnvKit (or Docker).

**Honest headline:** the default suite still runs on sqlite; the MySQL/PostgreSQL driver lanes additionally run `DatabaseDriverTest` (Eloquent + database cache store) when `OOMYCACHE_TEST_DB` is set, and the Redis/Memcached lanes are connectivity smoke lanes. The package suite runs on Testbench's **sqlite `:memory:`** connection, so the Redis/Memcached/MySQL/PostgreSQL jobs prove the client extension + credentials + full suite stay green — they do not yet assert package cache behaviour on those engines (see §6).

---

## 1. Coverage today (local + CI)

| Driver / store | Where it is tested today | Package CI (`.github/workflows/tests.yml`) | Driver-matrix lane |
| --- | --- | --- | --- |
| `array` cache store | ✅ package test [`tests/Feature/CacheStoreMatrixTest.php`](../../tests/Feature/CacheStoreMatrixTest.php) `:63` (hit/invalidate round-trip) | ✅ every push/PR matrix | sqlite jobs (baseline) |
| `file` cache store | ✅ same test `:78` (temp dir, flush cleanup) | ✅ every push/PR matrix | sqlite jobs (baseline) |
| `database` cache store (SQLite) | ✅ same test `:108` — Laravel `cache` table on Testbench's `testing` connection | ✅ every push/PR matrix | sqlite jobs (baseline) |
| Real **Redis** store | 🟡 app-level only: dogfood `tests/Feature/QueryCacheRedisStoreTest.php` against EnvKit Redis 8.8.0 with phpredis 6.3 (not in the package repo) | ❌ | ✅ redis lane: probe + full suite |
| **Memcached** store | 🟡 app-level only: dogfood `tests/Feature/QueryCacheMemcachedStoreTest.php` against EnvKit Memcached 1.6.8 with `ext-memcached` (not in the package repo) | ❌ | 🟡 memcached lane: probe only, `continue-on-error` |
| **MySQL/MariaDB** | ✅ package `tests/Feature/DatabaseDriverTest.php` runs Eloquent + database cache store on EnvKit MariaDB 13.0.2 (set `OOMYCACHE_TEST_DB=mysql`) | ✅ default suite skips; driver env runs it | ✅ mysql lane: probe + full suite with `OOMYCACHE_TEST_DB=mysql` |
| **PostgreSQL** | ✅ package `tests/Feature/DatabaseDriverTest.php` runs Eloquent + database cache store on EnvKit PostgreSQL 18.4 (set `OOMYCACHE_TEST_DB=pgsql`) | ✅ default suite skips; driver env runs it | ✅ postgres lane: probe + full suite with `OOMYCACHE_TEST_DB=pgsql` |
| SQLite dogfood (Eloquent, Filament) | ✅ dogfood app suite **159 tests / 575 assertions** (same report §1) — not in the package repo | ❌ | ❌ |
| Octane | ❌ not covered anywhere yet | ❌ | ❌ (see §6) |

Package suite baseline: **47 tests / 114 assertions**, PHPStan level 7 + Pint + 100% type coverage green ([`benchmark-and-launch.md`](benchmark-and-launch.md) §1).

---

## 2. The CI lane (`.github/workflows/driver-matrix.yml`)

**Triggers:** nightly `cron: '30 2 * * *'` (UTC), `workflow_dispatch`, and pushes to `main` that touch the workflow, `composer.json`, `config/oomycache.php`, `src/Cache/**`, `src/Payload/**` or `tests/**`. It is deliberately **not** a pull-request gate: `tests.yml` is the per-PR matrix and this lane is slower/noisier (service containers, PECL builds).

**Shape:** 5 job definitions → **6 runner jobs** (the `sqlite` job is a 2-entry matrix). The workflow has only been structurally validated locally so far — it is inert inside the monorepo (§5) and has no GitHub run history yet; the first scheduled run is what turns the "proves" column below into evidence.

| Job | PHP / Laravel | Services + extensions | What it proves | What it does **not** prove |
| --- | --- | --- | --- | --- |
| `sqlite` (×2) | 8.3 + Laravel 12 `prefer-lowest`; 8.5 + Laravel 13 `prefer-stable` | none; `sqlite`, `pdo_sqlite` | Driver-lane baseline: the suite is green on both edges of the supported matrix | External drivers |
| `redis` | 8.4 / 13 | `redis:7` service; `ext-redis` (phpredis) | phpredis talks to a real Redis; suite green with a Redis cache config present | Package Redis-store behaviour (no package test yet) |
| `memcached` | 8.4 / 13 | `memcached:1.6-alpine`; `ext-memcached` via PECL | Client can set/get/del against a real Memcached; suite green | Package-level Memcached store behaviour (app-level test exists; lane non-blocking until PECL build history is stable) |
| `mysql` | 8.4 / 13 | `mysql:8.0`; `pdo_mysql` | PDO credentials/driver work against MySQL 8; suite green; `DatabaseDriverTest` runs Eloquent + database cache store when `OOMYCACHE_TEST_DB=mysql` | sqlite remains the default suite engine |
| `postgres` | 8.4 / 13 | `postgres:16`; `pdo_pgsql` | PDO credentials/driver work against PostgreSQL 16; suite green; `DatabaseDriverTest` runs Eloquent + database cache store when `OOMYCACHE_TEST_DB=pgsql` | sqlite remains the default suite engine |

All jobs: pinned `actions/checkout` v7.0.1 + `shivammathur/setup-php` v2 SHAs (same pins as `tests.yml`), `composer require` for the Laravel/Testbench pair, `composer update --prefer-* --no-scripts`, `composer run prepare`, a **driver connectivity probe**, then `composer test:unit` (= `vendor/bin/pest --parallel`). No secrets: all service credentials are throwaway CI values. The probes read the job env, so the lane also validates the exact env names consumers will use.

---

## 3. Exact environment variables

| Lane | Variables set on the job |
| --- | --- |
| `redis` | `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379` |
| `memcached` | `CACHE_STORE=memcached`, `MEMCACHED_HOST=127.0.0.1`, `MEMCACHED_PORT=11211` |
| `mysql` | `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=oomycache`, `DB_USERNAME=oomycache`, `DB_PASSWORD=oomycache` (service `MYSQL_*` mirror these) |
| `postgres` | `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=oomycache`, `DB_USERNAME=oomycache`, `DB_PASSWORD=oomycache` (service `POSTGRES_*` mirror these) |

The probes are small `php -r` scripts: Redis `connect/ping/set/get/del`; Memcached `addServer/set/get/del` with a 30 s retry loop; MySQL `SELECT VERSION()` through PDO; PostgreSQL `SELECT version()` through PDO. The MySQL/PostgreSQL variables do **not** change what the package test suite asserts — Testbench keeps Eloquent on its sqlite `testing` connection and each store test configures its own store. The env + probe is the honest part: it proves the values/extension work for the driver; the suite proves nothing regressed.

---

## 4. Running the lanes locally

### 4.1 EnvKit (this Windows machine) — preferred

Read-only snapshot taken 2026-11-09 via the EnvKit MCP server (`status_live`, `db_connections`; endpoint `http://127.0.0.1:47600/mcp`). EnvKit stops idle services, so start them first (step 1); the values below are the EnvKit defaults on this machine:

| Service | State | Version | Endpoint |
| --- | --- | --- | --- |
| PHP-FPM | running | PHP 8.5.12 | `127.0.0.1:9000` |
| MySQL/MariaDB | running | MariaDB 13.0.2 | `127.0.0.1:3306` (user `root`, empty password) |
| PostgreSQL | running | PostgreSQL 18.4 | `127.0.0.1:5432` (user `postgres`, empty password) |
| Redis | running | Redis 8.8.0 | `127.0.0.1:6379` (no password) |
| Memcached | running | Memcached 1.6.8 | `127.0.0.1:11211` (no password) |

Local PHP probe output (read-only `PHP_VERSION`, PDO version queries, extension checks):

```text
redis: PING=true server=8.8.0
memcached: set/get=ok
mysql: SELECT VERSION() = 13.0.2-MariaDB
postgres: SELECT version() = PostgreSQL 18.4 on x86_64-windows ...
memcached_ext: loaded (php-memcached)
pdo_drivers: mysql,pgsql,sqlite
php: 8.5.12
```

Steps (EnvKit MCP tool names as discovered from `tools/list`; the EnvKit CLI equivalents are in parentheses):

1. **Start the services** — MCP `start_service {name:"redis"}`, `{name:"memcached"}`, `{name:"mysql"}`, `{name:"postgres"}`; CLI `envkit start redis memcached mysql postgres`; check with `envkit status` or MCP `status_live`.
2. **PHP extensions** — MCP `php_extensions {version:"8.5.12"}` then `install_php_extension {version:"8.5.12", name:"redis"}` / `set_php_extension {version:"8.5.12", name:"redis", enabled:true}`. `ext-redis` and `ext-memcached` are installed here (EnvKit `install_php_extension {version:"8.5.12", name:"memcached"}`).
3. **Scratch databases** (never use the dogfood DB) — MCP `create_db {engine:"mysql", name:"oomycache_ci"}` and `{engine:"postgres", name:"oomycache_ci"}`; connection details from MCP `db_connections` (same values as the table above). For CI parity use DB user `oomycache` / password `oomycache`; locally `root` (MySQL) or `postgres` (PostgreSQL) with an empty password is the EnvKit default.
4. **Probe the driver** — same snippets as the workflow, e.g.:

   ```bash
   # Redis
   php -r '$r=new Redis(); $r->connect("127.0.0.1",6379,2.0); var_dump($r->ping());'
   # MySQL
   php -r '$p=new PDO("mysql:host=127.0.0.1;port=3306;dbname=oomycache_ci","root",""); echo $p->query("SELECT VERSION()")->fetchColumn(), PHP_EOL;'
   # PostgreSQL
   php -r '$p=new PDO("pgsql:host=127.0.0.1;port=5432;dbname=oomycache_ci","postgres",""); echo $p->query("SELECT version()")->fetchColumn(), PHP_EOL;'
   ```

5. **Run the suite** — `cd packages/OoMyCache && composer test:unit`.
6. **Memcached locally (not done yet)** — MCP `install_service {service:"memcached", version:"1.6.8"}`, then `envkit start memcached`, then `install_php_extension {version:"8.5.12", name:"memcached"}`. Until then the memcached lane is CI-only and experimental.

### 4.2 Docker (generic developer / CI parity)

Docker is not installed on this machine, so the images below are the reference used by CI rather than a locally executed recipe:

```bash
docker run -d --name oomycache-redis     -p 6379:6379   redis:7
docker run -d --name oomycache-memcached -p 11211:11211 memcached:1.6-alpine
docker run -d --name oomycache-mysql -p 3306:3306 \
  -e MYSQL_DATABASE=oomycache -e MYSQL_USER=oomycache -e MYSQL_PASSWORD=oomycache -e MYSQL_ROOT_PASSWORD=root mysql:8.0
docker run -d --name oomycache-postgres -p 5432:5432 \
  -e POSTGRES_DB=oomycache -e POSTGRES_USER=oomycache -e POSTGRES_PASSWORD=oomycache postgres:16
```

Then export the variables from §3 and run the probes and `composer test:unit`. The migrations are not needed: the suite creates its own in-memory schema.

---

## 5. What is *not* covered (and the honest plan)

| Gap | Risk / impact | Plan |
| --- | --- | --- |
| **Package tests hard-code sqlite `:memory:`** — MySQL/PostgreSQL jobs are connectivity + suite only, not Eloquent/database-cache-store integration | Medium: engine-specific SQL/pagination differences could slip through | Add Testbench DB-config overrides + store-matrix rows for MySQL/PG (needs PHP test edits, outside this task) |
| **No package-level Redis/Memcached store tests** — real Redis evidence is app-level (`tests/Feature/QueryCacheRedisStoreTest.php` in the dogfood app) and does not ship in the package repo/CI | Medium: package refactors could break Redis with the app test as the only guard | Extract the Redis store test into the package with env-gated skips; move memcached from probe-only to a real store test once the CI extension is stable |
| **Memcached lane is non-blocking** (`continue-on-error: true`) and local memcached is not installed | Low now, must be made blocking before beta claims | Stabilise the PECL build over several nightly runs, then drop `continue-on-error`; add EnvKit memcached to the local recipe |
| **Octane lane absent** | Medium: long-worker stale config/version memo is a known open question ([`benchmark-and-launch.md`](benchmark-and-launch.md) §6) | P1 lane after the `forgetOptions()` audit; `laravel/octane` smoke job |
| **Windows runner only on the sqlite matrix** (`tests.yml`); driver services are Linux-only | Low: drivers are server processes; the PHP clients are the same | Keep as is; document in compatibility matrix |
| **Workflows are inert inside the monorepo** — the app root `.github/workflows/` is the only active workflow; package workflows run once `packages/OoMyCache` is split into its own repository | Medium: the lane will not run until the split | The split/extraction is the release task (see plan/06 §6/§10 and the release checklist); pre-split alternative: mirror the file with `defaults.run.working-directory: packages/OoMyCache` |
| **Not a PR gate** — nightly/`workflow_dispatch`/path-filtered main pushes only | Low: core suite still gated per PR by `tests.yml`; driver regressions surface within a day | Revisit once the lane has a stable run history (drop `continue-on-error`, add `pull_request` paths) |

---

## 6. Verification performed for this task

- `python -c "import yaml; ..."` parsed [`driver-matrix.yml`](../../.github/workflows/driver-matrix.yml): 5 job definitions (`sqlite`, `redis`, `memcached`, `mysql`, `postgres`), 2 sqlite matrix entries → 6 runner jobs; expected services (`redis:7`, `memcached:1.6-alpine`, `mysql:8.0`, `postgres:16`) and `continue-on-error: true` on memcached asserted — output pasted in the task completion report.
- Documented links were checked against the filesystem (all resolve): this file's `benchmark-and-launch.md`, `phase-1-dogfood.md`, `phase-2-progress.md`, `../plan/05-qa-checklist.md`, `../plan/06-roadmap.md`, `../../README.md`, `../../.github/workflows/driver-matrix.yml`.
- No PHP source, tests, config, app files or other docs were modified for this task (the workflow + this document are the only changes).
