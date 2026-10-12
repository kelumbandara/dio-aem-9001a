# Modules, Bounded Contexts and Dependency Rules

## 1. Module list and responsibilities

| # | Module | Bounded context / owns | Phase |
|---|---|---|---|
| 1 | **Core** | Company, branches, departments, users, employees, roles/permissions, settings, numbering, attachments, audit, notifications, module config, idempotency | MVP S1 |
| 2 | **Approvals** | Approval policies, requests, steps, delegation, execution status | MVP S2 |
| 3 | **MasterData** | Customers, suppliers (incl. bank accounts), items, UoM, categories, currencies and exchange rates, tax codes and rates, payment terms, price lists; CSV import ([detail](masterdata.md)) | MVP S3 |
| 4 | **Inventory** | Warehouses/locations, stock ledger, balances, lots, reservations, transfers, adjustments, reorder, weighted-average cost ([detail](inventory.md)) | MVP S4 |
| 5 | **Finance** | Chart of accounts, periods, journals, posting rules, AR, AP, receipts, payments, reports ([detail](finance.md)) | MVP S5 |
| 6 | **Sales** | Quotations, sales orders (credit-limit block, reservation), deliveries, tax invoices (VAT, SSCL/NBT), credit notes and returns ([detail](sales.md)); leads/activities later | MVP S6 |
| 7 | **Procurement** | Purchase orders (approval gate), goods receipts, supplier bills with three-way match, returns and debit notes ([detail](procurement.md)); requisitions later | MVP S7 |
| 8 | **Manufacturing** | BOM, routing, work centers, machines, lines, shifts, production orders, material issue/return, output, rejects, rework, downtime, QC, FG receipt | MVP (after ERP core) |
| 9 | **Reporting** | Deterministic KPI queries, dashboards, exports | MVP (incremental) |
| 10 | **AI** | Providers, orchestrator, agents, tools, conversation, usage, retrieval, safety, execution audit | MVP-lite, grows |
| 11 | **Communications** | Channels (web/mobile chat, email, WhatsApp, voice), identity linkage, templates, delivery | Post-core MVP |
| 12 | **IoT** | Devices, credentials, telemetry, normalisation, OEE | Post-MVP (contracts now) |
| 13 | **HR (light)** | Employee master lives in Core; leave/attendance later | Post-MVP |

Quality inspection lives inside Manufacturing for MVP; may split to a `Quality` module later (ADR).
Maintenance (machine PM/breakdown) is post-MVP but `Machine` is owned by Manufacturing.

## 2. Dependency map (allowed direction: downward only)

```
                       AI   Communications   Reporting
                         \       |        /
        Sales   Procurement   Manufacturing          IoT
            \        |       /      |                 |
             Inventory     Finance                    |
                  \         /                         |
                   MasterData  ----------------------/
                        |
                    Approvals
                        |
                      Core
```

Rules:

1. A module may depend only on modules **below** it, and only via their **Contracts**
   (`Modules/X/Contracts`, `Modules/X/DTOs`, `Modules/X/Events`, plus `Modules/X/Concerns` for reusable model traits).
2. **Events go upward/sideways, calls go downward.** e.g. Sales invoice posting raises
   `SalesInvoicePosted`; Finance listens and creates the journal; Sales never imports Finance.
3. No cross-module foreign keys **except** to Core and MasterData ids (enforced FKs are allowed to
   these). Cross-module links elsewhere use FK where both are in the same dependency chain
   (e.g. `sales_invoice_lines.item_id → items`) and soft references (`source_type/source_id`) for
   upward links (e.g. stock ledger → originating document).
4. No module reads another module's tables directly; use its query contract.
5. AI, Communications, Reporting may *read* via contracts and *act* only via Actions exposed as
   business tools (see ai-agents.md).
6. Enforced by an architecture test (`tests/Architecture`) that scans `use` statements.

## 3. Module internals (convention)

```
app/Modules/Inventory/
  Contracts/        public interfaces used by other modules
  DTOs/ Events/     public
  Models/ Actions/ Services/ Policies/ Listeners/
  Http/{Controllers,Requests,Resources}/  (web + api v1)
  Database/{Migrations,Seeders,Factories}/
  Livewire/ Views/ (resources in module)
  Routes/{web,api}.php
  Tests/
  InventoryServiceProvider.php   registers routes, policies, listeners, permissions, menu
```

## 4. Module activation

`module_settings` table + `config/modules.php` defaults. Each module declares: key, dependencies,
permissions, menu items, settings. Disabling a module hides routes/menu/AI tools and blocks its
Actions; a module cannot be disabled while an enabled module depends on it. Profiles:
`trading` (no Manufacturing/IoT), `manufacturing` (all).

## 5. Key business workflows (summary)

- **Sales:** Quotation → Sales Order (approval if discount/credit exceeds limit) → Reservation →
  Delivery (stock issue) → Invoice (AR + GL + tax) → Receipt → Allocation. Returns reverse.
- **Procurement:** Requisition → Approval chain → PO → Goods Receipt (stock in, GRNI) → Supplier
  Invoice (AP, 3-way match) → Payment. Returns reverse.
- **Inventory:** every change = row in `stock_ledger` (immutable); `stock_balances` is a locked,
  transactionally maintained projection; negative stock blocked unless setting allows.
- **Manufacturing:** BOM(version) → Production Order (planned qty) → Release (material
  requirements, reservation) → Material Issue (stock→WIP) → Output entry (reported) → QC
  (accepted/rejected/rework) → FG Receipt (received; WIP→FG) → Completion (variance).
- **Finance:** modules emit posting events → Finance builds balanced journals via posting rules
  (configurable account mapping) → period control → trial balance/GL/statements.
- **AI action:** user message → orchestrator → tool call → validate → authorise → risk policy →
  execute (low) or create approval (high) → audit.
