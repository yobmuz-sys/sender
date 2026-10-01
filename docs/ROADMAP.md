# Roadmap

Where the project is and what comes next. This is a plan, not a description of
the code — for what actually exists, see the README and `ARCHITECTURE.md`.

---

## Position

> **Stage 3D — extraction operational hardening**
> The pasted-text workload now runs the way it always claimed to: a bounded
> `sender:work` worker on the database queue, durable run evidence, streaming
> bounded-memory extraction, and paginated history and results. URL extraction,
> file upload, other formats and SMTP campaign sending remain future work.

**Stage 3A-3D complete. The first workload runs in production; a general-purpose
job engine still does not exist.**

Accepted baseline: `d2e56eb`. 304 tests / 848 assertions passing.

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

**Still not started:** a general-purpose job engine, URL extraction, file upload,
other file formats, campaign sending, recipients, suppression, plans, usage
tracking, the REST API and billing.

---

## What Stage 3C delivered

The first real workload, chosen so the engine would have a shape grounded in
something rather than guessed at. It is deliberately narrow.

- **Pasted text extraction.** Content is persisted, then processed on the
  database queue. Addresses are matched, lower-cased, validated and
  de-duplicated within one extraction.
- **Only the identifier crosses the queue boundary.** The worker reads content
  back from the database, so a large paste cannot inflate the queued payload.
- **Finite execution.** The job declares a `timeout` below the connection's
  `retry_after`, so an overrunning worker is killed and retried rather than being
  handed to a second worker while the first still runs.
- **Idempotency.** Results carry a unique key on `(extraction_id, email)`, so a
  retry cannot duplicate rows.
- **Ownership protection.** Another account's extraction returns a
  non-disclosing 404, not a 403 — a 403 would confirm the record exists and let
  one account enumerate another's identifiers. The same applies to the CSV
  download.
- **CSV download** of the extracted addresses.

**What this audit found and fixed.** The vertical slice was present but the job
was never dispatched anywhere in the application: the test called `handle()`
directly, so the suite passed while the workload did nothing. A pasted extraction
sat at `pending` forever. The test had asserted `status: pending` — that
assertion only held because the job never ran. Both are fixed, and the test now
asserts the extraction reaches `completed`.

The create form also offered **URL** and **file upload**, neither of which was
implemented; the controller accepted `source_type` of `url` or `file` and stored
a request the platform could not act on. Both are removed rather than left as
claims. The form now states that URL extraction and upload are not available.

**Still deferred:** URL extraction, XLSX, DOCX, PDF, XML, MX and DNS validation,
SMTP campaign delivery, recipients, suppression, billing and the REST API.

---

## What Stage 3D delivered

Stage 3C proved the extractor *could* work. Stage 3D makes it work in the
environment the application was actually designed for.

The finding that motivated this stage is a gap between what the suite proved and
what production would have done. `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so
every dispatch executed inline and every test passed. Production `.env.example`
sets `QUEUE_CONNECTION=database`. There was no `sender:work` command. The
consequence, precisely:

    web request -> dispatch -> jobs table -> nothing ever picks it up

A green suite and a non-functional platform, which is the worst possible
combination: the evidence looked like coverage.

- **``sender:work``** processes a bounded number of jobs within a bounded
  runtime and exits. It wraps Laravel's own ``queue:work`` rather than
  reimplementing queue internals, and owns only the bounds, the operator kill
  switch and the run evidence.
- **Every bound comes from ``DeploymentLimit``** or configuration. A magic
  runtime in the command would be the first thing to drift out of step with the
  reservation invariant it has to satisfy.
- **It refuses to start when the reservation invariant does not hold**, rather
  than creating the double-processing condition the invariant exists to prevent.
- **A runtime or job override can only tighten the ceiling**, never raise it.
- **Durable run evidence** via the existing ``RunRecorder``. No second run table
  and no second heartbeat system.
- **The cron probe moved to ``sender:work``**, because a scheduler that fires and
  successfully processes the queue is stronger evidence than one that fires and
  does nothing. A deployment failing every night on a misconfigured queue would
  otherwise be reported healthy. ``sender:heartbeat`` is retained and its runs
  still count.

### Extraction hardening

- **The input ceiling is a byte limit read from
  ``DeploymentLimit::MaxTextInputBytes``**, enforced with ``strlen``. The old
  ``max:20000`` was a literal *and* was not a byte limit: Laravel counts
  characters, so a character-based rule accepts up to four times the intended
  bytes on multi-byte input. An unconfigured limit now fails closed.
- **Processing is chunked and batched.** The job no longer runs one
  ``preg_match_all`` over the whole content and holds every candidate and every
  pending row in memory before writing. Chunks overlap by 320 bytes so an
  address spanning a boundary is not lost.
- **Idempotency is enforced by the database**, not an ``exists()`` check that
  leaves a race between the check and the write.
- **Explicit state transitions** ``pending -> processing -> completed|failed``
  with ``started_at``, ``completed_at``, ``processed_count`` and ``failed_count``.
  A throwing job records a failure rather than leaving a row claiming success,
  and the customer can see it.
- **History and results are paginated.** ``->get()`` and ``load('results')`` both
  loaded unbounded sets; the detail page now paginates results and the CSV
  download streams in 500-row chunks.
- **Pasted content is never serialised** into a queue payload, a log line or a
  model array.

### What remains true about the capability

TXT and CSV are accepted as *pasted* content: one regex pass over whatever text
is submitted. There is still no file upload and no dedicated CSV parser. The
documentation says exactly that rather than claiming CSV support the code does
not have.

**Still deferred:** URL extraction, file upload, XLSX, DOCX, PDF, XML, MX and
DNS validation, SMTP campaign delivery, recipients, suppression, billing and the
REST API.