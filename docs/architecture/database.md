# Database Design and Entity Catalog

Default engine: **PostgreSQL 16+** (ADR-0003). MySQL/MariaDB support is a **best-effort
compatibility target**: no PG-only features in migrations unless wrapped in a driver check.

## 1. Conventions

- Primary keys: `bigint` identity for internal tables; public/API ids use `uuid` column `public_id`
  (unique) where exposed to mobile/external.
- Money & quantities: `decimal(18,4)` amounts, `decimal(18,6)` quantities and unit costs
  `decimal(18,6)`. Never float. Rounding policy in Finance settings.
- Timestamps stored in **UTC**; displayed in `Asia/Colombo` (UTC+5:30).
- Every document: `status` (enum, lifecycle), `document_no` (from numbering), `branch_id`,
  `created_by`, `updated_by`, `version` (optimistic concurrency), soft deletes only for master data.
- **Posted** transactional rows are immutable: no UPDATE/DELETE (DB trigger on PG + model guard);
  correction = reversing document linked by `reverses_id`.
- Idempotency: `idempotency_key` unique per (`scope`, key) on creating endpoints and posting actions.
- FK on every relation, `ON DELETE RESTRICT` by default; indexes on all FKs, status, dates.
- CHECK constraints: quantities ≥ 0 where applicable, journal debit XOR credit, etc.

## 2. Entity catalog (by module)

### Core
`company_profile`, `branches`, `departments`, `users`, `employees` (optional `user_id`),
`user_types` (internal/customer/supplier/agent), `roles`, `permissions`, `model_has_roles`,
`role_has_permissions`, `approval_authority_matrix` (see Approvals), `settings` (key, scope, value
json), `currencies` link, `fiscal_settings`, `number_sequences`, `number_sequence_allocations`,
`attachments` (polymorphic, storage path, sha256, mime, size, scan_status), `audit_logs`
(who, what, old/new json, ip, channel, correlation_id), `notifications`, `module_settings`,
`idempotency_keys`, `outbox_events`, `inbox_events`, `failed_jobs`, `jobs`, `personal_access_tokens`,
`mfa_secrets`.

### Approvals
`approval_policies` (subject_type, risk_level, conditions json), `approval_policy_steps` (order,
approver role/user, min/max amount), `approval_requests` (subject polymorphic, payload snapshot,
status, expires_at, requested_by, channel, ai_run_id), `approval_request_steps`,
`approval_decisions` (comment), `approval_delegations`, `approval_executions` (status, result, error).

### MasterData
Built in S3 (columns and rules: [masterdata.md](masterdata.md)): `customers`, `customer_contacts`,
`customer_addresses`, `suppliers`, `supplier_contacts`, `supplier_addresses`, `supplier_bank_accounts` (account
number encrypted), `items`, `item_categories`, `item_uoms` (conversion), `units_of_measure`, `price_lists`,
`price_list_items`, `currencies`, `exchange_rates`, `tax_codes`, `tax_rates` (effective-dated), `payment_terms`.
Not built: `item_variants` (post-MVP). Approval payloads (`approval_requests.payload`) are encrypted text since S3.

### Inventory
Built in S4 (columns and rules: [inventory.md](inventory.md)): `warehouses`, `locations`, `lots`, `stock_documents` +
`stock_document_lines` (one typed table instead of separate transfer/adjustment tables), `stock_ledger` (immutable; signed
qty and value, `reverses_id`), `stock_balances` (CHECK on_hand ≥ 0, always — no "allow negative" flag), `item_costs`
(weighted-average quantity and value per item, the per-item posting lock), `stock_reservations` (reserved is computed,
not stored on balances), `reorder_rules`. Not built: `stock_counts` (post-MVP), `inventory_valuation_layers` (FIFO is post-MVP; average cost is used).

### Finance
Built in S5 (columns and rules: [finance.md](finance.md)): `accounts` (chart: groups and accounts in one tree), `account_mappings`
(the posting rules: key → account), `fiscal_years`, `fiscal_periods` (open/closed/locked; locked is immutable), `journal_entries`
(posted/reversed, kind, source; no drafts), `journal_lines` (debit XOR credit, optional customer/supplier tag; balance enforced by a
deferred trigger), `bank_accounts` (encrypted number), `open_items` (customer invoices and supplier bills, one table with `side`),
`settlements` (receipts and payments) and `settlement_allocations`. Not built: `tax_transactions` (S6, where tax is calculated),
dimensions (branch/department/cost centre) on lines, separate `ar_invoices`/`ap_bills` tables.

### Sales
Built in S6 (columns and rules: [sales.md](sales.md)): `sales_quotations`(+`_lines`), `sales_orders`(+`_lines`: delivered/invoiced quantities and reservation id), `sales_deliveries`(+`_lines`), `sales_invoices`(+`_lines`, `sales_invoice_taxes`), `sales_credit_notes`(+`_lines`, `sales_credit_note_taxes`). Not built: `leads`, `customer_activities`, `discount_rules`. Original sketch:

