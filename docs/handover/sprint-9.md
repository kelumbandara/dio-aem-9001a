# Sprint 9 (Manufacturing II) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Rules and decisions: [../architecture/manufacturing.md](../architecture/manufacturing.md) §8.
Your answers are built in: **output is reported per shift**, **finished goods are costed with materials only**, **only an admin activates a bill**.

## 1. What was built

- **Report production (operator screen)**: pick the order, then one big-button page: shift (the running one is preselected; a night shift after midnight belongs to the date it started), units made, rejected, rework, optional routing step and machine; machine downtime on the same page; latest reports below. Every entry belongs to a **shift**.
- **Rework**: units sent to rework come back with a *reworked* report and wait for inspection again.
- **Cancelling an entry**: reports and downtime are never edited; a supervisor cancels one with a reason (refused once its units were inspected/reworked) and it is entered again. The database refuses every other change or delete.
- **Quality inspection**: the units waiting are accepted, rejected or sent to rework (reason needed for rejects/rework); nobody can judge more than are waiting.
- **Finished goods**: accepted units are received into stock (lots and expiry for lot-tracked items). **Materials-only cost**: each unit carries its share of the material the order consumed, scrap included.
- **Completion**: needs everything judged, reworked and received; fixes the order's **variance** (material cost not absorbed by good output) and posts it once to a new account **2208 Production variance**, so work in progress for the order ends at nil. After that nothing can be reported, inspected, issued or returned.
- **Downtime**: reasons master (breakdown, changeover, waiting for material, planned …), entries by machine/shift/time, no overlaps for one machine.
- **Shift report**: output (made, good, rejected, rework, back from rework) and downtime minutes by shift date and shift; click a row for the entries. Also over the API.
- **Targets per shift** (your answer 3): set how many good units each shift should make of each product; the shift report shows good units against the target with an achievement %, and a shift that was due to work and reported nothing shows 0 %.
- **API** for all of it; new role **`operator`** (view, report output and downtime only) and demo user `operator@dio.test`; `production` gets report/inspect/receive/complete.
- Ready for IoT later: reports and downtime have `source` and a unique `external_event_id`; sending the same id twice records once.

## 2. Verified results (dev machine: PHP 8.3, PostgreSQL 16, Redis 7)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **1,070 passed** (9,030 assertions), 1,082 s, a clean full run after the last code change, with PostgreSQL and Redis up (the check now needs about 23 minutes; its time limit was raised to 50) |
| Sprint 9 tests | 81 (shop floor 48, screens and API 12, shift targets 18, parallel runs 3) on top of Sprint 8's 82 |

What the tests prove beyond "it runs":

