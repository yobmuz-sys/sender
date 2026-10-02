# Sender

A modular, cPanel-compatible SaaS platform for data extraction and email campaigns.

The application is built so that a powerful product can run on ordinary shared
hosting. It requires only PHP, MySQL/MariaDB, a web server, filesystem access
and cPanel Cron. Redis, Supervisor, Docker, Node.js servers, queues with
long-running daemons and root access are **not** required at any point.

---

## Current status

> **Stage 3E — secure single-URL extraction**
> Pasted-text extraction runs on a bounded `sender:work` worker over the database
> queue. One URL per extraction can now be fetched safely: scheme, port, DNS and
> address checks, pinning against DNS rebinding, per-hop redirect validation,
> streaming with a byte ceiling, and a capability that stays `UNKNOWN` until
> `sender:verify-url` actually measures it. Multi-URL extraction, file upload,
> other document formats and SMTP campaign sending remain future work.

Previous accepted baseline: `5e57606`. This release (Stage 5A) passes 468 tests / 1365 assertions.

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
| Email verification required before any product or admin page | done |
| Account profile, email change, password change, session regeneration | done |
| User suspension with a last-super-administrator guard | done |
| Declarative, permission-aware navigation derived from one source | done |
| Route-derived breadcrumbs, shared shell, standard error pages | done |
| Administration: dashboard, users, roles, jobs, runs, SMTP, system | done |
| Tenant-owned and administrator-assigned SMTP transports | done |
| Encrypted SMTP credentials, never rendered after saving | done |
| Sender-identity enforcement, per transport and per sending domain | done |
| Sending-readiness findings, reported as evidence rather than a score | done |
| Honest staged pages for features, plans, campaigns, API, billing, audit | done |
| Page-completeness and navigation-integrity test suite | done |

### Known intentional limitations

These are accepted consequences of the Stage 3B scope, not defects. None of them
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
7. **Placeholder pages carry no behaviour.** Features, plans, campaigns, API,
   billing and audit are rendered as explicit "not yet available" shells. They
   query no domain table, because no such table exists yet and inventing one
   would fabricate a dependency.
8. **One URL per extraction, and no query string.** `source_type` is `paste` or
   `url`; a URL extraction fetches exactly one address. `source_ref` is stored
   without its query string, because it is both what the worker fetches and
   text an operator and the owner can read — and queries routinely carry tokens.
   The consequence is deliberate and visible: the request is made against the
   path alone, so a site that varies on its query string will return different
   content than the address the user saw. TXT and CSV are accepted as *pasted*
   content; there is no file upload and no dedicated CSV parser.
9. **Background processing depends on cron.** `sender:work` runs from a cPanel
   Cron entry. Without one, extractions are created and queued but never
   processed, and the cron capability reports `UNKNOWN` with the fix attached.
   The suite runs `QUEUE_CONNECTION=sync`, so request-level tests prove the
   application path; `QueueWorkerTest` covers the database-queue path separately.
10. **Settings are read-only in the browser.** There is no web editor for
   configuration. A form that rewrote it would persist into the next deploy,
   would have to reconcile `.env` against cached configuration, and would
   bypass the invariants checked at boot. Operators edit the environment and
   confirm with `sender:diagnose`.
