# Sprint 7 — Procurement: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 8 (Manufacturing I) has not been started.
You asked me to start the next sprint without answering the Sprint 7 questions, so everything below uses my stated defaults (§4). Details: [../architecture/procurement.md](../architecture/procurement.md).

## 1. What was built

New module **Procurement** (depends on Core, Approvals, Master Data, Inventory, Finance — only through their contracts).

- **Purchase orders**: lines with unit, net-of-VAT price (supplier price list when left empty), discount, tax code; input VAT per line (items without a tax code are bought with VAT, EXEMPT items without). **Submitting goes through the approval gate** `procurement.po.approve` (amount = gross total): with no approval policy the order is approved at once, with one it waits for the approvers and can be withdrawn. Cancel while nothing is received or billed. Printable A4 purchase order to send to the supplier.
- **Goods receipts**: stock arrives in the order's warehouse at the order's price (lot and expiry for lot-tracked items); Finance books **Dr Inventory / Cr Goods received not invoiced**. Over-receipt refused; an unbilled receipt can be reversed.
- **Supplier bills with a three-way match**: quantity ≤ received and not yet billed (services: ordered); price within **2 %** of the order passes, beyond that it needs `procurement.bill.override` and a reason; GRNI is cleared, the price difference goes to **Purchase price variance (2217)** (debit or credit), services to a new **purchases** mapping (2412), **input VAT** to VAT receivable, the payable to the supplier — with the supplier's own invoice number as the payable's document number.
- **Purchase returns and supplier debit notes**: goods go back out of the warehouse (booked against GRNI); when they were already billed, naming the bill posts a debit note at the bill's own prices/VAT that reduces what you owe, capped at the bill and exact at the last quantity; the quantity becomes billable again for a replacement.
- **API** under `/api/v1` and **screens** in English / Sinhala / Tamil; sidebar entries for orders, receipts, bills, returns.
- **Changes outside Procurement**: Finance gained `DebitNotePoster` (+ non-cash `debit_note` settlement), supplier bills may carry credit lines (a cheaper price), mapping key `purchase_expense`, stock reason `supplier_return` against GRNI; Inventory gained the issue reason `supplier_return`; a new **`purchasing` role** (view, orders, receive, return; read-only master data and stock) and the accountant can now view purchasing and enter supplier bills.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **838 passed** (7,097 assertions), 669 s — a clean full run after the last code change |
| Procurement tests | 46: flow 16, screens and API 14, random scenarios 16, plus 2 real-process concurrency tests; Finance debit-note tests 4 |
| Dev database | migrated (2 new migrations), roles and mapping re-seeded, demo data loaded, `finance:reconcile` and `inventory:reconcile` clean |

What the tests prove beyond "it runs":

- **The books and the stock agree after random histories**: 16 random orders (1–3 lines, random prices, discounts, VAT/exempt) received, billed and returned in random slices — with and without debit notes, with and without price differences — always end with the order completed, nothing billed beyond what was ordered, debit notes never above their bill, bills less debit notes equal to the order to the cent when prices are exact, payables equal to bills less debit notes, the inventory account equal to the stock records, GRNI at exactly zero when nothing went back (below LKR 0.0005 after returns at average cost), and `finance:reconcile` / `inventory:reconcile` clean. This test found a real defect (a replacement delivery after billing did not complete the order), fixed.
- **Races**: 6 real parallel processes making 24 receipts of one unit against an order of 10 → exactly 10 receipts, stock 10; 24 bills of one unit against 10 received → exactly 10 bills and the payable is exactly 10 × 118.
- **Price match**: 99 against 100 passes and books a 10 credit to variance; 105 is refused for everyone except someone allowed to accept it with a reason, which is stored on the bill.
- **Approval**: with a policy the order waits (cannot receive, cannot be edited), is approved by the approver through the Approvals API, and can be withdrawn to a draft.
- **Immutability**: UPDATE/DELETE on a posted bill and its lines is refused by the database itself.
- **Access**: 403 without the permission, stale-version 409, validation 422 with codes, idempotency-key replay creates one document, every screen renders in all three languages without raw translation keys (and the Sinhala/Tamil files have exactly the English keys), the `purchasing` and `accountant` roles hold exactly what is described.

