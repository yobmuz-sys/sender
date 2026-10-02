# Sender

A modular, cPanel-compatible SaaS platform for data extraction and email campaigns.

The application is built so that a powerful product can run on ordinary shared
hosting. It requires only PHP, MySQL/MariaDB, a web server, filesystem access
and cPanel Cron. Redis, Supervisor, Docker, Node.js servers, queues with
long-running daemons and root access are **not** required at any point.

---

## Current status

> **Stage 5B — recipient validation and audience controls**
> Addresses an extraction finds are now checked, not just collected. A four-stage
> pipeline — syntax, mail route, catch-all, SMTP recipient — records what it
> actually observed and never records an address as inactive on evidence that
> does not support it. Addresses become canonical contacts that every list points
> at, consent is recorded as evidence rather than as a flag, suppression cannot be
> undone by a re-import, and an unsubscribe link works without an account, takes
> effect immediately and is idempotent. SMTP probing is **off by default**,
> because shared hosting usually blocks outbound port 25; with it off, validation
> honestly reports `UNKNOWN — verification blocked` and never invents a verdict.
> Campaign sending remains future work.

Stage 5B complete. 638 tests / 2037 assertions passing. Previous accepted
baseline: `70b380a`.

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
| Evidence-based recipient validation, with `UNKNOWN` distinguished from inactive | done |
| Canonical contacts, lists, consent records and suppression | done |
| Immediate, idempotent, unauthenticated unsubscribe | done |
| Honest staged pages for features, plans, campaigns, API, billing, audit | done |
| Page-completeness and navigation-integrity test suite | done |

### Known intentional limitations

These are accepted consequences of the platform's current scope, not defects. None
of them should be "fixed" by weakening a threshold or a check.

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
12. **Validation is off unless you turn it on.** `SENDER_VALIDATION_SMTP_PROBING`
    defaults to `false`, because shared hosting very often blocks outbound port
    25 and a validator that cannot reach a mail server has nothing to report. With
    probing off, every address that passes syntax and DNS is recorded as `UNKNOWN`
    with the reason *verification blocked*. That is reported as such and excluded
    from sending — never as active and never as inactive. `sender:diagnose` prints
    the current setting.
13. **Validation cannot prove delivery.** An SMTP `250` to a recipient check means
    the receiving server will accept mail for that address. It does not mean the
    message arrives, and it is reported as *likely active*, never as *active* or
    *verified*. There is no accuracy percentage anywhere in this product, because
    no such figure is defensible.
14. **A catch-all domain cannot be resolved.** When a domain accepts mail for an
    address that cannot exist, *acceptance proves nothing*, and every mailbox at
    that domain is recorded as `UNKNOWN` rather than as likely active. This is the
    most valuable single check in the pipeline and it costs one probe per domain
    per cache window.
15. **Consent is unknown by default, and stays that way.** A scraped or purchased
    list arrives with no evidence that anybody agreed to be contacted, so consent
    reads `Unknown` and the contact is excluded from sending. An operator's own
    attestation is recorded and reported but never promotes a contact to
    `Confirmed` — "you told us they agreed" is not the same as holding a record
    that they did.
16. **The suppression list cannot be undone by the UI.** `unsubscribed` and
    `complaint` are terminal: `SuppressionList::clear()` returns `false` for both
    and the platform offers no control that could undo them. Clearing an operator
    suppression is the one case it will lift.

**Not** implemented, and deliberately so at this stage: multi-URL extraction,
file upload, XLSX/DOCX/PDF/XML parsing, campaign sending, bounce and complaint
feedback ingestion, open and click tracking, rate-control implementation,
warm-up, scoring, IP rotation, analytics, plans, entitlements, usage tracking,
PHP integration and billing. See [docs/ROADMAP.md](docs/ROADMAP.md).

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

**Two questions are kept apart.** *SMTP verified* asks whether the account
connects, authenticates and submits. *Sending ready* asks whether the configured
sender meets the minimum sending policy. A transport can be fully reachable while
sending readiness is blocked — an unencrypted transport is exactly that case, and
the tests assert reachability and eligibility stay separate answers.

**Reverse DNS is reported per infrastructure, not per endpoint.** For Gmail,
Workspace and cPanel — any relay the customer does not operate — the observed
endpoint address is definitively *not* the sending infrastructure, so no reverse
DNS finding is made. Reporting a warning there would invent a deliverability
problem the customer cannot fix. For a custom host the customer runs, forward-
confirmed reverse DNS is checked and warned on when absent.

### Sending policy defaults

`sender.sending.minimum_interval_seconds` defaults to **30 seconds** between
recipients.

This is *this application's* conservative floor for ordinary shared hosting. It
is not a Gmail requirement, not a Yahoo requirement, and not derived from any
provider's published figure — there is no universal "best interval". It exists so
a misconfigured transport cannot dump a whole audience into a provider at once.
A provider's stricter limit always wins.

Observed complaint rates are read as bands, not as a score: above
`sender.sending.complaint_rate.warn_above` (0.1%) a warning, above
`block_above` (0.3%) sending is paused. They are inert until bounce and complaint
feedback exists, because there is currently no complaint data to read.

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

