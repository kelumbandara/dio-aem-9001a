# Pilot plan and go-live checklist

The pilot is the first real company using the system for real work, with the vendor close by. Written for the owner and the vendor team. The dates, the company and the people are the owner's to fill in; this is the shape.

## 1. Scope of the pilot

**In:** the modules the pilot company actually needs on day one — normally Master data, Inventory, Sales, Procurement, Finance, Approvals, Reporting; Manufacturing for a manufacturer; Communications (e-mail only until WhatsApp is bought); the phone app for approvals, shop-floor output and stock look-ups. The AI assistant only if the owner wants it (read-only and drafts; it cannot submit or approve anything).

**Out (not built yet):** HR/payroll, tasks, SMS, barcode scanning, photos on inspections, IoT, marketing campaigns, FrontAccounting import (S5b) — see the roadmap. Sinhala/Tamil wording is draft.

**Length:** 6–8 weeks, with two real month-ends inside it.

## 2. Before the pilot starts (all must be true)

- [ ] Owner has seen the sprint handovers and signed off the decisions that apply (approval amounts, roles, VAT/SSCL/NBT settings, financial year).
- [ ] Production environment installed by [first-install.md](first-install.md); §5 checks all pass; **backup restored once into a scratch database and checked** ([backup-restore.md](backup-restore.md)); the restore date and result written down.
- [ ] Mail works (test message received, SPF/DKIM set); the owner's own address is the first recipient of everything for the first week.
- [ ] Everyone has an account with the least role they need; administrators have an authenticator; the owner knows how to reset one.
- [ ] Opening data loaded and **checked by the accountant**: chart of accounts, opening balances as a posted journal, customers/suppliers with open items, items with opening stock quantities and costs. The trial balance and the stock valuation agree with the books the company keeps today (reconciliation signed by the accountant).
- [ ] Licence installed (or trial dates noted); licence renewal reminder known.
- [ ] Training: one session per role (45 minutes, on their own screens with test data); a one-page "what to do when" for each role.
- [ ] A named **pilot lead** at the company and a named **support contact** at the vendor, with agreed response times (suggest: stop-work problems within 2 hours in working time; others next working day).
- [ ] Old system still available read-only for the whole pilot (parallel run, see §4).

## 3. Roles during the pilot

| Who | Does |
|---|---|
| Pilot lead (company) | Collects issues, decides priorities with the vendor, confirms each week's checkpoint |
| Accountant | Daily/weekly checks (§4), month-end close, signs the reconciliations |
| Department users | Use the system for their real work from the start date; report problems with the screen name and what they expected |
| Vendor support | Answers within the agreed times, deploys fixes (by release, never by editing the database), writes the weekly note |

## 4. Parallel run and daily checks

For the first month the company keeps its old way of working **as the safety net** and compares:

- **Every day:** invoices issued vs the old system; stock counted at the shop floor for 5 fast-moving items vs the system on-hand.
- **Every week:** receivables and payables totals; cash/bank balance; stock valuation; open purchase orders. Differences are explained in writing before the next week (a difference is either a data-entry habit to correct or a defect to report).
- **Month-end:** trial balance, VAT return figures, AR/AP ageing, stock valuation — compared with the old system and checked by the accountant. The system's own reconciliation commands must report **no discrepancies**: `php artisan finance:reconcile` and `php artisan inventory:reconcile` (the vendor runs them weekly and at month-end and sends the result).

## 5. Exit criteria (go / no-go after the pilot)

All of these, written and agreed:

1. Two month-ends closed with trial balance, VAT and stock valuation agreeing with the accountant's own check.
2. No open defect that stops a daily task; no data-integrity defect (wrong posting, lost document) in the last 3 weeks.
3. Reconciliation commands clean on every weekly run.
4. At least 90% of the invoices, receipts, deliveries and goods receipts were entered in the system first (not re-keyed later).
5. A backup was **restored** at least once more during the pilot (not only taken), and the restore time is acceptable to the owner.
6. Users' sign-off per department (they can do their job faster or no slower, and know what to do when something is wrong).
7. The old system can be switched to archive-only.

## 6. Risks to watch (and what to do)

| Risk | Sign | Response |
|---|---|---|
| Opening data wrong | first month-end differs | fix with correcting journals/stock adjustments through the screens (never in the database); find the cause before the next close |
| People still use paper/old system | low "entered first" rate | pilot lead addresses it with the department; make the system step part of the physical process (e.g. print the delivery note from the system) |
| Permission too wide or too narrow | requests for "admin" rights | review roles weekly; add a role rather than giving admin |
| Slow screens at real volume | users complain | the vendor measures (scripts/perf) and fixes; see the S15 handover for the figures we measured |
| E-mail/WhatsApp mistakes | wrong recipients | sensitive messages need finance approval; check consent records; drafts are read by a person before sending |
| Single server failure | site down | [incident.md](incident.md); restore from the latest backup |

## 7. Go-live checklist (the day the old system stops being the primary one)

- [ ] Exit criteria of §5 signed.
- [ ] Final backup taken and restored into a scratch database the same day.
- [ ] Final reconciliations run and attached to the sign-off.
- [ ] Licence and renewal dates confirmed; vendor billing set up.
- [ ] Support contacts and response times confirmed in writing.
- [ ] Monitoring: uptime check on `/api/v1/health/ready`, alert to the vendor and the pilot lead; disk and backup-job alerts on.
- [ ] Old system exported and archived read-only.
- [ ] Owner's written go decision, with date.
