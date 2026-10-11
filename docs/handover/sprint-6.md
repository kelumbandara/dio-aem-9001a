# Sprint 6 — Sales: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 7 (Procurement) has not been started.
Your answers are built in: flow **quotation → sales order → delivery → invoice**, **credit limit blocks**, **SSCL 2.5 % on the liable turnover**, invoice number and layout designed by me, and an **accountant** role. Details: [../architecture/sales.md](../architecture/sales.md).

## 1. What was built

New module **Sales** (depends on Core, Approvals, Master Data, Inventory, Finance — only through their contracts).

- **Quotations**: draft → sent → accepted / rejected / cancelled, validity date (expired shows as such and cannot be accepted), prices from the customer's price list when left empty, converted into a sales order at the quoted prices.
- **Sales orders**: lines with unit, price, discount and tax code; **confirming** checks the customer's **credit limit** (a hard block — over the limit the order stays a draft; only someone with the override permission, and only with a written reason, can confirm; confirmations per customer are serialised so two orders cannot both squeeze under the limit) and **reserves the stock that is available**. Cancel (with reason) while nothing is delivered or invoiced. Completes itself when delivered and invoiced.
- **Deliveries**: partial or full, from the order's warehouse, as a stock issue (reason *sale*) — which is what posts **cost of sales** automatically. Never negative stock (an over-delivery fails as a whole). A delivery not yet invoiced can be reversed.
- **Tax invoices**: from the delivered-and-not-invoiced quantities (services by ordered quantity); partial/repeated invoicing; **VAT per line**, **SSCL/NBT on the liable turnover** (net of the non-exempt lines, each active levy with a rate in force, independent of VAT); revenue, VAT and levies posted to Finance with the customer's receivable in the same transaction. Number format **`INV/2026-27/00001`** (gapless per financial year). Posted invoices cannot be changed — the database refuses it.
- **Printable A4 tax invoice** (print / save as PDF; English, Sinhala or Tamil labels): company block with VAT/TIN, bill-to with the customer's TIN and address, line table, tax summary with each levy's rate and the turnover it was charged on, total due, bank details to pay into, signature lines.
- **Credit notes / returns**: by quantity per invoice line, with VAT and levies given back at the invoice's own rates; can never exceed the invoice (line by line and tax by tax) and ends exactly at it; optionally returns goods to stock; reduces the customer's open invoice.
- **API** under `/api/v1` (quotations, orders and their actions, deliveries, invoices, credit notes) and **screens** in English / Sinhala / Tamil; sidebar entries for the five lists.
- **Changes outside Sales**: Finance gained `CreditNotePoster`, `ReceivablePosition` (what a customer owes; bank accounts flagged *show on invoices*) and the credit-note settlement; Master Data gained `TaxCatalog` (tax kind, active levies) and `PartyDirectory::billingAddress`; Inventory gained `WarehouseDirectory`; Core gained `CompanyInfo::profile()` and an **Administration → Company profile** screen (`core.company.manage`); a new **`accountant` role** (all Finance permissions, read-only master data and stock, sees all sales documents and posts invoices/credit notes; authenticator required, like admins); `App\Support\Decimal::trim`.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **781 passed** (6,430 assertions), 518 s. A first full run showed 5 failures (older module-switch tests did not know about Sales); fixed and the whole suite re-run clean |
| Sales tests | 48: flow 18, screens and API 12, random scenarios 16 (613 assertions), plus 2 real-process concurrency tests; Finance credit-note tests 5 |
| Dev database | migrated (2 new migrations), roles re-seeded, demo data loaded, `finance:reconcile` and `inventory:reconcile` clean |

What the tests prove beyond "it runs":

- **The numbers add up**: 16 random orders (1–4 lines, random prices to 4 places, discounts, VAT and exempt items, VAT + SSCL + NBT) delivered, invoiced and credited in random slices always satisfy: slices of invoices = the order line to the cent; net + VAT + levy = gross; invoice lines and tax rows = header; credit notes never pass the invoice and total **exactly** the invoice per tax; the receivable ends at 0; the books and stock reconcile. A hand mutation (turning off the "last credit note takes the remainder" rule) is caught by this test.
- **Credit limit under pressure**: 5 real parallel processes trying 30 one-line orders (LKR 118 each) against a LKR 1,000 limit → exactly 8 confirmed, 22 refused; without the per-customer lock the same test confirms 9 (checked by hand).
- **Last units**: 6 parallel processes delivering 24 one-unit orders against 10 units on hand → exactly 10 deliveries, stock 0, nothing negative, stock and books reconcile.
- **Immutability**: UPDATE/DELETE on a posted invoice, its lines and its tax rows is refused by the database itself.
- **Tax rules**: VAT 18 % on 1,000 + SSCL 2.5 % on the same 1,000 = 1,205; an exempt line is outside the levy base; a levy is charged only while it is active **and** has a rate; changing a rate later does not change old invoices.
- **Money trail**: after delivery + invoice, cost of sales = quantity × average cost (600 on 10 units at 60), receivables = invoice gross, revenue/VAT/SSCL accounts carry the right amounts; a full credit note nets every account back to zero.
- **Access**: 403 without the right permission, stale-version 409, validation 422 with field details, idempotency-key replay creates one document, screens render in all three languages without raw translation keys, and the Sinhala/Tamil files have exactly the English keys.

Defects found by the tests during the sprint and fixed: quantities were written as `10.000000` and refused by units with no decimals; quote→order carried the same problem; list rows lost null dates; the test payload helper let the defaults override the test's own values.

