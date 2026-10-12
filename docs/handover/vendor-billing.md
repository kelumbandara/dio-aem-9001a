# Vendor billing project — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Design, rules and limits: [../architecture/billing.md](../architecture/billing.md). Install and daily use: [../../vendor-billing/README.md](../../vendor-billing/README.md).
Your answers are built in: separate project under `dio-aem-9001a/vendor-billing/`; invoices with VAT; number `SUB/2026-27/00001`; 30-day free trial; a customer stays shut out until they pay.

## 1. What was built

A separate Laravel application (own database, sign-in, tests, `.env`) that you run on your own server only.

- **Customers and plan** — add a customer (name, billing e-mail, VAT/TIN, installation ID). One plan whose **price, VAT rate, trial days and invoice lead days are on the Settings screen** (owner). The price is copied onto each customer when added.
- **Monthly invoices** — issued automatically 7 days before each month starts, e-mailed with a link; first billing month starts the day the 30-day trial ends. Gapless numbers per financial year (1 April–31 March). Printable tax invoice with your VAT number, bank details and a PAID / VOID stamp.
- **Payments** — staff key in a **bank transfer** (amount, date, reference); **PayHere** payments arrive by PayHere's signed notification. Part payments and overpayments are recorded as they are; an owner can reverse a payment with a reason.
- **Licences** — when an invoice is paid, a licence covering every month paid without a gap is signed with your private key, stored, e-mailed, and (if the installation has its token) fetched by the product itself within 4 hours.
- **Shut out until paid** — if the month is not paid the licence simply runs out: 7 days warning, 14 days read-only, then locked, and it stays locked until the invoice is paid. Reminder e-mails go out on day −3, 0, +3, +7, +21. The owner can also **Suspend** (shut out now, stop invoicing) and **Resume**.
- **Screens** — dashboard (customers, in trial, monthly recurring, overdue, trials ending, paid-but-no-licence), customers, invoices, settings, activity log. English only, plain CSS, no Node build.
- **Security** — password + mandatory authenticator code (single-use codes, throttled), owner and staff roles, activity log of everything, database triggers that make issued invoices, payments, licences and the log permanent.
- **Product side** — new `php artisan licence:fetch` (scheduled every 4 hours) installs the newest licence from this system; the licence is still verified offline.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15)

