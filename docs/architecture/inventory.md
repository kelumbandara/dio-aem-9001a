# Inventory module

Warehouses and locations, lots with expiry, the stock ledger, balances, weighted-average cost, receipts, issues,
transfers, adjustments, reversals, reservations, reorder rules, reconciliation. Depends on **Core**, **Approvals** and
**MasterData** (through `ItemCatalog` and `UomConverter` only). Every module above it (Sales, Procurement,
Manufacturing, Finance) changes or reads stock **only through the contracts in §5**.

## 1. Decisions taken (owner asked for defaults)

| Question | Decision |
|---|---|
| Warehouses and sites | many warehouses, optionally linked to a branch (site) of Core; each has one default location, so stock always has a place. Bins/racks are **off per warehouse** until `uses_locations` is switched on |
| Costing | **weighted average per item across the company** (ADR 0000 #10). FIFO layers are not built; the ledger records value on every row so they can be added later without rewriting history |
| Lot tracking | supported from day one, driven by the item's `tracking = lot` in Master Data; expiry from the shelf life or the date given |
| Negative stock | **never**. Refused by the service, and by a database CHECK that no code path can bypass |

## 2. Rules that hold everywhere

- **One door**: `StockPoster` is the only code that writes the ledger, balances or item costs. Web forms, the API and other modules all call it.
- **Atomic**: one database transaction per movement — document, lines, ledger rows, balances, average cost, audit entry and outbox event, or nothing.
- **Immutable ledger**: `stock_ledger` and `stock_document_lines` reject UPDATE/DELETE (PostgreSQL trigger *and* model guard); a posted `stock_documents` row may only be marked `reversed`. Corrections are **reversals** — an opposite document at the original quantities and values.
- **No drafts**: a stock document is posted when saved (it exists only if it changed stock). Business documents with drafts (orders, GRNs) live in Sales/Procurement and call the poster when they post.
- **Decimal strings, never float**; quantities 6 places, cost per unit 6, values 4 (LKR). Stock is kept in the item's **base unit**; lines are entered in any unit the item defines and converted by `UomConverter` (entered quantity must respect that unit's decimals).
- **Only active stock items** (`item_type = stock`) in active warehouses and locations.
- **Posting date** is a business date (Asia/Colombo), not in the future. Cost follows processing order, not the date, so a back-dated entry never re-prices history.
- **Document numbers** from Core numbering, gapless per fiscal year: `SRC/{FY}/{SEQ}` receipt, `SIS` issue, `STR` transfer, `SAD` adjustment.

## 3. Costing arithmetic

