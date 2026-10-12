# Core module (Sprint 1 implementation)

Status: **implemented in Sprint 1** and covered by tests. This file describes what exists; the
design intent is in [overview.md](overview.md), [modules.md](modules.md) and [security.md](security.md).

## 1. Module framework

| Piece | Where | Behaviour |
|---|---|---|
| Registry config | `config/modules.php` | key, name, provider, `depends_on`, default `enabled`, `required`. A module is added here when its sprint starts. |
| Loader | `App\Providers\ModulesServiceProvider` | builds the `ModuleRegistry` singleton, registers each module provider in config order |
| Base provider | `App\Support\ModuleServiceProvider` | loads `Database/Migrations`, `Lang` (namespace = module key), `Views`, `Routes/api.php` (mounted at `/api/v1`, route names `api.v1.*`) and `Routes/web.php`, then declares the module's permissions and menu items |
| Toggle | `module_settings` table + `ModuleRegistry` | `enable`/`disable` validate dependencies both ways, `required` modules cannot be disabled, changes are audited and cached |
| Enforcement | `module:<key>` middleware on every module route group | disabled module → `403 core.module_disabled` (API) or HTTP 403 (web). Routes stay registered, so toggling needs no route-cache rebuild |
| Visibility | `ModuleRegistry::menuFor()` / `permissions()` | menu items and permissions of disabled modules disappear |
| Boundaries | `tests/Architecture` + `tests/Support/ModuleBoundaryChecker` | tokenises every file under `app/Modules`, fails on an upward/undeclared dependency or use of a non-public namespace (`Contracts`, `DTOs`, `Events`, `Concerns`). The checker itself is tested against fixtures. |

Shared kernel outside modules: `app/Support` (module base provider, `BusinessException`, API error
renderer, base controller). Modules may use it; it must not contain business logic.

## 2. Services other modules use (all via `Core\Contracts`)

| Contract | Implementation | Notes |
|---|---|---|
| `ModuleRegistry` | `ConfiguredModuleRegistry` | see above |
| `AuditLogger` | `DatabaseAuditLogger` | actor, IP, user agent, channel, `X-Request-Id` as correlation id; redacts values for keys with the words `password`, `secret`, `token`, `nic`, `recovery_codes` (word match, so `technician` is untouched). `Auditable` trait on models records created/updated/deleted/restored with old+new values; updates that change nothing are not logged. |
| `SettingsService` | `DatabaseSettingsService` | typed JSON values; lookup order: exact scope (branch) → global → `config('core.settings_defaults')` → caller default; cached, invalidated on write; every change audited |
| `NumberingService` | `DatabaseNumberingService` | `define()` is idempotent and never alters an existing sequence. `next()` allocates under a row lock **inside the caller's transaction**: a rolled-back document does not burn a number (gapless), concurrent callers serialise per series/period/branch. Tokens `{SEQ} {YYYY} {YY} {MM} {FY} {BR}`; resets `never/yearly/fiscal_yearly/monthly`; period decided in Asia/Colombo; fiscal year from `company_profile.fiscal_year_start_month` (default April → label `2026-27`). |

Not contracts yet (Core-internal): `MfaService`, `OutboxRecorder`.

`OutboxRecorder` writes `outbox_events` rows in the caller's transaction. **The relay that publishes
them does not exist yet** (arrives with the queue work in Sprint 2); nothing consumes the table today.
`inbox_events` provides unique `(source, message_id)` for inbound de-duplication.

## 3. HTTP platform behaviour

- **Request context** (`AssignRequestContext`, global): accepts a client `X-Request-Id` (8–64 chars
  `A-Za-z0-9._-`) or generates a UUID; returns it on every response and in error bodies; adds
  `X-API-Version: 1` on API routes; stored in Laravel `Context` so logs and audit rows carry it.
- **Error envelope** (`ApiErrorRenderer`): for `/api/*` or JSON requests every exception becomes
  `{"error":{"code","message","details","request_id"}}`. Unexpected exceptions always return the generic
  `server.error` message (the real exception is still logged).
- **Idempotency** (`idempotent` middleware, applied to `POST /branches` as the reference; every
  document-creating POST uses it): header required (`400 idempotency.key_required`); same key + same
  request → stored `2xx` response replayed with `Idempotent-Replayed: true`; same key + different
  request → `422 idempotency.key_reused`; key still in flight → `409 idempotency.in_progress`.
  Scoped per user, kept 24 h (`core.idempotency.ttl_hours`). **Only successful responses are stored**:
  a failed attempt releases the key so the client can fix the request and retry with the same key.
