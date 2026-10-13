# Sprint 15 (Hardening & Release) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Details: [security review](../architecture/security.md) §9, [deployment](../architecture/deployment.md), [performance](../development/performance.md), runbooks in [docs/runbooks/](../runbooks/).

## 1. What was done

1. **Security review** of the whole product, with fixes (see the table in security.md §9). The important finds: a malformed web address made the site answer **500** instead of 404 on every screen; the framework's `/storage/{path}` route was open; **no browser protections** (CSP, framing, HSTS) existed; and **there was no way to create users, change or reset passwords** (a pilot could not have started).
2. **Users and passwords**: Users screens (add, roles, switch off, mailed password link, reset a lost authenticator), forgot-password, change-password, the first-administrator command `app:create-admin`; password rule (12+ characters, mixed case, a digit, not the e-mail name, not in known breaches in production); lockout after 10 failures; switching off or changing a password ends other sign-ins.
3. **Production image and start-up checks**: `Dockerfile` (one image, roles web / worker / scheduler / migrate), `docker/`, `deploy/compose.production.yaml`, `deploy/production.env.example`; `php artisan app:preflight` refuses to start an unsafe production configuration; CI builds the image.
4. **Horizon and ClamAV verified on Linux** in the real image (they could not be run on the Windows dev machine): Horizon ran and completed a queued job; a real ClamAV daemon flagged the EICAR test file and passed a clean one; an unreachable scanner refuses the upload.
5. **Health**: `/api/v1/health/ready` now also needs Redis and, in production, a heartbeat from the scheduler and from the queue worker (a dead worker used to be silent).
6. **Backup and restore**: encrypted nightly backup sidecar with retention, restore script with safety checks, **drill done** (§3).
7. **Performance** at about a year of a small company's data (§3): N+1 problems found and fixed (pages from 500+ queries to 8).
8. **Runbooks and the pilot plan**: [deploy](../runbooks/deploy.md), [first-install](../runbooks/first-install.md), [backup-restore](../runbooks/backup-restore.md), [incident](../runbooks/incident.md), [pilot](../runbooks/pilot.md).

## 2. Verified results (dev machine: PHP 8.3, PostgreSQL 16, Docker 29 / Linux containers)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan + Pest) | Pint passed; Larastan **no errors**; **1,374 passed** (11,376 assertions), 2,204 s (the machine was also running other work; CI's limit is 45 minutes). One small change came after that run, the per-channel consent wording (a view, a language file, one line in `IdentityService`, one test): after it Pint passed, Larastan had no errors and the Communications, hardening and architecture tests passed (117 tests); the full suite was not run a second time |
| Sprint 15 tests added | route-access sweep (3), hardening: headers/CSP/proxies/inline handlers/malformed addresses/lockout (14), user administration, passwords and first admin (24), preflight (9), heartbeats and readiness (6), virus-scan client + Horizon gate (5), party lookups (2), money formatter (2) |
| Production image | built (310 MB), runs as `www-data`, no `.env` inside, routes/config/views/events cache, `route:cache` works |
| Whole stack in containers (postgres, redis, clamav, web, worker, scheduler, migrate, backup) | started in the right order from an empty database; **seed and first administrator**; password set from the one-time link (weak password refused, link works once), sign-in, redirect to authenticator enrolment (QR shown); all security headers present |
| Horizon | "Horizon is running"; 1 supervisor, 1 worker; a queued job completed |
| ClamAV (real `clamd`) | clean file → clean; EICAR → infected; scanner unreachable → upload refused |
| Unsafe start | `APP_DEBUG=true` in production → container exits 1 and does not start |
| **Hand mutation checks** | 21 of 21 caught (after one test was strengthened: a successful sign-in must reset the failure counter). Guards checked: clickjacking header, CSP frame-ancestors, HSTS only over https, lockout counting and reset, role-escalation guard, self-change guard, deactivation ends sign-ins, tokens revoked, password mixed-case and length, no link for switched-off accounts, reset ends sign-ins, current password required, authenticator reset, malformed id → 404, unreadable value → 404, heartbeat staleness, preflight debug check, infected-reported-as-clean, a new unlisted route is noticed |

### The restore drill (2026-10-13, development machine)

Source: the performance database (238,469 rows in 128 tables, the data set above). Steps: `backup.sh` (4 s, 5.6 MB encrypted), checksum verified, restored by `restore.sh` into a **brand-new, empty PostgreSQL 16 server** in 14 s (including container start), then checked:

