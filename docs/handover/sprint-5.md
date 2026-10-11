# Sprint 5 — Finance: handover

Status: **ready for owner review.** Stop point per CLAUDE.md; Sprint 6 (Sales) has not been started.
Your answers (VAT 18 %, NBT 0 %, SSCL 2.5 % on **sales** only; financial year 1 April–31 March; stock postings journalled automatically; your chart of accounts) are built in. Details: [../architecture/finance.md](../architecture/finance.md).

## 1. What was built

New module **Finance** (depends on Core, Approvals, Master Data; reads nothing from Inventory).

- **Your chart of accounts**, imported from the FrontAccounting printout: all 117 accounts and codes kept, in 12 groups, with the corrections listed in finance.md §2 (equity moved out of liabilities, 2490 year result under equity, duplicate 2214/2217 resolved, discount received and marketing moved to the right groups, spelling) and nine accounts **added** that automatic posting needs (VAT/SSCL/NBT payable, VAT input, goods received not invoiced, cost of sales, stock adjustments, opening balance equity, FX loss).
- **Fiscal years and periods**: 12 monthly periods from 1 April, seeded for the current year; open → closed → locked (locking is final, enforced by the database).
- **Journals**: manual journals (with a live balance check), reversals; always balanced, never editable, only into open periods.
- **Account mapping (posting rules)**: which account each automatic posting uses; editable.
- **Perpetual inventory**: every stock receipt, issue, adjustment and reversal gets its journal automatically (debit/credit inventory against GRNI, cost of sales, WIP, stock adjustments…); transfers need none. After any sequence of stock movements the **inventory account equals the stock records** (tested, randomised).
- **Receivables and payables**: invoices and bills (posted by Sales/Procurement through a contract, in S6/S7), receipts and payments with allocation to invoices/bills (partial, several at once, or on account), ageing, party ledgers, tie-out to the control accounts.
- **Bank and cash accounts** (your six, tied to ledger accounts 1150, 1155, 1160–1162, 1165).
- **Reports**: trial balance, general ledger, profit and loss, balance sheet.
- **Approvals** (only when you create a policy): manual journals and supplier payments.
- **API** under `/api/v1` and **screens** in English / Sinhala / Tamil for all of it; daily `finance:reconcile`.
- **Changes outside Finance**: Inventory's stock event now carries the value change and reason (additive); Master Data's `PartyDirectory` gained by-code lookup and search; Core gained a `CompanyInfo` contract (fiscal year start month); five menu icons.

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **726 passed** (5,403 assertions), 375 s — a clean full run after the last code change; `composer audit` and `npm audit` report nothing |
| Finance tests | 129 feature tests (journals 34, stock→journal 22, receivables/payables 24, reports 10, API 20, web 19) + 1 real-process concurrency test |
| Dev database | migrated (3 new migrations, no change to existing tables), chart seeded, demo data loaded, `finance:reconcile` and `inventory:reconcile` both clean, stock value 102,800 = inventory account 102,800 |

What the tests prove beyond "it runs":

- **The books always balance**: every journal balances (service) and the database refuses an unbalanced or two-sided line even when the application is bypassed; a randomised stock sequence ends with trial-balance debits = credits and inventory account = Σ stock value; the balance sheet balances for any date, with reversals, and with earlier years' profit.
- **Permanence**: journal lines, allocations, receipts/payments and locked periods cannot be changed or deleted at model or database level; reversal nets to exactly zero and cannot be repeated.
- **Settlement safety**: 6 real parallel processes making 24 receipts of LKR 100 against a LKR 1,000 invoice → exactly 10 succeed, the invoice ends at 0, no over-settlement; receipts cannot be allocated to someone else's invoice or beyond what was received.
- **Control**: hand-posting to receivables, payables or inventory is refused; a stock journal cannot be reversed by hand (reverse the stock document); event re-delivery and out-of-order delivery post each journal once.
- **Architecture**: only Finance touches journals, settlements and open items; Finance may not use Inventory code (it learns about stock only from the event).
- **Approvals**: bad journals/payments are refused immediately rather than queued; approved ones post as the requester and are re-checked at execution.

Defects found by the tests and fixed this sprint: a date-variable mix-up that broke every automatic stock journal; reversal guards in the wrong order; a manual-journal rule that blocked the tests' own control-account postings; route defaults arriving in the wrong argument position (same trap as Sprint 3); the balance sheet's earlier-year profit; and module-switch tests that did not know about the new module.

## 3. NOT verified

- **No visual browser check** of the new screens (they are exercised through feature tests in all three languages). The manual-journal form's live balance counter and the allocation inputs are Alpine/HTML that I have not looked at in a browser. Please try *Journals → New manual journal* and *Receivables → Record a receipt*.
- **Sinhala and Tamil strings are drafts** needing native review (key parity with English is tested).
- **Accounting correctness has not been reviewed by an accountant.** The posting rules (which account each stock movement hits) are my reading of standard practice for your chart; please have your accountant read finance.md §3–§4.
- **No VAT / SSCL / NBT computation or tax reports yet**: Finance has the accounts and the posting path for sales invoices, but the tax lines are calculated by Sales (S6) using the tax codes. NBT 0 % / SSCL 2.5 % / VAT 18 % still need to be entered by your accountant on the Tax codes screen (nothing is seeded).
- **No foreign currency**: receipts/payments into the USD account are refused; no exchange gain/loss posting (accounts exist and are mapped).
- **No year-end closing entries, no bank reconciliation, no credit notes, no petty-cash vouchers, no depreciation, no cash-flow statement, no PDF/Excel export** (Reporting, S10).
- **Journals from stock appear within a minute** (when the outbox relay runs), not in the same instant as the stock movement; with the scheduler stopped they wait. The reconciliation does not compare stock to the ledger (Finance cannot read Inventory); the randomised test and the dev check do.
- **No load test**: reports sum all journal lines in SQL each time (fine for SME volumes, no summary tables yet).
- GitHub Actions has still not been seen running by me; the suite now needs ~6½ minutes.

