# Runbook: when something is wrong

Start with the three questions: **is the site up, is the work flowing, is the data safe?**

## 1. Quick look (2 minutes)

| Look at | Command / place | Healthy |
|---|---|---|
| Is it answering | `curl -s https://<domain>/up` and `/api/v1/health/ready` | 200, `{"status":"ok"}` |
| Containers | Coolify → the project; or `docker compose ps` | all `running`/`healthy` |
| Logs | `docker compose logs --tail=100 web worker scheduler` (log level `warning`) | no repeating errors |
| Queue | `docker compose exec worker php artisan horizon:status`; Horizon dashboard `/horizon` (administrator) | "Horizon is running"; failed jobs not growing |
| Settings | `docker compose exec web php artisan app:preflight` | passes; read the warnings |
| Disk | database volume and backups volume | more than 20% free |

`/api/v1/health/ready` answers 503 when the database or Redis is unreachable, or when the **scheduler** or the **queue worker** has shown no sign of life for 5 minutes. The answer never says which; the web log line "Health check: no sign of life from …" does.

## 2. Symptoms

**Site down / 502 from the proxy** — `web` container stopped or unhealthy: `docker compose logs web`. A failing preflight at start prints `PROBLEM <setting>`; fix the setting and redeploy. For an emergency start while you fix it: `SKIP_PREFLIGHT=true` (remove the same day).

**Approvals do nothing, messages stay "queued", no push, no reminders** — the worker or scheduler is down. `docker compose restart worker scheduler`. If Redis lost its data (rare, `appendonly` is on) pending jobs are gone: the outbox relay re-publishes events that were not yet processed (nothing posted to the ledgers is lost); re-send any message still showing "queued" from Communications → Messages.

**"Licence expired" / read-only / locked** — see Admin → Licence; the vendor issues a new licence (docs/handover/licensing.md). A grace period of 7 days and a read-only period of 14 days come before the lock.

**Everyone is told their password is wrong** — a wrong-password burst locks an account for 15 minutes (10 failures, any address). An administrator can wait it out; no data is at risk. If many accounts are locked at once, look at the audit log for `auth.login_locked` and the source address (possible attack): block it at the proxy.

**A person cannot sign in (lost phone)** — Admin → Users → the person → **Reset authenticator**. They sign in again and enrol a new one.

**E-mail not arriving** — Communications → Messages shows the status and error; check the mailbox quota/limits with the provider and SPF/DKIM. WhatsApp: template not approved, or the 24-hour/consent rule (status `failed` with the provider's reason).

**Uploads refused: "virus scan is not available"** — the `clamav` container is down or still loading signatures (first start takes minutes). Restart it; uploads work again at once. Do not switch the scan off to "get past" it.

**Wrong data entered / a wrong posting** — never edit the database. Ledgers are append-only: correct with a **reversal** document from the screen (journals, stock documents, settlements have a Reverse action with a reason). The audit log shows who did what.

**Data lost or damaged** — [backup-restore.md](backup-restore.md). Restore into a *new* database first and look; decide with the owner before switching.

## 3. Suspected security incident

1. Do not delete anything; note the time.
2. Contain: switch off the affected account (Admin → Users → untick Active; this ends its sign-ins at once) and, if a phone/token is lost, the account's tokens end with it. For a leaked secret rotate it in Coolify and redeploy (`APP_KEY` cannot simply be rotated: ask the vendor first, encrypted data depends on it).
3. Preserve: download the audit log for the period (`audit_logs` is append-only and kept 3 months by default; extend `AUDIT_RETENTION_MONTHS` first if needed) and the proxy/web logs.
4. Tell the owner the same day. Personal data involved (customers, staff NIC, bank details) may need to be reported under the PDPA; the owner decides with their adviser.

## 4. After any incident

Write down: what was seen, when, what was done, what was lost (if anything), and the one change that prevents it next time. Put the change on the backlog.
