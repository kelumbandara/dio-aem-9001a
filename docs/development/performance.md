# Performance checks

How speed is checked, what was measured in Sprint 15, and what to do next. Scripts live in `scripts/perf/`. They refuse to run unless the database name contains `perf`, so they cannot touch real data.

## 1. Run it

```
createdb dio_erp_perf                                   # any PostgreSQL 16
$env:DB_DATABASE = 'dio_erp_perf'
php scripts/perf/seed.php --items=2000 --customers=500 --orders=3000     # about 21 minutes on the dev machine
php scripts/perf/measure.php --runs=3                                    # about 3 minutes: times every readable page and API list
php scripts/perf/queries.php /finance/receivables                        # which statements one page repeats, and where from
```

`seed.php` uses the product's own services (orders, deliveries, invoices, stock receipts, then the outbox so Finance posts), so every rule, ledger and reconciliation holds; it ends by running the finance and stock reconciliations and exits non-zero if they find anything. `measure.php` signs in as a super-admin, calls every GET page and API list (using the first real record for pages that need one), and flags a page that is **slow (> 400 ms)**, makes **many queries (> 60)** or **errors**. A query count that grows with the rows shown is an N+1 problem.

## 2. The data set (Sprint 15, 2026-10-13)

2,000 items, 500 customers, 166 suppliers, 3,000 sales invoices (8,971 lines), 18,971 stock ledger rows, 6,200 journal entries (15,400 lines), 3,000 open items, 48,280 audit entries, 21,066 outbox events; 238,469 rows in all; backup 5.6 MB. Finance and stock reconciliations: **no discrepancies**. That is roughly a year of a small trading company; it is not a stress test.

Setup cost on the way: 10,000 opening stock lines (200 receipts of 50 lines) were slow while the machine was busy; a full order→delivery→invoice cycle took about 0.36 s.

## 3. Results

Measured on the development machine (Windows, PHP's built-in web server, PostgreSQL 16 on the same machine, debug off, other programs running), so absolute numbers on a server will differ; the **ratios and query counts** are what matters.

| | First run | After fixes |
|---|---|---|
| Pages measured | 194 | 185 (Horizon's own dashboard excluded: needs Redis) |
| Median / 95th percentile / slowest | 36 ms / 114 ms / 1,374 ms *(quiet machine)* | 80 ms / 241 ms / 685 ms *(busy machine)* |
| Pages with more than 60 queries | 7 (up to 536) | 2 |

Found and fixed:

| Page | Before | After | Cause and fix |
|---|---|---|---|
| New sales order / quotation form | 500–660 ms, 508 queries | ~290 ms, 8 queries | the customer suggestions looked each party up one by one (`PartyDirectory::search`); now one query with relations loaded |
| New purchase order form | 237 ms, 174 queries | ~257 ms, 8 queries | same, for suppliers |
| Invoice page | 527 ms, 531 queries | ~340 ms, 31 queries | same (the page's form options) |
| Receivables / payables / ageing | 1,250 ms, 535 queries | ~685 ms, 13 queries | party names fetched one by one (`SubledgerQueries::names`); new bulk lookups `PartyDirectory::customers()/suppliers()`; and the 3,000 money cells of the ageing table use a plain formatter (`MoneyText`) instead of a Blade component each (rendering cost was larger than the database work) |
| `/horizon` JSON, API errors | n/a | n/a | not part of the daily screens |

Each fix has a test that proves the query count no longer grows with the number of rows (`ContractServicesTest`) and that the formatter gives the same text as the component (`MoneyTextTest`).

## 4. Still flagged (known, constant per page, not growing with the data)

| Page | Now | Why it is left |
|---|---|---|
| Stock list (`/inventory/stock`) | ~270 ms, 83 queries for 25 items | availability is asked per item (3–4 small queries each); constant for a page of 25. A bulk `StockAvailability::levels()` would bring it to ~5 queries; worth doing before the phone app lists stock for hundreds of items |
| Stock API with 100 per page | ~370 ms, 304 queries | same cause, 100 items per page |
| Receivables page | ~685 ms | 500 debtors on one screen (519 KB). Paginating or collapsing the ageing table is the next step if a customer has thousands of debtors |
| Ageing API | ~470 ms | computing the buckets reads every open item (3,000) into memory; a SQL `GROUP BY` would remove that |

## 5. Limits worth knowing

- The sales/purchase forms suggest the first 500 customers/suppliers/items as you type; any code can still be typed in full. Beyond ~500 parties a search-as-you-type picker is needed.
- Nothing here measures many people at once. The concurrency tests (parallel stock issues and settlements) prove correctness under parallel writes, not throughput. Before a customer with many simultaneous users, run a load test on the production image.
- Indexes were not tuned: nothing measured needed it at this volume; re-run the scripts at 5–10× the volume to see.
