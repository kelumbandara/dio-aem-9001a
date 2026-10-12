# Finance module

Chart of accounts, fiscal years and periods, journals, automatic posting rules, perpetual-inventory journals,
receivables and payables (invoices, bills, receipts, payments, allocation), financial reports. Depends on **Core**,
**Approvals** and **MasterData** (customers and suppliers through `PartyDirectory`). It does **not** depend on Inventory:
it learns about stock only from the `inventory.stock.moved` event (an architecture test enforces this).

## 1. Rules that hold everywhere

- **One door**: `JournalPoster` is the only code that writes the general ledger. Manual journals, stock journals, invoices, receipts and payments all go through it.
- **Always balanced**: checked in the service, and again by a deferred database trigger when the transaction commits. Each line is either a debit or a credit above zero, never both (CHECK).
- **Permanent**: journal lines and allocations reject UPDATE/DELETE (PostgreSQL trigger and model guard); a journal entry may only be marked `reversed`; a posted receipt/payment may only change `status`; a **locked** fiscal period cannot change at all. A correction is a reversal: an opposite journal that nets to zero.
- **No drafts**: a journal exists only when it is posted (as for stock documents). Approval, where wanted, happens *before* posting.
- **Only into open periods**; dates cannot be in the future. Decimal strings, 4 decimal places, never float. LKR only.
- **Control accounts are not for hand posting**: receivables, payables and inventory move only through invoices/receipts/payments and stock documents, which keeps them equal to their sub-ledgers (`finance.control_account`).
- **Idempotent by source**: a journal for a given business document (`source_type` + `source_id`) is posted at most once, so re-delivered events and retried requests do not double-post.
- Journal numbers `JV/{FY}/{SEQ}`, receipts `REC/…`, payments `PAY/…` — gapless per fiscal year (Core numbering). Every posting is audited and announces `finance.journal.posted` through the outbox.

## 2. The chart of accounts

Seeded from your FrontAccounting chart (printed 2026-10-11, 117 accounts, all codes kept) by `FinanceSeeder` (idempotent, never overwrites an account the accountant edited). Corrections made, as you allowed:

| Issue in the printed chart | What was done |
|---|---|
| Share capital, retained earnings and owners' investment sat under **Liabilities** | classed as **Equity** (groups 05, 06); director current accounts stay liabilities |
| **2490 YEAR PROFIT / LOSS** filed under expenses | moved to Equity (retained earnings group), subtype `year_result` |
| **2214** and **2217** both "Purchase Price Variance" | 2217 kept; 2214 renamed "(DUPLICATE - USE 2217)" and **deactivated** |
| **2205 DISCOUNT RECEIVED** under cost of goods sold | moved to Other revenue (income) |
| **2203 MARKETING** under cost of goods sold | moved to Distribution cost |
| Spelling: Recoverables, Office Equipment Maintenance, Charity Donations | corrected (names only; codes unchanged) |
| No account for VAT, SSCL, NBT, goods received not invoiced, opening balances, cost of sales, stock adjustments, FX loss | **added**: 1126 VAT input receivable, 1524 VAT payable, 1525 SSCL payable, 1526 NBT payable, 1510 GRNI, 1340 opening balance equity, 2206 cost of sales, 2207 stock adjustments & write-offs, 2503 FX loss |

Groups (01–12) are non-postable parents; accounts are never deleted. Once an account has postings its code, class and kind are fixed; an account used for automatic posting, tied to a bank/cash account, or holding a balance cannot be deactivated.

## 3. Account mapping ("posting rules")

Each automatic posting names an account by **key**; the keys point at accounts you can change on *Finance setup* (class-checked, e.g. `inventory` must be an asset):
`inventory`→1100, `wip`→1105, `grni`→1510, `receivable_control`→1125, `payable_control`→1500, `sales_revenue`→2000, `cogs`→2206, `stock_adjustment`→2207, `sample_expense`→2203, `purchase_variance`→2217, `discount_given`→2100, `discount_received`→2205, `vat_output`→1524, `sscl_payable`→1525, `nbt_payable`→1526, `vat_input`→1126, `opening_balance_equity`→1340, `retained_earnings`→1330, `year_result`→2490, `fx_gain`→2102, `fx_loss`→2503. Looked up at posting time (never cached), so a change applies to the next posting.

