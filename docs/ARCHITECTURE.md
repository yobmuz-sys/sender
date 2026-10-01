# Architecture

Decisions recorded here are decisions that constrain later stages. Anything not
listed was a default choice and needs no defence.

---

## 1. Hosting constraint first

> Powerful application layer, modest infrastructure requirements.

Every architectural choice below is subordinate to the requirement that the
platform runs on basic cPanel shared hosting. Concretely, the core application
must never depend on:

Redis, Memcached, RabbitMQ, Supervisor, Docker, Node.js, Python, Elasticsearch,
WebSockets, long-running daemons, or root/VPS access.

The enforcement mechanism is not documentation, it is the capability inspector
(`php artisan sender:diagnose`), which reports a session, cache or queue driver
of `redis`, `memcached`, `dynamodb` or `apc` as `UNAVAILABLE` rather than
letting the install look healthy.

## 2. Framework and version

Laravel 12 on PHP `^8.2`. The full reasoning is in the README; the short version
is that Laravel 12 is the newest major that still runs on PHP 8.2, which is the
most widely available PHP version on shared hosting.

Rejected:

- **Laravel 13** — requires PHP 8.3, which excludes accounts still on 8.2.
- **CodeIgniter / CakePHP** — smaller ecosystems for auth, queues and validation;
  the platform needs a strong, current foundation more than a smaller footprint.

## 3. Server-rendered frontend

Blade with Tailwind CSS and Vite. No SPA, no Node.js backend.

Vite is a build tool, not a runtime. Compiled output is committed to
`public/build` so the production host needs no Node.js installation. This is a
deliberate reversal of Laravel's default `.gitignore`, which excludes
`public/build`.

Rejected: Inertia/Vue/React SPA. It would add a Node build to every deployment
and provide nothing that Blade does not, at this stage.

## 4. Module layout

```
app/
├── Domain/            Business concepts, independent of the framework
│   ├── System/        Host capability, limits
│   └── Users/         Roles and permissions
├── Http/              Controllers and form requests
├── Models/            Eloquent persistence models
├── Support/           Small framework-agnostic helpers
├── Console/Commands/  Operator CLI
└── Providers/
```

`Domain` was chosen over a heavier module system (nwidart/laravel-modules,
isolated package repositories) because the platform is not yet large enough for
package boundaries to pay for themselves, and because separate Composer
repositories would make the shared-hosting deployment story much worse.

`Domain` directories are created **when code lands in them**, not in advance.
Empty directories for `Extraction`, `Campaigns` or `Billing` would be
speculative structure with nothing to organize.

`App\Models\User` deliberately stays outside `App\Domain\Users`: the framework
resolves it from `config/auth.php`, and authorization rules can evolve in
`Domain` without touching persistence.

## 5. Authorization: roles plus permission gates

Two layers, separated on purpose:

- `Role` is a coarse account classification: `super_admin`, `admin`, `support`,
  `operations`, `billing`, `user`. Stored as one indexed column on `users`.
- `Permission` is the fine-grained ability catalogue (`users.suspend`,
  `jobs.manage`, `system.manage`, ...).

Every permission in `Permission::all()` is registered as a Gate at boot
(`AppServiceProvider::registerPermissionGates`). Registering the full catalogue
rather than only permissions some role holds is deliberate: it makes
`Gate::has()` true everywhere, so a typo in a controller or Blade template
fails loudly in testing instead of silently denying access in production.

Controllers and views use `->middleware('can:system.view')` or `@can`. No
hand-written role comparisons.

The `role` column is **not** mass assignable, so a registration request cannot
grant itself a role. This is asserted by a test.

Granular per-account grants are deferred to the plans/entitlements stage, where
they can be stored rather than implied by a role name.

## 6. Resource limits are configuration, not code

Every hard boundary is declared in `config/sender.php` and read through the
`Limit` enum:

```php
if ($size > Limit::MaxUploadBytes->value()) { ... }
```

Business logic never contains a magic number, and each limit is overridable per
deployment through an environment variable. That is what allows the same
codebase to serve a small shared plan and a larger one.

The limits are declared now but only partially consumed. The upload limit is
already read by the capability inspector, because a host whose
`upload_max_filesize` is below the configured maximum is a configuration error
worth reporting before the extraction stage ships.

## 7. Host capability reporting

`HostCapabilityInspector` produces checks classified as `READY`, `DEGRADED` or
`UNAVAILABLE`. The report's overall verdict is the worst individual check: a
host missing one required extension is never reported as fully ready.

`DEGRADED` is used for a value that is present but below the recommended
threshold (for example `memory_limit` of 128M). The host still works, and the
platform is designed to stay inside that headroom through the configured
limits. `UNAVAILABLE` is reserved for a genuinely missing dependency.

**The inspector reports only what it measures.** Checks that need an external
dependency the platform does not require — DNS resolution, outbound SMTP
reachability — are absent, because claiming a capability that has never been
tested is worse than reporting nothing. They are added by the stages that
depend on them.

`HostEnvironment` exists so runtime readings are injectable. The CLI SAPI and
the web SAPI report different `max_execution_time` values, and a test cannot
change the SAPI it runs in; the value object makes the web-only code path
testable.

## 8. Health endpoint versus diagnostics

- `/health` is public and minimal: an aggregate verdict and nothing else. It
  reveals no paths, versions or dependency detail, so it is safe to point at a
  monitoring service.
- `/diagnostics` is authenticated, requires `system.view`, is gated behind
  `SENDER_DIAGNOSTICS_ENABLED`, and shows host-level detail. It is a tool for
  the account owner, not a public page.

`/up` is retained as Laravel's own liveness probe because it answers before any
application code runs, so it stays available even when the application itself
is broken.

## 9. Logging redaction

`App\Support\SensitiveData` removes credential-shaped values from log context
and exception extras, and `RedactSensitiveDataProcessor` applies it to every log
channel through Monolog processors.

This is centralized rather than per-call-site because the platform will handle
SMTP passwords and API keys from Stage 5 onward, and exception context is the
most common way those values reach a log file.

Note: the `single` and `daily` built-in Laravel log drivers ignore the
`processors` key, so the file channels are configured through the `monolog`
driver instead. Leaving them on the built-in drivers would have produced a
configuration that looks correct and redacts nothing.

## 10. Error handling

Laravel's production behaviour is kept: no stack traces, no database
credentials, no environment values in production responses. On top of that:

- `password`, `password_confirmation`, `current_password`, `smtp_password` and
  `api_secret` are excluded from exception flashing.
- `APP_DEBUG=true` in production is reported as `UNAVAILABLE` by the inspector.

## 11. Testing

`php artisan test` runs the whole suite against an in-memory SQLite database, so
no Node build and no MySQL server is required. `withoutVite()` is applied in the
base test case for the same reason.

Tests assert behaviour that matters for this platform: role resolution, the fact
that registration cannot self-assign a role, login throttling, secret redaction,
and that the health endpoint leaks nothing.

## 12. Deferred by design

Not built yet, on purpose: the extractor, the crawler, SMTP campaign delivery,
recipients and suppression, the job engine, plans, entitlements, usage
tracking, the admin operations centre, the REST API, PHP integration and
billing. Each depends on foundations that did not exist before this stage, and
building them first would mean retrofitting quotas, authorization, locking and
logging around code that had already been written.
