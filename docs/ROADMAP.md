# Roadmap

Where the project is and what comes next. This is a plan, not a description of
the code — for what actually exists, see the README and `ARCHITECTURE.md`.

---

## Position

> **Stage 5C — campaign engine, in progress**
> Stage 5C has delivered **templates** and the **campaign domain**: a campaign
> copies its message and its audience at launch and reads neither again, so a
> template edited afterwards cannot change what is already going out. Preflight
> answers PASS/WARN/BLOCK/UNKNOWN from one service the builder, the campaign page
> and the start action all call — and the start action calls it again on the
> server. Recipients, retries and pacing live in database rows rather than in a
> process, so a worker killed mid-campaign resumes from the state and two workers
> cannot send the same message. Pacing is a minimum interval enforced from a
> timestamp, and the pages say plainly that the hosting scheduler decides when the
> worker runs. A transport that stops working stops its campaign; it is never
> quietly switched to another account. Bounce and complaint ingestion and
> analytics are next, on the delivery records this stage leaves behind.
>
> **Stage 5B — recipient validation and audience controls**
> Addresses an extraction finds are checked, not merely collected. A four-stage
> pipeline — syntax, mail route, catch-all, SMTP recipient — records what it
> observed, and only deterministic local checks or an allowlisted `5.1.x` status
> can produce `CONFIRMED_INVALID`; a timeout, a `4xx`, a `252`, a bare `550`, a
> catch-all domain and an unreachable resolver are all `UNKNOWN`. Addresses become
> canonical contacts that lists point at rather than copy, consent is recorded as
> evidence and derived rather than stored as a flag, suppression is enforced by a
> database constraint so a re-import cannot undo it, and the unsubscribe link is
> opaque, immediate, idempotent and unauthenticated. SMTP probing is **off by
> default**, because shared hosting usually blocks outbound port 25; with it off,
> validation reports `UNKNOWN — verification blocked` and invents nothing. There is
> no send button anywhere and no accuracy percentage, because neither is
> defensible.
>
> **Stage 5A** delivered mail transports, sender identity and deliverability
> preflight: tenants and administrators can each configure SMTP transports;
> credentials are encrypted at rest and never rendered back; the From address is
> bound to the authenticated username; transports are built per account rather
> than by mutating global mail configuration; SMTP hosts are subject to the same
> global-routability policy as submitted URLs; and `DeliveryReadiness` reports
> PASS/WARN/BLOCK/UNKNOWN evidence rather than a score.
>
> **Stage 3E** delivered secure single-URL extraction:
> Pasted text and one URL per extraction, both on the bounded `sender:work`
> worker. URL fetching is treated as a network-security workload, not a form
> field: scheme, port, DNS and global-routability checks, pinning against DNS
> rebinding, per-hop redirect validation, streaming with a 2 MiB ceiling, and a
> capability that stays `UNKNOWN` until `sender:verify-url` measures it.
>
> **Stage 3D.1** corrected four semantic defects found in the Stage 3D audit:
> `--max-jobs` could raise the configured ceiling; the recorded job count came
> from queue depth rather than job events; `sender:heartbeat` could still
> produce a `READY` cron verdict; and a retryable extraction failure briefly
> presented as terminal.

**Stage 5B complete; Stage 5C begun. Addresses are validated and the audience is
auditable; reusable message content exists; nothing sends mail yet.**

Stage 5B complete with templates landed as the first Stage 5C feature, plus a
two-control recipient probing switch. 753 tests / 2704 assertions passing.
Previous accepted baseline: `70b380a`.

## The next objective: sending

The audience layer now exists, so the binding constraint is no longer whether the
platform can find an address or decide whether to trust it. It is what happens
between the audience and a mail server.

