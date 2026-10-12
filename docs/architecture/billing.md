# Vendor billing system (`vendor-billing/`)

A separate Laravel application in the same repository, for the **vendor only**. It is never installed on a customer's
installation. It invoices customers every month, records payments (bank transfer by hand, PayHere automatically) and signs
the licences that the product's [Licensing module](licensing.md) checks. Decisions by the owner (2026-10-12): 30-day free
trial; invoices carry VAT; stay locked until the customer pays; invoice number `SUB/2026-27/00001`.

## 1. Folder layout

```
dio-aem-9001a/
  app/ …                    the product (ERP + MES) – what customers use
  vendor-billing/           THIS project – its own app, tests, vendor/, .env and database
    app/Models, Services, Http, Console, Mail, Support
    config/billing.php      starting values and environment-driven secrets
    database/migrations     tables + the triggers that keep records permanent
    resources/views         handwritten pages (no Node build) and e-mail templates
    public/css/billing.css
    tests/                  Pest; tests/fixtures/compat-licence.txt
    README.md               how to install and run it
```

Separate means: its own `composer.json`, own database (`dio_billing`, tests use `dio_billing_test`), own sign-in, own
`.env` (git-ignored). The root Pint config excludes the folder, and the product's pipelines never touch it. The only thing the
two share is the **licence format** — a copy of `LicenceToken` — and a test on each side proves they agree (§7).

## 2. What it does

| Area | Rule |
|---|---|
| Plan | One plan; name, monthly price (LKR, **excluding VAT**), VAT rate, trial days, invoice lead days and user limit are set on the Settings screen (owner). A customer's price is copied when they are added, so a later price change applies to new customers only. |
| Trial | 30 days from the customer's start date (date can be set to match the installation's first use). Billing starts the day the trial ends. |
| Invoice | Issued `invoice_lead_days` (7) before each period starts, e-mailed with a view/pay link. Period = one calendar month counted from the billing anchor (an anchor on the 31st bills 31 Jan, 28 Feb, 31 Mar — no drift) and periods are continuous. Due on the first day of the period. If the schedule did not run, every missed month is issued. Suspended customers are not invoiced. |
| Numbers | `SUB/<financial year>/<5 digits>`, financial year 1 April–31 March (`SUB/2026-27/00001`), gapless: the counter moves in the same transaction as the invoice. |
| VAT | VAT = price × rate rounded to the cent (half away from zero), total = price + VAT; the rate, amounts and both parties' details are frozen on the invoice. Printable tax invoice (`/i/{token}` for the customer, staff view in the app). |
| Payments | Bank transfer: staff key in amount, date and bank reference. PayHere: customer pays at `/pay/{token}`; PayHere's notification marks it. An invoice is paid when the payments reach its total; part payments and overpayments are recorded as they are. Staff can reverse a payment (owner, with a reason). |
| Licence | When an invoice is paid, a licence covering **every period paid without a gap** is signed with the private key, stored, and e-mailed. Paying month 3 before month 2 does not extend the licence; paying month 2 then issues one licence through month 3. The licence is only issued when it extends the last one. |
| Missing pieces | Paid but no installation ID or no signing key: the payment stands, nothing is lost, the dashboard lists the customer under “Paid, but no licence sent”, and the licence is issued when the ID is entered or by the “Issue licence” button. |
| Shut out | Nothing extra is needed: when the month is not paid the installation's licence runs out and it goes grace (7 days) → read-only (14 days) → locked (the product's rule). It stays locked until the invoice is paid; no export is offered. The owner can also **Suspend** a customer now (a newer, already-expired licence replaces the paid one, and invoicing stops) and **Resume**. |
| Reminders | `billing:remind` (08:00 Colombo) e-mails unpaid invoices on day −3, 0, +3, +7 (read-only starts) and +21 (locked), once each; a long-overdue invoice gets one message, not one per missed stage. |
| Voiding | An unpaid invoice can be voided (owner, with reason). That month is **waived** — it does not hold back the customer's licence. |
| Fetching | `GET /api/v1/installations/{installation_id}/licence` with the installation's own bearer token (owner creates it on the customer page; only a SHA-256 hash is kept). The product's `licence:fetch` calls it every 4 hours and installs the result, still checking the signature offline. Unknown installation and wrong token give the same 401. |

## 3. PayHere

