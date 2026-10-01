# Development workflow

The stage protocol used to build Sender. It exists so that each stage is
driven by the actual repository state rather than by a plan written before any
code existed.

---

## The loop

### 1. Investigate before implementing

Before any change:

```bash
git status
git branch
git log --oneline -10
```

Then read the README, the documentation, the complete repository tree, and every
source and configuration file relevant to the stage. Determine what is
actually implemented. Do not assume anything from previous planning is built.

Identify and record:

- existing functionality
- missing functionality
- incorrect decisions
- unnecessary complexity
- security concerns
- cPanel compatibility concerns
- technical debt

**The repository is the source of truth.** The plan is not.

### 2. Implement the stage

Build only what the stage requires. Do not build adjacent features "while we
are here" — a speculative table or abstraction is debt with no offsetting
benefit.

### 3. Test

```bash
php artisan test
vendor/bin/pint
```

Do not claim functionality works without running the tests. If a test cannot be
written for a piece of behaviour, that is a signal about the design.

### 4. Verify manually

```bash
php artisan sender:diagnose
php artisan sender:work
php artisan serve
```

Exercise the changed surface in a browser, not only through tests.

When the change touches SMTP, run `php artisan sender:verify-smtp --to=<a real
address you control>` rather than trusting that `.env` looks right. It proves
what it can prove — the server accepted a message — and no test can substitute
for checking whether the mail actually arrived.

### 5. Document

Update the README and `docs/ARCHITECTURE.md` to match what now exists. Never
document a feature that has not been implemented.

### 6. Review the diff

```bash
git status
git diff
git diff --staged
```

Read it. Confirm no secrets, no `.env`, no credentials, no large generated
files, no debugging leftovers.

### 7. Commit and push

One focused commit per stage, pushed to `origin/main`:

```bash
git add <intentional paths>
git commit -m "feat: <short description>"
git push origin main
```

Never commit `.env`, database credentials, SMTP passwords, API keys, private
certificates, `vendor/`, `node_modules/`, temporary files or logs.

---

## Stage review

After every stage, answer these questions before proposing the next one:

1. What exists?
2. What works?
3. What is wrong?
4. What is incomplete?
5. What should be refactored now?
6. What should **not** be built yet?
7. What is the safest, highest-value next step?

Then re-inspect the repository and write the next prompt from what is actually
there. Do not repeat the original roadmap; the roadmap changes as the code does.

---

## Stage sequence

```
0. Foundation / architecture                    completed
1. Laravel application foundation                completed
2. Capability / availability / deployment control completed
3. Job + cron processing engine                 3A done; engine pending
4. Email extraction engine                      pending
5. SMTP campaign engine                         pending
6. Admin operations centre                      pending
7. API / PHP integration                        pending
8. Billing                                      pending
9. Security / performance / deployment          pending
```

The sequence is not automatic. If repository investigation at a later stage
gives a reason to reorder, the repository wins.

---

## Ground rules

- One stage, one focused commit.
- No stage advances until the previous stage's tests pass and the diff is read.
- A stage that discovers a broken earlier decision fixes it. Leaving known
  incorrect code in place to keep a commit small is not a valid trade.
- Documentation describes the present, not the intention.
- A threshold is not a target. Do not weaken a host requirement to make a local
  check pass; a green local run must not be bought by encoding a developer
  workstation's configuration into the definition of a healthy deployment.
- "Not established" and "known broken" are different answers. Never let an
  unmeasured dependency be reported as a working one, and never let diagnostic
  severity quietly become process exit status.
- If something cannot be built within the cPanel constraint, it is deferred,
  not reimplemented with an extra service.