## 4. Perpetual inventory (stock → ledger)

`StockJournalListener` turns each `inventory.stock.moved` event into a journal (the event carries the net change in stock value, the kind and reason; Finance reads no Inventory table). Value up: debit `inventory`, credit the counter account; value down: the opposite.

| Movement | Counter account |
|---|---|
| receipt: purchase / production / return / opening / other | `grni` / `wip` / `cogs` / `opening_balance_equity` / `stock_adjustment` |
| issue: sale / production / sample / scrap / other | `cogs` / `wip` / `sample_expense` / `stock_adjustment` / `stock_adjustment` |
| adjustment (any reason) | `stock_adjustment` |
| transfer | none — value does not change |
| reversal of any of the above | the **original's** counter account, opposite amounts, as its own journal (so it never depends on the original having been posted first) |

Journals are created when the outbox relay delivers the event (every minute with the scheduler running), idempotently and in the stock document's own period; if that period has since been closed the journal is posted **today** instead. If the Finance module is switched off, stock keeps working and no journals are made. Cross-module proof: after any sequence of stock movements and relay runs, the **inventory account balance equals the sum of `item_costs.value`** (tested with randomised sequences).

## 5. Receivables and payables

- **Open items** (`open_items`): a customer invoice (`receivable`) or a supplier bill (`payable`) waiting to be settled, with `amount` and `open_amount`. Sales (S6) and Procurement (S7) post them through the `OpenItemPoster` contract: they give the revenue/VAT/SSCL/NBT lines (or expense/GRNI/input-VAT lines); Finance adds the control-account line tagged with the party, posts the journal and records the open item — atomically and once per source.
- **Receipts and payments** (`settlements`): money in/out through a bank or cash account, optionally settling several open items fully or partly; money not allocated stays *on account* and can be allocated later. Open items are locked in id order, so simultaneous settlements can never settle more than an invoice is worth (tested with real parallel processes). Reversing a receipt/payment reverses its journal and gives the amounts back to the invoices.
- **Limits**: LKR bank/cash accounts only — receipts/payments into the USD account are refused (`finance.foreign_bank_not_supported`) until multi-currency is designed; credit notes exist since S6 (section 6); no cheque register or bank reconciliation.
- **Reports**: ageing by days overdue (as at today, from what is still open), a party's ledger with running balance, unallocated money, and a **tie-out** (open items less unallocated money must equal the control account in the ledger).

## 6. Fiscal years and periods

A year is twelve monthly periods starting 1 April (company setting; seeded for the current year). Periods are **open → closed → locked**: closing and reopening need `finance.period.manage`; locking (final, enforced by the database) needs `finance.period.lock`.

## 7. Reports

Trial balance (cumulative, or opening / movement / closing for a range), general ledger (running balance), profit and loss, balance sheet — all computed from journal lines; nothing is stored. Reversed journals and their reversals both count, so they net out. **There are no year-end closing entries**: the balance sheet carries "profit of earlier years" and "profit this year" in equity by calculation, and always balances (tested). Closing journals can be added later without changing history.

## 8. Approval

Two actions are approval-gated (R3; amount feeds the authority matrix): `finance.journal.post` (manual journals) and `finance.payment.post` (payments to suppliers). With no policy they post at once. With a policy, the request is **checked first** (a bad journal/payment is refused immediately, not queued), approvers see the real amount, and the checks run again at execution. Receipts are not gated.

## 9. API (`/api/v1`, Sanctum + MFA-enrolled)

| Path | Methods | Permission |
|---|---|---|
| `accounts[/{id}]` | GET (`filter[q|class|is_active|groups]`, `with_balance`), POST, PATCH | `finance.view` / `finance.account.manage` |
| `account-mappings[/{key}]`, `bank-accounts[/{id}]` | GET, PUT / POST, PATCH | view / `finance.mapping.manage` / `finance.bank.manage` |
| `fiscal-years`, `fiscal-periods/{id}/{close|reopen|lock}` | GET, POST | view / `finance.period.manage` / `finance.period.lock` |
| `journals[/{id}]`, `journals/{id}/reverse` | GET, POST (idempotent; `202` under an approval policy) | view / `finance.journal.post` / `finance.reverse` |
| `receivables/…`, `payables/…`: `open-items`, `ageing`, `tie-out`, `unallocated`, `parties/{code}/ledger`, `settlements[/{id}]`, `…/allocate`, `…/reverse` | GET, POST | `finance.ar.view` / `.receive`, `finance.ap.view` / `.pay` (+ `finance.reverse`) |
| `reports/trial-balance`, `general-ledger`, `profit-and-loss`, `balance-sheet` | GET | `finance.report.view` |

