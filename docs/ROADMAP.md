# Roadmap

Where the project is and what comes next. This is a plan, not a description of
the code — for what actually exists, see the README and `ARCHITECTURE.md`.

---

## Position

> **Stage 2 — COMPLETE / VERIFIED**
> Capability, availability, deployment-control, subsystem flags, entitlement
> seam, and cron observation foundation established. No feature-level
> entitlement consumer or background job engine has been implemented.

**Stage 2 complete. Stage 3 not started.**

Accepted baseline: `84f46eb`. 99 tests / 380 assertions passing.

The four known limitations recorded in `README.md` (a `DEGRADED` developer
machine, an empty `SENDER_REQUIRED_CAPABILITIES`, no production entitlement
consumer, and a cache-backed heartbeat) are accepted consequences of the Stage 2
scope. They are not defects, and Stage 3 must not be scoped to eliminate them —
in particular, Stage 3 must not depend on making a local host `READY`.

## Stages

| # | Stage | State |
| --- | --- | --- |
| 0 | Foundation / architecture | complete |
| 1 | Laravel application foundation | complete |
| 2 | Capability, availability and deployment control foundation | complete |
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
- `config/sender.php` limits with the `DeploymentLimit` enum as the access path
- `HostCapabilityInspector`, `/health`, `/diagnostics`, `sender:diagnose`
- Production-safe logging with centralized secret redaction
- 64 automated tests

---

## What Stage 2 delivered

- `CapabilityStatus` with first-class `UNKNOWN`, separating "not established"
  from "known broken"; `NOT_ENTITLED` kept out of it as an authorization fact
- `CapabilitySubject`, `Subsystem`, `EntitlementStatus`, `AvailabilityState` and
  `AvailabilityReason`
- `CapabilityRegistry`, the single source every diagnostic surface consumes, so
  `/health`, `/diagnostics` and `sender:diagnose` cannot disagree
- `AvailabilityResolver`, composing infrastructure → operator flag → entitlement
  into one decision with a machine-readable reason
- `Entitlement` as a deny-by-default interface, so plans can arrive without
  touching any service
- `SubsystemFlagRegistry` over a new `system_settings` table, persisted rather
  than cached so a kill switch survives `cache:clear`
- `DeploymentLimit` and `SENDER_DEPLOYMENT_LIMIT_*`, kept distinct from the
  host `requirements`
- Cron observation via `sender:heartbeat`, with `UNKNOWN` → `READY` → `DEGRADED`
  and no `UNAVAILABLE` that evidence cannot support
- `sender:diagnose` exit semantics that treat severity and exit status as
  different things
- Runtime logging coverage: every channel is constructed, and the file-backed
  ones are written to and verified for redaction

---

## Explicitly not built yet

The email extractor, the web crawler, SMTP campaign delivery, recipients and
suppression, unsubscribes, the job engine, plans, entitlements, usage tracking,
the admin operations centre, the REST API, PHP integration, billing, analytics,
Redis, WebSockets and background daemons.

None of these should be started before the stage that establishes the
foundation they depend on. Note that Stage 2 built the *vocabulary* for
entitlements and subsystem control, not the features that use them: nothing
grants a plan, and no feature depends on a capability that has not been
measured.

---

## Open questions for later stages

Recorded now so they are decided deliberately rather than by accident:

- **Job storage.** The `jobs` table already exists. Whether to use Laravel's
  queue directly or a custom job table with explicit locking for extraction
  batches is a Stage 3 decision.
- **Cron granularity.** One entry that runs many short passes, or several
  entries per workload. Depends on the host's minimum cron interval. The
  heartbeat is now in place, so whichever shape is chosen is observable from
  the start.
- **SMTP credential storage.** Whether SMTP passwords are stored encrypted with
  the application key, or delegated to the host. Must be decided before Stage 5.
- **Entitlement model.** Whether plan quotas are read from a plan table or
  cached per account. The `Entitlement` interface and its deny-by-default
  binding are already in place, so this only decides what replaces the default,
  not what depends on it.
- **Upgrade path.** Laravel 12 reaches the end of security fixes in February
  2027. The Laravel 13 migration is a version bump, but it should be scheduled
  rather than absorbed into a feature stage.