- **Optimistic concurrency**: `branches.version`; `PATCH` must send the version it read; stale →
  `409 concurrency.stale_version`. Use the same pattern for documents.
- **Locale**: API = first supported language of an explicit `Accept-Language`, else the user's saved
  locale; web = session, else the user's saved locale. The default is re-applied on every request.
- **Rate limits**: `login` (5/min per email+IP), `api` (120/min per user or IP); `429 rate_limit.exceeded` + `Retry-After`.
- **Health**: `GET /api/v1/health` (liveness), `/api/v1/health/ready` (database ping, `503` when down);
  bodies carry no details. Laravel's `/up` is also enabled.

## 4. Authentication and MFA

- One credential check for web and API: `Actions\AuthenticateUser` (timing-equalised, audits failures,
  blocks inactive and non-`internal` accounts, verifies TOTP when enrolled).
- Web: session login, throttled; API: `POST /api/v1/auth/login` → Sanctum bearer token named after the
  device, expires after `SANCTUM_TOKEN_EXPIRATION` minutes (default 30 days), `POST /auth/logout` revokes it.
- **TOTP** (`MfaService`, `pragmarx/google2fa`): secret and recovery codes encrypted at rest; recovery
  codes stored as hashes and single-use; a TOTP time step can be used once (replay protection).
- **Enforcement**: roles in `config('core.mfa.required_roles')` (`super-admin`, `admin`) must enrol. The
  `mfa.enrolled` middleware blocks every other route until they do (`403 auth.mfa_enrollment_required`
  for API, redirect to `/mfa/setup` for web). Enrolment and logout routes stay reachable. This is checked
  on user state per request, not on token abilities, so it also covers tokens issued earlier.
- `super-admin` passes every Gate check (`Gate::before`). It is *not* a bypass for approval workflows —
  those must be enforced in services (Sprint 2).

## 5. Data model notes (what differs from the original catalog)

See [database.md](database.md) §Sprint 1 deviations.

## 6. Stable error codes introduced

`auth.unauthenticated` · `auth.forbidden` · `auth.invalid_credentials` · `auth.account_disabled` ·
`auth.mfa_required` · `auth.mfa_invalid` · `auth.mfa_enrollment_required` · `validation.failed` ·
`resource.not_found` · `rate_limit.exceeded` · `idempotency.key_required` · `idempotency.key_reused` ·
`idempotency.in_progress` · `concurrency.stale_version` · `core.module_disabled` · `core.module_unknown` ·
`core.module_required` · `core.module_dependency_disabled` · `core.module_has_dependents` ·
`core.branch_in_use` · `core.numbering_unknown_sequence` · `server.error` · `http.<status>`.

## 7. Added in Sprint 2

### Public surface for other modules

