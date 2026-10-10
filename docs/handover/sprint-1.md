# Sprint 1 — Foundation: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 2 has not been started.

## 1. What was built

Laravel 13 / PHP 8.3 / PostgreSQL 16 application with the Core module and the module framework
(details: [../architecture/core-module.md](../architecture/core-module.md)):

- Module loader, base provider, `config/modules.php`, module toggle with dependency rules, and an
  architecture test that fails the build on illegal cross-module dependencies.
- Core schema: company profile, branches, departments, users (extended), employees, MFA secrets,
  roles/permissions, settings, number sequences, audit log (append-only), module settings,
  idempotency keys, outbox/inbox, Sanctum tokens.
- Services: audit logger (+ model trait), settings, concurrency-safe numbering, module registry,
  TOTP MFA, outbox recorder.
- Auth: web login/logout, Sanctum API tokens, throttling, TOTP with recovery codes, MFA enrolment
  enforced for `super-admin`/`admin`.
- API v1 base: request id + version headers, standard error envelope, pagination, filtering/sorting,
  idempotency, optimistic concurrency, health endpoints; `branches` CRUD as the reference resource
  (FormRequest → Action → Resource, Policy, audit).
- UI: Blade layout with permission- and module-driven sidebar, language switcher (en/si/ta), dashboard
  with live KPI cards, module management page (with confirm modal), audit-log page, login/MFA pages;
  reusable components (kpi-card, status-badge, table, input, button, alert, modal, datetime, icon).
- Tooling: Pest, Pint, Larastan level 6, GitHub Actions workflow, composer scripts, dev helper scripts.

## 2. Verified results (run on the dev machine, PostgreSQL 16.15, PHP 8.3.33)

| Check | Result |
|---|---|
| `composer test` (Pest) | **107 passed**, 377 assertions, ~16 s |
| `composer analyse` (Larastan level 6) | no errors |
| `composer lint` (Pint) | passed |
| `composer audit` / `npm audit` | no advisories / 0 vulnerabilities |
| `npm run build` | succeeds |
| HTTP smoke against `php artisan serve` | login page + built CSS 200, guest redirect 302, `/api/v1/health` 200 with headers, API 401 in the error envelope, `/up` 200 |

Notable tests: 6 OS processes allocating numbers in parallel get unique gap-free values
(`tests/Concurrency`); the audit table rejects UPDATE/DELETE even through the query builder; the
boundary checker is itself tested against fixture trees; TOTP replay and single-use recovery codes.

A defect found and fixed during the sprint, worth knowing: `pragmarx/google2fa` returns `true`
instead of the time step when no previous step is supplied, which silently disabled replay protection.
`MfaService` passes `0`; a test covers it.

## 3. NOT verified

- **The GitHub Actions workflow has never run** (nothing is pushed yet). It mirrors the local commands.
- **No browser check.** Pages were verified through HTTP/feature tests and an HTTP smoke test, not by
  eye. Layout, responsiveness, the modal and the Alpine behaviour need a human look.
- **QR enrolment was not scanned with a real authenticator app.** The TOTP maths is tested with the
  library's own generator; the SVG QR is asserted to render, not to scan.
- **Sinhala and Tamil strings are drafts** and need review by native speakers. Key parity with English is tested.
- No load or performance testing.

## 4. Decisions for you / deviations from the written plan

1. **Warehouses and the currency seed were not built in S1** although the backlog lists them: Inventory
   and MasterData own those tables ([modules.md](../architecture/modules.md)). They arrive in S4/S3. `company_profile.base_currency = 'LKR'` covers the base currency now.
2. **`user_types` table became a `users.user_type` column**, and numbering uses `number_sequence_counters`
   instead of `number_sequence_allocations`. Full list in [database.md](../architecture/database.md) §3a.
3. **Only three roles are seeded** (`super-admin`, `admin`, `user`). Business roles (accountant, storekeeper…)
   are added together with the permissions of the module that needs them.
4. **MFA is mandatory for `super-admin` and `admin`** (config `core.mfa.required_roles`). Say if finance approvers etc. should be added now.
5. **API token lifetime is 30 days** with no refresh flow; tighten before the mobile app (S13) if wanted.
6. Departments, employees and the company profile have models and audit but **no screens or API yet**.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Numbering holds a row lock until the surrounding transaction commits | concurrent document creation on one series is serialised | acceptable for SME volumes; revisit with measurements in S6/S7 |
| `Gate::before` lets `super-admin` pass every Gate | approval workflows (R3) must not rely on Gate alone or super-admin would skip them | enforce approvals in services in S2; add a test that super-admin cannot bypass an approval |
| Outbox rows are written but nothing publishes them | events recorded today are not delivered | relay + queue worker in S2; do not rely on the outbox before then |
| Idempotency pruning needs the scheduler (`schedule:run`) | table grows until S2 sets up the scheduler | run `php artisan idempotency:prune` manually or wire the scheduler in S2 |
| Idempotency stores response bodies for 24 h | personal data could sit in that table | keep to 24 h; exclude endpoints returning sensitive PII or encrypt the column when such endpoints appear |
| Audit log has no retention/partition plan | unbounded growth | decide retention (owner) before production; partition by month if large |
| Rate limits and caches use the default cache store | per-process on `array`, DB-backed otherwise | Redis in S2 |
| Local PostgreSQL is a portable build, not a service | must be started after reboot; no `psql` | fine for dev; production uses managed/containerised PostgreSQL (deployment.md) |
| Dev DB password lives in the git-ignored `.env` | local only | rotate if the machine is shared; never commit |
| `composer audit` needs network; one attempt timed out then passed on retry | CI could flake | none needed |

## 6. Decisions requested from the owner before Sprint 2

- Approve or adjust the deviations in §4.
- Which roles must be forced through MFA beyond `super-admin`/`admin`?
- Audit-log retention period.
- Review the draft si/ta strings (or nominate a reviewer).
- Confirm Sprint 2 scope: approvals engine + authority matrix, notifications, secure attachments,
  queue/Horizon (Redis), scheduler. Redis must be available locally (or Docker); the dev machine has neither yet.

## 7. How to run it

See [../development/setup.md](../development/setup.md).