- *In* (receipt, adjustment gain with a cost): value = round₄(entered qty × cost per entered unit). *In* without a cost (adjustment): current average (needs existing cost, else `inventory.cost_required`).
- *Out* (issue, loss, transfer-out): value = round₄(qty × item value ÷ item qty), capped at the item's value; **the last unit takes whatever value remains**, so a rounding residue never stays behind.
- *Transfer*: out at average, in at the same value — the item's total value and average are unchanged.
- *Reversal*: exact negation of the original quantities **and values** (not today's average). It is refused (`inventory.reversal_blocked` / `insufficient_stock`) when the stock is no longer there or the cost has moved on so that the item's value would go negative.
- Zero-cost receipts are allowed (samples, opening stock).

## 4. Data model

| Table | Notes |
|---|---|
| `warehouses` | code, name, optional `branch_id`, `uses_locations`, `version`; never deleted |
| `locations` | per warehouse; exactly one default (`MAIN`, partial unique index); cannot deactivate the default or one holding stock |
| `lots` | item, lot no (unique per item), expiry date, received on |
| `stock_documents` / `stock_document_lines` | type, status `posted/reversed`, reason, reference, `source_type/source_id` (unique per type — another module's document posts once), `reverses_id` / `reversed_by_id`, total value. Lines keep entered unit and quantity **and** base quantity, from/to location, lot, cost, value |
| `stock_ledger` | one row per quantity change: signed base qty, unit cost, signed value, location, lot, `reverses_id` |
| `stock_balances` | on hand per item/location/lot; CHECK `on_hand ≥ 0`; partial unique indexes with/without lot |
| `item_costs` | per item: quantity and value (average = value ÷ qty). **Locked first by every posting of that item** — the concurrency mutex |
| `stock_reservations` | item + warehouse, qty, consumed qty, status, source document |
| `reorder_rules` | item + warehouse (or company-wide), minimum, suggested order quantity |

Deviations from the catalog in [database.md](database.md): no `stock_transfers`/`stock_adjustments` tables (one document table with a type), `reserved` is computed from reservations rather than stored on balances, `inventory_valuation_layers` is not built (average cost), `stock_counts` stays post-MVP.

## 5. Contracts for other modules (`Contracts/`, `DTOs/`)

| Contract | Use |
|---|---|
| `StockPoster` | `post(StockMovement)`, `check(StockMovement)` (exactly the same checks and arithmetic, then undone), `reverse(documentId)` → `PostedMovement`. Giving `sourceType`/`sourceId` makes posting idempotent |
| `StockAvailability` | `level(item, warehouse?)` → on hand / reserved / available; `averageCost(item)` |
| `StockReserver` | `reserve(item, warehouse, qty, sourceType, sourceId)`, `release`, `releaseBySource` |

`StockMovement` = type, posting date, reason, lines (`StockLine`: item, qty, unit, warehouse/location, to-warehouse/location, direction for adjustments, lot, expiry, unit cost, note, reservation).

## 6. How a movement is processed

1. Validate header (type, reason for that type, date, 1–200 lines) and every line (item, unit and decimals, places, lot rules, cost).
2. Lock the touched items' `item_costs` rows **in item-id order** (no circular waits). After this nobody else can change those items' balances, so everything read next is the truth.
3. Apply the lines in order against in-memory balances and costs: lots are chosen **first-expiry-first-out** when an issue/transfer names none (expired lots are never auto-picked; naming one is refused except in an adjustment write-off); stock never goes below zero.
4. Reservation rules: stock leaving a warehouse may not take it below what is still reserved for *other* documents; issuing against a reservation (`reservationId`) consumes it.
5. Persist document, lines, ledger rows, balances, costs; audit `inventory.stock_posted`; outbox `inventory.stock.moved`; announce reorder crossings.

## 7. Approval

Adjustments are the only manual stock change with no business document behind them, so they are approval-gated: key
`inventory.adjustment.post` (R3, amount = value of the adjustment, so the authority matrix and policy minimums work). With no policy they
post at once. With a policy the request is **checked first** (a bad adjustment is refused at once, not queued), approvers see the real value,
the checks run again at execution, and the posted document links back to the approval request. Receipts, issues, transfers and reversals are
protected by permissions, not approval.

## 8. Reorder levels and events

A rule says "tell me when available (on hand − reserved) of this item, in this warehouse or overall, drops below N". The moment a movement
takes it from at-or-above to below, `inventory.stock.below_reorder` is written to the outbox (once per crossing, not on every movement while below).
`GET /stock/below-reorder` and the Reorder screen list what is below now. **Nothing consumes the events yet** (Procurement, S7); no notification is sent in S4.

Events: `inventory.stock.moved` `{document_id, document_no, type, item_ids, posting_date}`, `inventory.stock.below_reorder` `{item_id, warehouse_id, available, min_qty, reorder_qty}`.

## 9. Reconciliation

`php artisan inventory:reconcile` (scheduled daily 02:30, exit 1 and a critical log entry on any difference) checks: balance = Σ ledger per item/location/lot; item cost qty and value = Σ ledger per item; each document total = Σ its lines. The same check runs after every step of the randomised tests.

## 10. API (`/api/v1`, Sanctum + MFA-enrolled)

| Path | Methods | Permission |
|---|---|---|
| `warehouses[/{id}]`, `warehouses/{id}/locations[/{id}]` | GET, POST (idempotent), PATCH | `inventory.warehouse.view` / `.manage` |
| `stock`, `stock/items/{code}`, `stock/ledger`, `stock/lots`, `stock/below-reorder` | GET | `inventory.stock.view` |
| `stock-documents[/{id}]` | GET list/show, POST (idempotent; `type` in body) | view; posting needs `inventory.receive` / `.issue` / `.transfer` / `.adjust` by type |
| `stock-documents/{id}/reverse` | POST | `inventory.reverse` + the permission of the document's type |
| `reorder-rules[/{id}]` | GET, POST, PATCH | view / `inventory.reorder.manage` |

Items, units, warehouses, locations, lots are given **by code** (`item`, `uom`, `warehouse`, `location`, `lot`); quantities and costs are strings. An adjustment that needs approval answers `202` with `approval_request_id`.
Error codes: `inventory.insufficient_stock`, `reserved_stock`, `insufficient_available`, `qty_invalid`, `cost_required`, `lot_required`, `lot_not_tracked`, `lot_expired`, `lot_expiry_mismatch`, `item_not_stocked`, `warehouse_inactive`, `locations_not_used`, `location_mismatch`, `same_location`, `date_in_future`, `reason_invalid`, `direction_required`, `already_reversed`, `cannot_reverse_reversal`, `reversal_blocked`, `reservation_invalid`, `reservation_insufficient`, `in_use`, `rule_exists`, `unknown_code`, `concurrency.stale_version`.

## 11. Screens (English / Sinhala / Tamil)

Stock on hand (filters, item card with where-it-is and recent movements), Stock documents (list, show, receive/issue/transfer/adjust forms with line repeaters, reverse), Ledger, Lots & expiry, Reorder (below-minimum list + rules), Warehouses (+ locations).

## 12. Permissions

`inventory.warehouse.{view,manage}`, `inventory.stock.view`, `inventory.receive`, `inventory.issue`, `inventory.transfer`, `inventory.adjust`, `inventory.reverse`, `inventory.reorder.manage`. `admin` holds all; other roles none until the owner decides who does what.