```text
5A  SMTP transport + per-user/admin assignment + deliverability preflight   DONE
      ↓
5B  Sender identity, recipients, lists, consent, suppression, unsubscribe   DONE
      ↓
5C  Campaign engine, bounded queue, rate control, preflight, delivery state   ACTIVE
      Templates DONE; campaign domain NEXT
      ↓
5D  Bounce and complaint feedback, provider adapters, sending analytics
      ↓
4F  Remaining extraction formats (upload, XLSX, DOCX, PDF, XML)
      ↓
3F  Multi-URL extraction, revisited when its shape is actually needed
```

Two boundaries hold for all of it:

- **Platform mail and user mail stay separate.** The installation's SMTP is for
  password resets, address confirmation and system notifications. A tenant's
  campaign transport must never become the application's transactional mailer.
  `SmtpCapability` continues to answer only the first question.
- **Deliverability practice, not inbox placement.** No feature here promises
  delivery, repairs an IP reputation, rotates transports to evade a provider's
  block, or bypasses a sending limit. See `ARCHITECTURE.md` for the reasoning.

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
| 5 | SMTP campaign engine | 5A and 5B complete; 5C begun (templates, campaign domain, preflight, durable audience, worker) |
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

Recipients, lists, consent, suppression, unsubscribes, bounce and complaint
feedback, rate-control implementation, campaigns and bulk sending, analytics,
open and click tracking, multi-URL extraction, file upload, the job engine,
plans, entitlements, usage tracking, the REST API, PHP integration and billing.

None of these should be started before the stage that establishes the
foundation they depend on. Note that Stage 2 built the *vocabulary* for
entitlements and subsystem control, not the features that use them: nothing
grants a plan, and no feature depends on a capability that has not been
measured.

Stage 5A is the clearest case of that rule. It built transports, identity and
readiness — and stopped. A campaign engine needs consent and suppression to be
meaningful, and a rate controller needs a real delivery outcome to react to.

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

**Still deferred:** multi-URL extraction, file upload, XLSX, DOCX, PDF, XML, MX
and DNS validation, SMTP campaign delivery, recipients, suppression, billing and
the REST API.

## What Stage 3E delivered

Stage 3D proved pasted text could be processed safely on a bounded worker. Stage
3E adds the second source type, and treats it as what it is: an instruction from
a third party for the server to make an outbound request.

```text
one URL
  -> SecureUrlFetcher
     -> scheme, credentials, port, length   (no network touched)
     -> DNS resolution, every address checked for global routability
     -> the validated address pinned via CURLOPT_RESOLVE
     -> bounded HTTP (5s connect, 10s total)
     -> 3xx handled by hand, each hop revalidated from scratch
     -> streamed to a temporary file, aborted past 2 MiB
     -> content type must be text
  -> FileExtractionSource
  -> the existing Extractor, unchanged and unaware of HTTP
```

The controls that matter, and why each exists:

- **Global routability, not a private-range blacklist.** A blacklist has to be
  complete; every forgotten range is a hole. `FILTER_FLAG_GLOBAL_RANGE` asks the
  positive question, so an address space PHP has not heard of is refused.
- **Every resolved address, not the first.** A host resolving to one public and
  one private address would leave the choice to whoever answers next — and the
  answer can differ between the check and the connection.
- **Pinning.** Resolving, checking and then requesting the hostname normally
  defeats both checks above, because the socket resolves the name again.
- **Redirects by hand.** A safe first response must not launder an unsafe second
  one, and a client following internally would reuse the previous hop's pinned
  address.
- **A byte ceiling enforced during the transfer.** Checking afterwards bounds
  nothing; a server that keeps sending fills the disk before any check runs.
- **Text only.** This extracts addresses from pages; it is not a downloader.

A refused URL records a *category* (`blocked_destination`, `http_error`,
`unsupported_content_type`, …), never a libcurl message — those describe this
network, not the user's request, and the record outlives the deployment. A
refusal is terminal rather than retried, because the same URL will be refused
identically forever.