11. **Deliverability findings are evidence, not a prediction.** The readiness
   report can establish that a transport is encrypted, authenticated and
   verified, and that the sending domain publishes SPF, DMARC and any DKIM
   selector it was told about. It cannot establish that a message will arrive, or
   land in an inbox rather than spam — that decision belongs to the receiving
   provider. There is no spam score, because no local calculation can produce an
   honest one. See [Sending health](#sending-health).
12. **Recipient hygiene does not exist yet.** No recipient, list, consent,
   suppression or unsubscribe records have been built. Sending readiness reports
   these as `Not established` rather than as passes, so a fully configured
   transport does not read as ready to send. This is the Stage 5B prerequisite,
   and the reason the campaign engine is not built yet.

**Not** implemented, and deliberately so at this stage: multi-URL extraction,
file upload, XLSX/DOCX/PDF/XML parsing, MX and DNS validation of extracted
addresses, campaign sending, recipients, lists, consent, suppression,
unsubscribe processing, bounce and complaint feedback, rate-control
implementation, analytics, plans, entitlements, usage tracking, PHP integration
and billing. See [docs/ROADMAP.md](docs/ROADMAP.md).

---

## Mail transports

Every tenant can send through their own SMTP, and an administrator can configure
one for them. Both are the same record, differing in one column —
`management_mode` — rather than two tables that would drift.

| Page | Purpose |
| --- | --- |
| `/account/smtp` | Your transports: provider, endpoint, status, From address |
| `/account/smtp/create`, `/account/smtp/{account}/edit` | Configure a transport |
| `/account/smtp/{account}` | Detail, verification, readiness findings, test actions |
| `/account/deliverability` | Sending health across your transports |
| `/admin/smtp/accounts` | Every tenant's transports |
| `/admin/users/{user}/smtp` | One tenant's transports |
| `/admin/deliverability` | Aggregate transport health and recent failures |

The existing `/admin/smtp` page is separate and unchanged: it reports the
**platform's own** mail, which sends password resets and address confirmations
and stays in `config/mail.php`.

**Credentials are encrypted with the application key** and are never rendered
after saving — not into the edit form, not into JSON, not into a log, and not to
an administrator. The plaintext is reachable only by the transport that needs
it, which is an explicit call rather than a property read.

**A verification does not survive a configuration change.** Change the host,
port, encryption, username, password or From address and the account returns to
`Not verified`, because the evidence described a transport that no longer exists.
Renaming an account does not, because nothing about the transport changed.

**The From address must be the address the transport authenticated as.** A
mismatch is refused: it would make this host an open relay for somebody else's
identity, and your provider would answer for a complaint you did not cause.

**SMTP hosts must resolve to publicly routable addresses.** Private, loopback,
link-local and reserved destinations are refused — including `169.254.169.254` —
so a transport cannot be pointed at services inside the network the platform
runs on. The same policy the URL extractor uses, not a second implementation.

**Transports are built per account, never globally.** `sender:work` processes
many jobs per invocation, so mutating `config('mail...')` for one tenant would
leak into the next job. Each send constructs its own transport from its own
account's parameters.

Providers are presets: Gmail, Google Workspace, cPanel and custom. They supply a
host, a port and guidance, and nothing else — every provider uses the same
generic SMTP connection, and your stored settings are what is actually used.
Gmail guidance points at an App Password and tells you not to enter your ordinary
Google account password.

### Sending health

Each finding is reported as `Pass`, `Warning`, `Blocked` or `Not established`.
Checks cover transport encryption, authentication, sender identity, SPF, DKIM,
DMARC and its published alignment policy.

`Not established` is a real answer, not a soft pass. DKIM without a configured
selector is reported that way: the final message is signed by your provider with
a selector this platform is not told, so any record found by searching would be a
guess, and a guessed pass is worse than an admitted gap. Same for authentication
alignment — only the outgoing message's headers can show it.

### What this cannot do

The application improves the practice of sending mail. It cannot guarantee inbox
placement: that decision is made by the receiving provider, from signals this
platform cannot observe.

Deliberately **not** built, and not planned in this form:

- No automatic transport or IP rotation, and no switching to dodge a block or a
  provider's limit.
- No spam-filter bypass, no header randomisation intended to evade filtering.
- No reputation score derived from the SMTP host's IP. A configured relay is
  frequently not the infrastructure the recipient's server sees, so the number
  would have no defensible meaning.
- No "warm up an IP" mechanism for defeating a provider's judgement.

When a transport has evidence of serious delivery problems, the platform reports
it and stops using it. The recovery path is to repair the domain or provider
reputation, or to configure a different relay you legitimately control.

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
failure. `url_fetch` is `UNKNOWN` until an operator runs `sender:verify-url` —
not because cURL is missing, but because its presence says nothing about whether
the network permits the connection. `smtp` is likewise `UNKNOWN` until
`sender:verify-smtp` — see below.

### Verifying URL fetching

```bash
php artisan sender:verify-url
```

The command performs one real fetch through the same hardened
`SecureUrlFetcher` the extraction feature uses, and records what it proved. It
does not report READY because the cURL extension is loaded: a verification that
proves nothing would be worse than no verification, because the capability would
then be able to be wrong.

To test a path this host cannot otherwise reach:

```bash
php artisan sender:verify-url --url=https://example.org/contact
```

A target refused by network policy (a private address, an unsupported port) is
reported as *not established* rather than *unavailable* — that outcome proves the
policy works, not that the network is broken.

### Running the worker

The platform processes queued work from cron. Point a cPanel Cron Job at:

```
*/5 * * * * cd /home/USER/sender && /usr/local/bin/php artisan sender:work >> /dev/null 2>&1
```

`sender:work` drains the database queue within bounds and exits. It is not a
daemon and must not be run in the foreground — on shared hosting a resident
process is killed when the invocation ends and cannot be supervised. Each run is
appended to `scheduled_runs` with its outcome — `running`, `succeeded` or
`failed` — so a worker that starts failing is distinguishable from one that has
stopped being called.

The counts on that record come from the queue's own job events, not from queue
depth. Subtracting depth before and after cannot be right: jobs dispatched
*during* the run inflate the figure, and a job that fails and is retried leaves
the queue without ever having succeeded. Both numbers are durable operational
evidence, so a wrong one is a wrong answer to "did the platform do its work".

`sender:work --max-jobs=N` and `--max-runtime=N` may only *tighten* the
configured ceilings; a value above the configured maximum is clamped and
reported, and a value below 1 is refused rather than quietly replaced. A cron
entry that does something other than it says is worse than one that declines.

`sender:heartbeat` still exists and records the same evidence, for installations
that only want to probe the scheduler. It is history, not proof: the cron
capability verdict comes from `sender:work` alone, so a deployment still running
only the heartbeat reports `UNKNOWN` rather than a healthy scheduler whose queue
is never drained.

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
| `authentication` | the server rejected these credentials |
| `acceptance` | the server accepted a test message from these credentials |

`authentication` appears only when credentials are rejected. Symfony logs in
during the same handshake that opens the socket, so a bad password and an
unreachable host raise the same exception; they are separated and reported
against the stage that actually failed, because an operator told "could not
connect" when the real problem is a bad password would chase the wrong thing.

Omitting `--to` proves the connection only, and the result is `DEGRADED`
because the credentials were never exercised.

**What this does not prove:** that a recipient received anything. Nothing inside
the application can observe a recipient's mailbox. The capability reports the
server accepted a message, and says so rather than letting "available" imply
"delivered".

The result is recorded, and the capability reports it from there, so an ordinary
page view never opens a mail connection. A verification is not trusted
indefinitely: an old one degrades, and one taken against a different `MAIL_MAILER`
degrades immediately, because a verification records what was true when it ran.
Discard one with `php artisan sender:verify-smtp --forget`.

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

### Public

| Path | Purpose |
| --- | --- |
| `/` | Landing page |
| `/health` | JSON readiness verdict — the aggregate status only, no host detail |
| `/up` | Laravel liveness probe, answering without application code |
| `/register`, `/login`, `/forgot-password`, `/reset-password/{token}` | Authentication |

`/health` returns exactly `status` and `capability`. Anything more would let an
unauthenticated caller enumerate the host.

### Authenticated

`/dashboard` is deliberately reachable by an authenticated account whose address
is not yet confirmed, so that a user who has just signed up has somewhere to land
while the verification banner explains the next step. Everything below requires
a confirmed address.

| Path | Purpose |
| --- | --- |
| `/dashboard` | Account overview. Authenticated only — not confirmation-gated |
| `/email/verify`, `/email/verify/{id}/{hash}` | Address confirmation; `/email/verification-notification` resends (POST) |
| `/account/profile` | Name, locale, time zone (PATCH) |
| `/account/security` | Password change (PUT) |
| `/extractor` | Extraction: create, history, detail, CSV download. Requires a confirmed address and the worker |
| `/account/smtp`, `/account/smtp/create`, `/account/smtp/{account}`, `/{account}/edit` | Your SMTP transports |
| `/account/smtp/{account}/verify`, `/send-test` | Verification actions, rate limited |
| `/account/deliverability` | Sending health and readiness findings |
| `/files`, `/lists`, `/templates`, `/campaigns`, `/suppression`, `/analytics` | Product surfaces — staged shells, see the limitation above |

### Administration

Each route requires its own permission; an account without it receives 403.

| Path | Permission |
| --- | --- |
| `/admin` | `admin.view` |
| `/admin/users`, `/admin/users/create`, `/admin/users/{user}`, `/admin/users/{user}/edit` | `users.view` / `users.create` / `users.edit` |
| `/admin/users/{user}/suspend`, `/reinstate` | `users.suspend` |
| `/admin/roles` | `users.view` |
| `/admin/features` | `features.view` |
| `/admin/plans` | `plans.view` |
| `/admin/campaigns` | `campaigns.view` |
| `/admin/jobs`, `/admin/runs` | `jobs.view` |
| `/admin/jobs/{run}/retry`, `/forget` | `jobs.manage` |
| `/admin/smtp`, `/admin/smtp/verification` | `system.view` |
| `/admin/smtp/verify`, `/admin/smtp/send` | `system.manage` |
| `/admin/smtp/accounts`, `/admin/smtp/accounts/{account}`, `/admin/users/{user}/smtp` | `mail_accounts.view` |
| `/admin/smtp/accounts/create`, `/{account}/edit`, `/status/{status}` | `mail_accounts.manage` |
| `/admin/smtp/accounts/{account}/assign` | `mail_accounts.assign` |
| `/admin/smtp/accounts/{account}/verify`, `/send-test` | `mail_accounts.manage` + rate limit |
| `/admin/deliverability` | `deliverability.view` |
| `/admin/system`, `/admin/system/diagnostics` | `system.view` |
| `/admin/system/subsystems`, `/{subsystem}/enable`, `/disable`, `/reset` | `system.manage` |
| `/admin/settings` | `system.view` |
| `/admin/api`, `/admin/billing`, `/admin/audit` | staged shells |

`/admin/system/diagnostics` renders in both states. With
`SENDER_DIAGNOSTICS_ENABLED=false` it explains what the page would show and
where to act, rather than 404ing — a navigation link that fails by configuration
is worse than one that states the condition.

`/diagnostics` is retained as a redirect to `/admin/system/diagnostics` so
existing bookmarks keep working.

A suspended account is rejected at sign-in with the same generic
`auth.failed` response as an invalid password, and is refused entry by
middleware on any subsequent request.

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