| Check | Result |
|---|---|
| `vendor-billing`: Pest | **60 passed** (477 assertions) |
| `vendor-billing`: Pint | passed |
| `vendor-billing`: Larastan level 6 | **no errors** |
| Product: `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **902 passed** (7,484 assertions), 907 s, after the last product change (+10 tests: licence fetch and format agreement) |
| Real HTTP smoke test (php artisan serve) | `/up` 200, `/login` 200, anonymous `/dashboard` → login, POST `/login` without CSRF → 419, POST `/payhere/notify` without signature → **400 (not 419)**, i.e. the CSRF exemption works and the signature check is what answers |

What the tests prove beyond "it runs":

- **Money**: VAT = price × rate rounded half-up to the cent, checked against an independently computed value for 60 random price/rate pairs; total = net + VAT always; zero VAT rate; (this found a real bug on the first run: the total was kept with 12 decimals in memory).
- **Numbers and periods**: `SUB/2026-27/00001` restarts on 1 April (31 Mar and 1 Apr tested); a refused duplicate consumes no number; an anchor on the 31st gives 31 Dec, 31 Jan, 28 Feb, 31 Mar, 30 Apr with periods continuous; first invoice appears exactly 7 days before the trial ends and only once; missed months are caught up; suspended customers are not billed.
- **Licence**: the licence a payment produces verifies with only the public key and carries the right installation, company, plan and dates; another key rejects it; months extend without overlap or gap; paying month 3 before month 2 issues nothing until month 2 is paid; a voided month is waived; paid-without-key and paid-without-installation-ID keep the payment and issue the licence later; the same coverage is never issued twice; suspend produces an already-expired licence and resume restores the paid one.
- **PayHere**: bad, missing or tampered signature, wrong merchant → 400 and nothing recorded; success pays and issues the licence; duplicate notification recorded once; pending/cancelled/failed/charged-back change nothing; wrong currency ignored; a short payment stays partly paid; a payment for a voided invoice is flagged for staff, not dropped; checkout hash equals the documented formula and the merchant secret never appears on the page.
- **Access**: every staff URL needs sign-in; authenticator code cannot be replayed (same step, or an older one); throttling; staff cannot reach settings, void, reverse, suspend or API tokens (403); API gives the same 401 for an unknown installation and a wrong token, a rotated token kills the old one, another customer's token does not open this installation.
- **Permanence**: the database refuses to change or delete invoices, payments, licences and the activity log (tested for each).
- **Both projects agree on the format**: a licence signed in the billing project with a fixed key is verified by the product's own `LicenceToken` (and rejected under another key or after one character changes); both copies of the text are compared.
- **Hand mutation checks**: breaking the "no gap" rule, accepting any PayHere signature, and truncating instead of rounding VAT were each caught by the tests. Removing the *in-transaction* duplicate-payment check was **not** caught, because a second layer (the unique index plus catching its violation) still makes the result correct; I therefore do not claim a test for that one line.

## 3. NOT verified / not built

- **PayHere has not been run against PayHere itself** (no merchant account/credentials here). The formulas follow PayHere's documentation and are tested against that formula, but you must do a sandbox payment before going live; if PayHere's field rules differ, only `App\Services\PayHere` changes.
- **PayHere recurring (card-on-file automatic charging) is not built.** Each month the customer gets an invoice and pays it (card via PayHere, or bank transfer); the recording after payment is automatic.
- No e-mail provider is configured (development writes mail to the log); no real e-mail was sent. No browser check by me of the screens, only HTTP/HTML tests.
- No screen to add staff (console `billing:owner --staff`); English only; one subscription per customer; no refunds or credit notes (a wrong invoice is voided and the right one added by hand).
- A payment reversal does not recall a licence already sent (use Suspend).
- The product's trial starts at its first use but the billing trial starts on the date you type for the customer; keep them aligned.
- The job scheduler must be running on your server (`schedule:work` or cron) or invoices/reminders do not go out.

## 4. What you need to do

1. `cd vendor-billing`, follow its README: create `.env`, `php artisan migrate`, `php artisan billing:owner you@…` (asks the password), sign in and link your authenticator.
2. **Settings**: plan price, VAT rate (18 %), your company name, address, VAT registration number, bank details.
3. On a safe machine run the product's `php artisan licence:keys`; put the **secret** key in the billing server's `LICENCE_PRIVATE_KEY` and the **public** key in every installation's `LICENCE_PUBLIC_KEY`.
4. PayHere: create the merchant account, set `PAYHERE_MERCHANT_ID`, `PAYHERE_MERCHANT_SECRET`, allow `https://<billing host>/payhere/notify` and try the **sandbox** first.
5. For each customer: add them, paste their Installation ID, make an API token and give the installation `VENDOR_BILLING_URL` / `VENDOR_BILLING_TOKEN`.

## 5. Decisions for you

1. **Plan price** (set on the Settings screen — I do not know it).
2. **Recurring card charging** with PayHere (needs their Subscription API and merchant approval): build it later, or is monthly invoice + pay link enough?
3. **Reminder days** (−3, 0, +3, +7, +21) — fine?
4. Still open from before: purchase-order approval amounts/approvers; Horizon vs plain worker in Docker; ClamAV; whether GitHub Actions is green.

## 6. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Signing key leaked or lost | anyone can mint licences / you cannot renew | env-only; two encrypted offline copies; rotate = new key pair on every installation |
| Schedule not running | no invoices or reminders | monitor the Coolify scheduler; the dashboard shows overdue |
| PayHere details differ from documentation | online payments not recognised | sandbox test before launch |
| Wrong bank entry | customer wrongly unlocked | owner reverses it; Suspend if the licence must stop |
| Billing server down | no new licences, no fetch | installations keep their last licence; 7 + 14 days of tolerance |

## 7. Next

After your review: **Sprint 8 (Manufacturing I)**. I still need your answers on bills of materials (single or multi-level, alternates, yield/scrap), routings (work centres, machines, shifts), material issue (manual or backflush) and costing (standard or actual, overhead absorption).