**One URL per extraction.** `max_urls_per_request` remains 100 at the
installation level, but nothing implements or advertises it: one request is one
URL, one extraction, one bounded job. Whether many URLs become many jobs or one
job is a Stage 3F decision, deliberately not pre-empted here.

**Still deferred:** multi-URL extraction, file upload, XLSX, DOCX, PDF, XML, SMTP
campaign delivery, bounce and complaint feedback, billing and the REST API.
Recipient validation, recipients, lists, consent, suppression and unsubscribe
were **not** deferred — they shipped in Stage 5B.

## Deferred: Stage 3F — multi-URL workload architecture

The open question is shape, not code: does one extraction with many URLs become
many bounded jobs, or one job per extraction? The single-URL slice supplies the
measurements that decide it — per-fetch time, retry rates, and how much of the
worker's runtime a single page consumes.

That work is **deferred behind Stage 5A–5D**. Nothing in the mail product
depends on it, and the extraction platform is stable enough to leave alone.
---

## What Stage 5A delivered

### One transport, two owners

`smtp_accounts` holds a tenant-owned transport and an operator-assigned one, and
the two differ only in `management_mode`. A tenant can hold several; nothing tries
them in turn, and there is no automatic failover or rotation.

Assignment **moves** the row rather than copying it, so one encrypted credential
never exists in two rows that could be verified and disabled independently.

### The credential is a type

Encrypted in an Eloquent cast, returned as an `SmtpSecret` whose only accessor is
`reveal()`. The model hides the attribute, so `toArray()` — a log line, an
exception dump, a queued payload — produces ciphertext. `secret` is in
`dontFlash`, so a validation failure cannot echo it back into a form.

### Verification follows the configuration

A fingerprint over host, port, encryption, auth mode, username, From address and
a hash of the secret. Changing any of them returns the account to `UNVERIFIED`;
renaming it does not. `SmtpVerifier` was refactored onto `SmtpTransportDefinition`
rather than duplicated, so the platform mailer and a tenant account are proved by
one implementation with the same stage names.

### Identity and network policy

The From address must be the authenticated SMTP username. SMTP hosts reuse the
URL extractor's `DnsResolver` and `IpPolicy`, so every resolved address must be
globally routable and one private answer refuses the whole host.

### Readiness, not a score

`DeliveryReadiness` reports PASS/WARN/BLOCK/UNKNOWN per check, with UNKNOWN as a
real answer — DKIM without a known selector and DMARC alignment both stay
Unknown. At the time of writing, consent, suppression, unsubscribe and rate policy
were reported as not built; Stage 5B built the first three, and `README.md`
records what they now report. No numerical score exists because inbox placement is
the receiving provider's decision, made from signals this platform cannot observe.

`SendingRatePolicy` and `DeliveryOutcome` are declared with no implementation, so
Stage 5C inherits a shape where a throttle leads to backing off rather than to
transport switching, and `RateDecision::pause()` exists as a real result.

### What was deliberately not built

No campaigns, no rate controller, no analytics, no multi-URL extraction — and no
transport rotation, IP warm-up, reputation score or filter-evasion mechanism. The
last group is not merely deferred: it is the model of the product this platform
declines to implement.

---

## What Stage 5B delivered

### The accuracy rule, written as code

One rule governs the whole stage: **maximise the precision of
`CONFIRMED_INVALID`, and never classify uncertain evidence as inactive.** Calling
a live recipient inactive costs one address; calling a dead one active costs a
bounce, and enough of them cost the sending reputation.

So `ValidationReason::definitive()` is an allowlist of exactly four entries —
`invalid_syntax`, `domain_not_found`, `no_mail_route`, `mailbox_not_found` — and
nothing outside it may produce an invalid verdict. Everything else lands in
`UNKNOWN` with the reason it could not be classified: a timeout, a `4xx`, a
`252`, a `251`, a policy `5.7.x`, an ambiguous `5.1.4`, a disabled mailbox, an
unreachable resolver, a catch-all domain, and probing being switched off.

