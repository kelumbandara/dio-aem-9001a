# Master Data module

Items, units of measure, categories, customers, suppliers, currencies and exchange rates, tax codes and rates,
payment terms, price lists. Depends on **Core** and **Approvals** only. Every module above it (Inventory, Finance,
Sales, Procurement, Manufacturing) reads master data **only through the contracts in §4** and refers to records by id.

## 1. Rules that hold everywhere

- **Records are never deleted**, only deactivated. History (documents, ledgers) keeps pointing at them.
- **Money and quantities are decimal strings** (bcmath, `MasterData\Support\Decimal`), rounded half away from zero.
  Never float. Prices keep 6 decimals, rates 8, tax percentages 4, quantities 6.
- **Codes, not ids, at the edges.** API payloads, forms and CSV refer to related records by code
  (`category: "GENERAL"`, `base_uom: "KG"`, `currency: "LKR"`); `CodeLookup` turns them into ids and answers an
  unknown code with `422 masterdata.unknown_code` naming the field. `InputMapper` does this for the API, the web forms
  and the importer, so all three behave identically.
- **Locked after creation**: a record's code; an item's type, tracking mode and base unit; a price list's type;
  a price list's currency once it holds prices; the base currency's decimals/active flag. Stock and documents are
  expressed in these, so a mistake is corrected by deactivating and creating a new record.
- **Optimistic concurrency**: items, customers, suppliers and price lists carry `version`; updates must send the
  version they read, a stale one is `409 concurrency.stale_version`.
- **One front door per rule**: web forms, the API, the importer and approvals call the same Actions
  (`SaveItem`, `SaveCustomer`, `SaveSupplier`, `SavePriceList`, `Reference\*`, `ApplyPriceChanges`, …).
- Dates are business dates in Asia/Colombo; stored as `date`. Rates are effective-dated.
- Every change is audited; events go through the outbox (§6).

## 2. Data model

| Table | Notes |
|---|---|
| `units_of_measure` | code, name, `decimals` (0–6, how many decimals quantities in this unit may have) |
| `item_categories` | tree (`parent_id`), cycles refused |
| `items` | code (`ITM-{SEQ}` if not given), type `stock/service/non_stock`, `tracking none/lot`, `shelf_life_days` (lot only), base UoM, default tax code, `is_sellable`, `is_purchasable`, barcode (unique), `version` |
| `item_uoms` | other units of an item: `factor` = base units per unit, `for_purchase`, `for_sale` |
| `currencies` | ISO code, decimals, exactly one `is_base` (LKR) — enforced by a partial unique index |
| `exchange_rates` | per currency per date: LKR per 1 unit of the currency |
| `tax_codes` / `tax_rates` | kind (`vat/sscl/nbt/other`), rate % effective-from/to; no overlaps per code |
| `payment_terms` | code, name, days |
| `customers`, `suppliers` | `CUS-{SEQ}` / `SUP-{SEQ}`, tax id, NIC, currency, payment terms, price list, credit limit / lead time, `version`; child `*_contacts`, `*_addresses` (one primary contact, one default address, normalised on save) |
| `supplier_bank_accounts` | **account number encrypted at rest**, one default per supplier (partial unique index) |
| `price_lists`, `price_list_items` | `PLS-` / `PLP-` codes, type sales/purchase, currency, `tax_inclusive`, validity, one default per type; prices per item + unit + minimum quantity |

CHECK constraints guard signs, ranges and enumerations in the database as well as in PHP.

## 3. Seed data (`ReferenceDataSeeder`, idempotent, never overwrites local edits)

Units (PCS, SET, BOX, PKT, DOZ, KG, G, L, ML, M, CM, SQM, HR), category GENERAL, currencies LKR (base), USD, EUR, GBP,
INR, payment terms CASH, NET7/14/30/45/60, tax codes **VAT 18 % from 2024-01-01** and EXEMPT 0 %, and **NBT and SSCL
as inactive codes with no rate** — see the handover for why (owner decision).
Run by `php artisan db:seed` and by the test suite.

## 4. Contracts for other modules (`Contracts/`, `DTOs/`)

