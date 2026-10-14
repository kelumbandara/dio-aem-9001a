# Project state, decisions and how to resume (last updated 2026-10-14)

This is the single place that says **where the project stands, what the owner decided, how the live server is run, what was learned the hard way, and how to continue from a copy of this folder**. It exists because the assistant's own notes live outside the repository (in the user's Claude profile) and do not travel with a copied folder. Read this first, then `CLAUDE.md`, `docs/architecture/overview.md` and `docs/brief/original-brief.md`.

Contents: 1 Status · 2 Where things are · 3 Development machine · 4 Live server · 5 Owner decisions · 6 What was done on 2026-10-13/14 (the last working sessions) · 7 Open items · 8 Hard-won lessons · 9 Starting a real company from a copy of this folder · 10 How the assistant is asked to work.

---

## 1. Status

- **Built and pushed:** Sprints 1–15 (Core, Approvals, Master Data, Inventory, Finance, Sales, Procurement, Manufacturing I and II, Reporting, AI core and agents, Mobile, Communications, Hardening and release), plus Branding, Licensing, the phone app's sales and purchasing screens, the live server set-up, **Database backups (download) and Restore**. Handovers: `docs/handover/sprint-*.md`, `branding.md`, `licensing.md`, `hosting-and-mobile-trade.md`, `vendor-billing.md`.
- **Every sprint is "built, awaiting owner review".** Nothing has been formally signed off.
- **Not started** (roadmap, owner's order): S5b FrontAccounting migration, S14b SMS, S16 Tasks, S17 HR and payroll; post-MVP items (IoT/OEE, voice, statutory returns, advanced costing, MRP, portals, bank reconciliation import, Maintenance module). See `docs/development-roadmap.md`.
- **Decided on 2026-10-14:** move on to a **real company with real data** (pilot per `docs/runbooks/pilot.md`).
- **Tests:** Pest suite of roughly 1,100+ tests plus Larastan level 6 and Pint (`composer check`, about 30 minutes with the concurrency tests). The result of the last full run is recorded in section 6.

## 2. Where things are

| What | Where |
|---|---|
| Product code | this repository, `app/Modules/*` (modular monolith; rules in `docs/architecture/modules.md`) |
| Architecture, ADRs | `docs/architecture/` (one file per module, `decisions/` for ADRs) |
| Runbooks | `docs/runbooks/`: `deploy.md`, `first-install.md`, `backup-restore.md` (sections 6 and 6.1 are the downloadable copies and the Restore), `coolify-setup.md`, `incident.md`, `pilot.md` |
| Phone app | `mobile/` (Expo/React Native; default server `mes.dio.lk` in `mobile/app.json`) |
| Production image and stack | `Dockerfile`, `deploy/compose.coolify.yaml`, `deploy/compose.production.yaml`, `deploy/production.env.example` (lists every setting; never put values in files) |
| **Vendor billing project (separate!)** | `C:\xampp\htdocs\dio-vba-9002a\ver1` on the owner's machine: its own Laravel app and its own local git repository (**no remote yet**). It is **not** inside this repository, so copying this folder does **not** copy it. Docs: `docs/architecture/billing.md` (a pointer) and `docs/handover/vendor-billing.md`. |
| Assistant notes (not in the repo) | `C:\Users\ASUS\.claude\projects\c--xampp-htdocs-dio-aem-9001a-ver1\memory\` (see section 9 on carrying them over) |

GitHub: `https://github.com/kelumbandara/dio-aem-9001a.git` (remote `origin`, branch `main`). The owner is signed in through the Windows credential manager. **Never ask for or store a token.**

## 3. Development machine (Windows)

- PHP 8.3.33 (winget). XAMPP's PHP 8.2 comes first on the machine PATH, so in PowerShell run `. .\scripts\dev\dev-env.ps1` in every new terminal; in Git Bash prepend `$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe` to PATH.
- PostgreSQL 16.15 is a **portable build** in `%LOCALAPPDATA%\dio-tools` (not a Windows service; it has **no** `pg_dump`/`psql`). Start it with `.\scripts\dev\postgres.ps1 start` **before** running tests or they hang; after a start wait about 30 seconds for crash recovery before the first connection. **The development database is outside the repository**: copying the folder does not copy it.
- Redis 7: `docker compose up -d redis` (needs **Docker Desktop running**; start it first). The password is in the git-ignored `.env`.
- Run the site with its worker and scheduler: `.\scripts\dev\run.ps1` (http://127.0.0.1:8000). Phone app: `.\scripts\dev\mobile.ps1`.
- Databases: `dio_erp` (dev) and `dio_erp_test` (tests). Git identity is repo-local.
- Checks: `composer test`, `composer lint` (`lint:fix`), `composer analyse`, `composer check` (all three; `process-timeout` is 3000 s). After changing Blade views with new Tailwind classes run `npm run build` and commit `public/build`.
- Dev logins are local only (the `Demo*Seeder`s print generated passwords once; re-running resets them). Never reuse them on a server.

## 4. Live server

**Host:** Hetzner Cloud VM `91.99.129.228`, run with Coolify. Names (all Let's Encrypt): **`mes.dio.lk` (the ERP; it was `erp.dio.lk` until 2026-10-14)**, `coolify.dio.lk` (dashboard), `dio.lk` and `www.dio.lk` (static website, `/data/sites/dio.lk/`). Full set-up and the firewall catch: `docs/runbooks/coolify-setup.md`.

**Access:** SSH as `root` with a key created on the owner's machine (`~/.ssh/dio_server_ed25519`, never in the repository). Password login is **still on, by the owner's decision** (revisit before real customer data). The Coolify API tokens used during set-up were **revoked**, so the assistant cannot press Deploy: **the owner presses Deploy in Coolify and says so**.

**Containers** of the `dio-erp` application: `web`, `worker`, `scheduler`, `postgres` (PostgreSQL 16.15, application role `dio_erp` is superuser), `redis`, `clamav`, `backup`. Names carry the application id and a timestamp; `docker ps` lists them. Settings live in Coolify's secret store (never in files). `APP_URL=https://mes.dio.lk`.

**Releasing:** push to `main`, run a backup (`docker exec <backup container> backup.sh`, or the Database backups page), press **Deploy** in Coolify (about 4 minutes; `migrate` runs first). **If a release adds a permission, run the roles seeder once** on the web container, because migrations do not create permissions: `php artisan db:seed --class="App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder" --force` (safe to repeat). The production image uses an authoritative class map, so `db:seed --class=` of a **new** class fails until the image is rebuilt.

**Backups (three layers):**
1. **Nightly encrypted** backup at 01:00 Sri Lanka time in the `backup` container (volume `backups`); the passphrase must be kept outside the server. Off-site copy (S3 / Google cloud) is **not set up yet**.
2. **Downloadable copies** (Admin → Database backups, permission `core.backup.manage`, `admin` and `super-admin`): plain `pg_dump` custom-format files in `storage/app/private/db-backups/` (volume survives deploys; the newest 8 kept), made on demand and **every Monday 08:00 (Sri Lanka time)** by the scheduler with a notification to the administrators. They are **not encrypted**: the page says so.
3. **Restore** (same page, **super-admin only**, permission `core.backup.restore` held by nobody else): asks for the typed word `RESTORE`, the person's password and an authenticator code. Order: safety copy of what is live → load into a scratch database and check → maintenance mode → swap database names in one transaction (the old database is kept as `<db>_before_restore_<time>`, the newest two kept) → migrate forward and re-apply roles → maintenance ends → everyone is signed out. A copy from a **newer** release is refused. Undo = restore the "made before a restore" copy. Emergency over SSH: `php artisan database:restore [<file> --force]`. Details and limits: `docs/runbooks/backup-restore.md` §6.1.

**PostgreSQL client version matters:** the image installs `postgresql16-client` to match the server. The default Alpine client (18) wrote dumps with `SET transaction_timeout`, which a 16 server rejects; the restore removes only that line so such copies still load. Raise client and server together.

**Users on the server (2026-10-14):** id 1 `kelumcb@gmail.com`, "Kelum (owner)", roles `admin` **and `super-admin`** (the super-admin role was added by the owner so the Restore button shows); ids 2–6 are demo users `demo.*@example.lk` (see section 6) that should be **deactivated or removed before real data goes in**. Passwords were shown once and are not stored anywhere.

**Mail:** SMTP through the owner's provider works (the owner received a real e-mail). The provider **rejects reserved domains** such as `example.com`. Open: change the mail password (it was shared in chat), SPF/DKIM for the sending domain.

## 5. Owner decisions (consolidated)

- **Business context (ADR-0005):** internal-first, may be sold later; must eventually replace FrontAccounting, Hostinger e-mail, WhatsApp Business, an SMS gateway, Jira and HR/payroll software.
- **Hosting/commercial:** the vendor hosts everything on its own cloud (Coolify); customers pay monthly by bank transfer (manual entry) or PayHere (automatic). One plan, **LKR 6,500 per month excluding VAT** (set on the billing app's Settings screen). Licensing: signed licences, grace 7 days → read-only 14 → locked, 30-day trial; the **user limit is not enforced yet**. PayHere recurring charging to be built later.
- **Tax and money:** VAT 18 %; NBT 0 % (abolished; left inactive); SSCL 2.5 % on **sales** invoices only, on net sales excluding exempt lines (accountant enters rates on the Tax codes screen). **Sales prices are VAT-inclusive; purchase prices are net.** LKR base currency, UTC in the database, Asia/Colombo for display. Financial year 1 April–31 March.
- **Access:** MFA required for `admin`, `super-admin` and `accountant`; audit retention 3 months; only `admin` manages stock; keep `super-admin` (the vendor); separate sales staff; accountant role exists. A credit limit must **block** (override needs a permission and a reason).
- **Inventory:** many warehouses, weighted-average cost, lots, never negative, no approval for stock changes; stock movements post journals automatically (perpetual inventory).
- **Documents:** flow quotation → order → delivery → invoice; invoice number `INV/2026-27/00001`, A4 print view; vendor billing numbers `SUB/2026-27/00001`.
- **Manufacturing:** multi-level versioned BOM with routing inside the version; manual issue/return; average cost into WIP; output reported **per shift** (a shift is required on every report and downtime); finished goods costed with **materials only**; bill activation is admin-only; per-shift targets.
- **Platform:** production = Coolify; commit `public/build`; Docker Desktop + WSL2 locally; ClamAV acceptable; Horizon runs; billing project is a **separate** app.
- **Today's choices:** downloadable backups for `admin` and `super-admin`, kept on the server **and** downloadable, compressed PostgreSQL format, reminder every Monday 08:00, database only (uploaded files will go to Google cloud later); **plain, unencrypted** copies accepted; Restore for the super-admin only; SSH password login stays on.
- **Habit to plan for:** the owner often says "start next sprint" without answering open questions, so use stated defaults and list them in the handover.

## 6. What was done on 2026-10-13/14

Commits (newest first): `82b5fcb` mail-refusal fix · `e2ff1ce` restore: load through SQL, PostgreSQL 16 client, kept database opens · `d662d17` restore feature · `33bce67` deploy runbook note · `145f51e` backups page · `b10b04e` domain rename · `fc944d3` phone app sales/purchasing screens, default server.

1. **Sample data on the live server.** The server had only the first administrator. A one-off seeder (since deleted by the owner's choice) loaded, through the real services so ledgers stay consistent: 3 warehouses, 10 items, 6 customers, 4 suppliers, 5 purchase orders (received/billed/paid, part, open, draft), 2 bills of materials, work centres, machines, shifts, 5 production orders at different stages, quotations, 7 sales orders, 3 invoices with payments and a credit note, and 5 demo users. Finance and stock reconciliations showed 0 discrepancies. The owner tested the phone app against it: OK.
2. **Domain change `erp.dio.lk` → `mes.dio.lk`.** DNS A record, domain on the Coolify `web` service, `APP_URL`, redeploy; certificate issued; phone app default and docs updated. (The phone app remembers a previously typed address: change it on the sign-in screen.)
3. **Database backups page and weekly schedule** (`145f51e`). A first deploy showed no menu item because the new permission did not exist yet: fixed by running the roles seeder; the lesson is now in `deploy.md`.
4. **Restore** (`d662d17`, `e2ff1ce`). Tested **for real** in a throwaway container on the server against a scratch copy of the production database (live site untouched): restore removes data added after the copy, keeps the old database, writes the audit entry, ends maintenance; undo brings the data back; a copy with an unknown migration is refused; a failing load changes nothing. The test **found a real defect** (PostgreSQL 18 client vs server 16, see section 4), fixed and re-tested with both clients. After the deploy the owner restored on the live site: a customer added after a copy was gone afterwards, which was confirmed on the server (audit entry, kept databases, maintenance off, services healthy).
5. **Mail refusal fix** (`82b5fcb`). Creating a user mails a password link inside the save; a provider that refuses an address aborted it with a raw 500. Now the form shows "The e-mail could not be sent… the user was not created" (nothing half-saved), re-sending a link shows an error, and "forgot password" keeps its identical quiet answer (no account-existence leak). Needs a deploy to go live (**not yet deployed at the time of writing**).
6. **Full check.** Started 2026-10-14 after these changes (`composer check`). Result: see the line below, filled in when it finishes.

> **Full check result (2026-10-14, `composer check`, 1,795 s):** Pint clean, Larastan "No errors", **1,410 tests passed, 1 failed (11,498 assertions)**. The one failure was a test that expected the `admin` role to hold every permission except the vendor-only one; the new super-admin-only `core.backup.restore` was not in its exception list (a test gap, not a product fault). The test was corrected and re-run on its own (`PlatformApiTest`: 19 passed) and Pint passes; **the whole suite was not run a second time**, so the last complete green run is still pending. Run `composer check` once on the new machine/folder before the pilot.

## 7. Open items

Pilot and go-live (`docs/runbooks/pilot.md`): choose the pilot company, pilot lead and support contacts, accountant sign-off of opening balances; decide where the first customer runs (server/provider, domain, S3-compatible storage and cost, off-site backup location); custody of the backup passphrase and `APP_KEY` outside the server; external penetration test yes/no; accept or schedule the CSP `unsafe-eval` rewrite and the 15-minute lockout; change the mail password; SPF/DKIM; Meta WhatsApp account; off-site (Google cloud) backups; turn SSH password login off when agreed; deactivate the demo users; deploy `82b5fcb`; vendor billing project needs a GitHub remote; PO approval amounts and which customers/items are VAT-exempt (earlier open questions); the user limit in the licence is not enforced.

Roadmap order after the pilot starts: **S5b FrontAccounting import** (the main one for replacing FrontAccounting), S14b SMS, S16 Tasks, S17 HR/payroll, then post-MVP.

## 8. Hard-won lessons (so they are not paid for twice)

**Tooling on this machine**
1. In Git Bash, heredocs with quotes or PHP code can break the shell (a failed one creates nothing): write files with the editor tool; for edits use a small Python file run with `python file.py`. `python -` or inline code containing `\U`, `\M` or `\-` raises a unicode-escape error.
2. PowerShell 5.1 `Set-Content -Encoding utf8` adds a BOM that breaks PHP. Line endings: Pint fixes CRLF; Windows Python writes CRLF unless `newline=''`.
3. `tar` and `scp` treat `C:` as a host name: use `/c/Users/...` paths. For `ssh`/`scp` use the **literal** `-i ~/.ssh/dio_server_ed25519` (a variable does not match permission rules).
4. A killed run: `taskkill //F //IM php.exe`. Never loop thousands of rows inside a test.
5. The portable PostgreSQL has no `pg_dump`/`pg_restore`: tests that need them use stand-ins; real checks run on the server in a throwaway container.

**Laravel/Pest/Larastan**
6. `google2fa verifyKeyNewer`: pass `0`. `App::setLocale` overwrites `config('app.locale')` (use `core.default_locale`). `db:seed` prompts in production unless `--force`. No `skipLocked()`: `->lock('for update skip locked')`.
7. Larastan: `@property` docblocks for cast columns; module migration path in `phpstan.neon`; one-line `@param … @return …` docblocks break parsing; `$x?->p ?? y` is flagged; paginator/Collection generics are invariant (use `covariant`); `A && ($x = f())` with a function that always returns a truthy value is flagged.
8. Pest: `expect($model)->value` hits Expectation's own property; dataset closures run before `$this`; `...SPREAD, 'literal'` is a PHP fatal (spread last); `array + array` keeps the LEFT values (use `array_merge`); after a PostgreSQL error inside a test use savepoints (`DB::transaction`); flashed old input leaks into the next request in the same test; the IDE reports "undefined property" on Pest's `$this->x` (a false alarm). Route-model binding of users uses `public_id`, not the numeric id.
9. Module toggle tests must `disable('manufacturing')` before procurement; a new Approvable class goes in `tests/Architecture/ModuleBoundariesTest.php`; route `->defaults()` params shift positional controller arguments; `isset` on a model is false for NULL attributes; stored quantities such as `10.000000` must be `Decimal::trim`med before handing to Inventory; levy codes (SSCL/NBT) are seeded inactive: tests must activate and add a rate; journal `source_type` for invoices is `receivable:sales_invoice`.

**Server and operations**
10. Deploying needs the owner (tokens revoked). New permissions need the roles seeder (section 4). New classes cannot be seeded with `--class` on the live image.
11. The restore and backup tools need the PostgreSQL **16** client (section 4). A restore signs everyone out (sessions are in the database) and takes about a minute; nginx stops a request after 120 s, so very large databases need the command line.
12. Docker Desktop crashed once under memory pressure while running the full stack and tests: start Docker Desktop before Redis; the full check takes about 30 minutes.
13. A new permission that only the super-admin should hold (like `core.appearance.vendor` and `core.backup.restore`) must be excluded in **two** places: the `array_diff` in `RolesAndPermissionsSeeder` and the expected list in `tests/Feature/Core/PlatformApiTest.php`.
14. The assistant's permission classifier blocks uploads and commands on the production server unless the owner adds a rule (`.claude/settings.local.json`, see section 10) and blocks granting roles to users: the owner does those steps.

## 9. Starting a real company from a copy of this folder

Prefer **`git clone https://github.com/kelumbandara/dio-aem-9001a.git <new-folder>`** (history, no secrets, no build junk). If the folder is copied instead, note what it contains and what it does not:

- **Copied by a folder copy but must NOT be reused:** `.env` (development secrets, Redis and database passwords), `.claude/settings.local.json` (allows SSH to the **current** server `91.99.129.228`: delete it, and add a new one only for the new server), `storage/` dev files, `node_modules`, `vendor` (reinstall with `composer install` and `npm ci` if paths changed).
- **NOT copied:** the development database (`dio_erp`, in `%LOCALAPPDATA%\dio-tools`; recreate with `php artisan migrate --seed`), Docker volumes (Redis), the **vendor billing project** (separate folder), and the **assistant's notes** (outside the folder; this file replaces them).
- **Carry the assistant's notes (optional):** copy `C:\Users\ASUS\.claude\projects\c--xampp-htdocs-dio-aem-9001a-ver1\memory\` into the matching folder under `C:\Users\ASUS\.claude\projects\` for the new location (the folder name is the new path with `\` and `:` replaced by `-`, for example `c--xampp-htdocs-newcompany-ver1`; open Claude Code once in the new folder to create it). Or simply tell the assistant to read this file.
- **One deployment per company.** The product is single-tenant per deployment (`CLAUDE.md`). The real company needs its **own** server or Coolify application, its own domain, its own secrets (`APP_KEY`, database and Redis passwords, backup passphrase, licence keys), its own mail account and storage. Do **not** put the real company's data on the rehearsal server (`mes.dio.lk`, which holds demo data) unless it is first cleaned (`php artisan migrate:fresh` is destructive: ask first) or the owner decides to reuse it.
- **Steps:** (1) `docs/runbooks/first-install.md` and `coolify-setup.md` for the new server; (2) `php artisan app:preflight --strict`; (3) seed and create the first administrator with a one-time link, no demo users; (4) company profile, chart of accounts review, VAT/SSCL rates, users and roles, approval policies; (5) opening data: master data CSV import (items, customers, suppliers), then **S5b FrontAccounting import** of opening balances and open receivables/payables, with the accountant's sign-off; (6) a backup, a restore drill, the off-site copy; (7) pilot per `pilot.md` (parallel run and daily reconciliation checks).
- **If the real company becomes the vendor's own first customer on the same Coolify**, create a **new** application and database, not a second schema in the rehearsal one.

## 10. How the assistant is asked to work

- Work sprint by sprint (`docs/development-roadmap.md`); stop for owner review at sprint boundaries; after each sprint update docs, write a handover summary and list unresolved risks. Never fabricate test results; no placeholder logic presented as complete.
- **Ask before:** destructive migrations or data loss, major architecture changes, production deploys, anything needing credentials (never write credentials into files; show generated passwords once on screen only).
- Stock and ledgers change only through domain services in transactions; money and quantities are decimals; the AI acts with the requesting user's permissions and never gets SQL, shell or secrets (`CLAUDE.md`).
- Production actions need the owner's permission rule: `.claude/settings.local.json` (git-untracked) with `Bash(ssh -i ~/.ssh/dio_server_ed25519 *root@<server-ip>*)` and the `scp` twin. Granting roles to users and pressing Deploy are done by the owner.
- Explain things plainly: the owner is not a developer and asked for steps with exact clicks; give a recommended default and say what was assumed.
