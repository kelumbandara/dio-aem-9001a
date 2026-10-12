# Reporting (Sprint 10): role dashboards, KPIs, exports

Module key `reporting`, folder `app/Modules/Reporting`. Handover: [Sprint 10](../handover/sprint-10.md).

## 1. What it is

Seven read-only dashboards that answer "how are we doing?" for the person who opens them:

| Dashboard | Opened with | What it shows |
|---|---|---|
| Owner overview (`ceo`) | `reporting.executive` | The few headline numbers of every module that is switched on (net sales, net profit, overdue customers/suppliers, cash, stock value, items below reorder, good units, target achieved, late orders) and the lists that need a decision |
| Sales | `sales.view` | Net sales, invoices, average invoice, credit notes, orders confirmed, quotations, orders still to invoice; best customers and items; largest invoices |
| Purchasing | `procurement.view` | Net purchases, orders approved, value ordered/received, returns, price variance, still to receive; biggest suppliers |
| Stores | `inventory.stock.view` | Stock value (by warehouse), stock in/out, items below reorder level, lots expiring/expired, items holding most value |
| Finance | `finance.report.view` | Income, cost of sales, gross and net profit, receivables/payables with ageing, cash and bank |
| Production | `manufacturing.view` | Good units, target achievement, orders on time, downtime (by reason and machine), late orders, work in progress |
| Quality | `manufacturing.view` | Right-first-time, reject and rework rates, reasons, products that reject most |

Every dashboard has a **period** (today … last financial year, or two typed dates), a **comparison** (the period just before, the same dates last year, or none), a **CSV download** (opens in Excel), a **print view** and the same data over the **API**. Every number that has a document behind it links to it.

## 2. Decisions

| Topic | Decision | Why |
|---|---|---|
| Where the numbers come from | Reporting never reads another module's tables. Each module offers a small read-only **metrics contract** (`SalesMetrics`, `PurchasingMetrics`, `StockMetrics`, `FinanceMetrics`, `ProductionMetrics`) answered with SQL aggregates on its own tables | Keeps the module boundaries (architecture tests) and puts the SQL next to the tables it understands; the AI agents in Sprint 11 can call the same contracts |
| Dependencies | `reporting` **requires only core**; the other modules are `optional_depends_on`: Reporting may import their Contracts/DTOs, but works without them, and a dashboard whose module is off simply disappears (also from the overview) | A trading company without manufacturing must not have to switch Reporting off; the boundary test treats optional modules as allowed references |
| Arithmetic | Decimal strings only (`App\Support\Decimal`, bcmath). SQL sums are `ROUND`ed to 6 places before they reach PHP. Display rounds half-up | No float drift; money matches the ledger to the cent |
| Dates | Colombo calendar days, both ends included; "this month" is to date; comparison = same number of days directly before, or same dates one year earlier (29 Feb → 28 Feb); financial year from `reporting.fiscal_year_start_month` (4 = 1 April); at most 732 days | One rule everywhere; a longer span is a data export, not a dashboard |
| Change figures | Money, counts, quantities: change and percent change (blank when the earlier value was 0). Rates: change in **percentage points** (never a "percent of a percent"). The arrow is green/red only when a direction is clearly better (`higherIsBetter`); neutral measures (stock value, purchases) are never coloured | Honest reading; colour is never the only signal (arrow and words too) |
| Snapshots | Open orders, receivables, stock now, work in progress are "as at now" figures and are labelled so; they have no comparison | They cannot be recomputed for a past date without history tables |
| Charts | Server-drawn **SVG** columns (current period in the brand colour, comparison in grey, nice gridlines, ≤ 12 axis labels, a `<title>` tooltip on every bar) and CSS bars for rankings (one colour, biggest first); a day series becomes weeks above 62 days and months above 400; every chart has a "show as a table" view or a figure list | No script, no external library; prints; readable by screen readers |
| Access | A dashboard is listed and opened only when its module is on **and** the person holds the module's own view permission (404 otherwise, on screen, CSV, print and API alike). The overview needs `reporting.executive`, which only admins get by default | Dashboards must never show a person more than the module's screens do |
| CSV | UTF-8 with byte-order mark, raw numbers (summable), a section per table; text starting `= + - @` tab or CR gets a leading apostrophe so a spreadsheet does not run it (numbers keep their sign) | Spreadsheet-formula injection guard |

## 3. Structure

```
Reporting/
  Services/DashboardRegistry.php        which dashboards exist for whom
  Services/DashboardService.php         request -> period, comparison -> built dashboard
  Services/Dashboards/*Dashboard.php    one builder per dashboard (arranges metrics; no queries of its own)
  Support/Period*.php  Kpi.php          period math; KPI value object (change, trend, good)
  Support/SvgChart.php Format.php       charts; display formatting
  Support/CsvExporter.php               export
  Http/Controllers/{Web,Api}            screens (/reports…) and API (/api/v1/reports…)
```

Metrics live in the owning module: `Sales/Services/DatabaseSalesMetrics`, `Procurement/…DatabasePurchasingMetrics`, `Inventory/…DatabaseStockMetrics`, `Finance/…DatabaseFinanceMetrics`, `Manufacturing/…DatabaseProductionMetrics` (contracts in each module's `Contracts/`).

## 4. Figures and their definitions

- **Net sales** = invoices (net of VAT and levies) − credit notes, by invoice/note date. **Average invoice** = invoiced net ÷ invoices (credit notes not deducted).
- **Net purchases** = supplier bills (net) − debit notes of returns. **Still to receive** = ordered − (received − returned), at the line's net price; a return re-opens its quantity, as it does for receiving.
- **Stock value** = on hand × the item's weighted-average cost; it equals the inventory account in the ledger (tested). **Stock in/out** = value of ledger movements in the period, transfers excluded.
- **Income / cost of sales / profit**: from posted journals; cost of sales uses the `cogs` account mapping.
- **Ageing**: the Finance sub-ledger's own buckets, as at today.
- **Production**: good = made − rejected − rework; **reject rate** = rejected ÷ made; **first-pass yield** = good ÷ made; **target achievement** = good units of shifts that have a target ÷ their targets (a due shift with no output counts 0 %); **orders on time** = completed on or before the planned finish ÷ completed.

## 5. API

`GET /api/v1/reports` lists what the caller may open; `GET /api/v1/reports/{key}?period=&from=&to=&compare=` returns `{data: {key, title, period, compare, kpis[], charts[], tables[]}}`. Errors (`reporting.period_invalid`, `reporting.date_invalid`, `reporting.period_too_long`, `reporting.compare_invalid`) are 422 with the field. Module off → 403.

## 6. Not built

Maintenance dashboard (no Maintenance module yet); per-department/branch filters; Excel `.xlsx` (CSV opens in Excel); scheduled e-mailed reports; saved/custom dashboards; AI-written explanations (Sprint 11); history snapshots for "as at" past dates.
