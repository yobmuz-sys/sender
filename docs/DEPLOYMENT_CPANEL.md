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

**Stage 1 required no cron at all.** Stage 2 only *observes* it, and Stage 3 is
the first stage that uses it to do work.

cPanel offers no way for PHP to ask whether a cron entry is configured, so the
platform cannot detect cron. It can only record that the scheduled command
actually ran and infer the capability from that. Add this entry in cPanel ->
Cron Jobs:

```
*/5 * * * * cd /home/USER/sender && /usr/local/bin/php artisan sender:heartbeat >> /dev/null 2>&1
```

Use the absolute path to the PHP binary your account has; confirm it under
cPanel -> MultiPHP Manager. Set the frequency to five minutes, and expect:

| Observed | Reported |
| --- | --- |
| never run | `UNKNOWN` |
| last run within `SENDER_CRON_STALE_AFTER_SECONDS` (default 900) | `READY` |
| older than that | `DEGRADED` |

Cron is never reported `UNAVAILABLE`. There is no positive evidence available
from inside PHP that would justify it, and inferring one would be a guess.

`UNKNOWN` does not fail `sender:diagnose` while cron is not required. Nothing
depends on it yet, so it is reported rather than treated as a fault.

Do not add cron entries for background work yet — no such work exists, and an
entry that calls a command which does not do anything only produces confusing
log noise.

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

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `/health` returns 503 | A capability was measured as unavailable | `php artisan sender:diagnose` names it |
| `sender:diagnose` exits 0 but prints `DEGRADED` | Correct: the host works with less headroom | Raise the PHP setting it names, or accept it |
| A capability shows `UNKNOWN` | Nothing has measured it yet | Expected for `url_fetch` and `smtp` at this stage |
| Cron shows `UNKNOWN` | No heartbeat has been observed | Add the `sender:heartbeat` cron entry |
| Cron shows `DEGRADED` | The entry is running but stopped, or is failing | Check the cron log in cPanel |
| 500 on every page | `APP_KEY` missing, or `storage/` not writable | `php artisan key:generate`, `chmod 775 storage` |
| Login loop | Sessions cannot persist | Confirm `sessions` table exists and the `database` session driver is set |
| Styles missing | `public/build` missing or stale | Confirm `public/build/manifest.json` exists |
| Jobs never run | No worker | Expected at this stage; the job engine arrives in Stage 3 |
