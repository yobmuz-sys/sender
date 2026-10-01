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
can all be present and still be wrong — a blocked port, an expired password, a
provider that rejects the sender address. The platform therefore does not derive
the `smtp` capability from configuration at all; it stays `UNKNOWN` until you
verify it:

```bash
php artisan sender:verify-smtp --to=you@example.com
```

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
question. `sender:heartbeat` remains available and its runs still count as
evidence, it is simply not the primary signal.

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
