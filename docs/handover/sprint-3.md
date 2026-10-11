# Sprint 3 — Master Data: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 4 (Inventory) has not been started.
Per your instruction ("use defaults, ask only the decisions that need you") every open question below was given a
working default; only §4 needs an answer from you.

## 1. What was built

New module **Master Data** (depends on Core and Approvals). Full description: [../architecture/masterdata.md](../architecture/masterdata.md).

- **Reference data**: units of measure (with allowed decimals), item categories (tree), currencies with
  effective-dated exchange rates (LKR is the locked base currency), tax codes with effective-dated rates
  (VAT, SSCL, NBT kinds), payment terms. Seeded: 13 units, 5 currencies, 6 payment terms, category GENERAL,
  **VAT 18 % from 2024-01-01**, EXEMPT 0 %.
- **Items** (stock / service / non-stock, lot tracking, shelf life, barcode, default tax, other units with
  conversion factors), **customers** and **suppliers** (contacts, addresses, tax ids, NIC, credit limit / lead time,
  payment terms, price list), **supplier bank accounts**, **price lists** (sales / purchase, per unit and
  minimum-quantity tiers, validity dates, one default per type).
- **Contracts for later modules**: `ItemCatalog`, `PartyDirectory`, `UomConverter`, `CurrencyConverter`,
  `TaxCalculator`, `PriceResolver` (+ DTOs). Inventory, Sales and Procurement will use only these.
- **Approval-gated** (R3): supplier bank-account changes and price-list price changes. With no policy they apply
  immediately; with a policy they wait for approval and are re-validated when executed.
- **Screens** (English / Sinhala / Tamil) for all of the above, **REST API** under `/api/v1`, and **CSV import**
  (web page and `php artisan masterdata:import`) with a check-only mode and all-or-nothing saving.
- **Core/Approvals changes made on the way**: approval payloads are now **encrypted at rest** (a request carrying a
  bank account number must not sit readable in the database; migration is forward-only and re-encrypts existing rows);
  audit redaction extended (`account_no`, `iban`, `swift`, `nic`); six new menu icons in Core.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **495 passed** (2,422 assertions), 149 s — one full run after the last code change (a second identical run was not repeated this sprint) |
| Master Data tests | 196 feature tests (contracts 24, items + parties, bank accounts 19, price lists 22, reference 22, web 27, import 22) plus 22 unit tests for `Decimal` — all passing |
| `composer audit` / `npm audit --omit=dev` | no advisories / 0 vulnerabilities |
| Dev database | already migrated, reference data and role permissions seeded; `public/build` rebuilt |

What the tests prove beyond "it runs":

- **Rules enforced by the backend, not the screen**: the same rule set is exercised through the API, the web forms,
  the importer and the approval executor (locked fields, stale versions, unknown codes, inactive references, in-use
  guards, category cycles, tax-rate overlaps, base-currency protection, unit decimals vs. existing items).
- **Money is never float**: `Decimal` (bcmath, half away from zero) has its own table-driven tests including carry
  and sign cases; tax, currency and price results are asserted as exact strings.
- **Secrets**: a supplier account number is not in the table, not in the audit log, not in the approval payload at
  rest, and is masked (`••••4567`) for anyone without `masterdata.supplier.bank.view`; the supplier profile endpoint
  cannot change bank accounts.
- **Approval flow end to end**: a bank change becomes `202` + request, changes nothing, is applied only when an
  approver approves, is refused if the supplier was deactivated meanwhile, and the requester cannot approve it.
- **Import**: nothing is saved when any row is bad (all problems listed with line and column), *check* is exactly a
  rolled-back run, repeated codes, locked-field changes, BOM, quoted commas, Sinhala/Tamil text, oversize and wrong
  file types, and per-kind permissions are covered.
- **Boundaries**: the architecture tests confirm other modules can only reach Master Data through `Contracts`,
  `DTOs` and `Events`; the execution-token allow-list now includes the two new approvable actions.

Defects found by the tests and fixed this sprint: the PATCH for reference data demanded every field (now partial);
route-default parameters were bound in the wrong order in the generic reference controller; blank "other unit" rows
in the item form failed validation; suppliers lacked the `priceList` relation the shared screen needs; the
importer processed 5,000 rows before refusing an oversized file (now refused up front).

## 3. NOT verified

- **No visual browser check.** Screens are exercised through feature tests (they render in all three languages with
  no raw translation keys, and forms post and redirect correctly), but layout, responsiveness and the Alpine-driven
  repeaters (other units, contacts, addresses) have not been looked at in a browser. Please click through them.
- **Sinhala and Tamil strings are drafts** needing native-speaker review (key parity with English is tested).
- **The GitHub Actions workflow has still never run** (nothing is pushed; push needs your GitHub sign-in).
- **No load test**: lists are paginated and filtered in SQL, but nothing was measured with tens of thousands of items.
  Search uses `lower(col) LIKE '%…%'` (no trigram index yet).
