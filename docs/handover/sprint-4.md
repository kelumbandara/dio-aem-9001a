# Sprint 4 — Inventory: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 5 (Finance) has not been started.
You asked me to choose the Sprint 4 defaults; they are in [../architecture/inventory.md](../architecture/inventory.md) §1 and repeated in §4 below for you to veto.

## 1. What was built

New module **Inventory** (depends on Core, Approvals, Master Data). Full description: [../architecture/inventory.md](../architecture/inventory.md).

- **Warehouses and locations**: many warehouses, optionally tied to a branch; every warehouse has a default location, racks/bins are switched on per warehouse.
- **The stock ledger and balances**, with **weighted-average cost**, kept consistent in one transaction per movement. The ledger is permanent (database trigger + model guard); corrections are reversals.
- **Receipts, issues, transfers, adjustments, reversals**, in any unit the item has (converted to the base unit), with **lots and expiry** (first-expiry-first-out; expired lots never issued automatically).
- **Negative stock is impossible**: refused by the service, and by a database CHECK that application code cannot bypass.
- **Reservations**: stock promised to a document is held back from other issues, transfers and adjustments; issuing against a reservation uses it up.
- **Reorder rules** with a "below minimum" list and an event when an item drops below its minimum.
- **Approval**: stock adjustments are approval-gated (R3, amount = value). With no policy they post at once.
- **Contracts for later modules**: `StockPoster` (post / check / reverse), `StockAvailability`, `StockReserver`. Sales, Procurement and Manufacturing will use only these.
- **Daily reconciliation** (`inventory:reconcile`, 02:30) proving balances and costs equal the ledger.
- **Screens** (English / Sinhala / Tamil): stock on hand and item card, stock documents with receive / issue / transfer / adjust forms, ledger, lots & expiry, reorder, warehouses. **REST API** under `/api/v1` with the same rules.
- **Changes outside Inventory**: `Decimal` moved to the shared `App\Support` (every module needs it; fixed to accept zero decimal places); Master Data's `ItemCatalog` gained `findMany`, `units` and `search`; Core gained a `BranchDirectory` contract; three menu icons; Composer's script timeout raised to 20 minutes because the full suite now takes ~6 minutes.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest), `composer audit`, `npm audit` | Pint passed; Larastan **no errors**; no advisories / 0 vulnerabilities; **594 passed** (4,388 assertions), 329 s — a clean full run after the last code change (the run before it hit the flaky Sprint 2 assertion described below; after removing it the full run was clean) |
| Inventory tests | 95 (posting 41, API 15, approvals 8, reservations and reorder 14, randomised invariants 3 seeds, web 14) plus 2 real-process concurrency tests |
| Dev database | migrated (4 new migrations, no change to existing tables) |
| `npm run build` | rebuilt, `public/build` committed |

What the tests prove beyond "it runs":

- **Books balance through chaos**: three seeded random sequences of 140 receipts, issues, transfers, adjustments and reversals (many refused on purpose) — after **every step** the reconciliation finds no difference, no balance or cost value is negative, and every reversal cancels its original exactly.
- **Real parallel processes** (`tests/Concurrency`): 6 processes issuing 36 times against 20 units → exactly 20 succeed and 16 are refused, none oversold; 6 processes posting multi-item transfers in opposite orders and directions → all 48 succeed with **no deadlock** and totals intact.
- **The tests can fail**: with the per-item row lock removed (checked by hand, then restored) both concurrency tests fail.
- **Rounding**: three units worth 10.0000 issued one by one leave exactly 0.0000, not a residue; average cost, transfers at average cost, and exact-value reversals are asserted as strings.
- **Immutability**: UPDATE/DELETE on the ledger, document lines and documents are refused at model and database; a balance written negative behind the application's back is refused by the database; the reconciler notices a balance edited behind the ledger's back.
- **Approvals end to end**: adjustment → 202 and no stock change → approver approves → posted, linked to the request and credited to the requester; rejected → nothing; stock sold meanwhile → execution fails and nothing changes; a bad adjustment is refused immediately and never queued; policy minimums gate only large adjustments.
- **Access**: each document kind needs its own permission; viewers cannot post, receive-only users cannot read; unauthenticated users are redirected.

Defects found by the tests and fixed this sprint: `Decimal::isDecimal(..., 0)` built an invalid regex (units with 0 decimals such as PCS); the lots query double-counted in pagination; reference PATCH route parameters; `datetime` component given a string; module-dependency tests assumed Master Data was the top module; and a Sprint 2 concurrency test had a flawed assertion (it compared an array with its re-indexed copy, so it failed whenever the winning worker was not the first one to finish) — it passed or failed by chance, and the flawed line is removed (the next assertion already checks that every loser got `approvals.not_pending`).

## 3. NOT verified