The consequence that is easy to get wrong is the bare `550`. It is the single
most consequential mapping in the product, because a server that answers `550` to
everything is a server declining to leak its user list, and reading that as "no
such mailbox" discards a live audience by the thousand. Only `5.1.1` means what a
confirmed-invalid verdict claims to mean.

### Four stages, and no probe message

Syntax → mail route → catch-all → mailbox. Each layer runs only if the previous
one did not produce a definitive answer, and every layer's failure mode is
`UNKNOWN` rather than a pass. Nothing is ever sent to a recipient to find out
whether they exist.

`DnsMailRouteResolver` distinguishes *the domain publishes nothing* from *our
resolver is down* with a control probe of an RFC 2606 `.invalid` name, because
treating them the same would let a momentary outage classify an entire audience as
invalid. `Unavailable` is never cached.

### Caching, so the accuracy is affordable

One DNS lookup per domain per window; one catch-all probe per domain per window,
from an address built from 32 hex characters of CSPRNG output; one recipient check
per mailbox until its evidence expires. The DNS cache is a table rather than a
memory cache so a worker and the page rendering the report read the same evidence
and the work survives a deploy. It is not tenant-scoped — a domain either
publishes MX records or it does not — while the mailbox cache is, because two
accounts may hold the same address with genuinely different evidence.

Both affirmative and negative catch-all verdicts are cached; only `unknown` is
not, because caching one would freeze a single unreachable moment into a verdict
about every mailbox at the domain.

### Canonical contacts, and lists that point rather than copy

`unique(user_id, normalized_email)` makes one address one contact per tenant, and
lists hold membership rows rather than contact copies. A validation result, a
consent record and a suppression recorded once therefore apply to every list. The
alternative is what makes "did I unsubscribe this person?" answerable only by
checking every copy.

### Consent as evidence

`Unknown` / `Confirmed` / `Withdrawn`, derived from records rather than stored as
a flag, because a boolean cannot distinguish "the recipient signed up" from
"somebody pasted this list". A withdrawal outranks everything except a newer
confirmation, compared on timestamps rather than insertion order. An operator's
attestation is recorded and reported and never promotes a contact to `Confirmed`.

### Suppression that re-importing cannot undo

`unique(user_id, contact_id)` is the mechanism. Nothing that imports, extracts or
lists a contact writes to that table, so the sequence *recipient unsubscribes →
tenant re-imports tomorrow → tenant sends again* is impossible rather than merely
unlikely. `unsubscribed` and `complaint` are terminal; `clear()` returns `false`
for both and no control is offered that could undo them.

### Unsubscribe

64 characters of CSPRNG output, stored only as a SHA-256 digest. A signed URL
would be tamper-proof but decodable, which is the wrong trade for the one link
this platform puts into strangers' inboxes. `GET` confirms and `POST` acts, so a
mail client's link preview cannot unsubscribe somebody on their behalf, and the
suppression is written synchronously so the next campaign cannot be assembled from
an audience that still contains this person.

### A second job, and bounded passes

`ProcessExtractionJob` fetches; `ValidateExtractionJob` checks. One job doing
both would perform up to thirty thousand DNS and SMTP operations inside a worker
whose entire runtime budget is 240 seconds, be killed mid-run, and show the
customer a badge that never moves. Each pass links and then checks at most
`max_per_pass` results and re-dispatches itself, so a list of any size is a series
of passes rather than one that overruns. Counters are recomputed from the results
table rather than incremented, which is what makes a killed-and-retried pass
converge instead of compounding.

Network I/O never happens inside a database transaction.

### Sequencing by dispatch order

