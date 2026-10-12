# Licensing and subscription billing — design (not built yet)

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
