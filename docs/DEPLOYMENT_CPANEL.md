# Deployment on cPanel

Sender is designed to run on ordinary cPanel shared hosting. This document
covers the requirements and the deployment sequence. It is written for the
current stage (capability and deployment control foundation); later stages will
add the cron entries that actually do work.

---

## 1. Requirements

### Software

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | 8.2 or newer | cPanel -> MultiPHP Manager |
| MySQL or MariaDB | MySQL 5.7+, MariaDB 10.3+ | Included with every cPanel account |
| PHP extensions | `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip` | cPanel -> Select PHP Version |
| Composer | 2.x | Needed only on the first deploy, not at runtime |

Node.js is **not** required. Compiled assets are committed under `public/build`.

### PHP settings

Set these in cPanel -> Select PHP Version -> Options:

| Setting | Minimum | Why |
| --- | --- | --- |
| `memory_limit` | 256M | Extraction and campaign processing hold working sets in memory |
| `max_execution_time` | 60 | Long tasks belong in cron, not in web requests |
| `upload_max_filesize` | 10M | Must be at least `SENDER_DEPLOYMENT_LIMIT_MAX_UPLOAD_BYTES` |
| `post_max_size` | 10M | Must be at least as large as `upload_max_filesize` |

These are platform requirements, not preferences. `config/sender.php` declares
them as constants, and loosening them in configuration does not give a host more
memory or more time — it only removes the warning that the host is short.

`php artisan sender:diagnose` reports any of these as `DEGRADED` with the exact
value, so they can be verified rather than assumed. A `DEGRADED` result still
exits `0`: the installation works, just with less headroom. Only an
`UNAVAILABLE` capability exits non-zero.

### Filesystem

- `storage/` and `bootstrap/cache/` must be writable by the PHP user (775 is
  normally sufficient).
- The account needs at least 512M of free space, shared with any extraction or
  campaign storage.

---

## 2. Create the database

In cPanel -> MySQL Databases:

1. Create a database, for example `cpaneluser_sender`.
2. Create a user with a strong password.
3. Add the user to the database with **all** privileges.
4. Note the full prefixed name; cPanel prefixes both database and user.

---

## 3. Upload the application

Upload the repository into a directory outside the web-accessible document
root, for example `~/sender`. Configure the domain's document root to point
directly at `~/sender/public`.

**Only `public/` may be web-accessible.** Keep `artisan`, `composer.json`,
`.env`, `storage/` and `vendor/` outside the document root. If the hosting
provider cannot point the domain at the application's `public/` directory,
do not expose the project root; use a hosting configuration that supports a
separate document root.

### Installation

```bash
composer install --no-dev --optimize-autoloader
```

Run this once. It is not needed on every deploy.

---

## 4. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

