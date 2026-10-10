# Sprint 2 — Approvals, notifications, attachments, queue/scheduler: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 3 has not been started.

## 1. What was built

New module **Approvals** (depends on Core only) and Core additions
(details: [../architecture/approvals.md](../architecture/approvals.md) §6 and [../architecture/core-module.md](../architecture/core-module.md) §7).

- **Approval engine**: policies (action key, amount range, risk level, branch, priority), ordered and parallel
  approver steps by role or person, authority matrix, delegation (time-boxed, scoped), expiry, reminders,
  cancel, retry of failed executions. Backend-enforced rules: no self-approval, one person cannot satisfy two
  steps, limits by amount, super-admin is not an approver, payload hash re-verified before execution,
  execution at most once.
- **Screens**: approvals inbox (waiting for me / my requests / all), request detail with decision form, steps,
  decisions, execution history, attachments; delegations; policy administration; approval-limit administration.
  **API**: `/api/v1/approvals` (list, show, approve, reject, cancel, retry) and delegations, idempotent, same
  error format. English/Sinhala/Tamil.
- **Notifications**: in-app inbox, bell with unread count, API; rendered in the reader's language.
- **Secure attachments**: hardened uploads, private storage, signed downloads, antivirus hook, audit.
- **Outbox relay** (at-least-once, backoff, dead-letter, per-aggregate order), **audit retention** (3 months,
  monthly partitions), **scheduler** wiring, **Redis** queue/cache, `UserDirectory`/`Notifier`/`EventOutbox`
  contracts for modules above Core.
