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
│   ├── System/        Host capability, deployment limits, subsystem flags
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
`DeploymentLimit` enum:

```php
if ($size > DeploymentLimit::MaxUploadBytes->value()) { ... }
```

Business logic never contains a magic number, and each limit is overridable per
deployment through a `SENDER_DEPLOYMENT_LIMIT_*` environment variable. That is
what allows the same codebase to serve a small shared plan and a larger one.

Four different boundaries are deliberately kept apart, because collapsing them
produces limits that cannot be reasoned about:

| Concept | Question it answers | Tunable by |
| --- | --- | --- |
| `requirements` | What must the host provide? | nobody; they are platform constants |
| `DeploymentLimit` | What does this installation permit? | the operator |
| `Entitlement` | What is this account granted? | the plans stage |
| usage | What has this account consumed? | the plans stage |

`requirements` is not operator-tunable on purpose. Loosening
`memory_limit_bytes` does not give a host more memory, so making it a setting
would only create the illusion of a change.

The limits are declared now but only partially consumed. The upload limit is
already read by the capability inspector, because a host whose
`upload_max_filesize` is below the configured maximum is a configuration error
worth reporting before the extraction stage ships.

## 7. Capability reporting

Three layers, in strict order of who is allowed to ask what:

```
HostCapabilityInspector   measures the host        (the only code that calls
                                                      ini_get() and friends)
        ↓ HostCapabilityReport
CapabilityRegistry        what the application may rely on
        ↓
AvailabilityResolver      may this operation proceed, and why not
```

`/health`, `/diagnostics` and `sender:diagnose` all consume the registry. No
surface re-implements a check, so they cannot disagree.

### Statuses mean exactly one thing each

```
UNKNOWN       not established — nothing has measured this yet
READY         measured, and it passed
DEGRADED      measured, working with less headroom than the platform prefers
UNAVAILABLE   measured, and known not to work
NOT_ENTITLED  a separate authorization fact about an account, not a capability
```

`UNKNOWN` is not a failure. It means the platform does not yet have sufficient
evidence to classify a capability, and **whether that blocks a particular
operation is decided by the consuming requirement, not by the registry**.
Folding policy into the registry would make every future feature inherit a
policy nobody chose.

This is why `url_fetch` and `smtp` report `UNKNOWN` today, and why a loaded
`openssl` extension does not promote SMTP to `READY`: a prerequisite is not
proof. Both become `READY` when the code that performs them exists to be
measured.

`CapabilitySubject::isRequired()` reads `sender.capabilities.required`, which
is empty. An `UNKNOWN` capability nothing depends on is reported but does not
fail the installation; an `UNKNOWN` capability that is required does.

### The five capability subjects

| Subject | Established by | State today |
| --- | --- | --- |
| `cron` | durable evidence of a completed scheduled run | `UNKNOWN` until a run is recorded |
| `storage` | writable paths and free disk | `READY` on a healthy host |
| `queue` | the queue driver, its table, and the reservation invariant | `READY` when configured safely |
| `url_fetch` | code that performs a fetch | `UNKNOWN` — no such code yet |
| `smtp` | a recorded `sender:verify-smtp` result | `UNKNOWN` until verified |

The split is deliberate. `storage` and `queue` are `READY` because the existing
checks *are* their prerequisites — a writable directory is what file storage
requires. `url_fetch` is `UNKNOWN` because no extension check can establish that
a fetch would actually succeed. `smtp` is `UNKNOWN` for a different reason: no
extension check or configuration read can establish that a delivery would
succeed, so it is established by running the verification.

Note that `smtp` and the "transactional mailer" check are different things. The
mailer check is untagged and reports `DEGRADED`, because the `log` mailer is
correct in development and a check that failed every developer machine would be
ignored everywhere. The capability stays `UNKNOWN`. Confusing the two would let
a discarded transport be reported as a working one.

### Availability is the policy decision

`AvailabilityResolver` answers "may this operation proceed right now?" by
composing the three independent inputs in a fixed order:

```
infrastructure capability  ->  operator flag  ->  entitlement
```

It returns one of three states:

| State | Meaning |
| --- | --- |
| `AVAILABLE` | verified capable, enabled and entitled |
| `UNVERIFIED` | not blocked, but a dependency has not been established |
| `BLOCKED` | something positively prevents the operation |

and, when blocked, one machine-readable reason:

