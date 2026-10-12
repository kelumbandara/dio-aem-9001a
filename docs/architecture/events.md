# Domain Events and Event Contracts

## 1. Principles

- Events are **past-tense facts** raised after a successful DB commit of the originating action.
- Payloads are versioned DTOs containing ids and the minimum facts needed (not whole models).
- Same-module reactions may be synchronous listeners **inside the transaction** only when they
  must be atomic (e.g. stock ledger + balance). Cross-module reactions are **queued listeners**.
- **Outbox:** events needing reliable handling are written to `outbox_events` in the same
  transaction, then dispatched by a relay job (at-least-once). Listeners must be idempotent.
- **Inbox:** inbound external events (WhatsApp webhooks, device messages) land in `inbox_events`
  with a unique `(source, external_id)` for dedupe, then are processed asynchronously.

## 2. Envelope

```json
{
  "id": "uuid", "type": "sales.invoice.posted", "version": 1,
  "occurred_at": "2026-01-01T10:00:00Z",
  "actor": {"type": "user|system|ai", "id": 12, "ai_run_id": null},
  "correlation_id": "uuid", "idempotency_key": "…",
  "branch_id": 1, "data": { }
}
```

## 3. Event catalog (initial)

| Event | Producer | Consumers |
|---|---|---|
| `core.user.created/deactivated` | Core | AI, Communications |
| `approval.requested/approved/rejected/expired/executed` | Approvals | Notifications, originating module, AI |
| `masterdata.item.changed` | MasterData | Inventory, Reporting cache |
| `inventory.stock.moved` | Inventory (built S4; payload `document_id, document_no, type, item_ids, posting_date`) | Finance (valuation), Reporting |
| `inventory.stock.below_reorder` | Inventory (built S4; once per crossing; payload `item_id, warehouse_id, available, min_qty, reorder_qty`) | Procurement (suggest requisition), Notifications, AI |
| `sales.order.confirmed` | Sales (built S6; payload `order_id, document_no, customer_id, gross_total`) | Notifications, Reporting |
| `sales.delivery.posted` | Sales (built S6; `delivery_id, document_no, order_id, customer_id`) | Reporting (stock and COGS are posted synchronously in the same transaction, not by this event) |
| `sales.invoice.posted` | Sales (built S6; `invoice_id, document_no, customer_id, gross_total`) | Reporting (the receivable and tax journal are posted synchronously through `OpenItemPoster`) |
| `sales.credit_note.posted` | Sales (built S6; `credit_note_id, document_no, invoice_id, customer_id, gross_total`) | Reporting |
| `procurement.order.approved` | Procurement (built S7; `order_id, document_no, supplier_id, gross_total`) | Communications (send to supplier), Reporting |
| `procurement.receipt.posted` | Procurement (built S7; `receipt_id, document_no, order_id, supplier_id`) | Reporting (stock and GRNI are posted synchronously in the same transaction) |
| `procurement.bill.posted` | Procurement (built S7; `bill_id, document_no, supplier_id, gross_total`) | Reporting (the payable journal is posted synchronously through `OpenItemPoster`) |
| `manufacturing.bom.activated` / `.bom.retired` | Manufacturing (built S8; `bom_version_id, item_id, version_no`) | Reporting, AI |
| `manufacturing.order.released` / `.order.cancelled` | Manufacturing (built S8; `order_id, order_no, item_id, planned_qty`) | Reporting, Communications |
| `manufacturing.output.reported` / `.output.voided` / `.rework.done` | Manufacturing (built S9; `order_id, order_no, report_id, shift, produced, rejected, rework`) | Reporting, AI |
| `manufacturing.inspection.recorded` / `.receipt.posted` / `.order.completed` | Manufacturing (built S9; receipts carry `qty, value, stock_document_no`, completion `received_qty, fg_value, variance_value`; the stock and work-in-progress postings come from `inventory.stock.moved`) | Reporting |
| `manufacturing.downtime.reported` | Manufacturing (built S9; `machine, shift, reason, category, minutes`) | Reporting, AI |
| `manufacturing.materials.issued` / `.materials.returned` | Manufacturing (built S8; `order_id, order_no, stock_document_no, value`; the stock and work-in-progress postings come from `inventory.stock.moved`) | Reporting |
| `procurement.return.posted` | Procurement (built S7; `return_id, document_no, order_id, supplier_id, gross_total`) | Reporting |
| `finance.journal.posted` | Finance (built S5; payload `journal_id, journal_no, entry_date, kind, total, source_type, source_id`) | Reporting |
| `finance.period.closed` | Finance | all posting modules (block) |
| `mfg.order.released` | Manufacturing | Inventory (reserve) |
| `mfg.material.issued/returned` | Manufacturing | Inventory, Finance (WIP) |
| `mfg.output.reported` | Manufacturing | Quality, Reporting, AI alerts |
| `mfg.inspection.completed` | Manufacturing | Manufacturing (accepted/rejected qty) |
| `mfg.fg.received` | Manufacturing | Inventory, Finance |
| `mfg.downtime.recorded` | Manufacturing | Reporting, AI, Maintenance |
| `ai.action.proposed/executed/blocked` | AI | Approvals, Audit |
| `comm.message.received/sent/failed` | Communications | AI, Audit |
| `iot.device.event.received` (future) | IoT | Manufacturing (normalised) |

## 4. Reliability rules

- Retries with backoff; poison events go to `failed_jobs` + alert.
- Ordering is guaranteed only per aggregate (`aggregate_id`), never globally.
- Consumers record processed `event.id` to be idempotent.
- Schema changes: add fields compatibly; breaking change = new `version`.

## 5. As implemented (Sprint 2)

The relay exists: `outbox:relay` publishes `outbox_events` rows as `App\Modules\Core\Events\IntegrationEvent`
(Laravel event) with the envelope above. Delivery, retry/backoff, parking, per-aggregate ordering and
concurrent relays are described in [core-module.md](core-module.md) §7. Producers call
`Core\Contracts\EventOutbox::record($type, $data, $aggregateType, $aggregateId, $branchId)` inside their transaction.

Events produced so far: `approval.requested`, `approval.approved`, `approval.rejected`, `approval.cancelled`,
`approval.expired`, `approval.executed`, `approval.execution_failed` (aggregate `approval_request`).
**No module consumes any event yet.** The consumers listed in the catalog arrive with their modules.
Not implemented: a consumer-side processed-event table (listeners must dedupe on `IntegrationEvent::$id`
themselves) and the `inbox_events` processing loop.