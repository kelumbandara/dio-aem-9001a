# Development Roadmap (phase by phase)

Each sprint ends with: working code, passing tests, docs updated, handover summary, risk list.
We stop for owner review at every sprint boundary.

## MVP / post-MVP boundary

**MVP:** Core, Approvals, MasterData, Inventory, Finance (core), Sales, Procurement, Manual
Manufacturing, Reporting dashboards, AI (read + draft agents), web UI, mobile (approvals,
production/downtime entry, AI chat), email + WhatsApp basics.

**Post-MVP:** IoT/MQTT, OEE live, voice, statutory tax returns, payroll/HR depth, advanced
costing (FIFO layers, standard cost variance), MRP planning, customer/supplier portals,
bank reconciliation import, Maintenance module, pgvector RAG.

## Phases

| Phase | Content |
|---|---|
| **0 Architecture** (now) | These docs; owner review; env setup (PHP 8.3, PostgreSQL) |
| **S1 Foundation** (done, pending review: [handover](handover/sprint-1.md)) | Laravel 13 scaffold, module skeleton + arch tests, auth (Sanctum, MFA hooks), company/branches/departments/warehouses shell, users vs employees, roles/permissions, settings, numbering, audit log, module toggles, API v1 base + error format, i18n (en/si/ta) shell, Blade layout/components, Pest + CI |
| **S2 Approvals + Notifications + Attachments** (done, pending review: [handover](handover/sprint-2.md)) | Approval engine, authority matrix, notifications, secure uploads, queue/Horizon, scheduler |
| **S3 Master Data** (done: [handover](handover/sprint-3.md)) | Items, UoM, categories, customers, suppliers, currencies, tax codes (LKR/VAT/SSCL/NBT seed), price lists |
| **S4 Inventory** (done: [handover](handover/sprint-4.md)) | Ledger, balances, lots, receive/issue, transfers, adjustments, reservations, reorder, negative-stock guard + reconciliation tests |
| **S5 Finance** (done, pending review: [handover](handover/sprint-5.md)) | CoA, periods, journals, posting rules, AR/AP, receipts, payments, TB, GL, statements + balance tests |
| **S6 Sales** (done, pending review: [handover](handover/sprint-6.md)) | Quotation → SO (credit-limit block, reservation) → delivery → tax invoice (VAT, SSCL/NBT) → credit notes/returns. Leads/CRM activities deferred |
| **S7 Procurement** (done, pending review: [handover](handover/sprint-7.md)) | PO (approval gate) → goods receipt → supplier bill (three-way match, price variance) → returns + debit notes. Requisitions deferred |
| **S7b Branding** (done, pending review: [handover](handover/branding.md)) | Appearance: company name and logo, colours, font and size, corners, document footer, vendor-only “Powered by”; applied to all screens and printed documents |
| **S7c Licensing** (part 1 done, pending review: [handover](handover/licensing.md)) | Signed monthly licence in the product, grace → read-only → lock after non-payment, licence generator; the separate vendor billing project is part 2: **built, pending review** ([design](architecture/billing.md), [handover](handover/vendor-billing.md)) |
| **S8 Manufacturing I** (done, pending review: [handover](handover/sprint-8.md)) | BOM versions, routing, work centers, machines, shifts, production orders, material issue/return |
| **S9 Manufacturing II** (done, pending review: [handover](handover/sprint-9.md)) | Output/reject/rework/downtime entry per shift, QC, FG receipt (materials-only cost), completion with variance, operator UI |
| **S10 Reporting** (done, pending review: [handover](handover/sprint-10.md)) | Seven role dashboards (owner, sales, purchasing, stores, finance, production, quality) with period comparison, drill-down, CSV, print and API |
| **S11 AI Core** (done, pending review: [handover](handover/sprint-11.md)) | Claude provider, run loop, 20 read tools, input checks, injection boundary, immutable audit, metering and budgets, web chat and API |
| **S12 AI Agents** (done, pending review: [handover](handover/sprint-12.md)) | 7 department agents (Maintenance/HR/Customer-service wait for their modules and channels), lookups, draft quotation and purchase order, safety suite; approvals integration deliberately not needed (the assistant never submits) |
| **S13 Mobile** (done, pending review: [handover](handover/sprint-13.md)) | Expo/React Native app: sign-in with MFA, approvals, production output and downtime (works offline), inspection, stock, AI assistant, notifications and push; server additions for devices, shift and settings |
| **S14 Communications** ✅ | Email, WhatsApp, templates, consent, identity linking (built; awaiting owner review) |
| **S15 Hardening & Release** (done, pending review: [handover](handover/sprint-15.md)) | Security review and fixes, user administration, production image, preflight, Horizon + ClamAV verified on Linux, backup/restore drill, performance at volume, runbooks, pilot plan |
| **Post-MVP** | IoT phases, voice, etc. |

### Added by ADR-0005 (internal-first, replace existing tools)

| Phase | Content |
|---|---|
| **S5b FrontAccounting migration** | Import CoA, parties, opening balances, open AR/AP; parallel run + reconciliation report |
| **S14b SMS channel** | SMS gateway adapter inside Communications |
| **S16 Tasks module** | Lightweight Jira replacement linked to ERP documents |
| **S17 HR + Payroll** | Attendance, leave, EPF/ETF/APIT, payslips, GL posting |

Order note: AI Core may be pulled forward (read-only) after S3 to deliver visible value early —
owner's call at review.

## Sprint 1 backlog (detailed)

1. Install/verify PHP 8.3, PostgreSQL, Composer, Node; `.env` for local. *(done)*
2. `composer create-project laravel/laravel` in repo root; Pest, Pint, Larastan, Sanctum, permission pkg.
3. Module loader + `ModuleServiceProvider` base + `config/modules.php` + arch test.
4. Core migrations: company_profile, branches, departments, warehouses/locations (shell), users,
   employees, roles/permissions, settings, number_sequences, audit_logs, module_settings,
   idempotency_keys, outbox/inbox.
5. Auth: login/logout web, Sanctum token API, throttling, MFA (TOTP) scaffold for privileged roles.
6. Services: NumberingService (concurrency-safe), SettingsService, AuditLogger (model observer +
   explicit), ModuleRegistry.
7. API v1 base: error handler, resources, pagination, idempotency middleware, health endpoints.
8. Blade layout: sidebar/nav driven by module registry + permissions, language switcher, KPI card,
   responsive table, form components, modal, status badge.
9. Seeders: roles, permissions, LKR currency, default company profile, demo admin (dev only).
10. Tests: auth, permission, numbering concurrency, audit, module toggle, arch, API contract.
11. Docs: update architecture docs, developer setup, handover summary.
> Sprint 1 delivered all items; deviations (warehouses, LKR currency seed, `user_types`) are listed in [handover/sprint-1.md](handover/sprint-1.md) §4.