| Reason | Blocked because |
| --- | --- |
| `CAPABILITY_UNAVAILABLE` | this installation cannot perform it |
| `SUBSYSTEM_DISABLED` | the operator switched the subsystem off |
| `NOT_ENTITLED` | the account is not granted the feature |

Order matters. An operator stop outranks an entitlement shortfall, so an
operator disablement always yields `SUBSYSTEM_DISABLED` — a deterministic,
operationally meaningful reason rather than a commercial one.

`UNVERIFIED` is the case that keeps the layers honest: an unmeasured dependency
is reported without being converted into a refusal, because only the consuming
requirement may decide that.

### Diagnostic severity is not process exit status

| Overall status | `sender:diagnose` exit |
| --- | --- |
| `READY` | 0 |
| `DEGRADED` | 0, with warnings |
| `UNAVAILABLE` | non-zero |
| `UNKNOWN` | non-zero only when the subject is required |

A degraded host is operational: it works with less headroom, and the
deployment limits exist precisely so it stays inside that headroom. Failing the
command for `DEGRADED` would train operators to ignore it, and would make the
check useless on the small shared plans this platform targets.

### `/health` and `sender:diagnose` deliberately answer different questions

This asymmetry is intentional and must not be "fixed" by making the two agree.

| Condition | `/health` | `sender:diagnose` |
| --- | --- | --- |
| `DEGRADED` | 200 `degraded` | exit 0, warnings printed |
| `UNAVAILABLE` | 503 `unavailable` | exit non-zero |
| required, `UNKNOWN` | 200 `unknown` | exit non-zero |
| optional, `UNKNOWN` | 200 `unknown` | exit 0 |

`/health` answers "is this process still serving?", for a monitoring service
that will restart or page when it sees a failure. A required-but-unestablished
capability does not stop the process serving, so it returns 200.

`sender:diagnose` answers "should an operator act?", for a person deciding
whether to change configuration. In that context an unestablished requirement is
exactly the thing worth failing on.

So `UNKNOWN` on a required capability is the one state where the surfaces
disagree: HTTP 200 alongside exit 1. That is the design. Monitoring that needs
to alert on it should read the `capability` field in the body rather than the
status code, or poll `sender:diagnose`.

### The inspector reports only what it measures

Checks that need an external dependency the platform does not require — DNS
resolution — are absent from the inspector, because claiming a capability that
has never been tested is worse than reporting nothing. Outbound SMTP is now
tested, but deliberately **not** from the inspector: it requires network I/O and
an operator-chosen destination, so it lives in the explicit `sender:verify-smtp`
command instead. See "SMTP is verified, never inferred".

`HostEnvironment` exists so runtime readings are injectable. The CLI SAPI and
the web SAPI report different `max_execution_time` values, and a test cannot
change the SAPI it runs in; the value object makes the web-only code path
testable.

### Cron cannot be detected, only observed

cPanel exposes no API that answers "is a cron entry configured?". The only
honest signal is that the scheduled command actually ran, so:

| Observed state | Capability |
| --- | --- |
| no run recorded | `UNKNOWN` |
| recent **successful** run | `READY` |
| run recorded but stale | `DEGRADED` |
| most recent run failed | `UNKNOWN` — liveness is not proven |
| not observed | never `UNAVAILABLE` — there is no positive evidence to justify it |

Health is deliberately **not** re-derived from process privileges. Root has an
`unlimited` `max_execution_time` whether or not cron is installed, so that
signal cannot distinguish a working host from a broken one.

Stage 1 required no cron at all; Stage 2 observed it; Stage 3A made the
observation durable.

### Runs are recorded, not inferred from a timestamp

Each run appends a `scheduled_runs` row with its command, start, finish, status
and outcome. Recording the *outcome* rather than merely that "something ran" is
what makes a broken cron diagnosable: a command that is still being invoked but
keeps failing is distinguishable from one that has stopped being invoked. Those
have opposite remedies, and a heartbeat could only ever answer for the second.

A run left in `running` means the process was killed before it closed its own
record. Nothing force-closes it, because that is a signal rather than a gap.

This was previously cache-backed on the argument that `cache:clear` is a useful
operator reset. That argument turned out to be wrong in practice: an operator
running `cache:clear` while diagnosing a broken deployment got `UNKNOWN` — the
same answer as a host that had never been configured — with nothing pointing at
the cause. Operator flags stay in `system_settings` for the opposite reason: a
cache-cleared kill switch that silently re-enables a subsystem is worse than no
kill switch. **Erasing transient state is safe; erasing the evidence that
something was broken is not.**

