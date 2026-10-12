# Licensing (part 1) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Design and rules: [../architecture/licensing.md](../architecture/licensing.md). Part 2 (the separate vendor billing project with bank-transfer entry and PayHere) has not been started.
Your answers are built in: 7-day grace, then 14 days read-only, then locked; one plan; hosted by you only.

## 1. What was built

New module **Licensing** (required; depends on Core only).

- **Signed licence**: a text starting `DIO1…`, signed by your private key (Ed25519). The product holds only your **public** key (`LICENCE_PUBLIC_KEY`), so it checks licences offline and cannot be tricked by an edited licence, a licence for another company, or an older licence.
- **Phases after the last day**: **7 days grace** (everything works, warning on every screen) → **14 days read-only** (look, print, export; nothing can be posted or changed) → **locked** (only the licence screen, sign-in/out remain). Data is never touched. Scheduled jobs (reconciliations, backups) keep running.
- **Free trial**: a new installation works for 30 days, then goes through the same phases until a licence is installed.
- **Renewal = a newer licence.** You can also **suspend** an installation by issuing a newer, shorter licence. Every licence ever installed is kept as permanent history.
- **Administration → Licence**: status, last day, days left, plan, included modules, the **installation ID** (what you need to issue a licence), paste-a-licence form, history. Everyone signed in can open it (it is the one screen left when locked); only admins can install a licence. Same over `/api/v1/licence`.
- **Warnings**: a banner on every screen (within 7 days of the end, and in each phase after it) and daily notifications to administrators (7, 3, 1 and 0 days before, and when each phase starts).
- **Plan limits**: the list of modules in a licence is enforced; the **user limit is recorded and shown but not enforced yet** (see §3).
- **Your tools**: `php artisan licence:keys` (once), `php artisan licence:issue <installation-id> --company="…" --months=1 --key-file=…`.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **892 passed** (7,458 assertions), 905 s — a clean full run after the last code change, with PostgreSQL and Redis up |
| New tests | 26 licensing tests (signature, installing, phase-by-phase dates, trial, screens and API enforcement, plan limits, commands and daily check, three languages) |

What the tests prove beyond "it runs":

- **Signature**: a licence verifies with the right key and fails when one character of the payload is changed, when signed by another key, or when garbled; line breaks from copy-paste are tolerated.
- **Installing**: refused for another installation, with a forged signature, dated in the future, or older than the one installed; the same licence twice changes nothing; a newer one — even a shorter one — takes over; the history cannot be edited or deleted (the database refuses).
- **Phases, day by day**: for a licence ending 31 December: 1 Dec active (30 days left), 31 Dec active (0), 1 Jan grace, 7 Jan grace, 8 Jan read-only, 21 Jan read-only, 22 Jan locked; the trial walks through the same phases; a licence that has not started yet does not replace the running one until its first day.
- **On the screens and API**: in grace everything works with a warning; read-only allows reading but refuses changes (web bounces back with the reason, API answers HTTP 402 `licensing.read_only`); locked redirects every page to the licence screen and answers 402 `licensing.locked`, while sign-in, sign-out, the licence screen and the logo still work; **installing a renewal from the locked state unlocks at once**.
- **Switched off** (development): nothing is restricted and no banner appears; enforcement is on by default only in production.
- **Plan**: a licence naming only some modules refuses the others (403 `core.module_not_licensed`); one naming none allows all.
- **Commands**: keys are generated; a licence issued from a key file installs; missing key, missing end date and impossible dates are refused; the daily check tells administrators exactly once per situation.

## 3. NOT verified / not built

- **Part 2 is not built**: no vendor billing system yet (customers, invoices, bank-transfer entry, PayHere recurring payments, automatic licence issue). Today you issue licences by hand with the command.
- **The user limit is not enforced** — there is no screen that creates users yet, so there is nowhere to enforce it. It is stored in the licence and shown.
- **No visual check in a browser by me** of the licence screen and banners; **Sinhala and Tamil text are drafts**.
- **Honest limit** (as in the design): a licence stops honest non-payment; it cannot stop someone who controls the server and the code. That is why installations stay on your servers.
- **Clock**: the phases follow the server's date. A server with a wrong date could keep a system open or lock it early; hosting on your own cloud servers with time sync avoids that.

## 4. What you need to do to use it (once per installation)

1. On your own machine run `php artisan licence:keys`. Store the **secret key** safely (password manager / encrypted drive; it is shown only once). Copy the **public key**.
2. On each hosted installation set in the environment: `LICENCE_PUBLIC_KEY=<public key>` (Coolify environment variables) and enforcement (`LICENSING_ENFORCE`) switches on by itself in production. Optionally `LICENCE_RENEW_NOTE="…your billing contact…"` (shown on the licence screen).
3. Open **Administration → Licence** on that installation, copy the **Installation ID**.
4. Issue: `php artisan licence:issue inst_… --company="Customer Ltd" --months=1 --key-file=secret.key` and send the text to the customer (or paste it yourself).

## 5. Decisions for you

1. **Vendor billing project** — shall I start it next (customers, monthly invoices, bank-transfer recording, licence issue, then PayHere)? It is a separate project; I would create it in its own folder/repository. Please also tell me the plan's monthly price and whether invoices to your customers need VAT and an invoice number format.
2. **Trial length** (30 days) and the **banner** wording — fine, or change?
3. **After lock** — should a locked customer's data be exportable (a one-time CSV export for them) or stay completely unreachable until they pay?

## 6. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Secret signing key lost | cannot issue renewals | keep two encrypted copies; if lost, change the public key on every installation and re-issue |
| Secret signing key leaked | anyone can make licences | rotate the key pair and re-issue; keep the key off servers |
| No automatic payment yet | renewals depend on you issuing licences | part 2 |
| Server clock wrong | phases shift | time sync on the hosting servers |

## 7. Next

Part 2 (vendor billing project) if you say so, then **Sprint 8 (Manufacturing I)** — your answers on bills of materials, routings and costing are still needed.
