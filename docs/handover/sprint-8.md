# Sprint 8 (Manufacturing I) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Rules and decisions: [../architecture/manufacturing.md](../architecture/manufacturing.md).
Also in this stretch: the sidebar is now grouped, searchable and collapsible (committed earlier as "Sidebar: grouped, collapsible sections with page search").

## 1. What was built

New module **Manufacturing** (depends on Core, Approvals, MasterData, Inventory).

- **Setup**: work centres (with capacity and hourly cost rate), production lines, machines, shifts (night shifts allowed). Switched off, never deleted.
- **Bills of materials**: multi-level, versioned (draft → active → retired), with the routing steps in the same version. One active version per product; activating a new one retires the old in the same step; active versions can never be edited (database-enforced). Copy to a new version, indented "everything needed" view, where-used, loop protection.
- **Production orders** (`MO/2026-27/00001`): made from the active bill (materials and routing steps worked out and frozen on the order), release (shortages are shown, not blocked), cancel (reason; not while material is still with the order). The six quantities (planned, reported, accepted, rejected, rework, received) exist with database checks; this sprint fills only *planned*.
- **Material issue and return**: manual, against a released order. Issue takes stock out at average cost into work in progress; return puts it back at the cost it left at; going over the requirement needs a supervisor permission and a reason. Every movement is a permanent record.
- **Screens** (Manufacturing section in the sidebar) and **API** (`/api/v1/boms`, `/production-orders`, `/manufacturing/...`) over the same services; English, Sinhala (draft), Tamil (draft).
- **Role** `production` (planner / store keeper) and demo user `production@dio.test`.
- Inventory: stock movements now report each posted line (item, quantity, value) so callers can record cost; production stock documents cannot be reversed from the stock screens/API (use a material return).

## 2. The defaults I chose for you — please confirm or change

| Question | Built | Easy to change? |
|---|---|---|
| BOM structure | multi-level with versions | structure is general; single-level is just a bill with no sub-assemblies |
| Routing | inside the BOM version | yes |
| Material issue | manual issue/return | backflush (automatic at output) fits on top in Sprint 9 |
| Costing | average cost into work in progress; labour and overhead from work-centre rates come in Sprint 9 | yes |
| Who activates a bill | admin permission (`manufacturing.bom.activate`); an approval chain can be added like purchase orders | yes |
| Stock for an order | shortages reported at release; no reservation | reservation can be added |

## 3. Verified results (dev machine: PHP 8.3, PostgreSQL 16, Redis 7)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **989 passed** (8,381 assertions), 989 s, a clean full run after the last code change, with PostgreSQL and Redis up |
| Manufacturing tests | 82 (BOM 35, order flow 30, screens/API 14, concurrency 3) |

What the tests prove beyond "it runs":

- **BOM**: 19 refusals (self-component, duplicates, service items, bad units, scrap 100 %, wrong machine …); one draft and one active version, old version retired on activation; active/retired versions refused **in the service and by the database** (every change, delete, revive); the database also refuses a second active version; loops refused directly, through two levels, and when two drafts are activated one after the other; a corrupted loop stops the indented view.
- **Quantities**: 25 random bills and quantities agree with an independently worked-out calculation (exact value and rounded value); component units (kg ↔ g) convert; multi-level explosion totals what must be bought; retired sub-assembly bills turn their parent's component into a bought item.
- **Orders**: numbering, frozen materials and minutes, release with shortages, replaced bill refused at release but a released order keeps its own version, switched-off work centre refused, stale edits refused.
- **Material**: stock out/in at the right cost even when the average cost has moved (400 charged, 700 now, 400 back); work in progress (account 1105) equals the order's records; over-issue refused / allowed only with permission and reason / percentage setting; return limits and reason; seven kinds of bad lines post nothing; one short line stops the whole issue and leaves everything as it was; movement records cannot be changed; a production stock document cannot be reversed from the stock side; a random sequence of 60 issues and returns keeps stock, work in progress and the movement records in step and both reconcilers clean.
- **Parallel use** (separate PHP processes): 24 simultaneous issue attempts for 10 needed → exactly 10 succeed; 24 returns of 10 held → exactly 10; 4 simultaneous activations of one bill → exactly 1.
- **Screens/API**: sign-in and permission checks, the planner can draft but not activate, form round trips (blank spare rows ignored), errors on the right field, API with codes and idempotency, setup lists, sidebar visibility, the three languages have the same texts and placeholders.
- **Hand mutation checks**: using the current average instead of the order's cost, disabling the over-issue test, disabling loop detection, not retiring the old version, and removing the row locks were each caught. Removing *either one* of the two row locks (order or material rows) alone is not caught because each is enough by itself; removing both is caught (the race tests fail).

## 4. NOT verified / not built

- **No look at the screens in a browser by me** (tests render and inspect the HTML only); the BOM/routing form rows are Alpine and have not been clicked through by me. Sinhala and Tamil are drafts.
- Output, rejects, rework, downtime, QC, finished-goods receipt, completion, variances, operator screens: **Sprint 9**. Labour/overhead costing, reservations, approval chain for bills, capacity/shift scheduling, alternate components, backflush: not built.
- Lot-tracked components: a return needs the lot number typed in (an issue picks the earliest-expiring lot); this has not been exercised with lot items in Sprint 8 tests beyond the stock module's own lot tests.
- The `production` role and `manufacturing.*` permissions need `php artisan db:seed --class="App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder"` on an existing database (a fresh `migrate:fresh --seed` does it).

## 5. Try it (development)

Open http://127.0.0.1:8000, sign in as admin (or `production@dio.test`; demo passwords are shown once by `php artisan db:seed --class=...DemoStaffSeeder`). Menu → **Manufacturing**. Demo data was added: work centres CUT/SEW/PACK, line L1, machines, two shifts, a "Demo shirt (cotton)" bill with three routing steps, and two orders (one released with fabric and buttons already issued, one draft).

## 6. Decisions for you

1. The defaults in §2. Especially: **who may activate a bill** and whether issuing should be **automatic at output** (backflush) instead of manual.
2. What should a **finished-goods cost** include: materials only, or also labour and overhead from the work-centre hourly rates (needs the run times Sprint 9 records)?
3. Do you want **shifts to matter** (e.g. output reported per shift, shift-wise targets) or only be a list for now?

## 7. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Averages move between issue and return | tiny rounding residue in work in progress (a cent) when everything is returned | accepted; the order's record keeps the true values and a note in the docs |
| A user with stock-adjust rights can still change stock outside an order | work in progress and the order could drift apart if they adjust stock the order issued | adjustments do not touch the order; the reconcilers compare stock to ledger, not to orders |
| Bill changed while orders are draft | draft order uses the old bill until saved again | release refuses a replaced bill with a clear message |
| Alpine form not clicked through by me | a layout or JS detail may need a fix | you try it; I fix what you find |

## 8. Next

Sprint 9 (Manufacturing II): output, reject, rework, downtime entry and reasons, quality inspection, finished-goods receipt with costing, completion and variances, operator-friendly screens.
