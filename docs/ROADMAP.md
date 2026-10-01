# Roadmap

Where the project is and what comes next. This is a plan, not a description of
the code — for what actually exists, see the README and `ARCHITECTURE.md`.

---

## Position

**Stage 1 complete. Stage 2 not started.**

## Stages

| # | Stage | State |
| --- | --- | --- |
| 0 | Foundation / architecture | complete |
| 1 | Laravel application foundation | complete |
| 2 | SaaS users, features, plans | pending |
| 3 | Job and cron processing engine | pending |
| 4 | Email extraction engine | pending |
| 5 | SMTP campaign engine | pending |
| 6 | Admin operations centre | pending |
| 7 | REST API and PHP integration | pending |
| 8 | Billing | pending |
| 9 | Security, performance, deployment hardening | pending |

The sequence follows the dependency chain:

```
Authentication -> Users -> Plans -> Features -> Quotas
              -> Jobs -> Extractor -> SMTP -> Admin
```

The extractor is deliberately **not** early. Building it first would mean
retrofitting authentication, quotas, job handling, permissions, logging, hosting
limits and administration around code that already existed.

---

## What Stage 1 delivered

- Laravel 12 application on PHP `^8.2`, documented with the reasoning
- `.env.example` with shared-hosting defaults and no secrets
- Foundation migrations (`users` with `role`, `password_reset_tokens`, `sessions`,
  `cache`, `jobs`)
- Authentication: register, login, logout, password reset, login throttling
- Authorization: six roles, fourteen permissions, gates registered centrally
- `config/sender.php` limits with the `Limit` enum as the access path
- `HostCapabilityInspector`, `/health`, `/diagnostics`, `sender:diagnose`
- Production-safe logging with centralized secret redaction
- 63 automated tests

---

## Explicitly not built yet

The email extractor, the web crawler, SMTP campaign delivery, recipients and
suppression, unsubscribes, the job engine, plans, entitlements, usage tracking,
the admin operations centre, the REST API, PHP integration, billing, analytics,
Redis, WebSockets and background daemons.

None of these should be started before the stage that establishes the
foundation they depend on.

---

## Open questions for later stages

Recorded now so they are decided deliberately rather than by accident:

- **Job storage.** The `jobs` table already exists. Whether to use Laravel's
  queue directly or a custom job table with explicit locking for extraction
  batches is a Stage 3 decision.
- **Cron granularity.** One entry that runs many short passes, or several
  entries per workload. Depends on the host's minimum cron interval.
- **SMTP credential storage.** Whether SMTP passwords are stored encrypted with
  the application key, or delegated to the host. Must be decided before Stage 5.
- **Entitlement model.** Whether plan quotas are read from a plan table or
  cached per account. Affects Stage 2 schema.
- **Upgrade path.** Laravel 12 reaches the end of security fixes in February
  2027. The Laravel 13 migration is a version bump, but it should be scheduled
  rather than absorbed into a feature stage.