### SMTP is verified, never inferred

SMTP is the clearest case of a capability that configuration cannot establish.
`MAIL_HOST`, `MAIL_USERNAME` and `MAIL_PASSWORD` being set proves only that
somebody typed them. So the platform does not derive `smtp` from configuration
at all. `SmtpCapability` reports a recorded `sender:verify-smtp` result, and is
`UNKNOWN` until one exists.

Verification is staged, and each stage is reported separately, because the
stages fail for different reasons and an operator needs to know which:

| Stage | Establishes |
| --- | --- |
| `configuration` | a mailer that actually delivers is configured |
| `transport` | Laravel can build that transport from the configuration |
| `connection` | host resolves, TCP connects, TLS negotiates where required |
| `acceptance` | the server accepted a message from these credentials |

Verification stops at the first failing stage. Omitting `--to` proves the
connection only and reports `DEGRADED`, because the credentials were never
exercised — reporting `READY` there would overstate what was established.

**The strongest available claim is server acceptance, not delivery.** Nothing
inside the application can observe a recipient's mailbox. That boundary is
stated in the command output, in the diagnostic detail, and in the persisted
payload itself (`proves` / `does_not_prove`), because "SMTP available" is
otherwise read as "mail arrives". An early version of the command printed its
reassurance unconditionally, including after a failed verification — the exact
failure mode this design exists to prevent.

Two properties follow from running it as a command rather than inline:

- **No request pays for network I/O.** The capability reads stored evidence, so
  a page view never opens a mail connection.
- **It does not expire silently.** An old verification degrades rather than
  being trusted forever, since configuration can change after it was taken.

Persisted failure text goes through `SensitiveData::redactText`. A run record
and a verification record outlive the deployment that wrote them, and error text
routinely quotes the configuration that caused the failure, including a
password.

### Queue reservation is an invariant, not a default

A queue job stays reserved for `retry_after` seconds before it becomes visible
again. If that is shorter than the time a worker may legitimately take, a slow
but healthy job is handed to a second worker and processed twice. The failure is
silent and produces duplicate sends — the worst possible failure for this
platform.

The invariant is therefore explicit:

```
retry_after >= max_worker_runtime_seconds + reservation_margin_seconds
```

Shipped defaults give `300 >= 240 + 60`. The check runs only for the `database`
driver, the one driver whose configuration the platform can reason about, and a
violation reports `queue` as `UNAVAILABLE` with remediation.

This is why a supported driver is not automatically `READY`: capability and
configuration are separate concerns, and `queue` can be unavailable because the
driver is unsupported *or* because a supported one is configured unsafely.

### Operator control is one flag, persisted

`SubsystemFlagRegistry` is the single mechanism for safe mode, emergency
disablement and subsystem toggles. They differ only in intent, and splitting
them would mean three places to check before allowing an operation.

The three registered subsystems are `cron`, `url_fetch` and `smtp`, matching
the defaults in `sender.subsystems`. A flag exists only for a subsystem that
exists today; none are added speculatively. Each maps to the capability it
depends on, which is why `storage` is not a subsystem: it is measured as a
capability but is not operator-switchable.

Flags are stored in `system_settings`, not the cache, because a cache-cleared
kill switch that silently re-enables a subsystem is worse than no kill switch
at all.

**There is no operator surface yet.** `enable()`, `disable()` and `reset()` are
reachable only from tests, and `system.manage` is used by no route or command.
The mechanism is in place; the control panel that exposes it is deferred to the
admin operations centre, or to Stage 3 if the job engine needs an emergency
stop sooner.

### Entitlement fails closed

`Entitlement` is a deny-by-default interface, currently bound to
`DenyAllEntitlement`. When the plans stage replaces that binding, no service
that depends on the interface has to change.

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

The invariant is that every configured channel redacts, and a test asserts it
by resolving each channel and checking its processor or tap. Drivers that ignore
`processors` — `slack`, `syslog`, `errorlog` — use a tap instead.

**One known gap: the `emergency` channel does not redact.** Laravel's
`createEmergencyLogger()` builds a bare `StreamHandler` from the `path` key and
applies neither processors nor taps, so it cannot be covered by configuration.
It only fires when the application has already failed to log anything else, but
a fatal error message can still carry a value. The test excludes it explicitly
rather than pretending the invariant holds. Closing it requires either a custom
emergency handler or accepting the exposure; it is not a Stage 2 fix.

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