- **Shifts**: the running shift and its date at 10:00, 21:30, 02:15 after midnight (belongs to the evening before), 06:00 (none), at a shift's end time and on a day a shift does not work.
- **Output**: quantities add up and are kept apart; ten kinds of bad input recorded nothing; over-production capped at the plan + 10 %; machine in maintenance refused; a device's repeated report recorded once; a step goes "in progress".
- **Rework and cancelling**: rework comes back for inspection; a report is cancelled only while its units are still unjudged (and rework-done reports likewise); the database refuses editing, deleting or re-voiding.
- **Inspection**: cannot judge more than waiting, nothing, or a reject without a reason; records cannot change.
- **Cost and books**: 10 shirts × 810 of material → 4 received at 810 leave 4,860 in work in progress, the rest clears it to nil; with 2 rejected the 8 good ones carry 810 each and the remaining 1,620 becomes the variance (debit 2208, credit 1105); all rejected → all 8,100 written off; a rounding residue of 0.0010 on 3,000 units goes to variance instead of staying in work in progress; stock and the ledger reconcile in every case.
- **Completion**: every precondition refused in turn (nothing made, still to inspect, in rework, accepted not received); afterwards every action on the order is refused.
- **Downtime**: stored in UTC, shown in Colombo time; a night shift's after-midnight hours roll to the next day; overlapping time refused, touching time and other machines fine; seven kinds of bad input refused; cancelled entries free the slot.
- **Shift report**: totals by date and shift exclude cancelled entries; shift filter; entry detail.
- **A random sequence of 80 reports, inspections, reworks, cancellations and receipts** keeps `reported`, `accepted`, `rejected`, `rework`, `received` equal to what the records add up to, finished goods in stock equal to the received quantity, and work in progress plus finished-goods value equal to the material that went in.
- **Parallel use**: 24 simultaneous one-unit reports against an allowance of 11 → exactly 11; 24 simultaneous receipts of 10 passed → exactly 10 (and the value adds up); 4 operators reporting the same stoppage at once → exactly 1.
- **Screens/API**: an operator can report and nothing else (403 on cancel, inspect, receive, complete, issue, create order); the planner can inspect, receive and complete; the full order lifecycle over the screens and over HTTP; errors on the field they belong to; shift report page and API; texts in three languages.
- **Targets**: setting, changing and switching off; seven kinds of bad input; the database's own checks; achievement of 87.5 %, 125 % (only good units count), 75 % (cancelled reports left out), 0 % for a due shift that reported nothing, no rows for days a shift does not work, days not yet come, or switched-off targets; separate targets per shift; screens (manage vs read-only) and API. Four hand mutations (working-day check, counting rework as good, ignoring the on/off switch, rows for future days) were each caught.
- **Hand mutation checks** — each was caught: costing over accepted units only, no variance journal, no overlap check, no over-production cap, cancelling judged reports, completing with unreceived units, night-shift roll-over, and removing the row lock on receipts (the parallel test fails).

## 3. NOT verified / not built

- **No look at the screens in a browser by me**, in particular the touch layout of the operator page on a phone or tablet. Sinhala and Tamil texts are drafts.
- **Labour and overhead are not in the cost** (your decision); the hourly rates and run times are stored for when you want them.
- A **wrong finished-goods receipt cannot be undone** from the stock screens (blocked on purpose so the order stays right); correct it with a stock adjustment and a note.
- Inspection is one decision per batch (no inspection plan with measurements, no photos); downtime is entered after the fact (no running stopwatch); OEE not calculated yet.
- Materials should be issued **before** finished goods are received for the cost per unit to be accurate (a unit is costed on the material issued so far); any difference is caught as variance at completion.
- The running dev server and demo data were added to your development database only.

## 4. Try it (development)

http://127.0.0.1:8000 → **Manufacturing → Report production** (sign in as `operator@dio.test` or admin; demo passwords are printed once by the demo-staff seeder and were given to you in the chat). Demo data: downtime reasons, output for two shifts on the demo order, one inspection, 30 shirts received, one breakdown. Also **Shift report** and the **Manufacturing → Production orders** page of the demo order.

## 5. Your answers to the decisions (2026-10-12)

1. Over-production allowance **10 % is right** (setting `MANUFACTURING_OVER_PRODUCTION_PCT`).
2. **The cancel permission is enough**: no approval step for cancelling a report.
3. **Targets per shift: yes**: built (see §1 and the Shift targets screen).

Still open from before: purchase-order approval amounts, Horizon vs plain worker, ClamAV, whether GitHub Actions is green, the billing project's GitHub address. Next: Sprint 10 (Reporting dashboards).

## 6. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Operators report on the wrong order or shift | wrong shift totals | cancel and re-enter (audited); dashboards later flag odd entries |
| Back-dated entries (up to 7 days) | a closed week's numbers change | lower `MANUFACTURING_REPORT_BACKDATE_DAYS` |
| Unissued material at receipt time | early units under-costed | issue first; variance at completion catches the difference |
| Completion posts to a period that is closed | the variance journal rolls to today (system postings roll forward) | accountant sees it in the activity of the order |

## 7. Next

Sprint 10 (Reporting): role dashboards, KPIs and exports over the data now in place (sales, purchases, stock, finance, production by shift).