One active task per account, different accounts independent, enforced by not
dispatching a waiting task's job until its predecessor reaches a terminal state.
A lock that each job takes, times out and re-checks would be a spin loop wearing a
lock's clothes: it burns worker invocations and turns into exactly the "many
workers all spinning on one lock" situation the existing `sender:work` bounds
exist to prevent. The cost — a task whose worker is killed without `failed()`
running blocks the account until the retry count exhausts — is bounded, recoverable
and visible on the task's own badge.

### Off by default, and stoppable without a deploy

`SENDER_VALIDATION_SMTP_PROBING` defaults to `false`, because shared hosting
usually blocks outbound port 25. With probing off, syntax and DNS still run and
everything else is `UNKNOWN` with the reason *verification blocked*. The task
finishes, the counts are honest, and nothing is ever recorded as inactive on that
basis.

Probing is then governed by **two** controls composing as an AND: the deployment
ceiling above, and an operator switch at `/admin/system/subsystems` persisted in
`system_settings`. An operator can stop outbound port 25 mid-incident with no
deploy, and cannot raise a ceiling the deployment has set — the platform cannot
tell a mistake from an intention to try anyway. Entitlement is not an input: a
safety control over the host's outbound traffic must not depend on a commercial
decision, or an unentitled installation would have no way to switch probing off.
`sender:diagnose` names which of the two is responsible, because an operator who
switched it off during an incident and forgot should be able to read their own
decision out of the diagnostic.

`smtp_validation` is also the one subsystem with no capability subject, and that
is deliberate rather than an omission: establishing whether the host permits port
25 would mean dialling a mail server from a diagnostic command, which is the
behaviour the flag exists to prevent. It is decided by the flag and the ceiling.

### No send button, and no accuracy percentage

`AudienceEligibility` was built and consumed by the report pages only, which was
deliberate: a campaign stage that grew its own "eligible" clause would grow its own
version of the rules, and the divergence would show up as a campaign that
included a recipient somebody had unsubscribed from. Stage 5C **reuses that query
rather than replacing it**, and the send button that appeared then was added
against it, so there is still exactly one definition of who may be contacted.
`UNKNOWN` is excluded from sending by default — not sending loses one recipient we
might have reached, while sending risks a bounce and a provider signal held
against the whole account — and the breakdown counts overlap deliberately, because
presenting them as a partition that sums to the list total would be a claim the
data does not support.

There is no percentage anywhere in the product, and the wording is *likely
active* rather than *guaranteed*. A test asserts that no status label contains
"guarantee", "99%" or "100%".

---

## What Stage 5C delivered

Two features so far: **templates**, then the **campaign domain**. Both exist so
that what leaves this platform is defensible, and neither claims anything about
where a message ends up.

### Templates

Reusable message content, tenant-scoped, with a whitelisted placeholder renderer
(`{{email}}`, `{{first_name}}`, `{{unsubscribe_url}}`) that never compiles a
customer's HTML, a preview rendered in a sandboxed frame, and a version counter
that increments only when content actually changes. `Template::snapshot()` is the
contract the campaign stage consumes.

### The campaign list is a read model, not a second domain

`/campaigns` is where a customer finds out what is happening, so its two hard
requirements are arithmetic and isolation, and neither is a rule of its own:

- **Every figure is a count of recipient rows that exist.** The per-status counts
  are correlated subqueries selected in the same statement as the page of campaigns,
  so the screen costs the same number of queries whether the account has two
  campaigns or two hundred. No per-row query, and no estimate derived from a status
  column.
- **Progress is terminal recipients, not sent ones.** A campaign where 4,000 of
  5,000 were sent and 900 were refused is 98% done, and a bar counting only
  successful sends would say 80% when the worker had nothing left to send. The
  numerator is whatever `CampaignRecipientStatus::isTerminal()` calls finished —
  the same answer the campaign page gives to "is this over" — and a draft has no
  percentage at all, because an audience frozen at launch is the denominator and a
  draft has not frozen one.
- **The header counts the whole tenant; the list obeys the filters.** A summary
  that changed when you searched would be answering a different question than it
  appears to. The page says so, and says how many campaigns matched.