## Audience and recipient validation

Finding addresses is the easy half. Stage 5B is about what happens to them
afterwards, and every decision in it is shaped by one rule:

> **Maximise the precision of `CONFIRMED_INVALID`. Never classify uncertain
> evidence as inactive.**

Calling a live recipient inactive costs one address. Calling a dead one active
costs a bounce, and on a shared host enough of them cost the sending reputation
outright. So the pipeline is built so that only one kind of evidence can produce
an invalid verdict.

### The four stages

| Stage | Question | A negative answer means |
| --- | --- | --- |
| Syntax | can this string be an address at all? | `CONFIRMED_INVALID` — deterministic, decided locally |
| Mail route | does the domain publish MX, or an address for the implicit route? | `CONFIRMED_INVALID` only on NXDOMAIN or *exists but takes no mail* |
| Catch-all | does the domain accept an address that cannot exist? | every mailbox at the domain becomes `UNKNOWN` |
| Mailbox | does the recipient server accept this address? | `LIKELY_ACTIVE`, or `CONFIRMED_INVALID` on `5.1.1` |

There is **no probe message**. Nothing is ever sent to a recipient to find out
whether they exist.

### Why a bare `550` is not an invalid verdict

This is the single most consequential mapping in the product. RFC 5321 makes an
unambiguous reply code optional, and the large providers answer `550` to *every*
address rather than leak their user list to anyone willing to ask. So:

| Reply | Verdict |
| --- | --- |
| `250` | `LIKELY_ACTIVE` |
| `251` | `UNKNOWN` — forwarding is a routing statement, not an existence one |
| `252` | `UNKNOWN` — the server says it cannot verify |
| `4xx` | `UNKNOWN` — the server asked us to try again |
| `550`, no enhanced code | `UNKNOWN` — a rejection is what anti-enumeration is built from |
| `550` + `5.1.1` | `CONFIRMED_INVALID` |
| `550` + `5.1.2` / `5.1.3` | `CONFIRMED_INVALID` |
| `550` + `5.1.4` | `UNKNOWN` — explicitly ambiguous |
| `550` + `5.7.x` | `UNKNOWN` — the server's policy, not the mailbox |
| timeout / refused | `UNKNOWN` — nothing was learned |
| domain accepts a synthetic address | `UNKNOWN` for the whole domain |

Only four reasons may accompany `CONFIRMED_INVALID`: `invalid_syntax`,
`domain_not_found`, `no_mail_route` and `mailbox_not_found`. That list is the
formal accuracy rule, written as code, and the test suite asserts both directions
— that each reply shape maps to the right verdict, and that nothing outside the
allowlist can produce an invalid one.

The statuses a customer sees are **Likely active**, **Confirmed inactive**,
**Unknown** and **Risky**. There is no percentage and no guarantee anywhere,
because no such figure is defensible. An address the platform could not check is
reported as unknown and **excluded from sending by default**: not sending loses
one recipient we might have reached, while sending risks a bounce and a provider
signal held against the whole account.

### Caching, because accuracy has to be affordable

- **Mail route** — one DNS lookup per domain per window, cached in a table rather
  than in memory so a worker and the page that renders the report are looking at
  the same evidence, and so the work survives a deploy. `Unavailable` is never
  cached: caching a resolver outage would classify a whole audience as unknown
  for a day.
- **Catch-all** — one probe per domain per window, from an address built from 32
  hex characters of cryptographic randomness. A guessable probe address
  (`test@example.com`) proves nothing, because a mail server may treat well-known
  local parts specially.
- **Mailbox** — one recipient check per mailbox until its evidence expires, held
  on the canonical contact. A positive result expires in days; a
  `CONFIRMED_INVALID` result is kept longer, because a mailbox that does not
  exist is a stable fact. The mailbox cache is tenant-scoped; the DNS cache is
  not, because a domain either publishes MX records or it does not.

### Canonical contacts, lists, consent, suppression

**One address is one contact per tenant.** Lists hold memberships, never copies,
so a validation result, a consent record and a suppression recorded once apply to
every list the contact is on. Duplicates are prevented by
`unique(user_id, normalized_email)`, not by an `exists()` check — that window is
where two concurrent pastes both see nothing.

**Consent is evidence, derived, never a flag.** `Unknown`, `Confirmed` and
`Withdrawn`. A withdrawal anywhere in the history wins over everything except a
newer confirmation, and the comparison is on timestamps rather than on insertion
order. `imported_with_user_attestation` is recorded, reported and searchable, and
does not make anybody sendable.

**Suppression cannot be undone by re-importing.** `unique(user_id, contact_id)`
means there is one row per contact per tenant; recording twice is a no-op; and
nothing that imports, extracts or lists a contact writes to that table at all. The
recorded reason is never downgraded — a complaint stays a complaint.