Public namespaces are now `Contracts`, `DTOs`, `Events` **and `Concerns`** (the reusable model traits
`HasPublicId` and `Auditable`). New contracts: `UserDirectory` (roles, role members, active users,
display names – modules above Core never import Core's `User`), `Notifier`, `EventOutbox`,
`AttachmentStore`, `AttachmentScanner`, `HasAttachments` (marker). New DTOs/events: `NotificationMessage`,
`AttachmentInfo`, `IntegrationEvent`.

### Notifications

`Notifier::notify($userIds, NotificationMessage)` writes an in-app notification for each active internal
user (deduplicated). A message carries **translation keys and parameters, not text**
(`approvals::notifications.requested` + `{doc, summary, requester}`), so it is rendered in the *reader's*
language when they open it; a missing translation shows the key instead of hiding the gap. It is written
**synchronously inside the caller's transaction**: a rolled-back change leaves no notification. UI: bell
with unread count in the header, `/notifications` (mark one/all read; the link is followed only if it is a
local path), API `GET /api/v1/notifications`, `POST …/{id}/read`, `POST …/read-all`. Email, WhatsApp and
SMS delivery are Communications (S14) and will be queued; nothing sends outside the app today.

### Attachments

`AttachmentStore::store()` for any model implementing `HasAttachments` (it needs a `public_id`, a morph
alias registered with `Relation::morphMap()` and a policy with `view` and `attach`). An attachment is exactly
as visible as its parent record. Hardening:

- the type is detected from the **file's bytes** (`finfo`; Office files are checked inside the zip), never taken from the client;
- only allow-listed type + extension pairs: PDF, PNG, JPEG, WebP, TXT/LOG, CSV, DOCX, XLSX (`config/core.php`); SVG, HTML, scripts and executables are refused;
- dangerous words anywhere in the name are refused (`invoice.php.pdf`); display names are sanitised (Sinhala/Tamil kept) and never used as paths;
- 10 MB cap (`core.attachments.max_kb`), empty files refused;
- stored on the private `attachments` disk under a random name (`Y/m/uuid.ext`); the SHA-256 is recorded;
- an antivirus hook runs before anything is persisted (`AttachmentScanner`); the default **records `skipped`** – no scanner is wired yet;
- downloads are always `Content-Disposition: attachment` with `X-Content-Type-Options: nosniff`, the detected type, `no-store`; API download URLs are short-lived signed URLs (5 min) **and** still authorise the viewer;
- delete is a soft delete; `attachments:purge` (daily) removes the file and row 30 days later; upload, download, delete and purge are audited.

API: `GET/POST /api/v1/attachments`, `GET/DELETE …/{id}`, `GET …/{id}/download` (signed). Uploads are not
idempotency-keyed (multipart bodies are not hashed). Web: upload/delete/download routes and the
`<x-core::attachments>` component.

### Outbox relay

`outbox:relay` (every minute, no overlap) publishes pending rows as `IntegrationEvent` envelopes
(id, type, version, occurred_at, actor, correlation id, branch, aggregate, data). At-least-once: a row is
marked processed only after all synchronous listeners returned; each event runs in its own savepoint so a
failing listener (even with a database error) rolls back its own writes without stopping the others;
failures back off 2, 4, 8… minutes (cap 1 h) and are **parked** (`failed_at`) after `core.outbox.max_attempts`
(8) – parked rows are reported by a non-zero command exit code and never silently dropped; events of one
aggregate are delivered in order (a failing event holds back later events of the *same* aggregate only);
several relays can run at once (`FOR UPDATE SKIP LOCKED`). Listeners with real work should be queued and
must be idempotent (use `IntegrationEvent::$id`).

### Audit retention (owner decision: 3 months)

On PostgreSQL `audit_logs` is partitioned by month (existing rows were copied by the migration). `audit:maintain`
(daily 02:00) creates the next months' partitions and **drops whole partitions older than the retention**
(`AUDIT_RETENTION_MONTHS`, default 3). Entries therefore live between 3 and 4 months. Row `UPDATE`/`DELETE`
stays blocked by the trigger, which is cloned to every partition; dropping a partition is DDL and is itself
audited (`audit.partitions_dropped`). The default partition (rows outside any month) is never dropped.
On other databases the command deletes expired rows. **Nothing exports audit data before it is dropped.**

### Queue, cache, scheduler

Redis (via `predis`) is the dev default for queue, cache and rate limits (`QUEUE_CONNECTION=redis`,
`CACHE_STORE=redis`); tests use `sync`/`array`, plus tests that talk to a real Redis when one is reachable.
The scheduler (`schedule:work` / the scheduler container) runs: `outbox:relay` (minutely), `idempotency:prune`
(daily), `audit:maintain` (02:00), `attachments:purge` (02:30), `approvals:expire` and `approvals:remind` (hourly).
**Laravel Horizon is not installed** – see [deployment.md](deployment.md).

### New stable error codes

`attachment.too_large` · `attachment.type_not_allowed` · `attachment.upload_failed` · `attachment.infected` ·
`attachment.unknown_target` · `attachment.missing` · `approvals.not_eligible` · `approvals.not_pending` ·
`approvals.not_open` · `approvals.not_retryable` · `approvals.expired` · `approvals.cannot_cancel` ·
`approvals.comment_required` · `approvals.no_approver` · `approvals.payload_changed` ·
`approvals.execution_error` · `approvals.unknown_action` · `approvals.unknown_role` · `approvals.unknown_user` ·
`approvals.invalid_step` · `approvals.steps_required` · `approvals.invalid_range` · `approvals.invalid_holder` ·
`approvals.invalid_delegation` · `approvals.delegation_too_long`.

## Company profile (added in Sprint 6)

`CompanyInfo::profile()` gives other modules the legal name, trade name, tax ID (VAT/TIN), registration number, address lines, phone, email and website for
document headers (today: the printed tax invoice). It is edited at **Administration → Company profile** (`core.company.manage`, admin only) through the
`SaveCompanyProfile` action; until it is filled the placeholder name "My Company (Pvt) Ltd" is printed.

## Appearance (added with the branding step)

`Appearance` (contract) gives every layout and printed document the installation's name, logo, theme CSS and document CSS; it is edited at
**Administration → Appearance** (`core.appearance.manage`) and over `/api/v1/appearance`. Full description: [branding.md](branding.md).
