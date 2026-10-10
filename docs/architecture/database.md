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
`customers`, `customer_contacts`, `customer_addresses`, `suppliers`, `supplier_contacts`,
`items`, `item_categories`, `item_uoms` (conversion), `units_of_measure`, `item_variants`(post-MVP),
`price_lists`, `price_list_items`, `currencies`, `exchange_rates`, `tax_codes`, `tax_rates`
(effective-dated), `payment_terms`.

### Inventory
`warehouses`, `locations`, `lots` (batch no, expiry, item), `stock_ledger` (immutable: item, location,
lot, qty_in/out, unit_cost, value, source_type/id, posting_date, reverses_id),
`stock_balances` (item, location, lot → on_hand, reserved; CHECK on_hand ≥ 0 unless allowed),
`stock_reservations`, `stock_transfers` (+lines), `stock_adjustments` (+lines),
`stock_counts` (post-MVP), `reorder_rules`, `inventory_valuation_layers` (FIFO/avg cost).

### Finance
`accounts` (chart, type, parent), `fiscal_years`, `fiscal_periods` (open/closed/locked),
`journal_entries` (status draft/posted/reversed, source), `journal_lines` (account, debit, credit,
dimension: branch/department/cost center, partner), `posting_rules` (event → account mapping),
`ar_invoices`/`ap_bills` (or reuse sales/purchase docs with `open_amount`), `receipts`,
`receipt_allocations`, `payments`, `payment_allocations`, `bank_accounts`, `tax_transactions`.

### Sales
`leads`, `customer_activities`, `quotations`(+lines), `sales_orders`(+lines), `deliveries`(+lines),
`sales_invoices`(+lines), `sales_returns`(+lines), `discount_rules`.

### Procurement
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

## 4. Migration policy

Forward-only in production; destructive changes (drop column/table) need explicit approval and a
two-release expand/contract plan. Seeders: reference data (currency LKR, UoM, tax codes, roles).
Per-customer rollout and backups are described in deployment.md.
