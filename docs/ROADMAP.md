# Roadmap

Where the project is and what comes next. This is a plan, not a description of
the code — for what actually exists, see the README and `ARCHITECTURE.md`.

---

## Position

> **Stage 3B — COMPLETE / VERIFIED**
> The application surface and administration foundation: permission-aware
> navigation, verified email, account management, user suspension, and a full
> administrative surface over the Stage 3A evidence. No job engine, extractor,
> or campaign sending has been implemented.

**Stage 3A and 3B complete. The Stage 3 job engine itself has not started.**

Accepted baseline: `6445a9c`. 253 tests / 702 assertions passing.

The known limitations recorded in `README.md` (a `DEGRADED` developer machine, an
empty `SENDER_REQUIRED_CAPABILITIES`, no production entitlement consumer, an
unverified `smtp` capability, and placeholder-only product pages) are accepted
consequences of the current scope. They are not defects, and Stage 3 must not be
scoped to eliminate them — in particular, Stage 3 must not depend on making a
local host `READY`.

Two earlier limitations are now resolved: the cron heartbeat was cache-backed
(durable since Stage 3A), and Stage 2 had no administrator beyond a single
diagnostics page (Stage 3B adds the full administrative surface).

## Stages

| # | Stage | State |
| --- | --- | --- |
| 0 | Foundation / architecture | complete |
| 1 | Laravel application foundation | complete |
| 2 | Capability, availability and deployment control foundation | complete |
| 3 | Job and cron processing engine | 3A and 3B complete; engine not started |
| 4 | Email extraction engine | pending |
| 5 | SMTP campaign engine | pending |
| 6 | Admin operations centre | 3B foundation in place; operational workloads pending |
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
  and no `UNAVAILABLE` that evidence cannot support. **Superseded by Stage 3A:**
  the evidence is now durable and records outcomes, not just occurrences.
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
- **Operator surface for subsystem flags.** `SubsystemFlagRegistry` has
  `enable()`, `disable()` and `reset()`, but nothing exposes them to an
  operator, and `system.manage` gates no route or command. Decide whether that
  arrives in Stage 3 — the job engine is the first subsystem worth stopping in
  an emergency — or is deferred to the admin operations centre, where it belongs
  alongside the other operator controls.
- **Emergency logger redaction.** Laravel's `emergency` channel builds a bare
  `StreamHandler` that honours neither `processors` nor `taps`, so it is the one
  logging path without redaction. It needs a custom handler or a decision to
  accept the exposure; it is not a Stage 2 fix.
- **Cron heartbeat durability.** Resolved in Stage 3A. Run evidence is durable in
  `scheduled_runs` and `cache:clear` no longer erases it, and runs record an
  outcome rather than only that something ran, so a failing cron is now
  distinguishable from an absent one.

---

## What Stage 3A delivered

Stage 3 is the job and cron processing engine. Stage 3A was scoped to the three
infrastructure defects that would have made that engine unsafe to build on top
of, before committing to a workload:

- **Queue reservation invariant** (`7b8cc10`). `retry_after` defaulted to 90s
  against a 240s worker runtime, so a slow but healthy job would be handed to a
  second worker and processed twice — a silent duplicate-send bug. The invariant
  is now explicit and enforced for the `database` driver.
- **Durable scheduled-run evidence** (`19f5a77`). Replaced the cache-backed
  heartbeat with `scheduled_runs`. Resolves the open question below.
- **SMTP verification** (`891eb81`). `sender:verify-smtp` establishes the
  capability explicitly, staged, and records what it did and did not prove.

No job engine, workload, extractor or campaign sending was built. The next
decision is which real workload the engine should run first; that choice should
be made against a re-audit, not against this document.

---

## What Stage 3B delivered

Stage 3B is the application surface: everything a person uses to operate the
platform, and the evidence the earlier stages produce made visible. It was
scoped to the surface, and deliberately stopped short of any workload.

- **Navigation.** A single declarative source per audience
  (`AdminNavigation`, `ProductNavigation`), rendered by a view composer and
  filtered by `Permission`. Views never re-implement authorization, so a link
  cannot appear that the server would refuse.
- **Breadcrumbs.** Derived from the route name rather than declared per view.
  Thirty hand-written trails would repeat the same prefix and any of them could
  disagree with the actual route.
- **Email verification.** `MustVerifyEmail` on the account, enforced by
  middleware across every product and admin route. Changing the address clears
  the confirmation and resends the link.
- **Account management.** Profile, email and password. A password change
  regenerates the session id so a token minted before the change cannot survive
  it.
- **User suspension.** A `UserStatus` column and middleware. A suspended account
  is refused at sign-in with the same generic `auth.failed` as an invalid
  password, so suspension is not distinguishable from a wrong password.
- **Last-super-administrator protection.** In `SuperAdministratorGuard`, not in a
  controller, so every path that removes a privilege passes through one check.
- **Administration.** Dashboard, users, roles, jobs, runs, SMTP, system,
  subsystems and settings — all reading the Stage 3A registries. Settings are
  read-only; there is deliberately no configuration editor.
- **Staged shells.** Features, plans, campaigns, API, billing, audit and the
  product pages render an explicit "not yet available" state and query no table,
  because no such table exists and inventing one would fabricate a dependency.
- **Standard error pages.** 403, 404, 419 and 500, with no environment name,
  stack trace or route internals.

The **environment name was previously rendered in the footer of every page**,
where any customer could read it. It is now available only on the system
overview, behind `system.view`.

**What this re-audit found and fixed**

These were real defects in already-accepted Stage 3A code, surfaced by building
the surface on top of it:

- `/health` published `DEGRADED` while the diagnostics page reported `UNKNOWN`.
  The two used different calculations. The page now renders the registry's
  aggregate, and the test asserts equality rather than string presence — it had
  been passing because "Unknown" appeared elsewhere in the page.
- `.env.example` documented a `SENDER_`-prefixed retry window, but the platform
  reads `DB_QUEUE_RETRY_AFTER`. Operators had no documented way to satisfy the
  reservation invariant. The suite asserts every `docs/*.md` file names only the
  variable the configuration actually reads.
- The dashboard rendered `app()->environment()` in the body.
- Product input ceilings were far above the worker budget: `max_urls_per_request`
  at 1000 could not be processed inside 240s, and `max_text_input_bytes` at 10M
  was not plausibly processable at all. Reduced to 100 and 1 MiB, tied to the
  worker runtime rather than chosen independently.
- `Password::defaults()->min` is protected in Laravel 12; the security page read
  it and every render of that page was a 500.
- The staged-page shell bound its record parameter positionally, so every
  record route 404'd.
- The settings page iterated a nested configuration tree and passed arrays where
  the template expected scalars.

**Still not started:** the job engine, the extractor, campaign sending,
recipients, suppression, plans, usage tracking, the REST API and billing.

Stage 3C should be scoped to one real workload, chosen deliberately, rather than
to the general-purpose engine — the engine has no stable shape until something
is asked of it.
