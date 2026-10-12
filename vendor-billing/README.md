# DIO Billing (vendor only)

Monthly subscription billing for the DIO ERP + MES product: customers, invoices with VAT, bank-transfer entry, PayHere
payments, and the signed licences that keep each installation running. **Never install this on a customer's server.**
Design, rules and limits: [`../docs/architecture/billing.md`](../docs/architecture/billing.md).

Laravel 13 · PHP 8.3 · PostgreSQL 16 · Pest. No Node build (handwritten CSS in `public/css`).

## First-time setup (development)

```powershell
. ..\scripts\dev\dev-env.ps1          # PHP 8.3 first on PATH
..\scripts\dev\postgres.ps1 start     # portable PostgreSQL
composer install
copy .env.example .env                # then fill DB_USERNAME / DB_PASSWORD; never commit .env
php artisan key:generate
php artisan migrate                   # database dio_billing must exist; tests use dio_billing_test
php artisan billing:owner you@example.com --name="Your Name"   # asks for the password
php artisan serve --port=8001
```

At first sign-in you link an authenticator app. Then open **Settings** and fill in the plan price, VAT rate and your company
and bank details **before adding customers** (adding a customer is refused until the price is set).

## Production environment (Coolify secrets)

| Variable | |
|---|---|
| `APP_KEY`, `APP_URL`, `DB_*` | as usual (own database) |
| `LICENCE_PRIVATE_KEY` | the **secret** signing key from `php artisan licence:keys` run in the product (keep two encrypted copies elsewhere) |
| `PAYHERE_MERCHANT_ID`, `PAYHERE_MERCHANT_SECRET`, `PAYHERE_SANDBOX=false` | from your PayHere merchant account; the notify URL `https://<this host>/payhere/notify` must be allowed there |
| `MAIL_*` | outgoing mail for invoices, reminders and licences |
| scheduler | run `php artisan schedule:work` (or cron `* * * * * php artisan schedule:run`) |

Each customer's installation needs `LICENCE_PUBLIC_KEY` (the matching public key); for automatic renewal also
`VENDOR_BILLING_URL` (this host) and `VENDOR_BILLING_TOKEN` (customer page → *New API token*, shown once).

## Daily use

1. **Customers → New customer** (the free trial starts today or on the date you give). Paste the *Installation ID* from the customer's Administration → Licence screen as soon as you have it.
2. Invoices appear by themselves 7 days before each month starts and are e-mailed. **Invoices → open → Record a bank transfer** when money arrives; PayHere payments record themselves.
3. On payment the licence is signed and e-mailed (and fetched by the installation if you gave it a token). The dashboard shows overdue invoices, trials ending and anyone paid-but-without-licence.

## Tests

```powershell
vendor\bin\pest          # needs PostgreSQL running; uses database dio_billing_test
vendor\bin\pint          # style
```