Accounts, parties and bank accounts are given by code/public id; amounts are strings. Error codes: `finance.unbalanced`, `period_closed`, `no_period`, `control_account`, `account_invalid`, `one_side`, `amount_invalid`, `date_in_future`, `mapping_missing`, `mapping_class`, `already_reversed`, `reverse_via_document`, `system_journal`, `over_allocated`, `open_item_invalid`, `document_exists`, `foreign_bank_not_supported`, `period_state`, `year_overlaps`, `code_taken`, `account_in_use`, `concurrency.stale_version`.

## 10. Screens (English / Sinhala / Tamil)

Chart of accounts (grouped, balances, edit), Journals (list, show, manual journal form with live balance check, reverse), Receivables and Payables (outstanding, ageing, tie-out, receipts/payments list and form with allocation, party ledger, reverse), Financial reports (four), Fiscal periods, Finance setup (account mapping, bank/cash accounts).

## 11. Permissions

`finance.view`, `finance.account.manage`, `finance.mapping.manage`, `finance.bank.manage`, `finance.period.manage`, `finance.period.lock`, `finance.journal.post`, `finance.reverse`, `finance.ar.view`, `finance.ar.receive`, `finance.ap.view`, `finance.ap.pay`, `finance.report.view`. `admin` holds all; `super-admin` passes every check.

## 12. Reconciliation

`php artisan finance:reconcile` (scheduled 02:45; exit 1 and a critical log on any difference): every journal balances and equals its total; the whole ledger balances; each open item equals its amount less its settlements; receivables and payables equal their control accounts.

## 6. Credit notes and what Sales reads (added in Sprint 6)

- **Credit notes** (`CreditNotePoster`): Sales gives the *debit* lines (revenue, VAT, SSCL, NBT given back); Finance adds the receivables credit tagged with the customer, posts the journal and records a **non-cash settlement** (`method = credit_note`, no bank account — the database allows a missing bank account only for that method). If an invoice is named the settlement is allocated to it (up to its open amount); any excess stays as credit on account and can be allocated later like a receipt. Numbers come from Sales (`CN/…`) and share the settlements' unique document-number space. A credit note is idempotent by source.
- **`ReceivablePosition`**: what a customer owes (`outstanding` = open invoices less unallocated receipts and credits, never below zero), what is overdue, and the bank accounts flagged *show on invoices* (`bank_accounts.show_on_invoices`) for the printed invoice. Sales uses it for the credit-limit check.
- **Accountant role** (seeded): every `finance.*` permission, read-only master data and stock, and `sales.view` + posting invoices/credit notes; authenticator (MFA) required like admins.

## 7. Supplier debit notes and bills with credit lines (added in Sprint 7)

- **Debit notes** (`DebitNotePoster`): the supplier-side mirror of the credit note. Procurement gives the credit lines (GRNI, input VAT, expense and — when the bill had a favourable variance — a debit line for it); Finance adds the debit on payables control tagged with the supplier, posts the journal and records a **non-cash settlement** (`method = debit_note`, no bank account, payable side only — enforced by the database) allocated to the named bill up to its open amount; the rest stays as credit on account. Idempotent by source.
- **Supplier bills may carry credit lines** (`OpenItemPoster`, payable side): a bill priced below the goods received credits Purchase price variance. The total payable must stay above zero; sales invoices still accept credit lines only.
- Mapping key **`purchase_expense`** (→ 2412 OTHER, class expense) for services and non-stock purchases; stock issue reason `supplier_return` posts against GRNI.

- **Production variance (2208, mapping key `production_variance`)**: material cost of a completed production order not absorbed by its good output; posted by Manufacturing when an order is completed (debit this account, credit work in progress).