- Row counts of all 128 tables compared with the source: all equal except `sessions` (12 vs 11): sessions were created in the source after the backup by my own measuring runs.
- Sums of `journal_lines.debit`, `sales_invoices.gross_total`, `open_items.open_amount` and `stock_ledger.id` identical.
- The 45 database triggers (the immutability rules of the ledgers) all present after the restore.
- The application's own checks run **against the restored copy**: `finance:reconcile` → "The books agree."; `inventory:reconcile` → "Stock agrees with the ledger." (1.3 s).
- A wrong passphrase, a damaged file and an existing target database are each refused before anything is changed (tested).
- Retention tested with 400 fake daily files: keeps the last 7 days, one a week to 4 weeks, one a month to 12 months (23 files).

### Performance (summary; full table in docs/development/performance.md)

185 pages measured at that volume: median 80 ms, 95th percentile 241 ms, slowest 685 ms on a busy dev machine. Sales/purchase forms 500 → ~290 ms (508 → 8 queries); invoice page 531 → 31 queries; receivables 1,250 → ~685 ms (535 → 13 queries). Two stock list pages still make many (constant) queries and the receivables page is heavy at 500 debtors: documented with the fix for each.

## 3. NOT verified / not done (read this)

- **Not deployed anywhere.** Nothing was run on Coolify, a real server, a real domain, real TLS, a real S3 bucket or the Hostinger mailbox from the container. The stack was run in Docker Desktop on the dev machine; TLS was simulated with `X-Forwarded-Proto`. A first real deployment will find things (names, firewalls, volume permissions).
- **Backup copy to S3** (`BACKUP_S3_URI`) is written but **never run**; so is the quarterly drill on the customer's server. The drill above proves the scripts and the data survive, not the customer's environment.
- **Horizon** was verified in the Linux container with one job, not under load. The **GitHub Actions image job** and the pcntl/posix extension line were written but **have not run on GitHub yet**; I could not check the workflow syntax with a parser here (no yaml tool), only by reading it. The first push will show.
- **Not load-tested**: speed was measured one request at a time; many simultaneous users are untested. The machine was busy during measuring.
- **No external penetration test.** The review was done by reading code, tests and running the system; it can miss things an attacker would find. Recommended before the first customer holds real data.
- **Accepted security items:** CSP keeps `'unsafe-eval'` (Alpine); no re-authentication for sensitive actions; lockout can be triggered by someone who knows an e-mail address (15 minutes); phone-app `braces` advisory in build tooling only; no Sentry-style error tracking or disk/size alerts.
- **Users API** was not built: user administration is on the website only (the phone app does not need it).
- Sinhala/Tamil strings for the new screens are drafts.
- The production environment's weaker-but-allowed settings are warnings: local file storage instead of S3, no virus scan, no breach check.
- **Docker Desktop crashed once** during the drill (memory pressure on the dev machine); I restarted WSL and Docker Desktop and the restored data was intact. Nothing was lost; noted because it shows the dev machine is at its limit when running the full stack plus tests.

## 4. Decisions needed from you

1. **Where will the first customer run?** Coolify on which server/provider, which domain, which S3-compatible storage (and its monthly cost), which off-site backup location. I need these (never the secrets) to write the exact first-install values and do a real trial.
2. **Who is the pilot company** and who is the pilot lead / support contact on both sides (docs/runbooks/pilot.md §2–3)? Agree the response times.
3. **Backup passphrase and `APP_KEY` custody:** who holds the copies outside the server (suggest: owner + vendor password manager).
4. **External penetration test** before real data: yes/no, and budget.
5. **Accepted risks** in security.md §9 (especially `unsafe-eval` and the 15-minute lockout): accept, or schedule the CSP-build rewrite.
6. **Next step after the pilot starts:** phone app sales and purchase screens, WhatsApp account, S5b FrontAccounting import, SMS, HR/payroll (roadmap), or a bulk stock lookup for speed.
7. Still open: mail password (change it: it was shared in chat), SPF/DKIM for skytechsl.com, Meta WhatsApp account.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| First real deployment untried | surprises on day one | do a rehearsal deployment on the real server with test data before the pilot (half a day) |
| Docker/Coolify details differ from the reference compose file | start fails | the preflight prints the setting; keep `SKIP_PREFLIGHT` for emergencies only |
| Backup passphrase or `APP_KEY` lost | backups or encrypted fields unreadable | custody in decision 3; test the restore quarterly |
| Pages heavy at thousands of debtors/items | slow screens | the fixes listed in performance.md §4, done when a customer needs them |
| Single server | outage until restored | documented restore (about 1 hour); consider a second server later |
| Pilot data load wrong | wrong books | accountant sign-off of opening balances (pilot.md §2) and the weekly reconciliation commands |

## 6. Next

Pilot preparation per [pilot.md](../runbooks/pilot.md) once decisions 1–3 are made; after your review, any of the roadmap items in decision 6.
