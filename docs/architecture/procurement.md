# Procurement module

Purchase orders, goods receipts, supplier bills (with three-way matching), purchase returns and supplier debit notes.
Depends on **Core, Approvals, Master Data, Inventory and Finance**, and only through their Contracts/DTOs (an architecture test
enforces this; only Procurement code may use Procurement models). **Purchase requisitions** are not in this sprint (see §10).

```
Purchase order ──submit──▶ (approval, if a rule exists) ──▶ Goods receipt (stock in) ──▶ Supplier bill ──▶ pay (Finance)
 draft → pending_approval        approved                    stock at the order's cost     matched to order + receipt
        → approved → completed                               "goods received, not invoiced"  price difference → variance account
                                          Purchase return ──▶ stock out (+ debit note when the goods were billed)
```

## 1. Rules that hold everywhere

- **One door per document**: `PurchaseOrderService`, `GoodsReceiptService`, `SupplierBillService`, `PurchaseReturnService`. API and screens call the same services.
- **Stock and ledgers only through their owners**: a receipt is a stock *receipt* (`StockPoster`, reason `purchase`, valued at the order's net price per unit); a return is a stock *issue* (reason `supplier_return`); a bill is posted by Finance (`OpenItemPoster`, payable side); a debit note by Finance (`DebitNotePoster`). Each runs in the **same database transaction** as the Procurement document.
- **Posted documents are permanent**: supplier bills, returns and receipt lines reject UPDATE/DELETE in the database (trigger); receipts can only change `status`. A mistake is corrected by a return, a debit note or a reversal.
- Money is decimal, LKR, 2 places on documents (4 stored); quantities up to 6 places, checked against the unit's own decimals.
- Numbers are gapless per fiscal year: `PO/2026-27/00001`, `GRN/…`, `BILL/…` (our own), `PRN/…` (returns and their debit notes). The supplier's own invoice number is kept on the bill and is the payable's document number (unique per supplier).
- Every action is audited and announces an event through the outbox.

## 2. Purchase orders and approval

- Lines: item (must be purchasable), quantity, unit, **price including VAT** (empty → the supplier's purchase price list, converted to the order's basis), discount %, tax code. **Prices include VAT** (owner's rule, `config/procurement.php` → `prices_include_vat`, default on, copied onto each order and bill so old documents never change meaning): the typed line total is split into net = total ÷ (1 + rate) and **input VAT** = the rest, per line, exactly as for sales; an item without a code is bought with VAT (`procurement.default_vat_code`, `VAT`); EXEMPT items carry none. Delivery warehouse and payment terms (default: the supplier's) are on the order.
- **Submit** goes through the approval gate `procurement.po.approve` with the order's gross total as the amount. With **no approval policy** the order is approved at once; with one it becomes *Waiting for approval* (read-only), and is approved when the approvers approve. A waiting order can be withdrawn back to a draft (for example after a rejection). **Starting rules** (seeded by `PurchaseOrderPolicySeeder`, editable on the Approvals policy screens; it only creates a rule that is missing): **every order needs one approval by role `purchase-approver`**; an order of **LKR 100,000 or more** needs that approval **and then one by `finance-approver`** (different people). The requester cannot approve their own order. The LKR 100,000 threshold was confirmed by the owner on 2026-10-13; change amounts and roles on the Approvals screen. Submitting fails with `approvals.no_approver` while nobody holds an approver role.
- Only an **approved** order can receive goods or be billed. A draft, waiting or approved order is cancelled with a reason while nothing is received or billed. It becomes *Completed* by itself when everything is received (net of returns) and billed (net of debit notes).
- Printable A4 purchase order for sending to the supplier (company header, supplier, lines, VAT, total, prepared/authorised signature lines).

## 3. Goods receipts

Stock items only (services are just billed). Quantity per line up to what is still open: ordered − received + returned (a replacement for returned goods is welcome). Lot-tracked items need a lot number (and may carry an expiry date). The stock is valued at the order's net price per unit, so Finance books it as **Dr Inventory / Cr Goods received not invoiced (GRNI)** straight away. Over-receipt is refused. A receipt that has **not been billed or returned** can be reversed (`procurement.reverse`): the stock leaves at exactly the value it came in at.

## 4. Supplier bills — the three-way match

A bill is entered against an approved order with the supplier's invoice number, date and due date (default: bill date + terms).

1. **Quantity**: no more than has been *received and not yet billed* (net of returns); services up to the ordered quantity. The check happens under the order's lock, so two bills racing for the same goods cannot both pass (tested with real parallel processes).
2. **Price**: each line's price (as the supplier quotes it, VAT included) defaults to the order's. A price within **`procurement.price_tolerance_pct`** (default **2 %**, either way) is accepted silently; further away the bill is refused with `procurement.price_variance_exceeded` unless the person holds `procurement.bill.override` **and** gives a reason, which is stored on the bill and audited.
3. **Posting** (one journal through Finance): Dr **GRNI** for the goods received, Dr/Cr **Purchase price variance** (2217) for the difference between the bill and the goods-received value, Dr **Purchases of services/non-stock items** (mapping `purchase_expense`, default 2412) for services, Dr **VAT receivable** (input VAT) per line, Cr **Payables control** for the total — tagged with the supplier, creating the open item the supplier will be paid against (Finance's payment screens).
4. The bill that clears the last received quantity of a line takes exactly what is left in GRNI for it, so the account does not keep rounding dust (see §9).

## 5. Returns and debit notes

A purchase return sends received goods back: the stock leaves the order's warehouse (reason *return to supplier*; Finance books **Dr GRNI / Cr Inventory** at average cost) and the quantity can be received again as a replacement. If the goods were **already billed**, name the bill: Finance also posts a **supplier debit note** for the same quantities at the bill's own prices, VAT and variance (Dr Payables, Cr GRNI / variance / VAT receivable), which reduces the bill (or becomes credit on account) and makes the quantity billable again. The debit note can never exceed the bill, line by line, and the last quantity takes the exact remainder. A service line can only be credited against a bill (no goods move). Debit notes use the return's number (`PRN/…`).

## 6. Permissions and roles

`procurement.view`, `procurement.order.manage`, `procurement.receipt.post`, `procurement.return.post`, `procurement.reverse`, `procurement.bill.post`, `procurement.bill.override`.
**Admin** has all. **Purchasing** (new `purchasing` role): view, create/submit orders, receive goods, return goods, read suppliers/items/prices/stock — not bills, not price overrides, not reversals. **Accountant**: view and enter supplier bills (not the price override).

## 7. API (all under `/api/v1`, POSTs need an `Idempotency-Key`)

Suppliers, items, units, warehouses and tax codes are referred to by **code**; order lines by their **line number**.

| Method and path | Purpose |
|---|---|
| `GET/POST /purchase-orders`, `GET/PUT /purchase-orders/{id}` | list (filter status, q), create, read, replace a draft (`version`) |
| `POST /purchase-orders/{id}/submit` · `/withdraw` · `/cancel` (`reason`) | approval and cancellation |
| `POST /purchase-orders/{id}/receipts` (`lines?: [{line, qty, lot?, expiry_date?}]`, `supplier_dn_ref?`) | receive (all open stock lines when no lines) |
| `POST /purchase-orders/{id}/bills` (`supplier_invoice_no`, `lines?: [{line, qty, unit_price?}]`, `override_reason?`) | bill (everything billable at the order's prices when no lines) |
| `POST /purchase-orders/{id}/returns` (`reason`, `bill?`, `lines: [{line, qty}]`) | return goods, with a debit note when `bill` is given |
| `GET /goods-receipts`, `GET /goods-receipts/{id}`, `POST /goods-receipts/{id}/reverse` | |
| `GET /supplier-bills`, `GET /supplier-bills/{id}` · `GET /purchase-returns`, `GET /purchase-returns/{id}` | |

New error codes: `procurement.price_variance_exceeded`, `procurement.qty_exceeds_open|billable|received|bill`, `procurement.nothing_to_receive|bill|return`, `procurement.bill_exists`, `procurement.receipt_used`, `procurement.order_in_progress`, `procurement.invalid_state`, `procurement.line_not_receivable`, `procurement.item_not_purchasable`, `procurement.price_missing`, plus the Inventory/Finance/Master Data codes.

## 8. Events (outbox)

`procurement.order.approved`, `procurement.receipt.posted`, `procurement.bill.posted`, `procurement.return.posted` (ids, document number, supplier, totals). Stock and ledger effects are synchronous in the same transaction, not driven by these events.

## 9. Changes made to other modules for this sprint

- **Finance**: `DebitNotePoster` (mirror of the credit note: journal + non-cash `debit_note` settlement of the bill); a supplier bill may now carry **credit lines** (a price lower than the goods received) as long as the total is above zero; mapping key `purchase_expense` (→ 2412 OTHER); stock reason `supplier_return` books against GRNI; method labels for credit/debit notes.
- **Inventory**: issue reason `supplier_return`.
- **Rounding**: stock is valued at `round(qty × unit cost, 4)` with the unit cost at 6 places, so a bill that clears a line in several pieces can differ from the receipts by 0.0001; the completing bill takes the exact remainder. Goods returned leave at **average cost** (Inventory's rule), not necessarily the order's price, so GRNI can keep a few ten-thousandths of a rupee after a return when prices differed — it is tested to stay below LKR 0.0005 and to be exactly zero without returns.

## 10. Known limits (by design for now)

- **No purchase requisitions** (request → approval chain → order). Orders are raised directly; approval of the order itself is built (the chain, thresholds and approvers are set as Approvals policies).
- Supplier prices include VAT. VAT on a bill is worked out per line from the tax code; a supplier's invoice that differs by a few cents of VAT cannot be keyed in as-is.
- **No withholding tax**, no import costs (landed cost), no supplier price-list approvals, LKR only, no payment on order (advance/deposit).
- Quantity tolerance on receipts is zero (over-delivery must be sent back or a new order raised).
- A debit note cannot be reversed; a rejected order approval leaves the order *Waiting* until it is withdrawn.