- **Ordering is operational.** Sending, stopped by a problem, paused, waiting,
  drafts, then work that is over. The lifecycle order is right for the figures
  along the top and wrong for a list a customer opens to find what is moving.
- **Actions are read off the status, in one place.** `CampaignSummary::actions()`
  is used by the list *and* by the campaign's own page, so the two cannot offer
  different buttons for the same state. Two consequences are deliberate and depart
  from the original brief: a *scheduled* campaign cannot be paused, because it has
  sent nothing to pause and resuming it would throw away the start time the
  customer chose; and cancelling is a link to a confirmation page rather than a
  button in a column of twenty-five rows, because it is the one action on this page
  that cannot be undone.

Filters are search by name or frozen subject, status, and sending account — each
either a real value or absent, so a hand-edited URL cannot produce a page that
silently matches nothing. Date filtering, and any notion of bounce or open
analytics, wait for the data those features would need.

### The campaign page is an operations screen

The list answers *which* campaigns exist; this one answers *what is happening to
this one*. It is the page a customer opens when they need to know whether to act,
so its structure is decided by that question rather than by the shape of the data:

- **The first viewport is state.** Badge, one sentence of plain figures, the action
  that is legal now. The template, the list and the transport are what a campaign is
  *about*; none of it may sit above the answer to "is this sending, and how far".
- **Every figure comes from the same read model the list uses.** The progress bar,
  the six status counts, the headline and the "still to send" figure are
  `CampaignProgress` read four ways, so a percentage on this page and on a row of
  the list cannot disagree.
- **The snapshot section says "frozen" rather than "selected",** and its template
  name is a copied column rather than the live relation. That is not tidiness: the
  `template_id` reference is `nullOnDelete`, so a deleted template would otherwise
  leave a year-old campaign unable to say what it sent.
- **Stored server text is scrubbed on the way out, not on the way in.** The attempt
  history keeps a server's reply verbatim because that is the only evidence a
  future bounce can be matched against; printing it on a page that gets left open
  is a separate act, and it goes through `SensitiveData::redactText()`. The secret
  is never printed at all, and neither is the account's username — the From
  identity is, because recipients see it.
- **Worker visibility is state, not a live feed.** "Next send may happen at 14:32"
  is the pace clock the worker reads and is labelled a floor rather than a promise;
  "a worker is processing this right now" is a live claim; "nothing is waiting on a
  timer" is what a settled campaign looks like. There is no countdown and no
  polling, because a countdown would have to be computed from state that does not
  yet exist.

Two things it deliberately does not have:

**No event log.** The timeline is built from the timestamps a campaign already keeps
plus the attempt history, which is a real log. Individual pause and resume cycles
are therefore not recorded, and the page says so rather than inventing a history
that reads like evidence. An event table written only to draw this list would be a
table with no other purpose.

**No advice that evades a provider.** `CampaignInterruption` explains a pause as a
decision that can be undone and a transport failure as one that cannot, quotes the
reason recorded when the campaign stopped, and tells a customer to fix the account
and start a new campaign. It never suggests another account, because that is the
exact failure `CampaignStatus::Failed` exists to prevent — and advice on this page
would undo it in the one place a customer is reading.

The recipient log is paged on the server at fifty a row, ordered with unsettled work
first, and filtered by address and by status; each row can open its attempt history.
A campaign that has run has as many rows here as it had recipients, so the page's
size is bounded by the page size rather than by the audience — and the counts in
the status filter are read once, with an option disabled when it would return
nothing, because a dropdown offering "Failed (0)" invites a click that produces an
empty table and the suspicion of a bug.

### A campaign is a frozen sending job

The invariant the whole stage is arranged around:

> Template content, audience membership, suppression result and transport
> selection used for a launched campaign must not silently change underneath it.

