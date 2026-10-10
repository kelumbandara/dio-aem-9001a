# MES and Future IoT

## 1. Manual MES (MVP)

Production flow: `Production Order → Release → Material Issue → Output Reports → QC → FG Receipt → Completion`.

### Quantity model (kept separate, never conflated)

| Quantity | Meaning | Written by |
|---|---|---|
| `planned_qty` | Order target | Planner |
| `reported_qty` | Total produced as reported (sum of reports) | Operator/device |
| `accepted_qty` | Passed inspection | QC |
| `rejected_qty` | Failed (scrap) | Operator/QC |
| `rework_qty` | Sent to rework (re-enters flow) | Operator/QC |
| `received_qty` | Actually received into finished-goods stock | FG receipt (inventory posting) |

Invariants: `accepted + rejected + rework ≤ reported`; `received ≤ accepted`; completion
computes variances. Only FG receipt and material issue/return post to Inventory/Finance via
validated services. AI estimates are never stored as actuals.

### Operator UX

Large-touch forms, shift/line/machine preselected, one-screen output + reject + downtime entry,
numeric keypad, barcode/QR for order & item (later), draft-then-submit, offline drafts on mobile.

### Downtime

`downtime_reasons` master (category: planned/breakdown/changeover/material/quality/other),
`downtime_events` (machine, start, end, reason, source manual|device).

## 2. IoT-ready contracts (design now, build later)

Principle: **devices produce events that become the same `production_reports` /
`downtime_events` rows** — no duplicate MES models. Added columns: `source`, `device_event_id`,
`external_event_id` (unique per source for dedupe).

```
Device → MQTT broker → Ingestion service (Laravel command/worker)
       → device_events_raw (immutable) → Normaliser → device_events (typed)
       → Mapper (device ↔ machine ↔ work order context) → Manufacturing Actions
```

- **Topics:** `dio/{customer}/{site}/{device_id}/{state|counter|downtime|health|telemetry}`.
- **Envelope:** `{device_id, seq, ts, type, payload, sig?}`; `seq` + `device_id` is dedupe key.
- **Devices:** ESP32 / PLC gateway / Andon; registration with per-device credentials or X.509
  certs; rotation & revocation; broker ACLs per device topic.
- **Event types:** machine state (run/idle/down/setup), production counter (delta + total),
  downtime start/stop, device health (heartbeat, RSSI, firmware), generic telemetry (stored in
  `telemetry_points`, retention/partition policy).
- **Automatic production reporting:** counter deltas → draft `production_reports` (auto-submit
  configurable per line; operator confirmation default for reject quantities).
- **OEE:** Availability × Performance × Quality computed by deterministic queries from
  machine states, ideal cycle time (routing), and accepted/rejected qty.
- **Safety:** inbound telemetry only. **No machine-control/write-back commands**; andon lights
  are the only outputs and are out of MVP.

## 3. Phasing

MVP: manual MES + schema hooks (`source`, `external_event_id`). Phase IoT-1: broker, device
registry, raw ingestion. IoT-2: state/counter mapping → auto reports. IoT-3: OEE dashboards,
alerts, AI explanations.
