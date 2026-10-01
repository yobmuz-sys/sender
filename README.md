# Sender

A modular, cPanel-compatible SaaS platform for data extraction and email campaigns.

The application is built so that a powerful product can run on ordinary shared
hosting. It requires only PHP, MySQL/MariaDB, a web server, filesystem access
and cPanel Cron. Redis, Supervisor, Docker, Node.js servers, queues with
long-running daemons and root access are **not** required at any point.

---

## Current status

> **Stage 3A — COMPLETE / VERIFIED**
> Queue reservation safety, durable scheduled-run evidence, and real SMTP
> capability verification established. No job engine, extractor, or campaign
> sending has been implemented.

Accepted baseline: `84f46eb`. 136 tests / 483 assertions passing.

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
| Queue reservation invariant (`retry_after` vs worker runtime) | done |
| Durable scheduled-run evidence, replacing the cache heartbeat | done |
| SMTP verification and the capability it establishes | done |

### Known intentional limitations

These are accepted consequences of the Stage 3A scope, not defects. None of them
should be "fixed" by weakening a threshold or a check.

1. **`DEGRADED` on the developer machine.** Laragon's stock `php.ini` is below
   the platform's 256M / 10M requirements. This is exactly what the inspector
   exists to report. `sender:diagnose` correctly exits `0`: the repository's
   gate must remain valid on an ordinary cPanel account, so the local
   configuration is not raised to manufacture a green run.
2. **`SENDER_REQUIRED_CAPABILITIES` is empty.** Nothing depends on a capability
   yet, so no unestablished capability is a fault. The `UNKNOWN` → non-zero
   exit path is covered by test only.
3. **No production feature consumes `Entitlement`.** The interface exists and is
   deny-by-default; no feature resolves a plan through it yet.
4. **`smtp` is `UNKNOWN` until somebody verifies it.** Credentials being present
   is not evidence that they work, so the capability is not inferred from
   configuration. Run `sender:verify-smtp --to=you@example.com`.
5. **Verification cannot prove delivery.** `sender:verify-smtp` can prove the
   server accepted a message from these credentials. Nothing inside the
   application can observe a recipient's mailbox, and the command says so
   rather than letting "available" imply "delivered".
6. **Run evidence is a record of outcomes, not a progress model.** Runs are
   deliberately minimal: no chunking, progress or dependency graph. Those belong
   to the workload that needs them.

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

Mail is not verified by `migrate`. With the default `MAIL_MAILER=log`, nothing
is delivered, so confirm the real transport once the database exists:

```bash
php artisan sender:verify-smtp --to=you@example.com
```

This is separate from setup on purpose — a local box usually has no relay, and
failing installation over mail configuration would be the wrong signal.

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
failure. Today `url_fetch` is `UNKNOWN`, because no code exists yet to perform
it. `smtp` is also `UNKNOWN` until an operator verifies it — see below.

### Observing cron

cPanel cannot tell PHP whether a cron entry exists, so the platform records
durable evidence that the scheduler ran and infers the capability from it. Point
a cPanel Cron Job at:

```
*/5 * * * * cd /home/USER/sender && /usr/local/bin/php artisan sender:heartbeat >> /dev/null 2>&1
```

Each run is appended to `scheduled_runs` with its outcome — `running`,
`succeeded` or `failed` — so a command that starts failing is distinguishable
from one that has stopped being called. That distinction is the reason the
evidence is durable: an operator checking a broken deployment previously saw
`UNKNOWN`, the same answer as a host that had never been configured, with
nothing pointing at the cause.

Until the first run arrives, cron reports `UNKNOWN`. It is never reported
`UNAVAILABLE` from absence, because there is no positive evidence to justify it.
A recent *successful* run gives `READY`; stale evidence gives `DEGRADED`. The
evidence lives in the database, so `php artisan cache:clear` does not erase it.

### Verifying SMTP

Configuring SMTP credentials does not establish that they work. Run the
verification explicitly:

```
php artisan sender:verify-smtp --to=you@example.com
```

It reports each stage separately, because the stages fail for different reasons
and you need to know which one broke:

| Stage | What it establishes |
| --- | --- |
| `configuration` | a mailer that actually delivers is configured |
| `transport` | Laravel can build that transport from the configuration |
| `connection` | the host resolves, connects, and negotiates the configured scheme |
| `acceptance` | the server accepted a test message from these credentials |

Omitting `--to` proves the connection only, and the result is `DEGRADED`
because the credentials were never exercised.

**What this does not prove:** that a recipient received anything. Nothing inside
the application can observe a recipient's mailbox. The capability reports the
server accepted a message, and says so rather than letting "available" imply
"delivered".

The result is recorded, and the capability reports it from there, so an ordinary
page view never opens a mail connection. An old verification degrades rather
than being trusted forever. Discard one with
`php artisan sender:verify-smtp --forget`.

### Queue reservation safety

A queue job is reserved for `retry_after` seconds before it becomes visible
again. If that is shorter than the time a worker may legitimately take, a slow
but healthy job is handed to a second worker and processed twice.

The platform therefore requires:

```
retry_after >= max_worker_runtime_seconds + reservation_margin_seconds
```

With the shipped defaults that is `300 >= 240 + 60`. The check applies only to
the `database` driver, and a violation reports the `queue` capability as
`UNAVAILABLE` with remediation — a supported driver with unsafe configuration is
not `READY`. See
[docs/DEPLOYMENT_CPANEL.md](docs/DEPLOYMENT_CPANEL.md).

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
| `/register`, `/login`, `/forgot-password`, `/reset-password` | guest | Authentication |
| `/dashboard` | authenticated | Account overview |
| `/logout` | authenticated | End the session (POST) |
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