At launch, in one transaction, a campaign copies the template's subject and both
bodies into its own columns, records the version it copied, and snapshots the
audience into `campaign_recipients`. After that it reads neither the template nor
the list again. Editing a template to version 4 while a campaign built from
version 3 is halfway through sending changes the template and nothing else — and
that is tested, not asserted.

Delivered:

- **Durable recipient state** — `campaign_recipients` with `unique(campaign_id,
  contact_id)`, a per-recipient `next_attempt_at`, and six statuses (`queued`,
  `sending`, `sent`, `failed`, `skipped`, `blocked`). A worker killed mid-campaign
  resumes from this table; nothing important lives in a PHP process.
- **Audience snapshot** — the launch reuses `AudienceEligibility` rather than
  restating it, records only eligible contacts, and does not mutate the list.
  Excluded contacts stay on the customer's list, because deleting them would hide
  the exclusion in the last place they would look for it. Each recipient also
  stores the address it was created for, so deleting a contact cannot rewrite a
  finished campaign's totals.
- **Preflight** — one service answering PASS / WARN / BLOCK / UNKNOWN for name,
  template ownership and readiness, placeholders, unsubscribe link, transport,
  verification, sender identity, deliverability readiness, audience, suppression,
  consent, interval and batch size. The builder, the campaign page and the start
  action call the same service, and the start action calls it **again on the
  server**: a result rendered in a browser is a claim, not a decision. It scores
  nothing and offers no alternative transport.
- **Durable rate scheduling** — the campaign's minimum interval lives in a
  `next_send_at` timestamp, not a sleep. A worker sends what is due, advances the
  clock by one interval, and hands the next opportunity back to the queue as a
  delayed job. A run that is behind catches up at the configured average rate,
  bounded by the batch size. The strictest of the campaign interval, the
  installation floor and the per-run ceiling wins. The UI says *minimum send
  interval* and states plainly that the hosting scheduler decides when the worker
  actually runs.
- **Scheduling is an instant, not a wall-clock** — the builder takes a start time
  and a time zone with today's offset shown in the list, because "09:00" alone is
  not a moment. What the customer typed is stored as the zone they typed it in and
  shown back to them the same way; the database holds UTC and nothing else. A
  scheduled campaign waits for the worker, and *send now instead* discards the
  wait — after re-running the checks, because a customer who no longer wants the
  wait still has to be refused if the transport stopped working in the meantime.
- **Resumable campaign worker** — `ProcessCampaignJob` carries an identifier
  only, runs one bounded pass, and is bounded by the same `WorkerBounds` as every
  other job. Campaign and recipient claims are conditional updates decided by the
  database, so two workers cannot send the same message; a claim left by a killed
  worker goes stale and is taken back.
- **Delivery attempt history** — `delivery_attempts` is append-only, one row per
  attempt with the server's own code and reply, retained so a bounce arriving next
  month can be matched to the submission that produced it.
- **Retry and failure policy** — temporary failures retry with bounded exponential
  backoff and a hard attempt ceiling; a refused recipient fails immediately and
  keeps its code and response; a transport failure **stops the campaign and says
  so** rather than retrying or switching to another account. Suppression is
  re-checked immediately before every submission, so an unsubscribe clicked after
  the snapshot cannot be overtaken by the next campaign.
- **Unsubscribe** — the existing `UnsubscribeLink` gained a `url()` method rather
  than a second mechanism. A message without `{{unsubscribe_url}}` cannot launch,
  `List-Unsubscribe` is sent as a header on every message, and each recipient
  receives their own link.

### Still not built

Bounce and complaint ingestion, provider webhook adapters, sending analytics,
open and click tracking, transport warm-up, IP or SMTP rotation, and the
administrative campaigns page. Those are Stage 5D and 5D-plus; the durable
delivery records they need now exist, so they will be built on the data rather
than retrofitted onto it. `Sent` means the transport accepted the message and
nothing more.