| Contract | Use |
|---|---|
| `ItemCatalog` | `find`, `findByCode`, `requireActive` → `ItemInfo` (id, code, name, type, tracking, base UoM, flags, tax code) |
| `PartyDirectory` | `customer`, `supplier`, `requireActiveCustomer/Supplier` → `PartyInfo` |
| `UomConverter` | `convert(item, qty, from, to)`, `toBase`, `factor`; refuses units not defined for the item (`uom_not_convertible`) and results that need more decimals than the target unit allows |
| `CurrencyConverter` | `baseCode`, `decimals`, `rate(code, on)`, `convert(amount, from, to, on)`; latest rate on or before the date; no rate → `no_exchange_rate` |
| `TaxCalculator` | `rate(code, on)`, `fromNet`, `fromGross` → `TaxResult`; several codes are independent (no compounding); `no_tax_rate` if none applies on the date |
| `PriceResolver` | `quote(item, qty, uom, type, priceListId, partyId, on)` → `PriceQuote`: the list given, else the party's list, else the type's default list (it must be active and within its validity dates); best minimum-quantity tier; derives from the base-unit price via the unit factor when no price exists in the requested unit; returns `null` when there is no price (callers decide) |

Only these (and `Events`) may be used by other modules; the architecture tests enforce it.

## 5. Approval-gated actions

Registered with the Approvals engine (`MasterDataServiceProvider::boot`); with no policy they apply immediately, with a
policy they return `202` + `approval_request_id` and change nothing until approved. The change is re-validated when it
executes (a supplier deactivated while waiting blocks it).

| Action key | Risk | What |
|---|---|---|
| `masterdata.supplier.bank_account.change` | R3 | create / update / deactivate a supplier bank account |
| `masterdata.price_list.prices.change` | R3 | a batch of price changes (up to 1000 lines) |

Approval payloads are encrypted at rest (migration `2026_10_12_100100`), so an account number inside a request is not
readable in the database; audit redaction also covers `account_no`, `iban`, `swift`, `nic`.

## 6. Events (outbox)

`masterdata.item.changed`, `masterdata.customer.changed`, `masterdata.supplier.changed`,
`masterdata.price_list.changed` — `{id, code, created, is_active}`; delivered at least once, per-aggregate ordered.
Nothing consumes them yet.

## 7. API (`/api/v1`, Sanctum + MFA-enrolled, error format per [api-standards.md](api-standards.md))

| Path | Methods | Permission |
|---|---|---|
| `items`, `customers`, `suppliers`, `price-lists` (+ `/{id}`) | GET list/show, POST (idempotent), PATCH | `masterdata.<x>.view` / `.manage` |
| `suppliers/{id}/bank-accounts[/{account}]` | POST, PATCH, DELETE (= deactivate) | `masterdata.supplier.bank.manage` (view full number: `.bank.view`; otherwise masked `••••1234`) |
| `price-lists/{id}/prices` | GET, POST (batch of changes) | view / manage |
| `units-of-measure`, `item-categories`, `currencies`, `tax-codes`, `payment-terms` (+ `/{id-or-code}`) | GET, POST, PATCH | `masterdata.reference.view` / `.manage` |
| `currencies/{code}/rates`, `tax-codes/{id}/rates[/{rate}]` | GET/POST, POST/DELETE | reference view / manage |

Lists: `filter[q|category|item_type|is_active|…]`, `sort`, `per_page`. Notable error codes: `masterdata.unknown_code`,
`code_taken`, `code_locked`, `item_field_locked`, `in_use`, `category_cycle`, `tax_rate_overlap`, `tax_rate_started`,
`base_currency_locked`, `uom_decimals_in_use`, `price_invalid`, `too_many_changes`, `concurrency.stale_version`.

## 8. Screens and import

Web: Items, Customers, Suppliers (with bank accounts), Price lists (prices set by pasting `item,price[,unit[,min qty]]`
lines), Reference data (five kinds on one screen, with exchange-rate and tax-rate history), Import. English / Sinhala /
Tamil (`masterdata::ui`).

**CSV import** (`/masterdata/import`, `php artisan masterdata:import {items|customers|suppliers} file.csv [--check]`):
header row of column names (template downloadable), UTF-8 (BOM tolerated), ≤ 2 MB / 5000 rows. Rows are matched by
`code` (known → update, blank/new → create); blank cells keep the current value. **All or nothing**: any row problem →
nothing saved and every problem listed with its line and column. *Check* runs the whole import inside a transaction and
rolls back, so its report is what a real run does. Needs `masterdata.import` plus the manage permission for the kind.
Opening balances and price lists are not imported (balances belong to S4/S5; prices use the price screen/API).

## 9. Permissions

`masterdata.reference.{view,manage}`, `masterdata.item.{view,manage}`, `masterdata.customer.{view,manage}`,
`masterdata.supplier.{view,manage}`, `masterdata.supplier.bank.{view,manage}`, `masterdata.price_list.{view,manage}`,
`masterdata.import`. (Role defaults are an owner decision; the seeder gives them to `admin` via the module registry.)
