# Licensing and subscription billing

**Status:** part 1 (the Licensing module in this product) and part 2 (the separate vendor billing project, `vendor-billing/`, see [billing.md](billing.md)) are built. PayHere *recurring* charging is not; customers pay each monthly invoice through PayHere or by bank transfer.

Decisions by the owner (2026-10-12): the vendor **hosts every installation** on its own cloud servers (customers never run
the software themselves); customers pay **monthly**, by **bank transfer** (recorded by hand) or **PayHere** (automatic);
there is **one plan** whose price and terms are set in a settings page; access **stops after non-payment**; billing is a
**separate project** from this product; first the Licensing module is built into this product, automatic payment follows.

## 1. Two parts

1. **Vendor billing system (separate project, vendor's server only).** Customers, the plan, monthly invoices, payment records
   (bank transfers keyed in by staff; PayHere recurring payments confirmed by its notification), reminders, and a **licence
   issuer** that signs a licence for each paid period. It is the only place that holds the signing (private) key.
2. **Licensing module (inside this product, on every installation).** Verifies a signed licence offline with the public key built
   into the product, shows the licence on a settings page, warns before it ends, and restricts the system after it has lapsed.

## 2. The licence

A small signed document (Ed25519 signature over JSON): installation id, company, plan, `valid_until`, optional user and module
limits, issue time, licence serial. The module refuses a licence with a bad signature, for another installation, older than the
current one, or from the future. Keys are entered on the settings page (copy-paste) at first; later the installation fetches its
renewal automatically from the vendor system. The vendor system issues the next licence when a payment is confirmed (PayHere:
immediately; bank transfer: when staff mark it received).

## 3. What happens when it lapses

| Phase | When | Effect |
|---|---|---|
| Active | until `valid_until` | normal; a banner from 7 days before the end |
| Grace | 7 days after (setting) | normal, with a clear warning to admins and on every screen |
| Read-only | the next 14 days (setting) | people can view, print and export, but not post or change anything; payments of the subscription can still be entered |
| Locked | after that | only the licence screen and sign-out; data is kept safely |

Because the vendor hosts the installation, the vendor can also suspend or resume it from its own side; the licence is the
customer-visible, auditable form of that decision.

## 4. Honest limits

A licence stops honest non-payment and accidental use; it cannot stop someone who controls the server and the code. That is why
installations stay on the vendor's servers and the contract states the terms. The private key never leaves the vendor system.

## 5. Build order

1. Licensing module in this product: licence model and verification, settings page (current licence, paste a new one, plan terms), warnings, grace / read-only / lock enforcement, tests, plus a command-line licence generator for the vendor.
2. Vendor billing project: customers, plan, invoices, bank-transfer recording, licence issue.
3. PayHere recurring payments and automatic renewal.

## 6. What is built (part 1)

- **Module `licensing`** (required; depends on Core). Table `licences` keeps every installed licence permanently (the database refuses changes and deletions). **The licence with the newest issue date that has started is the one in force**, so a renewal is just a newer licence, and the vendor can also shorten or end a licence — suspend an installation — by issuing a newer one. A licence older than the newest one installed is refused, as is one for another installation, one with a bad signature, or one dated in the future.
- **Signature**: `LicenceToken` — `DIO1.<payload>.<signature>`, Ed25519 (libsodium) over the exact payload bytes. The public key comes from `LICENCE_PUBLIC_KEY`; stored licences are re-checked against it on every read, so a tampered row is ignored.
- **Phases** (today against the last day, Asia/Colombo): active → grace (7 days, everything works, warning on every screen) → read-only (14 days: GET/print/export allowed, every change refused with HTTP 402 `licensing.read_only`, or bounced back on the web) → locked (everything but the licence screen, sign-in/out, MFA, language, the logo and health redirects to the licence screen or answers 402 `licensing.locked`). Background jobs and scheduled commands keep running, so reconciliation and backups are never interrupted. A new installation has a free trial of 30 days with the same phases after it. All the day counts are settings (`LICENCE_*`).
- **Enforcement** is on by default in production only (`LICENSING_ENFORCE` forces it either way), so development and tests are never locked out; the licence screen says so when it is off.
- **Plan limits**: the module list of a licence is enforced (a module outside the plan answers 403 `core.module_not_licensed`). The **user limit is recorded and shown but not enforced yet**: there is no user-management screen to enforce it in; it will be enforced when users can be created from the product.
- **Screen** (Administration → Licence, `licensing.manage`, held by admin): status, last day, days left, plan, modules, the **installation ID** to give the vendor, paste-a-licence form, history. The screen itself is open to every signed-in user (so it is reachable when locked) but only managers can install. **API**: `GET/POST /api/v1/licence`.
- **Vendor tools**: `licence:keys`, `licence:issue` (secret key read from a file or `LICENCE_PRIVATE_KEY`, never an argument), and the daily `licence:check` notifications (7, 3, 1 and 0 days before the end, and at the start of each stricter phase; once each).
- Core knows only a small contract (`LicenceGate`: banner and module check); without the module it allows everything.
- **Automatic renewal**: `php artisan licence:fetch` (scheduled every 4 hours; does nothing unless `VENDOR_BILLING_URL` and `VENDOR_BILLING_TOKEN` are set) asks the vendor billing system for this installation's newest licence and installs it. The licence is still verified offline against `LICENCE_PUBLIC_KEY`, so the billing system is trusted for delivery only.