```ini
APP_NAME=Sender
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sender.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cpaneluser_sender
DB_USERNAME=cpaneluser_sender
DB_PASSWORD=the-generated-password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=mail.example.com
MAIL_PORT=465
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="noreply@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Setting these does not establish that mail works. A host, a port and a password
can all be present and still be wrong â€” a blocked port, an expired password, a
provider that rejects the sender address. The platform therefore does not derive
the `smtp` capability from configuration at all; it stays `UNKNOWN` until you
verify it:

```bash
php artisan sender:verify-smtp --to=you@example.com
```

These settings are the **platform's own** mail: password resets, address
confirmation and system notifications. They are not a tenant's sending
transport, and nothing a tenant configures in the browser can replace them. See
[Per-user SMTP transports](#per-user-smtp-transports).

This prints one line per stage — `configuration`, `transport`, `connection`,
`authentication`, `acceptance` — and exits non-zero if any of them fails, so it
is safe to run from a deployment script.

Read the result precisely:

- `acceptance ok` means **the server accepted a message from these
  credentials**. It does **not** mean the message was delivered to a mailbox.
  Nothing inside the application can observe that, and the command says so.
- `authentication FAILED` with `connection ok` means the host was reachable and
  the password or username was rejected. That is the common case, and it is
  reported separately so you do not go looking at the network.
- Omitting `--to` proves only the connection and reports `DEGRADED`, because
  the credentials were never exercised.
- The result is recorded and shown by `sender:diagnose`, `/health` and
  `/diagnostics`. Re-run it after changing any mail setting: an old verification
  degrades, and one taken against a different `MAIL_MAILER` degrades at once.

If you are unsure whether a message arrived, send to an address you control and
check it directly.

Rules that matter in production:

- `APP_DEBUG=false`. Debug output leaks stack traces and environment values.
  The capability inspector reports this as `UNAVAILABLE` if it is wrong.
- Keep the session, queue and cache drivers on `database`. Switching to
  `redis` or `memcached` makes the application depend on a service the account
  does not have, and the inspector will say so.
- `.env` must not be committed to Git and must be readable only by the account.

### Optional: recipient validation

```ini
# Off by default — most shared hosts block outbound port 25.
SENDER_VALIDATION_SMTP_PROBING=false
```

Setting this to `true` on a host that blocks port 25 gains nothing and produces
confusing reports. Ask your host first. Full detail is in
[Recipient validation on shared hosting](#recipient-validation-on-shared-hosting).

### The second control: the operator switch

Recipient probing has **two** independent controls, and they compose as an AND:

```text
effective = SENDER_VALIDATION_SMTP_PROBING  AND  /admin/system/subsystems
```

The environment variable is the ceiling and the operator switch is the runtime
kill switch. An operator can stop outbound port 25 during an incident without
editing a file or reloading the application; an operator **cannot** use the switch
to go above a ceiling the deployment has set, because the platform cannot tell a
mistake from an intention to try anyway.

To enable it, do both:

1. set `SENDER_VALIDATION_SMTP_PROBING=true` and reload the application;
2. enable **Recipient SMTP validation** at `/admin/system/subsystems`.

Doing only one leaves it off, and the page says so. `sender:diagnose` reports
which of the two is responsible: `off by configuration` or `off by operator`.

---

## 5. Migrate and verify

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Verify the host before declaring the deployment healthy:

```bash
php artisan sender:diagnose
```

The result must not be `Unavailable`. In a browser, `/health` must return
`{"status":"ok","capability":"READY"}`.

`/health` reports the aggregate verdict and nothing else — no paths, versions or
dependency detail — so it is safe to point at a monitoring service. Its full
response set:

| Condition | HTTP | Body |
| --- | --- | --- |
| `READY` | 200 | `{"status":"ok","capability":"READY"}` |
| `DEGRADED` | 200 | `{"status":"degraded","capability":"DEGRADED"}` |
| `UNKNOWN`, nothing required | 200 | `{"status":"unknown","capability":"UNKNOWN"}` |
| `UNKNOWN`, subject in `SENDER_REQUIRED_CAPABILITIES` | 200 | `{"status":"unknown","capability":"UNKNOWN"}` |
| `UNAVAILABLE` | 503 | `{"status":"unavailable","capability":"UNAVAILABLE"}` |

Note the fourth row: `/health` returns **200** even when a required capability
cannot be established, while `sender:diagnose` exits non-zero for the same
condition. That asymmetry is deliberate — 200 means "still serving", which is
what a probe should restart on. Monitoring that needs to alert on an
unestablished requirement should read the `capability` field rather than the
status code. `docs/ARCHITECTURE.md` section 7 records the reasoning.

Then create the first administrator: register through the interface, and run

```bash
php artisan sender:set-role you@example.com super_admin
```

---

## 6. Optional: enable diagnostics

```ini
SENDER_DIAGNOSTICS_ENABLED=true
```

`/diagnostics` then shows the full host report, restricted to accounts holding
`system.view`. It is useful when diagnosing a host-specific problem. Leave it
disabled on a production account if the extra visibility is unwanted.

---

## 7. Cron

**Stage 1 required no cron at all.** Stage 2 only *observed* it. Stage 3A made
the observation durable and recorded outcomes. Stage 3D gave cron real work:
`sender:work` drains the database queue, so this entry is what makes the
platform's background processing happen at all.

cPanel offers no way for PHP to ask whether a cron entry is configured, so the
platform cannot detect cron. It can only record that the scheduled command
actually ran and infer the capability from that. Add this entry in cPanel ->
Cron Jobs:

```
*/5 * * * * cd /home/USER/sender && /usr/local/bin/php artisan sender:work >> /dev/null 2>&1
```

Use the absolute path to the PHP binary your account has; confirm it under
cPanel -> MultiPHP Manager. Set the frequency to five minutes, and expect:

| Observed | Reported |
| --- | --- |
| never run | `UNKNOWN` |
| last run succeeded within `SENDER_CRON_STALE_AFTER_SECONDS` (default 900) | `READY` |
| last run succeeded but older than that | `DEGRADED` |
| last run **failed** | `DEGRADED` — the scheduler fires but is not doing its work |

That last row is why the outcome is recorded, and why the probe command moved
from `sender:heartbeat` to `sender:work`. A cron entry that is still being
invoked but keeps failing — a queue pointing at a table that does not exist, for
instance — is a different problem from an entry that has stopped being invoked,
and they have opposite remedies. A heartbeat could only ever answer the second
question.

`sender:heartbeat` remains available for installations that only want to probe
the scheduler, but as of Stage 3D.1 it is **history, not proof**: the cron
capability verdict comes from `sender:work` alone. An installation that never
migrated and keeps running only the heartbeat therefore reports `UNKNOWN` — which
is correct, because its queue is never drained.

The counts recorded on each run come from the queue's own job events, not from
queue depth. Depth arithmetic cannot be right: jobs dispatched during the run
inflate the figure, and a retried job leaves the queue without having succeeded.

`sender:work --max-jobs=N` and `--max-runtime=N` may only tighten the configured
ceilings. A larger value is clamped and warned about; a value below 1 is refused.
Do not add flags to the cron line to make it run longer — the bounds exist
because a shared host kills overrunning processes, and a longer worker does not
fix a slow queue, it hides it.

The worker is bounded and exits. Each invocation processes at most
`SENDER_QUEUE_MAX_JOBS_PER_RUN` jobs (default 25) and stops after
`SENDER_DEPLOYMENT_LIMIT_MAX_WORKER_RUNTIME_SECONDS` (default 240). If the queue
is deeper than one run can clear, the next cron entry continues — so choose a
frequency that matches your backlog rather than expecting one entry to drain
everything.

The worker refuses to start when the reservation invariant does not hold, i.e.
when `DB_QUEUE_RETRY_AFTER` is less than the worker runtime plus the margin.
Starting anyway would let the same job be handed to a second worker while the
first still held it. `sender:diagnose` reports this as
`queue reservation window`.

**A large extraction takes several cron entries, by design.** Finding addresses
and checking them are two separate jobs, and the checking job processes at most
`SENDER_VALIDATION_MAX_PER_PASS` results per invocation before re-queueing itself.
A ten-thousand-address list therefore finishes across many runs rather than
overrunning one. The task's own badge shows the stage and an honest progress
figure, so an operator watching the queue is looking at the same numbers the
customer is.

One task runs per account at a time. A second paste waits behind the first and
starts when it finishes; different accounts never wait on each other.

Each run appends a row to `scheduled_runs` recording the command, its duration
and a redacted failure message, so you can inspect the history directly in the
database:

```sql
SELECT command, status, started_at, duration_ms, error
FROM scheduled_runs ORDER BY started_at DESC LIMIT 10;
```

Cron is never reported `UNAVAILABLE`. There is no positive evidence available
from inside PHP that would justify it, and inferring one would be a guess.

`UNKNOWN` does not fail `sender:diagnose` while cron is not required. Nothing
depends on it yet, so it is reported rather than treated as a fault.

Run evidence lives in the database, not the cache, so
`php artisan cache:clear` does **not** erase it. Do not use `cache:clear` to
"reset" cron — it will not, and previously it left an operator with an
unexplained `UNKNOWN` while diagnosing the very problem it appeared to clear.

Do not add cron entries for background work yet — no such work exists, and an
entry that calls a command which does not do anything only produces confusing
log noise.

---

## 7a. Queue reservation

A queued job is reserved for `retry_after` seconds before it becomes visible to
another worker. If that is shorter than the time a worker may legitimately take,
a slow but healthy job is picked up a second time and processed twice.

For this platform that failure is worse than an error: it means duplicate
emails, with no exception and nothing in the log.

The deployment therefore has to satisfy:

```
DB_QUEUE_RETRY_AFTER >= SENDER_DEPLOYMENT_LIMIT_MAX_WORKER_RUNTIME_SECONDS + SENDER_QUEUE_RESERVATION_MARGIN_SECONDS
```

The shipped defaults give `300 >= 240 + 60`, which satisfies it with margin.
`php artisan sender:diagnose` reports `queue` as `UNAVAILABLE` with the exact
numbers when it does not hold, and applies this check only to the `database`
driver.

If you raise the worker runtime, raise `retry_after` with it. The variable is
named after the connection Laravel reads it for, following its own convention:

```
DB_QUEUE_RETRY_AFTER=600
SENDER_DEPLOYMENT_LIMIT_MAX_WORKER_RUNTIME_SECONDS=240
```

Raising the worker runtime without raising `retry_after` is the specific
mistake this invariant exists to catch.

---

## 8. Updating an existing installation

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If `public/build` changes in a release, it is committed, so no Node build is
required on the server.

`migrate` creates the `scheduled_runs` table added in Stage 3A. Run it before
`config:cache`; an older cached config on a newer schema is harder to diagnose
than a missing cache.

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `/health` returns 503 | A capability was measured as unavailable | `php artisan sender:diagnose` names it |
| `sender:diagnose` exits 0 but prints `DEGRADED` | Correct: the host works with less headroom | Raise the PHP setting it names, or accept it |
| A capability shows `UNKNOWN` | Nothing has measured it yet | Expected for `url_fetch`; `smtp` needs `sender:verify-smtp` |
| `smtp` shows `UNAVAILABLE` | Verification found a real problem | `sender:verify-smtp --to=...` names the failing stage |
| `smtp` shows `UNKNOWN` | Never verified | Run `php artisan sender:verify-smtp --to=you@example.com` |
| Password reset reports success but no mail arrives | `MAIL_MAILER` is `log`/`array`, or the relay is wrong | Verify SMTP; `log` discards messages silently |
| Queue shows `UNAVAILABLE` on the `database` driver | Reservation invariant violated | See section 7a; raise `retry_after` or lower the worker runtime |
| Cron shows `UNKNOWN` | No run has been recorded, or the last run failed | Add the `sender:work` cron entry; check `scheduled_runs.error` |
| Cron shows `DEGRADED` | Runs succeeded but the last one is stale | Check the cron log in cPanel |
| `cache:clear` did not reset cron | Correct: run evidence is durable | Expected; evidence lives in `scheduled_runs`, not the cache |
| 500 on every page | `APP_KEY` missing, or `storage/` not writable | `php artisan key:generate`, `chmod 775 storage` |
| Login loop | Sessions cannot persist | Confirm `sessions` table exists and the `database` session driver is set |
| Styles missing | `public/build` missing or stale | Confirm `public/build/manifest.json` exists |
| Jobs never run | No worker | Expected at this stage; the job engine arrives in Stage 3 |

---

## URL fetching on shared hosting

Stage 3E adds one outbound HTTP workload. On shared hosting it is the most
likely thing to be silently blocked, and the failure is worth understanding.

### Verify before offering the feature

```bash
php artisan sender:verify-url
```

Until this has been run, `url_fetch` reports `UNKNOWN`. That is not a bug and
should not be worked around: the capability reports what was measured, and
nothing has been measured. The command performs one real fetch through the same
hardened fetcher the feature uses, so a pass means something and a failure is
diagnosable.

If it reports `UNAVAILABLE`, outbound access is being blocked or DNS is not
working. Check with your host. Do not "fix" it by disabling the address checks.

### What the platform will never fetch

The address checks are security controls, not tuning. These are refused whatever
the operator asks for:

- `file:`, `ftp:`, `gopher:`, `data:`, `javascript:` and every scheme but http/https
- URLs containing a username or password
- any port but 80 and 443
- destinations that are not globally routable: loopback, RFC1918, link-local
  (including `169.254.169.254`), carrier-grade NAT, reserved blocks, multicast,
  and non-global IPv6

A redirect is revalidated in full, so a public URL cannot redirect the platform
into any of the above. If an extraction fails with `blocked_destination`, that is
the policy working. If you genuinely need an internal page extracted, it needs a
different, deliberate mechanism — not a relaxed check.

### Temporary files and disk

Response bodies are streamed to the system temporary directory and removed as
soon as extraction finishes, including on failure. On a host with a small
`/tmp` quota, set `SENDER_URL_MAX_RESPONSE_BYTES` lower than the space available;
the default 2 MiB is small, but a queue of extractions can be in flight at once.
The directory is writable only if PHP can write to it — verify with
`sender:diagnose` if extractions fail with an unexpected category.

### Timeouts

`SENDER_URL_CONNECT_TIMEOUT_SECONDS` (default 5) and
`SENDER_URL_REQUEST_TIMEOUT_SECONDS` (default 10) sit comfortably below the
worker runtime. Do not raise the worker runtime to accommodate slow sites: the
reservation invariant depends on a job finishing inside its `retry_after`, and a
longer worker does not make a slow host faster.

---

## Per-user SMTP transports

Tenants configure their own sending SMTP from the browser, and an administrator
can configure one for them. **No environment variable is involved** — a tenant's
transport lives in the `smtp_accounts` table, and the platform's own mail in
`config/mail.php` stays exactly as above.

### What operators need to know

- **Credentials are encrypted** with `APP_KEY` using Laravel's encryption, which
  is authenticated and supports previous-key rotation. If `APP_KEY` is lost, every
  stored transport credential is unrecoverable and each account must be re-entered.
- **Rotating `APP_KEY`.** Set the new key as `APP_KEY` and the previous key as
  `APP_PREVIOUS_KEYS` first. Without it, `APP_KEY` rotation silently turns every
  stored credential into *no credential*, and the next send fails against the
  provider for a reason that has nothing to do with the provider.
- **SMTP hosts must be publicly routable.** Private, loopback, link-local and
  reserved addresses are refused, including `169.254.169.254`. On cPanel this
  means an account configured for `localhost` or `127.0.0.1` will be refused —
  use the hostname cPanel issues, usually `mail.yourdomain.com`. This is
  intentional: the shared host must not be usable as a probe into the network it
  runs on.
- **Verification actions are rate limited**, and sending a test message is bounded
  far more tightly than testing a connection because it consumes the tenant's
  provider quota. Tune with `SENDER_SMTP_VERIFY_THROTTLE` and
  `SENDER_SMTP_SEND_TEST_THROTTLE`.
- **`SENDER_SMTP_TIMEOUT_SECONDS`** defaults to 10. Keep it well below
  `SENDER_MAX_WORKER_RUNTIME_SECONDS`, for the same reason the URL timeouts are
  bounded: a transport that could occupy the whole worker budget would break the
  queue's reservation invariant.

### What this does not do

The application improves the practice of sending mail. It cannot guarantee inbox
placement — that is the receiving provider's decision, made from signals this
platform cannot observe.

There is no transport or IP rotation, no provider-limit bypass and no spam-filter
bypass. When a tenant's provider rate-limits or blocks, the platform records the
failure, stops using that transport, and says so. The remedy is to repair the
domain or provider reputation, or to configure a different relay that the tenant
or an administrator legitimately controls.

### Before a tenant can send

Recipients, consent, suppression and unsubscribe handling were built in Stage 5B
and are real, so `/account/deliverability` no longer reports them as missing.
Campaigns remain unavailable: nothing in the platform sends mail, and there is
deliberately no send button anywhere.

What the audience layer requires of the host is one thing — **outbound port 25** —
and it is optional. See below.

---

## Recipient validation on shared hosting

Extracted addresses are checked by a four-stage pipeline: syntax, mail route,
catch-all, and an SMTP recipient check. The first two cost nothing and need no
special permission. The last two speak SMTP to other people's mail servers on
port 25, which most shared hosts block.

### It is off by default, and that is the right default

```ini
SENDER_VALIDATION_SMTP_PROBING=false
```

With it off:

- syntax and DNS still run, and a definitive failure there is still reported as
  `CONFIRMED_INVALID`;
- everything else is recorded as `UNKNOWN` with the reason *verification
  blocked*;
- **nothing is ever recorded as inactive on that basis**, and nothing is recorded
  as active either;
- the task still finishes, so the report and the badge show real numbers.

That is the honest answer rather than a degraded one: the platform is not allowed
to ask a mail server whether a mailbox exists, and it declines to guess.

`sender:diagnose` prints the current state as `recipient validation probing` and
names which of the two controls is responsible.

### Turning it on

Ask your host whether outbound port 25 is permitted to your account. Most shared
hosts block it by default and some will not lift the block at all. If it is
permitted:

```ini
SENDER_VALIDATION_SMTP_PROBING=true
SENDER_VALIDATION_SMTP_TIMEOUT_SECONDS=5
```

Then enable **Recipient SMTP validation** at `/admin/system/subsystems`. Both are
required.

If it is not, leave the switch off. There is no partial mode: the pipeline either
may ask or may not.

### Knobs worth knowing

| Variable | Default | Why it matters |
| --- | --- | --- |
| `SENDER_VALIDATION_SMTP_TIMEOUT_SECONDS` | 5 | Per-server budget. Keep it well below `SENDER_MAX_WORKER_RUNTIME_SECONDS`: a mail server that stops answering must not consume the worker's whole budget, because the queue's reservation invariant depends on a job finishing inside its `retry_after`. |
| `SENDER_VALIDATION_DOMAIN_CACHE_TTL_SECONDS` | 21600 (6 h) | How long a domain's MX is trusted. |
| `SENDER_VALIDATION_CATCH_ALL_CACHE_TTL_SECONDS` | 259200 (3 d) | How long "this domain accepts anything" is remembered. Shorter than the route window on purpose, so a domain that turns catch-all off is noticed. |
| `SENDER_VALIDATION_MAILBOX_CACHE_TTL_SECONDS` | 604800 (7 d) | How long a *likely active* result is trusted. A mailbox's answer can change between two messages. |
| `SENDER_VALIDATION_INVALID_CACHE_TTL_SECONDS` | 2592000 (30 d) | How long a *confirmed invalid* result is trusted. A mailbox that does not exist is a stable fact, so this is deliberately longer. |
| `SENDER_VALIDATION_MAX_PER_PASS` | 150 | Results checked per worker invocation before the job re-queues itself. |
| `SENDER_VALIDATION_BATCH_SIZE` | 50 | Rows written per batch. |

**Raising the TTLs is the wrong way to make validation faster.** The DNS and
catch-all caches already bound the work to the number of *domains* in a list
rather than the number of addresses, which is what makes a ten-thousand-address
list cost the same per worker invocation as a ten-address one.

### Cost and etiquette

Validation asks *other people's* mail servers one question per address. On a
shared host that is the behaviour which gets an outbound IP blocked, so:

- it is off unless you deliberately turn it on;
- the operator can switch it off from the browser, mid-incident, with no deploy;
- one catch-all probe per domain per cache window, sent to a random address that
  cannot exist;
- no message is ever delivered to a recipient to find out whether they exist.

If your provider starts rate-limiting you, `sender:diagnose` and
`/admin/validation` will show it: a rise in `catch_all`,
`verification_blocked` or `policy_rejection` is a change in the outside world,
and that page is the only place it becomes visible.

### What a result means

The customer-facing statuses are **Likely active**, **Confirmed inactive**,
**Unknown** and **Risky**. Read them as:

- **Likely active** — the receiving server said it will accept mail for this
  address. It did not say the message arrives.
- **Confirmed inactive** — a deterministic local check, or an enhanced status of
  `5.1.1`/`5.1.2`/`5.1.3`. Nothing else can produce it. In particular a bare
  `550` cannot, because providers answer `550` to every address rather than leak
  their user list.
- **Unknown** — the platform could not reach a conclusion. This is the common
  case on a host with port 25 blocked, and it is never reported as inactive.

There is no accuracy percentage anywhere in the product and none should be added:
no such figure is defensible, and quoting one would be a claim the platform
cannot support.

**Unknown addresses are excluded from sending by default.** That is a policy
choice, not a limitation: not sending risks losing one recipient we might have
reached, while sending risks a bounce, a complaint and a provider signal held
against the whole account.