`leads`, `customer_activities`, `quotations`(+lines), `sales_orders`(+lines), `deliveries`(+lines),
`sales_invoices`(+lines), `sales_returns`(+lines), `discount_rules`.

### Manufacturing
Built in S8 (rules: [manufacturing.md](manufacturing.md)): `work_centers`, `production_lines`, `machines`, `shifts`; `bom_versions` (one per product and version number; partial unique index = one `active` per product), `bom_components`, `bom_operations` (routing); `production_orders` (the six quantities with check constraints), `production_order_materials` (required / issued / returned quantities and values), `production_order_operations`, `production_material_movements` (permanent record of every issue and return). Triggers: an active or retired BOM version and its components/routing cannot change or be deleted, a retired version cannot be revived, material movements cannot be changed or deleted.

### Procurement
Built in S7 (columns and rules: [procurement.md](procurement.md)): `purchase_orders`(+`_lines` with received/returned/billed quantities), `goods_receipts`(+`_lines` with the stock value = the GRNI amount), `supplier_bills`(+`_lines` with GRNI, variance and input VAT parts), `purchase_returns`(+`_lines`, the debit note's parts). Not built: `purchase_requisitions`. Original sketch:

`purchase_requisitions`(+lines), `purchase_orders`(+lines), `goods_receipts`(+lines),
`supplier_invoices`(+lines, match status), `purchase_returns`(+lines).

### Manufacturing
`boms`, `bom_versions` (status, effective dates), `bom_lines`, `routings`, `routing_operations`,
`work_centers`, `machines`, `production_lines`, `shifts`, `production_orders`
(planned_qty), `production_order_operations`, `production_material_requirements`,
`production_material_movements` (issue/return), `production_reports` (reported_qty, accepted_qty,
rejected_qty, rework_qty, `source` manual|device, `device_event_id` nullable, idempotency),
`quality_inspections`(+results), `quality_defect_codes`, `downtime_reasons`, `downtime_events`
(source, start/end), `finished_goods_receipts` (received_qty), `scrap_records`.

### AI
`ai_providers_config`, `ai_agents_config`, `ai_conversations`, `ai_messages`, `ai_runs` (model,
tokens in/out, cost, latency), `ai_tool_calls` (validated input, decision, result), `ai_usage_meter`
(per day/user/agent), `ai_knowledge_documents`, `ai_embeddings` (optional; pgvector later),
`ai_safety_events`.

### Communications
`channel_identities` (user ↔ phone/email/app, verified_at), `channel_preferences` (consent),
`message_templates`, `messages` (direction, channel, status), `message_deliveries`,
`webhook_receipts` (signature ok, raw hash).

### IoT (schema contract only, tables created in IoT phase)
`devices`, `device_credentials`, `device_machine_bindings`, `device_events_raw`, `device_events`,
`machine_states`, `telemetry_points`, `device_health`, `oee_snapshots`.

## 3. Key invariants (testable)

1. Σ debit = Σ credit per posted journal; trial balance always balances.
2. `stock_balances` = Σ `stock_ledger` per key (reconciliation test).
3. Posted document lines never change; reversals reference originals.
4. Production: `accepted + rejected + rework ≤ reported`; `received ≤ accepted`.
5. No posting into a closed/locked fiscal period.
6. Document numbers unique and gap-policy documented (gapless only where legally required).
7. Every approval-executed action stores the approved payload hash; execution revalidates it.

## 3a. Sprint 1 deviations from the catalog above

Implemented in `app/Modules/Core/Database/Migrations` (details in [core-module.md](core-module.md)).

| Catalog said | Implemented | Why |
|---|---|---|
| `user_types` table | `users.user_type` string (`internal`/`customer`/`supplier`/`agent`) | four fixed values, no attributes; a table adds a join for no gain. Only `internal` can sign in today. |
| `number_sequences` + `number_sequence_allocations` | `number_sequences` (definition) + `number_sequence_counters` (one row per period/branch bucket, locked on allocation) | allocation happens under a row lock in the caller's transaction, so no allocation log is needed for correctness |
| `settings (key, scope, value json)` | plus `scope_id` (default 0, not NULL) | keeps the unique index `(key, scope, scope_id)` NULL-free on PostgreSQL |
| `warehouses/locations (shell)` in the S1 backlog | **not created in S1** | Inventory owns them ([modules.md](modules.md)); created in S4 |
| LKR currency seed in the S1 backlog | `company_profile.base_currency = 'LKR'` only | `currencies` belongs to MasterData (S3) |
| `version` on every document | `branches.version` (reference implementation) | pattern proven on the first API resource; documents add it with their tables |
| `notifications`, `attachments` | not created in S1 | roadmap places them in S2 |
| `mfa_secrets` | `secret` and `recovery_codes` use encrypted casts; `last_used_timestep` added for TOTP replay protection | |
| `idempotency_keys` | `(scope, key)` unique, stores request hash and the successful response | scope = `user:<id>` |
| `audit_logs` | append-only enforced by a PostgreSQL trigger (`prevent_row_mutation`) in addition to the model guard; `correlation_id` = `X-Request-Id` | invariant: ledgers are immutable |

`departments`, `employees` and `company_profile` exist with models, factories and audit, but have no
API or screens yet (shell, as in the roadmap). `company_profile` is constrained to a single row (`id = 1`).

## 3b. Sprint 2 additions and deviations

| Catalog said | Implemented | Why |
|---|---|---|
| `notifications` | Laravel's standard table (`uuid` id, `data` holds translation keys + params) | rendered in the reader's language |
| `attachments` (polymorphic, sha256, mime, size, scan_status) | as listed, plus soft deletes; `attachable_type` is the morph **alias**; `path` is unique and random | files are purged later by `attachments:purge` |
| `outbox_events` | + `actor_type`, `actor_id`, `branch_id`, `failed_at` (parked) | envelope fields and the dead-letter state |
| `audit_logs` | **partitioned by month** (PostgreSQL), composite primary key `(id, occurred_at)`, default partition; migration `2026_10_11_000004` copies existing rows and is forward-only | retention without breaking append-only: expired months are dropped as partitions |
| `approval_policies` | + `risk_levels`, amount range, `branch_ids`, `priority`, `expiry_hours`, `reminder_after_hours`, `allow_self_approval`, `distinct_approvers`; soft deletes | conditions and four-eyes rules |
| `approval_policy_steps` (order, approver role/user, min/max amount) | order, role **or** user (CHECK), label; **no per-step amount** | amount limits live in the authority matrix; equal `step_order` = parallel |
| `approval_authority_matrix` | role **or** user (CHECK), `action_key` (`*` = all), `max_amount` (null = unlimited) | evaluated only for actions that have rows |
| `approval_requests` | + `document_no` (numbering `APR/{FY}/{SEQ}`), `policy_snapshot`, `summary`, `amount`, `idempotency_key` (unique per requester), `expires_at`, `last_reminded_at`, `version`; status CHECK | `draft` is not a request state |
| `approval_request_steps` | step status `waiting/pending/approved/rejected/skipped` | |
| `approval_decisions` | append-only (trigger on PostgreSQL), stores `payload_hash`, `on_behalf_of`, channel, IP | |
| `approval_delegations` | time-boxed (CHECK `ends_at > starts_at`, delegator ≠ delegate), optional `action_key`, `revoked_at` | max length `approvals.delegation_max_days` (90) |
| `approval_executions` | one row per attempt, append-only; partial unique index allows one `succeeded` per request (PostgreSQL) | retry history |

## 3c. Sprint 3 and 4 notes

Master Data (S3) and Inventory (S4) tables are described in [masterdata.md](masterdata.md) and [inventory.md](inventory.md).
Both add only new tables; the one change to an existing table is that `approval_requests.payload` became encrypted text (S3).
New immutability triggers (S4): `stock_ledger` and `stock_document_lines` are append-only; `stock_documents` rows may only change `status`/`reversed_by_id`.

## 3d. Sprint 5 notes

Finance adds only new tables. New immutability triggers: `journal_lines` and `settlement_allocations` are append-only; `journal_entries` may only
change `status`/`reversed_by_id`; `settlements` may only change `status`; `fiscal_periods` rows cannot change once `locked`. A deferred constraint
trigger checks every journal balances when the transaction commits.

## 3e. Sprint 6 notes

Sales adds only new tables (`sales_*`) plus, in Finance, `bank_accounts.show_on_invoices` and a relaxed `settlements` rule (`method = 'credit_note'` has no bank
account; every other method must). New immutability triggers: posted invoices, credit notes (with their lines and tax rows) and delivery lines reject UPDATE and
DELETE; deliveries may only change `status`/`reversal_document_id` (no delete). Order lines carry `qty_delivered`/`qty_invoiced` with CHECKs (never above the ordered quantity).

## 3f. Sprint 7 notes

Procurement adds only new tables (`purchase_*`, `goods_*`, `supplier_bill*`). Finance: `settlements` allows method `debit_note` (payable side, no bank account) beside `credit_note` (receivable side).
New immutability triggers: posted supplier bills, their lines, purchase returns and their lines, and goods-receipt lines reject UPDATE and DELETE; receipts may only change
`status`/`reversal_document_id` (no delete). Order lines carry received/returned/billed quantities with CHECKs (returned ≤ received, received − returned ≤ ordered, billed ≤ ordered).

## 4. Migration policy

Forward-only in production; destructive changes (drop column/table) need explicit approval and a
two-release expand/contract plan. Seeders: reference data (currency LKR, UoM, tax codes, roles).
Per-customer rollout and backups are described in deployment.md.
