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
| `inventory.stock.moved` | Inventory | Finance (valuation), Reporting, reorder check |
| `inventory.stock.below_reorder` | Inventory | Procurement (suggest requisition), Notifications, AI |
| `sales.order.confirmed` | Sales | Inventory (reserve), Notifications |
| `sales.delivery.posted` | Sales | Inventory (issue), Finance (COGS) |
| `sales.invoice.posted` | Sales | Finance (AR journal + tax) |
| `sales.return.posted` | Sales | Inventory, Finance |
| `procurement.po.approved` | Procurement | Communications (send to supplier, approval-gated) |
| `procurement.goods.received` | Procurement | Inventory (stock in), Finance (GRNI) |
| `procurement.supplier_invoice.posted` | Procurement | Finance (AP) |
| `finance.journal.posted/reversed` | Finance | Reporting |
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
