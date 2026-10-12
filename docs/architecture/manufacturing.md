# Manufacturing (Sprint 8: planning and material control; Sprint 9: shop floor, quality, finished goods)

**Status:** built in Sprints 8 and 9 and awaiting owner review. The module depends on Core, Approvals, MasterData, Inventory and Finance
(Finance only for the one production-variance journal at completion; every other posting follows from the stock events, as for every other
module). Handovers: [Sprint 8](../handover/sprint-8.md), [Sprint 9](../handover/sprint-9.md).

## 1. Decisions taken for you (change any of them in review)

The owner could not yet answer the Sprint 8 questions, so these defaults were chosen as the safest for a small manufacturer. Each is
isolated enough to change later.

| Question | What was built | Why |
|---|---|---|
| BOM structure | **Multi-level** (a component may itself have a bill), **versioned**, one **active** version per product | Covers both a simple recipe and a garment with sub-assemblies; single-level data fits in the same model |
| Routing | Part of the BOM version (steps with work centre, optional machine, setup minutes once per order, run minutes per unit) | A bill and its routing are always changed together, so they are versioned together |
| Material issue | **Manual issue and return** against a released order (store keeper types the quantity) | Matches how small shops work and gives control; automatic issue when output is reported (backflush) can be added in Sprint 9 without changing these tables |
| Costing | Material leaves stock at the item's **weighted-average cost** into **work in progress** (account 1105); returns go back **at the cost they left at**. Labour and overhead are *not* absorbed yet | Labour/overhead need the actual run times that Sprint 9 records; the work-centre hourly rate is already stored for that |
| Approvals | Activating a bill needs the `manufacturing.bom.activate` permission (admin); no approval chain | Keeps Sprint 8 small; the approval gate can be added exactly as for purchase orders |
| Reservations | Not made on release; **shortages are reported** at release, not blocked | Stock often arrives before it is issued; reserving can follow if you want it |

## 2. Master data in this module

`work_centers` (code, name, capacity hours/day, hourly cost rate in LKR), `production_lines`, `machines` (belong to a work centre, optionally
a line; status available / maintenance / retired), `shifts` (start and end time, an end earlier than the start means the shift ends the next
day; weekdays Monday–Sunday). They are switched off, never deleted. Screens: **Manufacturing → Work centres & machines**; API
`/api/v1/manufacturing/{work-centers|lines|machines|shifts}`.

## 3. Bills of materials

- A version of a product: `base_qty` (what the components make, e.g. 100 pieces), components (item, quantity in any of the item's units,
  scrap % for loss), routing steps. Status **draft** → **active** → **retired**.
- **One draft** per product at a time; **one active** version per product (the database enforces it with a partial unique index).
- **Activate** (permission `manufacturing.bom.activate`): checks the bill again, retires the previously active version in the same step,
  stamps today as its start. **Retire** stops using the active version without replacing it. **Copy to new version** starts a draft from any
  version. Only a draft can be edited or deleted.
- **Immutable history**: database triggers refuse any change to the components or routing of an active/retired version, to its product or
  makes-quantity, deleting it, re-activating a retired one, or taking an active one back to draft.
