# Hosting, licence setting and the phone's sales and purchasing screens — handover

Status: **ready for owner review.** Follows [Sprint 15](sprint-15.md). Server details: [../runbooks/coolify-setup.md](../runbooks/coolify-setup.md). Phone design: [../architecture/mobile.md](../architecture/mobile.md) §2a.

## 1. What was done

**Hosting (2026-10-13, on the owner's Hetzner server 91.99.129.228)**
- Server checked against the requirements (Ubuntu 22.04, 3 vCPU, 3.8 GB RAM, 75 GB disk), updated, rebooted, 4 GB swap, `ufw` firewall; Coolify v4.4.6 installed; key-based SSH set up from the development machine.
- The ERP runs from the Coolify stack file `deploy/compose.coolify.yaml` (web, worker, scheduler, one-off migrate, PostgreSQL 16, Redis, ClamAV, nightly encrypted backup). Live at **https://erp.dio.lk** with the production settings; the Coolify dashboard at **https://coolify.dio.lk**; certificates by Let's Encrypt.
- The owner's static website **https://dio.lk / https://www.dio.lk** runs as a second small Coolify service (Apache, 192 MB cap, files in `/data/sites/dio.lk`), moved from the old host with no downtime; an SFTP-only upload account `dio-site` exists for it.
- Ports 8000, 6001 and 6002 (Coolify's own) are closed to the internet. Found on the way: **Docker bypasses `ufw`**, so deleting firewall rules did not close them; a rule in Docker's own chain, re-applied at every boot by a small service, does (documented).
- Found and fixed on the way: the stack file demanded secrets at build time; the worker and scheduler showed "unhealthy" because the image's built-in health check asks the web port (each now checks itself); two real start-up problems found by the rehearsal.

**Licence checking is off for the vendor's own system** (owner's decision). The set-up needs one setting in Coolify (`LICENSING_ENFORCE` = `false`). Found on the way: an **empty** value used to mean "off" in the licence code, which would have switched licensing off for every future customer whose settings simply left it empty; an empty value now means "the default" (on in production). The start-up check now **warns** (not refuses) when licensing is off.

**Phone app: Sales and Purchasing** (Sprint 13b): Sales tab (quotations, orders, invoices: list, search, detail; new quotation; mark sent / accepted / rejected / cancel) and Purchasing tab (purchase orders: list, search, detail; new purchase order; send for approval, withdraw, cancel with a reason). The app's default server is now `https://erp.dio.lk`. WhatsApp: later (owner's decision).

## 2. Verified results

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan + Pest) | Pint passed; Larastan **no errors**; **1,376 passed** (11,376 assertions), 2,209 s, one clean full run after the last change (tests added: an empty licence setting is the default not "off"; licence-off warns without refusing) |
| Phone app: `npm test` / `npm run typecheck` / `npm run lint` | **72 tests passed** (was 53; +19: line checks, document moves, status colours, where the server's complaints belong, permissions for the new tabs, links); TypeScript no errors; ESLint no problems |
| Phone app bundles | `expo export` for **Android (Hermes)** and **web** both build the whole app |
| Phone app against the real API (local dev server, a sales user and a purchasing user) | **43 of 43 checks passed**: lists, search, details, suggestions, creating quotations and purchase orders, the moves, the approval flow, and the refusals (see below) |
| Hand mutation checks of the phone logic | **13 of 13 caught** (one test gap found and fixed: a purchase-order link with a bad id) |
| ERP on the real server | all containers healthy; `https://erp.dio.lk/api/v1/health/ready` ok; strict start-up check passes (2 warnings: files on the server's disk, no licence key); real ClamAV scan (clean passes, EICAR flagged); real e-mail accepted by Hostinger from the server |
| **Restore drill on the real server** | backup → restore into a scratch database in 3 s → tables and triggers match → the application's own finance/stock checks on the copy: no discrepancies → scratch database dropped (recorded in the backup runbook) |
| Website | HTTPS on both names, `.htaccess` single-page fallback works, public zip moved out of the web folder |
| Closed ports | from the internet only 22, 80 and 443 answer |

What the walk-through proved beyond "it opens": the same request sent twice (a double tap or a lost answer) makes **one** quotation or purchase order; a missing price and a fraction of a piece are refused **with the server's own sentence on the right line**; a draft quotation cannot be accepted, an accepted one cannot go back to sent (409); a sales user cannot make purchase orders and a purchasing user cannot make quotations (403); a large purchase order waits for approval and can be withdrawn, and cancelling needs a reason.

## 3. NOT verified / not done (read this)

- **The new phone screens have not been seen on a phone.** They compile, bundle, lint, are tested for their logic, and their calls were exercised for real, but layout, touch sizes, the keyboard and the look in dark mode are unproven until you try them (Expo Go, `.\scripts\dev\mobile.ps1`; the server address is already filled in).
- **No real data is on the live server yet.** To try the phone's Sales and Purchasing there you first need a warehouse, a customer, an item with a price (and a supplier for purchases) created on the website. I can load a small starting set if you want.
- **Push notifications** still need a built app (Expo account, Firebase key); not part of this step.
- **Creating documents on the phone needs a connection** (by design); there is no offline mode for quotations and purchase orders.
- **Not on the phone:** deliveries, goods receipts, credit notes, turning a quotation into an order, editing a draft, attachments.
- **Licence:** the licence screen and the billing project were not touched; with `LICENSING_ENFORCE=false` there is **no restriction, ever**. That is right for your own system and wrong for a customer's.
- **Backups are on the same server only** (owner's decision); a lost server loses them. The website is not in the backup (it is easy to rebuild from its zip: keep the zip).
- The SSH **root password** is still the one shared in chat and password login is still on (owner will change it later); API tokens shared in chat were revoked by the owner.
- Sinhala/Tamil texts for the new screens are not written yet (they show English).
- The GitHub Actions run for the latest commits has not been looked at.

## 4. Decisions needed

1. Should I load a small **starting set of data** on the live server (one warehouse, a few items with prices, one customer, one supplier) so you can try the phone screens and the whole flow on erp.dio.lk?
2. When do you want to **try the phone app** (Expo Go now, or a built Android file for your own phone later)?
3. Still open from before: change the root password, then I turn off password login for SSH; S3 storage for backups when you are ready; a penetration test before a customer's real data; the billing project and licence keys for the first customer.

## 5. Risks

| Risk | Impact | Handling |
|---|---|---|
| Single server holds the ERP, its backups, Coolify and the website | one failure loses all | off-site backups before any customer data; second server later |
| Server RAM 3.8 GB (1.8 GB used) | slow or killed processes if the website or users grow | watch `free -m`; upgrade to 8 GB at about 80% |
| Phone screens never seen on a device | layout surprises | the 10-minute trial in `docs/handover/sprint-13.md` §4, then tell me what looks wrong |
| Licensing off on the vendor system | none for the vendor; a customer deployed with it off could use the system unpaid | the start-up check warns; keep it off only on your own system |