- **Nothing consumes the four `masterdata.*.changed` events yet.**
- **Import of 5,000 rows** was not timed; the row cap was tested with a stub, not a real maximum file.
- **No FrontAccounting export was available**, so the importer is tested with synthetic files only (migration is S5b).
- Antivirus, Horizon, phpredis, S3-storage: unchanged from the Sprint 2 handover (still open).

## 4. Decisions for you / deviations from the written plan

Needs an answer:

1. **NBT and SSCL are present but inactive with no rate.** I could not verify current rates or applicability
   from here, and a wrong rate on an invoice is worse than none. Please tell me: which of VAT / SSCL / NBT apply to
   your customers (and on what base — e.g. SSCL on turnover rather than per invoice), the rates and start dates, and
   whether VAT is still 18 %. An accountant can enter them on *Reference data → Tax codes* today; no code change.
2. **Code formats** are my defaults: `ITM-00001`, `CUS-00001`, `SUP-00001`, `PLS-/PLP-` price lists; a code may also
   be typed (letters, digits, `. _ - /`) and is fixed afterwards. If you have established formats (or want the
   category in the item code), say so before real data is loaded — changing the generator later is easy, changing
   issued codes is not.
3. **Mandatory fields**: only the name is mandatory for customers/suppliers (tax id, NIC, phone and so on are
   optional); items need name, category and base unit. Say which must be mandatory for you (e.g. VAT number for
   VAT-registered customers).
4. **Prices are tax-exclusive by default**; each price list can be marked tax-inclusive (stored and shown, applied
   by Sales in S6).

Defaults I chose — change any you disagree with:

5. Master records are **never deleted**, only deactivated; the base unit / type / tracking of an item and a record's
   code are fixed after creation.
6. Both approval-gated actions are **R3**, apply at once when no policy exists (so nothing is blocked until you
   create the policies in *Approval policies*). Typical policy: role `finance-approver` for bank-account changes.
7. Role defaults: `admin` gets every Master Data permission; other roles get none until you decide who may manage
   what (a real permission-to-role matrix is a Sprint 4 task alongside warehouse roles).
8. CSV: all-or-nothing, ≤ 2 MB / 5,000 rows, matched by code, blank cell = keep, no opening balances, no price lists.
9. Exchange rates are entered manually (LKR per 1 unit of foreign currency, latest rate on or before the date).
   No rate feed is configured.
10. No item variants (colour/size) — the data model leaves room, post-MVP as planned.

Deviations from the plan: the plan said "web page + command" for import — both exist; the supplier/customer form
edits the first-class fields, contacts and addresses but **bank accounts are managed separately** on the supplier
page by design (they are approval-gated); price changes on the web are entered as pasted lines (`item,price[,unit[,min qty]]`)
rather than an editable grid.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Tax rates for SSCL / NBT unconfirmed | wrong tax on documents once S6/S7 use them | answer decision 1 before Sales/Procurement |
| Encrypted approval payloads: changing `APP_KEY` makes old requests unreadable | pending approvals fail with "payload changed" | key rotation procedure needed before go-live (S15); keep `APP_KEY` in the deployment secrets, never regenerate |
| Account numbers are encrypted with the app key | same key dependency; backups need the key | include the key in the backup/restore runbook |
| Master data is editable by `admin` only for now | none until roles are designed | decision 7 |
| Customer/supplier duplicates (same tax id typed twice) are allowed | duplicate parties | consider a warning on matching tax id / name in S4 housekeeping; not blocked because related companies share ids in practice |
| Substring search is a sequential scan | slow past ~100k rows | add trigram indexes when volumes are known |
| Price import is line-based text | typos in item codes are caught (whole batch refused), but a wrong price on a valid code is not | the approval policy on price changes is the control |
| Outbox events unused | events accumulate in `outbox_events` until a consumer exists | relay marks them processed; nothing else needed yet |

## 6. Decisions requested before Sprint 4 (Inventory)

- Answers to §4 items 1–3 (item 1 can wait until S6, items 2–3 should not).
- Warehouses: how many sites / warehouses now, whether bin locations are needed in the MVP, and whether negative
  stock must ever be allowed (the plan says no).
- Valuation method (FIFO vs. moving average — ADR 0000 lists it as open) and whether lot tracking is wanted from day one.
- Re-confirm from Sprint 2: Horizon now or with the Docker image; ClamAV container acceptable; **push the
  repository** (needs your GitHub sign-in) so CI can run.

## 7. How to try it

Start PostgreSQL and Redis ([../development/setup.md](../development/setup.md) §6–7), run `.\scripts\dev\run.ps1`,
sign in as the demo admin, then use the sidebar: **Reference data** (check units, currencies, add tax rates),
**Items**, **Customers**, **Suppliers** (add a bank account), **Price lists** (create a list, paste prices),
**Import** (download a template, check a file, import it). The API is under `/api/v1/items`, `/customers`,
`/suppliers`, `/price-lists`, `/currencies`, `/tax-codes`, … with the same login/token flow as Sprint 1.