**Unsubscribe is immediate, idempotent and unauthenticated.** The token is 64
characters of CSPRNG output, stored only as a SHA-256 digest, so nothing in the
URL or in a leaked backup identifies a recipient. `GET` confirms, `POST` acts, so
a mail client's link preview cannot unsubscribe somebody on their behalf. The
suppression is written synchronously — there is no worker between the click and
the record.

### Pages

| Path | Purpose |
| --- | --- |
| `/extractor`, `/extractor/{task}` | Task list and report, with a per-stage badge and honest progress |
| `/lists`, `/lists/new`, `/lists/{list}` | Lists, their members, and what is actually sendable from each |
| `/suppression` | Your suppressions, with the reasons that can and cannot be lifted |
| `/unsubscribe/{token}` | Public. Confirm and unsubscribe. No account, ever |
| `/admin/audience` | Contacts, classifications, tasks and suppressions across tenants |
| `/admin/validation` | What could not be classified, and why; failed and stuck tasks |
| `/admin/lists`, `/admin/suppression` | The same, for an operator who has to act |

`/admin/validation` exists instead of a new `/admin/tasks` because `/admin/jobs`
and `/admin/runs` already report the queue. A third page listing the same tasks
in a different order would leave an operator unsure which one was authoritative.
There is deliberately **no send button** anywhere: an audience that has never been
checked for consent is not an audience anybody should be able to mail.

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

### Validation on shared hosting

Recipient validation needs outbound port 25, which most shared hosts block. It is
therefore **off by default**:

```
SENDER_VALIDATION_SMTP_PROBING=false
```

With it off, validation still runs its syntax and DNS stages — which cost
nothing and are frequently conclusive — and everything else is reported as
`UNKNOWN` with the reason *verification blocked*. The task finishes, the counts
are honest, and nothing is ever recorded as inactive on that basis.

`php artisan sender:diagnose` prints the current setting as
`recipient validation probing`. If your host permits outbound port 25, set the
variable to `true`; if it does not, leave it off. The remaining knobs —
`SENDER_VALIDATION_SMTP_TIMEOUT_SECONDS`, the three cache TTLs,
`SENDER_VALIDATION_BATCH_SIZE` and `SENDER_VALIDATION_MAX_PER_PASS` — are in
[docs/DEPLOYMENT_CPANEL.md](docs/DEPLOYMENT_CPANEL.md).

### One task at a time per account

An account runs one processing task at a time; a second paste waits its turn and
is dispatched by the first task finishing. Validation is the expensive half of
the pipeline, and two of them at once on a shared host is twice the outbound
connections. Different accounts are never serialised against each other — one
tenant's backlog must not be able to stall another's work.

This is sequenced by dispatch order rather than by a lock: a waiting task's job
is simply not queued until its predecessor reaches a terminal state. There is no
lock to take, no lock to time out and no worker invocation spent spinning. The
trade-off is that a task whose worker is killed without `failed()` running blocks
the account until the queue's `retry_after` re-reserves the job and the retry
count exhausts — bounded and recoverable, and visible on the task's own badge.

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
| `/unsubscribe/{token}` | Confirm (GET) and act (POST). Unauthenticated, immediate |

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
| `/extractor`, `/extractor/new`, `/extractor/{task}`, `/extractor/{task}/csv` | Extraction: create, history, report, CSV download. Requires a confirmed address and the worker |
| `/lists`, `/lists/new`, `/lists/{list}`, `/{list}/edit` | Named lists, their members, and what is sendable from each |
| `/lists/{list}/contacts`, `/contacts/{contact}` | Add pasted addresses (POST), remove one member (DELETE) |
| `/suppression`, `/suppression/{row}` | Your suppressions and the reasons that can be lifted |
| `/account/smtp`, `/account/smtp/create`, `/account/smtp/{account}`, `/{account}/edit` | Your SMTP transports |
| `/account/smtp/{account}/verify`, `/send-test` | Verification actions, rate limited |
| `/account/deliverability` | Sending health and readiness findings |
| `/files`, `/templates`, `/campaigns`, `/analytics` | Product surfaces — staged shells, see the limitation above |

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
| `/admin/audience` | `contacts.view` |
| `/admin/validation` | `validation.view` |
| `/admin/lists` | `lists.view` |
| `/admin/suppression` | `suppression.view` |
| `/admin/suppression` (POST), `/admin/suppression/{row}` (DELETE) | `suppression.manage` |
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

### The message contract

`CampaignMessage` is a value object with no table behind it — Stage 5C owns
sending, and Stage 5A fixes only the shape. Two decisions live in it because
they are the ones most often taken wrongly later and are invisible until
messages reach mailboxes:

- **Plain text is derived, not optional by luck.** HTML-only input produces a
  text part automatically, which the sender may then review and edit. Marketing
  mail sent as HTML with no alternative is a deliverability handicap, and a
  cosmetic one a caller would never notice was being taken.
- **`parts()` decides the MIME structure once.** `multipart/alternative` whenever
  both bodies exist, so the structure is stated rather than rediscovered at send
  time.

It does not allow a free-text From address: the sender is constrained by
`SenderIdentityPolicy`, so a composed message cannot be built with a From its
transport never authenticated as.