- **No loops**: a component list that would make a product contain itself (directly or through sub-assemblies' active bills) is refused when
  drafted and checked again when activated (two drafts written before either was active can still form a loop).
- Rules: components are stock items, active, each at most once per version, not the product itself; quantities above zero with at most 6
  decimals; scrap from 0 up to (not including) 100 %; routing steps need an active work centre, a machine (if any) of that work centre and not
  retired; step numbers (10, 20 …) unique.

### What a quantity needs

For `Q` of the product: `component qty × (base units per its unit) × Q ÷ base_qty × (1 + scrap/100)`, worked out exactly (12 decimals) and
rounded half-up once, to the component's base-unit decimals, when it becomes a requirement. Screens show it level by level (**indented BOM**)
and "where used". Sub-assembly components are issued as stock items; if you make them yourself, make their own production order.

## 4. Production orders

`MO/2026-27/00001` (financial year, gapless). Created from the product's **active** bill: the material requirements and routing steps are
worked out and written onto the order (they never change afterwards, even if the bill is replaced; a draft is simply saved again to pick up
the new bill). Statuses: draft → released → in progress (at the first issue) → completed / closed (Sprint 9) or cancelled.

The six quantities of [mes-iot.md](mes-iot.md) exist from day one and are kept apart by database checks: planned, reported, accepted,
rejected, rework, received (`accepted + rejected + rework ≤ reported`, `received ≤ accepted`). Sprint 8 fills in only *planned*.

- **Release** (permission `manufacturing.order.release`): the bill must still be active, work centres/machines/warehouses available; lists
  shortages without refusing.
- **Cancel** needs a reason and is refused while any material is still with the order (return it first) or output was reported.
- Edit/optimistic version: a draft saved from a stale form is refused (`concurrency.stale_version`).

## 5. Material issue and return

- **Issue** (permission `manufacturing.issue.post`) takes stock from the order's source warehouse through the one stock poster
  (`issue` / reason `production`, source `production_issue`): Inventory values it at the average cost, and Finance books **debit 1105 Work in
  progress / credit 1100 Inventory** from the `inventory.stock.moved` event.
- **Limit**: the net quantity with the order may not exceed what it needs plus `MANUFACTURING_OVER_ISSUE_PCT` (default 0). More needs a person
  with `manufacturing.issue.over` **and** a reason; the movement is marked *over requirement*.
- **Return** (reason required) is a `receipt` / reason `production` at the **cost the material left at** (issued value less returned value,
  per unit still held), up to what the order holds; Finance reverses the work-in-progress amount. Lot-tracked items need the lot number on the
  return.
- Every issue and return is a permanent row (`production_material_movements`, change/delete refused by the database) with its stock document.
  A production stock document cannot be reversed from the stock screens or the stock API (the document policy refuses it); use a material return.
- Atomic: the stock document and the order's records commit together or not at all (an item short of stock refuses the whole issue).
  Concurrent issues/returns are serialised by row locks, so the limits hold under parallel use (proved with real parallel processes).

## 6. Permissions and roles

`manufacturing.view`, `.setup.manage`, `.bom.manage`, `.bom.activate`, `.order.manage`, `.order.release`, `.issue.post`, `.issue.over`.
`admin` has all; the new **`production`** role (planner/store keeper) may view, draft BOMs, plan, release, issue and return, but not activate a
bill, over-issue or change setup; the `accountant` can view. Demo user `production@dio.test` in local development.

## 7. API and events

`/api/v1/boms` (list, show, create, update draft, activate, retire, copy, delete draft), `/api/v1/production-orders` (list, show, create,
update draft, release — answers `meta.shortages` —, cancel, `issues`, `returns`), setup lists as above. Writes accept `Idempotency-Key`.
Items, units, warehouses, work centres, machines and lines are given by code; material lines by their line number or item code. Errors use
`manufacturing.*` codes. Events (outbox): `manufacturing.bom.activated`, `.bom.retired`, `manufacturing.order.released`, `.order.cancelled`,
`manufacturing.materials.issued`, `.materials.returned`.

## 8. Sprint 9: output, quality, finished goods, downtime

Owner decisions (2026-10-12): output is **reported per shift**; finished goods are **costed with materials only**; a bill is **activated by an admin only**; **10 % over-production** is right; the cancel permission is enough (no approval); **targets per shift** are wanted.

### Reporting output (operator screen)
- **Manufacturing → Report production** lists released and running orders; one big-button page per order takes the shift (the running one is preselected,
  a night shift after midnight belongs to the date it started), the shift date, units made, rejected, rework, optionally the routing step and machine.
  The same page takes **machine downtime** (machine, reason, time it stopped, minutes) and shows the latest reports.
- Every report and downtime entry **names a shift**; a report may be entered for today's shift or up to `MANUFACTURING_REPORT_BACKDATE_DAYS` (7) days back,
  never for the future. A report says *units made* (good, reject and rework together), *of which rejected*, *of which rework*; the rest wait for inspection.
- **Over-production** is capped at the plan plus `MANUFACTURING_OVER_PRODUCTION_PCT` (10 %).
- Reports are **never edited**. A mistake is cancelled (`manufacturing.output.void`, with a reason) and entered again; the database refuses any other change or
  delete. Cancelling is refused once its units were inspected or reworked. A device or client can send an `external_id`: the same id returns the report already made.
- **Rework**: units sent to rework come back with a *rework done* report and wait for inspection again.

### Quality inspection (permission `manufacturing.qc.inspect`)
Decides, for units waiting (`reported − accepted − rejected − rework`), how many are accepted, rejected or sent to rework; a reason is required for rejects and rework.
Nobody can judge more units than are waiting. The six quantities obey `accepted + rejected + rework ≤ reported` and `received ≤ accepted` (database checks).

### Finished goods and cost (permission `manufacturing.receipt.post`)
Accepted units are received into the order's output warehouse through the stock poster (`receipt` / `production`). **Materials-only costing**: a unit is costed at
*work in progress still held ÷ units still expected*, where the units expected are the larger of "planned − received" and "accepted − received". So good units carry
the material cost of the whole plan, **scrap included**, and what is left over (fewer good units than planned, or rounding) is the order's variance. Lots and expiry
are taken for lot-tracked items. Finance books **debit Inventory / credit Work in progress** from the stock event.

### Completing (permission `manufacturing.order.complete`)
Needs: something reported, nothing waiting for inspection, no units in rework, every accepted unit received. Completion fixes the **variance** (material value still held
by the order) and posts it once as **debit Production variance (2208) / credit Work in progress (1105)** (the reverse if rounding absorbed a little too much),
so work in progress for the order is nil. Then the order accepts no more reports, inspections, issues or returns and cannot be cancelled. Statuses `closed` stays unused.

### Downtime
`downtime_reasons` (code, name, category planned / breakdown / changeover / material / quality / other; managed under *Work centres & machines*). An entry is *machine + shift +
reason + start + minutes*; two entries for one machine cannot overlap (serialised per machine, so parallel entries cannot both win); `external_id` makes a device's entry idempotent.
Also by `started_at` / `ended_at` through the API.

### Shift report
**Manufacturing → Shift report** and `GET /api/v1/shift-report`: for each shift date and shift, units made, good, rejected, rework, back from rework, downtime minutes and
the number of entries; click a row for the individual entries. Cancelled entries are left out.

### Shift targets (owner decision 2026-10-12: targets per shift)
**Manufacturing → Work centres & machines → Shift targets** (and `GET/PUT /api/v1/shift-targets`): for each shift and product, the good units the shift is expected to make
(one target per shift and product; switched off, not deleted; managed with `manufacturing.setup.manage`, everybody with access can read). The **shift report** lists, under each
date and shift, the products made: good units, the target and the **achievement %** (good ÷ target). *Good* = made − rejected − sent to rework. A shift that was due to work that
day (its weekdays), has an active target and reported nothing still appears, with 0 %, so a missing shift cannot hide; days that have not come, days the shift does not work and
switched-off targets add no rows. Periods are limited to 93 days.

### Permissions and roles (Sprint 9)
`manufacturing.output.report`, `.output.void`, `.downtime.report`, `.qc.inspect`, `.receipt.post`, `.order.complete`. `admin` has all. Role `production` (planner / store keeper): report,
downtime, inspect, receive, complete (not cancel a report). New role **`operator`**: view, report output, report downtime only (demo user `operator@dio.test`).

### API and events (Sprint 9)
`POST /api/v1/production-orders/{id}/output | rework-done | inspections | receipts | complete`, `POST /api/v1/production-reports/{id}/void`, `POST /api/v1/downtime-events`
(+ `/{id}/void`), `GET /api/v1/shift-report`, `GET/PUT /api/v1/shift-targets`, setup `downtime-reasons`. Events: `manufacturing.output.reported`, `.output.voided`, `.rework.done`, `.inspection.recorded`,
`.receipt.posted`, `.order.completed`, `.downtime.reported`.

## 9. Not built (next sprint or later)

Labour and overhead in the cost of finished goods (the work-centre rates and the recorded run times exist but are not used); backflush; stock reservation for orders; an
undo for a wrong finished-goods receipt (correct with a stock adjustment); a multi-step inspection plan with measurements; downtime that is still running; OEE; approval chain for bill
activation; capacity planning and scheduling by shift (targets per shift exist, scheduling does not); alternate components; IoT intake (the `source` / `external_event_id` columns are ready).