- **Dev tooling**: `compose.yaml` (Redis), `scripts/dev/run.ps1` (web + worker + scheduler), a local-only demo
  action with demo users, `public/build` committed, CI gets a Redis service, developer guide
  [gating-an-action.md](../development/gating-an-action.md).

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer test` (Pest) | **278 passed**, 1,178 assertions, ~58 s — run twice in a row, identical |
| `composer analyse` (Larastan level 6) | no errors |
| `composer lint` (Pint) | passed |
| `composer audit` / `npm audit` | no advisories / 0 vulnerabilities |
| End-to-end over HTTP against the running app (script-started web + worker + scheduler) | requester signs in → requests LKR 25,000 → approver signs in → approves → the Redis queue worker executes it → request shows *Done* with a successful execution record; repeated for a second request |

What the tests prove beyond "it runs":

- **Races with real processes** (`tests/Concurrency`): 8 workers delivering one approved job → exactly one
  execution; 6 simultaneous approvals by the same person → exactly one decision; two parallel approvers deciding
  at the same instant → both recorded and the request executes once (neither sees the other as "still pending");
  4 different approvers racing for one step → exactly one wins. 6 processes allocating document numbers (S1).
- **The tests can fail**: removing the row lock from the execution action makes the concurrency test fail
  (checked by hand and restored); adding a stray user of `ExecutionToken` makes the architecture test fail
  (checked, removed).
- **Security cases**: uploads disguised as other types (PHP as `.pdf`, HTML as `.txt`, PDF as `.png`, a zip
  as `.docx`, an executable as `.pdf`, SVG with script), double extensions, path-like names, empty/oversized
  files, an "infected" scanner verdict, tampered/expired/unsigned download links, outsiders on every attachment
  and approval endpoint, super-admin trying to approve, a payload edited in the database after approval.
- **Real Redis**: a job is pushed through Redis and processed by a worker (skipped automatically if Redis is
  unreachable).

Defects found by the tests and fixed this sprint: `approve()` returned a stale model when the job ran
synchronously after commit; `skipLocked()` does not exist in Laravel's query builder (relay now uses the raw
lock clause); the boundary checker flagged the demo seeder importing Core's private `User` model (moved to a Core
seeder); one order-dependent assertion in the module-toggle test (audit rows read without `ORDER BY`; it failed
once in a full run, did not reproduce afterwards, and is now ordered).

## 3. NOT verified

- **The GitHub Actions workflow has still never run** (nothing is pushed; push needs your GitHub sign-in).
  It now also starts a Redis service.
- **No visual browser check** of the new screens. They are exercised through feature tests and the HTTP flow
  above (including the Alpine-driven policy form markup), not by eye: layout, responsiveness and the policy
  step editor's behaviour in a real browser need a human look.
- **No antivirus scanner is wired**: uploads are recorded as `skipped`. The hook and its blocking behaviour are
  tested with a fake scanner only.
- **Laravel Horizon is not installed** (see §4).
- **Sinhala and Tamil strings for Approvals and notifications are drafts** needing native review (key parity with
  English is tested).
- **No load testing.** Inbox listing loads all candidate requests for a user and filters in PHP.
- **Nothing consumes outbox events yet**, so the relay is verified against test listeners only.
- **S3-compatible storage for attachments** is configured (`ATTACHMENTS_DISK=s3`) but only the local private disk was exercised.
- Restoring audit data after a partition drop is impossible by design; no export exists (§5).

## 4. Decisions for you / deviations from the written plan

1. **Horizon was not installed.** It requires the `pcntl` extension (absent on Windows PHP), so it could not be
   run or checked here, and installing it unverified would be a placeholder. `queue:work` is used instead; Horizon
   comes with the production Docker image (S15) or sooner if you want the dev stack in Docker. Your call.
2. **Policy and authority-matrix administration has screens but no API**; requests, decisions and delegations do.
3. **Branch-scoped policies** work (data + matching, tested) but have no selector on the policy form yet.
4. **Amount limits are on the authority matrix, not on policy steps** (the original catalog had both); equal
   `step_order` means parallel. An action with no matrix rows is unrestricted — an explicit, documented choice.
5. **Defaults I chose, please confirm or change**: policy expiry 72 h, reminder after 24 h; delegation at most
   90 days; four-eyes (`distinct_approvers`) on and self-approval off by default; deleted attachments are purged
   after 30 days; upload cap 10 MB and the file-type allow-list in `config/core.php`; failed executions notify
   the requester and everyone with the role `admin`; API tokens still 30 days.
6. **Audit retention is 3 whole months** as you decided: entries live 3–4 months because months are dropped
   whole. Converting `audit_logs` to a partitioned table was a schema change to an existing table (rows copied,
   none lost, forward-only migration) — flagging it because CLAUDE.md asks for review of destructive migrations.
7. **API locale**: an explicit `Accept-Language` wins over the user's saved language. Browsers always send one,
   so a saved language only applies to API clients that send none (the mobile app should send its own).
8. **Demo action and demo users exist only in the `local` environment** (never registered in testing or
   production; tested). Remove them whenever you like.
9. **Still deferred from S1 decisions**: warehouses (S4) and the currency seed (S3).

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Audit data past retention is dropped with no export | cannot answer a question about activity older than 3–4 months | decide whether an archive/export (object storage) is needed before go-live; legal/PDPA view |
| No consumer for outbox events, relay needs the scheduler | events pile up if the scheduler is down | alert on scheduler and on `outbox_events` with `failed_at` or old `processed_at IS NULL` (S15 monitoring) |
| Worker or scheduler stopped | approved requests are not executed, reminders/expiry stop | process supervision + alerts; the UI shows *Approved* (not *Done*) while waiting |
| Antivirus not wired | malicious files can be stored (still never executed or served inline) | choose ClamAV container or provider; bind `AttachmentScanner` |
| Inbox filters in PHP | slow with thousands of open requests | revisit with real volumes; add an index-backed query |
| Approver eligibility depends on role membership at decision time | a role change mid-flight changes who can approve | intended; documented. Policies snapshot the steps, not the people |
| `Gate::before` still lets `super-admin` pass permission checks | view/cancel everything | intended; approving is not a Gate and is tested to be closed to super-admin |
| Demo passwords were printed in the development session | local only | rotate by re-running the demo seeder; never used outside `local` |
| Predis on the dev machine, phpredis in production | small behavioural differences | tested path is predis; run the suite against phpredis in the S15 image |
| Dev Redis password is in the git-ignored `.env` | local only | none |

## 6. Decisions requested before Sprint 3 (Master Data)

- Confirm or change the defaults in §4.5, and answer §4.1 (Horizon now or with the Docker image).
- Antivirus: ClamAV container acceptable?
- Please push the repository (needs your GitHub sign-in) so CI can run.
- For Master Data please bring: the item-code and customer/supplier-code formats you use today, the units of measure
  and item categories you need, current VAT/SSCL/NBT rates and effective dates, which customer/supplier fields
  are mandatory (NIC / business registration / VAT number, credit terms), and whether prices are tax-inclusive.
  If a FrontAccounting export of customers/suppliers/items is available, it saves re-keying (migration is S5b).

## 7. How to try it

[../development/setup.md](../development/setup.md) §7: start PostgreSQL and Redis, run `.\scripts\dev\run.ps1`,
open <http://127.0.0.1:8000>. Demo staff and the *Approval demo* page exist in the `local` environment.