## 3. NOT verified

- **No visual check in a browser by me** of the new screens or the printed purchase order.
- **Sinhala and Tamil strings are drafts** needing native review.
- **Accounting treatment not reviewed by an accountant**: Dr Inventory / Cr GRNI at receipt, GRNI cleared by the bill with the difference to 2217, services to 2412, input VAT fully recoverable on every purchase, goods returned leave at average cost.
- **VAT on a supplier's invoice is calculated, not typed**: if the supplier's printed VAT differs by a few cents the bill cannot be keyed to match.
- **Goods returned leave at average cost**, so after a return at a different price than the order GRNI can hold a few ten-thousandths of a rupee.
- **Price tolerance** applies per line and as a percentage only (no absolute amount rule); quantity tolerance on receipts is zero.
- **No load test**; GitHub Actions has still not been seen running by me.

## 4. Decisions for you / deviations from the written plan

1. **No purchase requisitions** (request → approval chain → order). I built the approval on the order itself. Say if you want requisitions before Manufacturing.
2. **PO approval rule**: none exists, so every order is approved at once. Create a policy on the Approvals screens — for example "orders from LKR 500,000 need the finance manager". Tell me the amounts and approvers you want.
3. **Price tolerance 2 %** (`PROCUREMENT_PRICE_TOLERANCE_PCT`) and who may accept a difference (`procurement.bill.override`, admin only today).
4. **Input VAT recoverable on every purchase** at the item's tax code; **purchase prices are without VAT** (unlike your sales prices). Tell me if your suppliers' price lists are VAT-inclusive.
5. **No withholding tax** on supplier payments (the plan listed it as a question).
6. **Roles**: `purchasing` staff cannot enter bills or accept price differences; the accountant enters bills. Change if your process differs (for example purchasing enters bills too).
7. Supplier payment itself remains in Finance (Payables screens, with its optional approval).

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| No approval policy for purchase orders yet | any purchaser can commit the company to any amount | create the policy before real use (§4.2) |
| Items without a tax code are bought with VAT | wrong input VAT claimed on exempt goods | set EXEMPT on exempt items |
| Accounting rules not reviewed by an accountant | wrong accounts hit once real documents flow | accountant to read procurement.md §4–§5 and test a few documents on the dev data |
| Returns at average cost | a few ten-thousandths in GRNI | acceptable; FIFO layers are post-MVP |
| Stock journals depend on the outbox relay (as before) | a stopped scheduler delays inventory/GRNI journals, not the documents | alert on parked/old outbox events (S15) |
| Company profile still the placeholder | purchase orders print "My Company (Pvt) Ltd" | fill Administration → Company profile |

## 6. Decisions requested before Sprint 8 (Manufacturing I)

- Answers to §4 (items 1–2 matter most).
- **Bill of materials**: one BOM per finished product with versions, or alternative BOMs per plant/line? Do you want scrap/yield % per material?
- **Routing and work centres**: your real production steps for the first product (cutting, sewing, packing…), machines per work centre, shifts, and whether time is tracked per operation.
- **Production orders**: issue materials at order start or backflush on output? Is WIP costed (material only, or also labour/overhead rates)?
- **Costing**: standard cost, or actual weighted-average as now? Any overhead absorption rate?
- Still open from earlier sprints: Horizon now or with the Docker image; ClamAV container acceptable; whether the GitHub Actions run was green; the Sprint 6 open items (invoice layout, sales roles).

## 7. How to try it

The local app is at <http://127.0.0.1:8000> (admin demo user holds every Procurement permission). In the sidebar: **Purchase orders** (a completed-looking order with a bill and a debit note, one half-received, and a draft — open the draft and press *Submit for approval*; with no policy it is approved at once), **Goods receipts**, **Supplier bills** (open `CT-88413`: cups LKR 11,000 + VAT 1,980 = 12,980, with the 10 damaged cups credited by a debit note of 649), **Purchase returns**. On an approved order you can *Receive goods*, *Enter supplier bill* (try a price 5 % off to see the block) and *Return goods / debit note*; *Print / PDF* gives the purchase order for the supplier. Commands: `php artisan finance:reconcile`, `php artisan inventory:reconcile`.
