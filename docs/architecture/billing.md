# Vendor billing system

The vendor's subscription billing (customers, monthly VAT invoices, bank-transfer entry, PayHere, licence issue) is a **separate
project with its own repository**, not part of this product and never installed on a customer's server:

- Folder: `C:\xampp\htdocs\dio-vba-9002a\ver1` (next to this repository)
- Design, rules and limits: `docs/billing.md` in that project; install and daily use: its `README.md`
- Handover: `docs/handover.md` in that project (also summarised in [../handover/vendor-billing.md](../handover/vendor-billing.md))

## What this product has to do with it

- `licence:fetch` ([licensing.md](licensing.md)) asks the billing system for this installation's newest licence, using
  `VENDOR_BILLING_URL` and the installation's own `VENDOR_BILLING_TOKEN`, and installs it after checking the signature offline.
- Both projects use the same licence format. `tests/Fixtures/vendor-billing-licence.txt` is a licence signed by the billing
  project with a fixed test key; `tests/Feature/Licensing/VendorCompatibilityTest.php` verifies it with this product's own
  `LicenceToken`. The billing project keeps an identical copy and compares the two when both folders sit side by side. If the format
  ever changes, change both projects' tests together.
