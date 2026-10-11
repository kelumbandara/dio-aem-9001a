# Sales module

Quotations, sales orders, deliveries, tax invoices, credit notes and returns. Depends on **Core, Approvals, Master Data,
Inventory and Finance**, and only through their Contracts/DTOs (an architecture test enforces this; only Sales code may use
Sales models). Leads and activities (CRM) are **not** in this sprint.

Flow, as you asked: **quotation → sales order → delivery → invoice** (and credit notes against an invoice).

```
Quotation ──accepted──▶ Sales order ──confirm──▶ Delivery (stock out) ──▶ Invoice (VAT + SSCL/NBT) ──▶ Credit note
 draft→sent→accepted     draft→confirmed          sales_delivery issue        posted to Finance           returns / corrections
                          (credit check,           (cost of sales posts       (receivable open item)
                           stock reserved)          automatically)
```

## 1. Rules that hold everywhere

- **One door per document**: `QuotationService`, `OrderService`, `DeliveryService`, `InvoiceService`, `SalesCreditNoteService`. The API and the
  screens call the same services; nothing else writes Sales tables.
- **Stock and ledgers only through their owners**: a delivery is a stock *issue* (`StockPoster`, reason `sale`); an invoice is posted by
  Finance (`OpenItemPoster`); a credit note by Finance (`CreditNotePoster`) and, when goods come back, a stock *receipt* (reason `return`).
  Each runs in the **same database transaction** as the Sales document: all of it or none.
- **Posted documents are permanent**: invoices, credit notes and delivery lines reject UPDATE/DELETE in the database (trigger); deliveries
  can only change `status`. A mistake is corrected by a credit note or a reversal, never by editing.
- **Money is decimal**, LKR only, 2 places on documents (stored with 4), half away from zero. Quantities up to 6 places, checked against the
  unit's own decimals (pieces cannot be 2.5).
- Numbers are gapless per fiscal year (Core numbering): `QUO/2026-27/00001`, `SO/…`, `DN/…`, **`INV/2026-27/00001`**, `CN/…`.
  A rolled-back document does not use up a number.
- Every action is audited and announces an event through the outbox.

## 2. Tax: VAT, SSCL, NBT

- **VAT** is charged **per line** at the rate of the line's VAT code on the invoice date. The code comes from the item (default tax code)
  unless the line names another. A line may use only a VAT or an *exempt* code.
- **SSCL and NBT (levies)** are charged on the **liable turnover**: the net value (after discount, before VAT) of all lines that are not exempt.
  Every levy code that is **active and has a rate in force that day** is applied, each on that base on its own — **not on top of VAT and not on
  each other**. This is your rule ("the levy rate of 2.5% is applied to the liable turnover").
  Example: net 1,000 → VAT 180 (18 %) + SSCL 25 (2.5 %) = 1,205.
- SSCL/NBT are created **inactive with no rate**; the accountant switches them on and enters the rate in Master data → Tax codes (no code change).
  NBT (abolished) is simply left inactive. A levy with no rate for the date is not charged.