- **No visual browser check.** Screens are exercised through feature tests (they render in all three languages with no raw keys and post/redirect correctly); layout, responsiveness and the Alpine line repeaters on the document forms have not been looked at by eye. Please click through *Receive stock* first.
- **Sinhala and Tamil strings are drafts** needing native review (key parity with English is tested).
- **The GitHub Actions workflow has not been seen running**: the repository is pushed but I cannot read the Actions tab. The suite now needs ~6 minutes; the CI job timeout and a Postgres+Redis service were set in S2 but have not been exercised on GitHub.
- **No load test.** Posting locks per item (so two people moving the *same* item queue briefly); movements of different items run in parallel. Nothing was measured with thousands of lines or items. Stock lists order by item id (not code) and search uses substring scans.
- **Reorder events are not consumed** and no notification is sent when an item drops below its minimum (Procurement S7 / Notifications).
- **Reservations have no screen or API** — only the contract; Sales and Manufacturing will create them.
- **No stock count document** (stocktake) — adjustments are used for now; the catalog lists counts as post-MVP.
- **Finance is not connected**: stock value is tracked and every movement emits `inventory.stock.moved`, but nothing posts journals yet (S5).
- Item search in the document form's item picker lists the first 300 items (a browser datalist); a real search-as-you-type picker needs a small API call — fine for hundreds of items, not for tens of thousands.

## 4. Decisions for you / deviations from the written plan

Defaults I chose — veto any:

1. **Costing = weighted average per item across the company** (not per warehouse). Moving stock between warehouses never changes cost. FIFO layers are not built.
2. **Adjustments need approval only if you create a policy** for `inventory.adjustment.post` (*Approval policies*). Receipts, issues, transfers and reversals are protected by permissions only. A typical setup: adjustments above LKR 50,000 → role `finance-approver`. Say if issues or reversals should be gated too.
3. **No draft stage**: a stock document posts the moment it is saved. Drafts belong to Sales/Procurement documents.
4. **Posting date cannot be in the future**, and cost follows processing order, not the date, so back-dated entries never re-price history. Period locking arrives with Finance (S5).
5. **Roles**: only `admin` holds Inventory permissions today. Who should receive, issue, transfer, adjust, reverse? A role-to-permission matrix (storekeeper, production, sales…) is needed — tell me the roles you want and I will seed them.
6. Lot-tracked items require a lot number on every receipt; expiry defaults from the item's shelf life; an expired lot can only leave through an adjustment.
7. Reasons for each document kind are a fixed short list (e.g. receipt: purchase / production / return / opening / other); say if you want different ones.
8. The ledger value of a reversal is the original value, not today's cost. If the cost has moved on so the item would end with negative value, the reversal is refused and an adjustment is the way to correct it.

Deviations from the plan: no `stock_counts` (post-MVP as planned); `stock_transfers`/`stock_adjustments` tables were merged into one typed `stock_documents`; reservations are per item and warehouse (not per location/lot); `reserved` is computed, not stored on balances.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Per-item lock serialises concurrent movements of one item | a very busy item could queue | fine at SME volumes; revisit with real numbers (lock per item+warehouse is possible but complicates average cost) |
| Company-wide average cost | a warehouse's stock is valued at the company average | acceptable for MVP; per-warehouse costing would need a design decision before Finance |
| Reversal refused when cost has moved on | an old document cannot be undone exactly | by design; correct with an adjustment (approval-gated) |
| Anything that writes the ledger outside `StockPoster` bypasses all rules | wrong stock | architecture test pins the table writers to this module; the daily reconciliation would catch it; keep to the contract in S6–S9 |
| Reconciliation only reports | a difference is logged (critical) and the command exits 1; nobody is paged | wire monitoring/alerting in S15; for now watch `storage/logs` |
| Item names live in Master Data | stock lists look items up through the catalog in one extra query per page | acceptable; a read model can be added if lists get slow |
| Tests are slow (≈6 min) | slower feedback | split suites in CI (unit/feature vs concurrency); parallel Pest later |
| Draft translations | wrong wording in si/ta | native review before go-live |

## 6. Decisions requested before Sprint 5 (Finance)

- The decisions in §4 (items 2 and 5 matter most).
- **Chart of accounts**: do you have your current FrontAccounting chart (account codes/names)? If yes, export it (CSV) and I will import it as the starting point; if not I will propose a standard Sri Lankan SME chart for review.
- **Financial year**: confirm 1 April – 31 March (ADR default).
- **Tax treatment** to wire into posting: VAT 18 %, SSCL 2.5 %, NBT 0 % (as you told me) — and whether SSCL/NBT apply on sales invoices, purchase invoices, or both, and on what base.
- **Inventory accounting**: perpetual (every stock movement posts a journal automatically) is my recommendation; say if you prefer periodic.
- Re-confirm from earlier sprints: Horizon now or with the Docker image; ClamAV container acceptable. And please tell me whether the GitHub Actions run was green.

## 7. How to try it

Start PostgreSQL and Redis ([../development/setup.md](../development/setup.md) §6–7), run `.\scripts\dev\run.ps1`, sign in as the demo admin (it holds every Inventory permission). Then: **Warehouses** → *New warehouse* (e.g. MAIN); **Stock** → *Receive stock* (an item code from the Items screen, a quantity, a cost per unit, warehouse MAIN) → open the document; *Issue stock*; *Transfer stock* to a second warehouse; *Adjust stock*; open an item's stock card; **Reorder** → add a minimum and issue below it; **Lots & expiry** after receiving a lot-tracked item. API: `/api/v1/stock`, `/stock-documents`, `/warehouses`, … with the same login/token flow as earlier sprints. Command: `php artisan inventory:reconcile`.