## 3. NOT verified

- **No visual check in a browser by me** of the new screens or of the printed invoice (they are exercised through feature tests in all three languages). Please open an invoice and press *Print / PDF*, and try *New sales order* (the line editor is Alpine/HTML I have not seen rendered).
- **Sinhala and Tamil strings are drafts** needing native review (key parity with English is tested).
- **The tax treatment has not been reviewed by an accountant**: VAT per line, SSCL/NBT on the net turnover of non-exempt lines (I read "liable turnover" as net value after discount, before VAT), levies independent of VAT. Tell me if your accountant reads SSCL differently (e.g. including VAT, or only on part of the sales).
- **Invoice layout is my design** — I have not seen your current printed invoice; there is no logo yet (the company profile has a `logo_path` field but no upload).
- **Price list "approval for big discounts"** from the plan is not built (a discount is accepted as typed); no leads/CRM, no customer statements, no e-invoicing, LKR only.
- **Returns re-enter stock at today's average cost**, not the cost the unit left at (documented simplification); lot-tracked items cannot be restocked from a credit note (receive them in Inventory with the lot).
- **Credit limit** counts invoices and open orders; it does not look at cheques in transit or overdue age, and a customer with **no limit set is unlimited** (a limit of 0 blocks every order).
- **Delivery/invoice dates in the past** are accepted if the accounting period is open; there is no "invoice must not be older than N days" rule.
- **No load test**; GitHub Actions has still not been seen running by me.

## 4. Decisions for you / deviations from the written plan

1. **SSCL base** (above): net value of non-exempt lines. Confirm with your accountant.
2. **Items without a tax code are zero-rated *and* subject to SSCL.** In the demo data "Yoghurt cup" and "Sewing thread" have no tax code, so no VAT is charged on them. Set the right default tax code (VAT or EXEMPT) on every sellable item before real invoicing; I did not guess for you.
3. **Which customers/items are VAT-exempt?** (still unanswered) — exempt items need the *EXEMPT* tax code.
4. **Roles**: `admin` holds every Sales permission including the credit-limit override; `accountant` can view everything and post invoices and credit notes. There is **no sales-staff role yet**: tell me who should create quotations/orders, confirm orders, deliver goods and who may override credit, and I will add the roles.
5. **Invoice number** `INV/2026-27/00001` and the other series (`QUO/`, `SO/`, `DN/`, `CN/`): change the format only before the first real document.
6. **Delivery before invoice**: cost of sales posts at delivery, revenue at invoice (as I understood "quotation → order → delivery → invoice"). Services are invoiced without delivery.
7. Deviations from the plan: leads/activities and discount approval are deferred; a credit note cannot itself be reversed; "returns" are credit notes with an optional restock rather than a separate return document.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| SSCL/NBT/VAT treatment not reviewed by an accountant | wrong tax on real invoices | accountant to test with a few real invoices on the dev data first; the rates and activation are on the Tax codes screen |
| Items/customers without a tax code | VAT silently not charged | set default tax codes on all items; consider making the tax code mandatory (Master Data setting) |
| Company profile still the placeholder | invoices print "My Company (Pvt) Ltd" and no TIN | fill *Administration → Company profile* and tick *show on invoices* on the bank accounts |
| Returns restocked at average cost | small cost-of-sales error if cost changed since the sale | acceptable for now; per-sale cost tracking (FIFO layers) is post-MVP |
| Cost of sales depends on the outbox relay | a stopped scheduler delays the cost journal (not the sale) | as for Sprint 5: alert on parked/old outbox events (S15) |
| One credit-limit lock per customer | none expected; very hot customers serialise confirmations | by design |
| Encrypted bank-account numbers print on invoices | the printed number is the company's own (not secret), but depends on APP_KEY | key management before go-live (S15) |

## 6. Decisions requested before Sprint 7 (Procurement)

- Answers to §4 (items 1–4 matter most) and the open tax question: which items/customers are exempt.
- **Procurement flow**: requisition → PO → goods receipt → supplier invoice → return, or simpler (PO → receipt → bill)? Approval limits for POs (amounts and who approves)?
- **Three-way match**: must a supplier bill match the PO and receipt exactly, or within a tolerance (e.g. 2 %)? Price variance goes to account 2217.
- **Supplier VAT/withholding**: input VAT recoverable on every purchase? Any withholding tax on supplier payments?
- Still open from earlier sprints: Horizon now or with the Docker image; ClamAV container acceptable; whether the GitHub Actions run was green; confirmation of the Sprint 5 chart corrections and posting rules (you said "confirm" to the chart corrections — noted).

## 7. How to try it

The local app is at <http://127.0.0.1:8000> (start with `.\scripts\dev\run.ps1`; the demo admin holds every Sales permission). In the sidebar: **Quotations** (draft and sent examples), **Sales orders** (a completed one, one half-delivered, and a draft for *Small Shop* whose credit limit is LKR 1,000 — press *Confirm order* to see the block), **Deliveries**, **Invoices** (open `INV/2026-27/00001`: VAT 765.00 + SSCL 136.25 on net 5,450.00 = 6,351.25; press *Print / PDF*), **Credit notes** (issue one from the invoice screen). **Administration → Company profile** sets the name, address and VAT/TIN printed on invoices. The demo SSCL rate (2.5 % from 2024-01-01) was entered on the Tax codes screen's data, NBT is left inactive. Commands: `php artisan finance:reconcile`, `php artisan inventory:reconcile`.
