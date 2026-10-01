# Sender

A modular, cPanel-compatible SaaS platform for data extraction and email campaigns.

The application is built so that a powerful product can run on ordinary shared
hosting. It requires only PHP, MySQL/MariaDB, a web server, filesystem access
and cPanel Cron. Redis, Supervisor, Docker, Node.js servers, queues with
long-running daemons and root access are **not** required at any point.

---

## Current status

This repository is at **Stage 2 — capability, availability and deployment control
foundation**.

Implemented and tested:

| Area | State |
| --- | --- |
| Laravel application skeleton | done |
| Environment configuration (`.env.example`) | done |
| Database connection and foundation migrations | done |
| Authentication (register, login, logout, password reset) | done |
| Role and permission authorization foundation | done |
| Host capability inspector and health endpoint | done |
| Production-safe logging and secret redaction | done |
| Automated test suite | done |
| cPanel deployment documentation | done |
| Capability vocabulary (`READY`/`DEGRADED`/`UNKNOWN`/`UNAVAILABLE`) | done |
| Shared capability registry behind every diagnostic surface | done |
| Deployment limits, kept distinct from host requirements | done |
| Deny-by-default entitlement seam | done |
| Operator subsystem kill switches | done |
| Cron observation via `sender:heartbeat` | done |

**Not** implemented, and deliberately so at this stage: the email extractor,
the web crawler, SMTP campaign sending, recipients, suppression, plans,
entitlements, usage tracking, the admin operations centre, the REST API, PHP
integration and billing. See [docs/ROADMAP.md](docs/ROADMAP.md).

---

## Technology choices

| Component | Version | Reason |
| --- | --- | --- |
| PHP | `^8.2` (developed on 8.4) | PHP 8.2 is the lowest version offered by cPanel's PHP Selector that is still widely deployed. |
| Laravel | `^12.0` (12.69.x) | Requires only PHP 8.2, so one codebase runs on the widest range of shared hosts. |
| Database | MySQL 5.7+ / MariaDB 10.3+ / MariaDB 10.4+ | The stack cPanel provides by default. SQLite is supported for tests and throwaway local work. |
| Frontend | Blade + Tailwind CSS 4 + Vite | Server-rendered HTML. Compiled assets are committed so the host needs no Node.js runtime. |
| Session, cache, queue | `database` driver | Removes Redis and Memcached from the requirement list entirely. |

### Why Laravel 12 rather than the newest release

Laravel 13 requires PHP `^8.3`. Laravel 12 requires PHP `^8.2` and runs
unchanged on 8.2, 8.3 and 8.4.

The PHP version on a shared host is chosen by the account owner through cPanel,
and hosts upgrade their PHP offerings at different speeds. Requiring 8.3 would
exclude any account still on 8.2 for no benefit to this product. Laravel 12
receives security fixes through February 2027, and moving to a later major
version later is a routine `composer require` bump rather than a rewrite.

The reasoning is recorded here so the decision can be revisited deliberately
rather than by accident. `docs/ARCHITECTURE.md` covers the rest of the
structural decisions.

---

## Local development (Windows / Laragon)

Requirements: PHP 8.2+ with the extensions listed below, Composer 2, Node.js
(for the asset build only), and MySQL or MariaDB.

```bash
composer install
npm install
npm run build

copy .env.example .env
php artisan key:generate
```

Create the database, then set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in
`.env`. To create a local database with MySQL:

```sql
CREATE DATABASE sender CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then:

```bash
php artisan migrate
php artisan serve
```

The application is available at <http://127.0.0.1:8000>.

### Creating the first administrator

```bash
# 1. Register through the web interface, then:
php artisan sender:set-role you@example.com super_admin
```

### Verifying the host

```bash
php artisan sender:diagnose
```

This prints the same report shown at `/diagnostics`.

It exits non-zero only when a capability is measured as `UNAVAILABLE`, or when a
capability listed in `SENDER_REQUIRED_CAPABILITIES` cannot be established. A
`DEGRADED` host exits `0` with warnings: it is operational, just with less
headroom, and a check that fails on it would be a check operators learn to
ignore.

A capability the platform has never measured reports `UNKNOWN`, which is not a
failure. Today `url_fetch` and `smtp` are `UNKNOWN`, because no code exists yet
to perform them.

### Observing cron

cPanel cannot tell PHP whether a cron entry exists, so the platform records that
the scheduler ran and infers the capability from it. Point a cPanel Cron Job at:

```
*/5 * * * * cd /home/USER/sender && /usr/local/bin/php artisan sender:heartbeat >> /dev/null 2>&1
```

Until the first heartbeat arrives, cron reports `UNKNOWN`. It is never reported
`UNAVAILABLE`, because there is no positive evidence to justify that.

### Running the tests

```bash
php artisan test
```

The suite runs against an in-memory SQLite database and requires no Node build.

### Frontend work

```bash
npm run dev     # watch mode during development
npm run build   # production build, committed to public/build
```

---

## Endpoints

| Path | Access | Purpose |
| --- | --- | --- |
| `/` | public | Landing page |
| `/health` | public | JSON readiness verdict, no host detail |
| `/up` | public | Laravel liveness probe |
| `/register`, `/login`, `/forgot-password` | guest | Authentication |
| `/dashboard` | authenticated | Account overview |
| `/diagnostics` | `system.view` | Full host capability report |

`/diagnostics` additionally requires `SENDER_DIAGNOSTICS_ENABLED=true` in
`.env`; it returns 404 otherwise.

---

## Documentation

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — structure and design decisions
- [docs/DEVELOPMENT_WORKFLOW.md](docs/DEVELOPMENT_WORKFLOW.md) — the stage protocol
- [docs/DEPLOYMENT_CPANEL.md](docs/DEPLOYMENT_CPANEL.md) — production deployment
- [docs/ROADMAP.md](docs/ROADMAP.md) — stage plan and current position

## Security

Never commit `.env`, database credentials, SMTP passwords, API keys or private
certificates. `.env` and the local SQLite files are already ignored by Git.
Report a vulnerability privately to the maintainer rather than in a public
issue.