- Checkout: `/pay/{token}` posts the documented fields to PayHere (sandbox or live by `PAYHERE_SANDBOX`). `hash = UPPER(md5(merchant_id + order_id + amount(2 dp) + currency + UPPER(md5(merchant_secret))))`. The order id is the invoice number with `/` replaced by `-`.
- Notification `POST /payhere/notify` (no CSRF token, no sign-in — PayHere's servers call it): believed **only** when `md5sig = UPPER(md5(merchant_id + order_id + payhere_amount + payhere_currency + status_code + UPPER(md5(merchant_secret))))` matches (constant-time compare). Otherwise 400 and a log entry.
- Only `status_code = 2` pays. Pending, cancelled, failed and charged-back are logged and change nothing. Currency must be LKR. The amount recorded is the signed amount PayHere actually took (even if smaller than the total — then the invoice stays partly paid). The `payment_id` is unique, so a repeated notification is recorded once. If an invoice was voided meanwhile the money is not dropped: it appears in the activity log as `payhere.needs_attention`.
- The merchant secret exists only in the environment; it is never stored, shown or sent to the browser (a test checks the page).
- **Not built:** PayHere *recurring/automatic charging* (their Subscription API with a stored card token). Customers receive an invoice each month and pay it (card via PayHere, or bank transfer); the payment is then automatic. See §8.

## 4. Security

- Sign-in: password (min 12 characters, set with `php artisan billing:owner`, asked for at the prompt and never written to a file), then a **mandatory authenticator code** for everyone. Codes are single-use (a used time step cannot be replayed). Five failed attempts lock the login for a minute (per e-mail + IP, and per user for codes). Lost phone: `php artisan billing:mfa-reset <email>` from the server.
- Roles: **owner** (everything) and **staff** (customers, bank-transfer entry, issue licence). Settings, activity log, API tokens, suspend/resume, void and payment reversal are owner-only.
- The licence **private key** is `LICENCE_PRIVATE_KEY` in this server's environment only: not in the database, not in the repository, not in any log. The public key goes into each installation's `LICENCE_PUBLIC_KEY`.
- The invoice/pay links use a 40-character random token; there are no sequential ids on public pages.
- PostgreSQL triggers: an issued invoice's number, amounts, period, parties and token cannot change and it cannot be deleted; a void invoice cannot be reopened; a payment's amount/method/date/reference cannot change or be deleted (only reversed, once); issued licences cannot change or be deleted; the activity log cannot be edited or deleted.
- Every action (invoice issued, payment, reversal, settings changed, licence issued, sign-ins, rejected PayHere messages) is written to the activity log with the user.

## 5. Tables

`users` (role, TOTP secret encrypted), `settings`, `customers` (installation id, token hash), `subscriptions` (one per customer: price, trial end, billing anchor, status), `invoice_counters`, `invoices`, `payments`, `issued_licences` (kind `renewal` or `suspension`), `activity_log`. Money is `numeric(14,2)` and handled as decimal strings (the product's `Decimal` helper), never floats. Dates are Colombo calendar days; timestamps are UTC.

## 6. Commands

| Command | |
|---|---|
| `php artisan billing:owner <email> [--staff] [--name=]` | create a user (asks for the password) |
| `php artisan billing:mfa-reset <email>` | forget a user's authenticator |
| `php artisan billing:invoice` | issue due invoices (scheduled 06:00 Colombo) |
| `php artisan billing:remind` | send reminders (scheduled 08:00 Colombo) |
| `php artisan schedule:work` / cron `* * * * * php artisan schedule:run` | run the schedule |

## 7. Agreement with the product

`tests/fixtures/compat-licence.txt` is a licence signed in this project with a fixed test key. The product keeps the same text in `tests/Fixtures/vendor-billing-licence.txt` and its `VendorCompatibilityTest` verifies it with the product's own `LicenceToken`; this project's test fails if the two copies differ or if its signing output changes. If the format ever changes, both tests must be changed together.

## 8. Limits and open points

- No PayHere recurring charge (see §3); no refunds or credit notes (a wrong invoice is voided and re-issued by hand — the next period is still created by the schedule); one subscription per customer; English only; no screen to add staff (use the console command).
- A payment reversal does not recall a licence that was already sent; use Suspend if it must stop at once.
- The product's own trial starts at its first use; the billing trial starts at the date typed when adding the customer. Keep them aligned (type the installation's first-use date).
- No e-mail provider is configured: development logs mail; production needs `MAIL_*` set.