## 4. Decisions for you / deviations from the written plan

1. **Chart corrections** (finance.md §2) are mine; please confirm or tell me to revert any, especially: 2490 as equity, 2214 deactivated, 2205/2203 moved groups, and the nine added accounts (codes 1126, 1340, 1510, 1524–1526, 2206, 2207, 2503).
2. **Posting rules for stock** (finance.md §4): purchases credit **GRNI** until the supplier bill arrives (S7); opening stock credits **Opening balance equity**; sales issues debit **Cost of sales**; samples debit **Marketing**; scrap and counts debit **Stock adjustments**; issue/receipt "production" uses **WIP**. Say if you want different accounts — they are editable under Finance setup.
3. **Your old "periodic" accounts** (2200 OPENING STOCK, 2201 PURCHASES) stay in the chart but nothing posts to them: with perpetual inventory purchases go to inventory, not to an expense. Tell me if you want them hidden.
4. **No journal drafts** (consistent with stock): approval, when wanted, happens before posting.
5. **LKR only** for now (see §3). Your USD bank account is in the chart and active but cannot take receipts/payments yet.
6. **Role defaults**: only `admin` holds Finance permissions. A finance-clerk / accountant / finance-approver permission matrix is still to be designed (same open point as Inventory).
7. **Approval policies** for manual journals and supplier payments exist as gateable actions; none is created for you.
8. Deviations from the plan: AR/AP use one generic open-item/settlement model (not separate invoice/bill tables) — Sales and Procurement keep their own documents; `tax_transactions` is deferred to S6 where tax is calculated; the "balance/closing" of a fiscal year is by calculation, not by posting.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Accounting rules not yet reviewed by an accountant | wrong accounts hit once real documents flow | have the accountant read finance.md §3–§4 before S6 goes live; mappings are editable |
| Stock journals depend on the relay/scheduler | a stopped scheduler delays journals; a parked event means missing journals | alert on parked/old outbox events (S15); `finance:reconcile` plus the stock-vs-ledger check in Reporting (S10) would catch a gap |
| A closed period rolls system journals to today | a late stock movement lands in the current month | by design (never lose a posting); visible in the journal's description/date |
| Manual journals cannot touch receivables/payables/inventory | an accountant needing a rare correction cannot do it by hand | such corrections go through a document (credit note S6, adjustment) — tell me if a controlled override is needed |
| Reports compute from all lines each time | slow with millions of lines | summary tables later; indexes exist |
| Number sequences and period lock are final | a mistake in a locked period cannot be reopened | lock only after the accountant's sign-off; reversal in the next period is the remedy |
| Encrypted bank-account number depends on APP_KEY (as S3) | key loss = unreadable numbers | key management before go-live (S15) |

## 6. Decisions requested before Sprint 6 (Sales)

- Answers to §4 (items 1, 2 and 6 matter most).
- **Sales document flow**: do you want quotations → sales orders → delivery → invoice as in the plan, or is a simpler "order → invoice" enough for the first customer? Do deliveries and invoices happen at different times (so cost of sales posts at delivery and revenue at invoice)?
- **Tax on invoices**: VAT 18 % (inclusive or exclusive prices — S3 default was exclusive), SSCL 2.5 % and NBT 0 % on sales; confirm the **base** for SSCL (the invoice value before VAT?) and whether any customers/items are VAT-exempt.
- **Invoice numbering and layout**: number format (e.g. `INV/2026-27/00001`), and whether you have a printed invoice layout / logo / bank details to reproduce.
- **Payment terms and credit control**: block orders over credit limit, or warn only?
- Re-confirm from earlier sprints: Horizon now or with the Docker image; ClamAV container acceptable. And please tell me whether the GitHub Actions run was green.

## 7. How to try it

The local app is at <http://127.0.0.1:8000> (start with `.\scripts\dev\run.ps1`; the admin demo user holds every Finance permission). Open **Chart of accounts** (balances, click an account for its ledger), **Journals** (two stock journals from the demo stock, one manual telephone-bill journal — try *New manual journal*), **Receivables** (invoice INV-1001 part-paid, INV-1002 not yet due, ageing and the tie-out), **Payables** (BILL-77), **Financial reports** (trial balance, P&L, balance sheet), **Fiscal periods** (close/reopen April), **Finance setup** (account mapping, bank accounts). Commands: `php artisan finance:reconcile`, `php artisan inventory:reconcile`. Seeding a fresh database: `php artisan db:seed --class="App\Modules\Finance\Database\Seeders\FinanceSeeder"` (also part of `db:seed`).