- Lines with an **exempt** tax code carry no VAT and are outside the levy base. An item with **no** tax code gets the default VAT code (above).
- **Prices include VAT** (owner's rule, `config/sales.php` → `prices_include_vat`, default on). The price typed on a line is what the customer pays for
  one unit; the line total (qty × price − discount, 2 places) is split into net = total ÷ (1 + rate) and VAT = the rest, so the customer pays exactly the
  typed total plus any SSCL/NBT. SSCL is then charged on the **net** values. The setting is copied onto every quotation, order and invoice when it is
  created, so changing it later never reinterprets old documents (documents made before 2026-10-12 are net-priced). A price list's own tax-inclusive flag is
  converted to the document's basis. Exempt lines have no VAT in the price.
- **Every item is sold with VAT unless it is set to EXEMPT**: a line whose item has no tax code gets the code named in `sales.default_vat_code` (`VAT`).
- Each invoice stores, per tax code, the rate used, the base and the amount (`sales_invoice_taxes`), so old invoices never change when rates
  change, and credit notes use the **invoice's** rates.
- Postings: credit `sales_revenue` (net), `vat_output`, `sscl_payable`, `nbt_payable`; debit receivables control (added by Finance, tagged with the customer).

## 3. Quotations

Draft (editable) → sent → accepted | rejected; a draft, sent or accepted quotation can be cancelled. Valid for 30 days unless set; an expired
quotation cannot be accepted (shown as *Expired*). An accepted quotation becomes a draft sales order with **the quoted prices** and is marked *converted*.
Nothing is reserved or posted by a quotation. Edits use a version check (a second person changing it gets `concurrency.stale_version`).

## 4. Sales orders, credit limit, reservation

- Lines: item, quantity, unit, price (empty → the customer's price list, via Master data), discount %, tax code. A warehouse is chosen per order.
  Payment terms default from the customer.
- **Confirm** does two things in one transaction:
  1. **Credit-limit check — a hard block.** Exposure = what the customer already owes (open invoices less unallocated receipts, from Finance)
     + the uninvoiced value of the customer's other confirmed orders + this order. Over the customer's credit limit → `sales.credit_limit_exceeded`
     (HTTP 422) and the order stays a draft. No limit set on the customer = no check. Only someone with `sales.credit.override` may confirm anyway,
     and only **with a reason**, which is stored on the order and audited. Confirmations for one customer are serialised (advisory lock), so two
     orders racing for the last of a limit cannot both pass (tested with real parallel processes).
  2. **Reserve available stock** for the order's stock lines (never fails for a shortage: it reserves what exists; the rest simply is not promised).
- An order is cancelled with a reason (reservations are released) only while nothing is delivered or invoiced. It becomes *Completed* by itself when
  every stock line is delivered and everything is invoiced.

## 5. Deliveries

A delivery takes any part of the open quantities of the order's **stock** lines out of the order's warehouse as a stock issue. Stock can never go
negative, so delivering more than is on hand fails as a whole (`inventory.insufficient_stock`) and leaves nothing behind. Lots are picked
first-expiry-first-out by Inventory. The issue is what posts **cost of sales** automatically (Finance, from the stock event). After a delivery
the order's reservations are rebalanced to what is still to deliver. A delivery that has **not been invoiced** can be reversed (`sales.reverse`):
the stock comes back at the cost it left at, its cost-of-sales journal is reversed, and the order reopens. Services (non-stock items) are never delivered.

## 6. Invoices

- Created from a confirmed order for the **delivered-and-not-yet-invoiced** quantity of stock lines and the **ordered** quantity of services; partial
  and repeated invoicing is allowed. Dates: invoice date (today by default), due date = invoice date + payment terms unless given.
- The invoice that completes a line takes the exact remainder of that line's value, so split invoices always add up to the order.
- Posted to Finance in the same transaction: journal + customer open item (idempotent by invoice id).
- **Layout** (A4, print / save as PDF, English/Sinhala/Tamil labels): company name, address, VAT/TIN and registration from **Company profile**;
  "Tax invoice", invoice no., date, due date, order no., customer PO; bill-to with the customer's VAT/TIN and billing address; payment terms;
  line table (description, qty, unit price, discount, VAT, amount); net value, each tax with its rate and — for levies — the liable turnover it
  was charged on; total due in LKR; "please pay to" with every bank account flagged *show on invoices*; prepared-by / received-by signature lines.
  Set the company's details at **Administration → Company profile** (permission `core.company.manage`) and tick *show on invoices* on the bank accounts to print.

## 7. Credit notes and returns

Against one invoice, by quantity per invoice line (price corrections: credit the line and re-invoice). Reason is required. VAT and levies are
given back at **the invoice's rates**; the sum of all credit notes can never exceed the invoice, line by line and tax by tax, and the note that
completes the invoice gives back the exact remainder (tested with random splits: credits end at the invoice to the cent). Finance posts the
reversal of revenue/VAT/levies against receivables and a non-cash *credit note* settlement reduces the invoice's open amount (anything beyond
the open amount stays as credit on account). Optionally **return goods to stock**: a stock receipt (reason `return`) at *today's average cost*
(the cost a unit left at is not tracked per sale — a known simplification), which also reverses the matching cost of sales; lot-tracked items
must be received in Inventory with their lot number instead.

## 8. Permissions and roles

`sales.view`, `sales.quotation.manage`, `sales.order.manage`, `sales.order.confirm`, `sales.credit.override`, `sales.delivery.post`,
`sales.reverse`, `sales.invoice.post`, `sales.credit_note.post`. **Admin** has all.
**Sales staff** (`sales` role, separate from admin and accountant): view, create/edit quotations and orders, confirm orders, post deliveries, and read customers, items, prices and stock — no invoicing, no delivery reversal, no credit override. **Accountant** (new role from Sprint 5, plus MFA required) can
see all sales documents and issue invoices and credit notes, in addition to Finance. Roles are seeded (`RolesAndPermissionsSeeder`); there is no roles screen yet.

## 9. API (all under `/api/v1`, idempotent POSTs need an `Idempotency-Key`)

Customers, items, units, warehouses and tax codes are referred to by **code**; document lines by their **line number**.

| Method and path | Purpose |
|---|---|
| `GET/POST /quotations`, `GET/PUT /quotations/{id}` | list (filter status, q), create, read, replace a draft (`version`) |
| `POST /quotations/{id}/{sent\|accepted\|rejected\|cancelled}` | status changes |
| `POST /quotations/{id}/convert` | accepted quotation → draft order (`warehouse`) |
| `GET/POST /orders`, `GET/PUT /orders/{id}` | list, create, read, replace a draft |
| `POST /orders/{id}/confirm` (`override_reason?`) | credit check + reservation |
| `POST /orders/{id}/cancel` (`reason`) | cancel |
| `POST /orders/{id}/deliveries` (`lines?: [{line, qty}]`, `delivery_date?`) | deliver (all open stock lines if no lines) |
| `POST /orders/{id}/invoices` (`lines?`, `invoice_date?`, `due_date?`, `notes?`) | invoice (everything billable if no lines) |
| `GET /deliveries`, `GET /deliveries/{id}`, `POST /deliveries/{id}/reverse` | |
| `GET /invoices`, `GET /invoices/{id}` (with company, bill-to, banks), `POST /invoices/{id}/credit-notes` (`reason`, `lines?`, `restock?`, `warehouse?`) | |
| `GET /credit-notes`, `GET /credit-notes/{id}` | |

Business errors keep the standard format; new codes: `sales.credit_limit_exceeded`, `sales.quotation_expired`, `sales.invalid_state`,
`sales.qty_exceeds_open|billable|invoice`, `sales.nothing_to_deliver|invoice|credit`, `sales.delivery_invoiced`, `sales.order_in_progress`,
`sales.price_missing`, `sales.item_not_sellable`, `sales.tax_code_invalid`, `sales.restock_lot_item`, plus the Inventory/Finance/Master Data codes.

## 10. Events (outbox)

`sales.order.confirmed`, `sales.delivery.posted`, `sales.invoice.posted`, `sales.credit_note.posted` — payloads carry ids, document number,
customer id and totals. (Stock and ledger effects are not driven by these events: they happen synchronously in the same transaction, so the
documents can never disagree with the books.)

## 11. Known limits (by design for now)

- No leads/CRM activities, price-list *approvals* on discount (a discount is accepted as typed; an approval policy for large discounts can be added with the Approvals engine), customer statements/dunning, e-invoicing, multi-currency sales (LKR price lists only).
- Credit-limit check covers invoices and open orders, not cheques in transit; overdue-based blocking is not included.
- Returns re-enter stock at today's average cost; invoice-less returns and price-only credit notes are done by crediting the line.
- A posted credit note cannot be reversed (issue a new invoice instead).
