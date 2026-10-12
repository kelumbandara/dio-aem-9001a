# Sprint 10 (Reporting) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Design and definitions: [../architecture/reporting.md](../architecture/reporting.md).

## 1. What was built

- **Seven dashboards** under the new sidebar group **Reports → Dashboards**: Owner overview, Sales, Purchasing, Stores, Finance, Production, Quality. Each person sees only the dashboards whose module is on and for which they hold that module's view permission (the owner overview needs the new permission `reporting.executive`, given to admins).
- **Period and comparison** on every dashboard: today … last financial year, or typed dates; compare with the period just before, the same dates last year, or nothing. Arrows show the change (green/red only where a direction is clearly better, always with words too).
- **Charts** drawn on the server (columns for trends, bars for rankings), each with a table view. **Click-through**: customers, items, suppliers, invoices, orders, production orders and stock pages.
- **CSV download** (opens in Excel; numbers stay numbers), **print view**, and the same data over the **API** (`/api/v1/reports`).
- Each module now offers a small read-only **metrics** service (sales, purchasing, stock, finance, production) that the dashboards use — and that the AI in Sprint 11 can reuse.
- Reporting depends only on Core; the other modules are *optional* (new `optional_depends_on` in `config/modules.php`), so a company without Manufacturing simply has no Production/Quality dashboard and can switch modules off freely.

## 2. Verified results (dev machine: PHP 8.3, PostgreSQL 16, Redis 7)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan + Pest) | Pint passed; Larastan **no errors** (725 files); **1,118 passed** (9,488 assertions), 1,133 s, a clean full run after the last code change |
| Sprint 10 tests | 48 in `tests/Feature/Reporting` (metrics 11, periods/KPIs/CSV 18, dashboards/permissions/API 19) |

What the tests prove beyond "it runs":

- **Figures against independent sources**: sales net = ledger income; stock value = inventory account; receivables = invoice less credit note; profit = income − cost of sales; owner overview figures equal the dashboards' own; day series add up to the totals; ends of a range included; empty periods give zeros and no division by zero.
- **Periods**: every preset at a fixed date, financial-year turn (before/after 1 April), Colombo midnight vs UTC, month ends and leap years, comparison windows, nine kinds of bad input refused with a clear code (one — unreadable date text — was a real crash found by the tests and fixed).
- **KPIs**: change in money/count, percentage points for rates, from-zero, loss-to-profit swings, neutral measures never coloured.
- **CSV**: byte-order mark, commas/quotes/line breaks, formula-injection guard (numbers with a minus sign untouched).
- **Access**: signed-out → sign-in; a dashboard outside the person's permissions is 404 on screen, CSV, print and API; module off → missing from lists and the overview; Reporting off → 403; reading writes nothing.
- **Hand mutation checks**, each caught: inverted good/bad, wrong comparison window, injection guard weakened, credit notes added instead of deducted, permission filter removed, profit sign flipped.

## 3. NOT verified / not built

- **I did not look at the screens in a browser.** The chart markup was inspected as text only; please check the look on a phone and when printing. Sinhala/Tamil only cover menu and titles (rest shows English).
- Not built: Maintenance dashboard (no module yet), department/branch filters, `.xlsx` (CSV opens in Excel), scheduled e-mailed reports, custom dashboards, AI explanations (Sprint 11), history for "as at" past dates (open orders, receivables, stock and work in progress are always "as at now").
- A purchase return re-opens its quantity as "still to receive" (same rule the receiving screen uses).

## 4. Try it

http://127.0.0.1:8000 → sidebar **Reports → Dashboards** (admin sees all seven; other demo users only theirs).

## 5. Your answers (2026-10-12)

1. **Admin only** sees the Owner overview (as built; the permission can be given to other roles later).
2. The Owner overview's headline numbers are **right** as built.

Still open from before: purchase-order approval amounts, Horizon vs plain worker, ClamAV, whether GitHub Actions is green, the billing project's GitHub address.

## 6. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Heavy aggregates on very large data | slow dashboards | indexes exist on dates; add summary tables if it shows in the Sprint 15 performance work |
| "As at now" figures cannot be re-run for the past | cannot compare receivables to last month | add nightly snapshots if wanted |
| Local timezone fixed to Colombo | other zones differ | deployment is Sri Lanka only |

## 7. Next

Sprint 11 (AI core): provider, orchestrator, tool registry (read), audit, metering, web chat.
