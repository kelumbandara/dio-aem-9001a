# Runbook: first installation for a new customer

One customer = one Coolify project with its own database, Redis, storage bucket and secrets (docs/architecture/deployment.md §2, security.md §7). Nothing is shared between customers.

Tried end to end on 2026-10-13 with the production image and `deploy/compose.production.yaml` on a Linux Docker engine (fresh database → migrations → seed → first administrator → password set from the link → sign-in → authenticator enrolment page). Real e-mail, S3 storage and the customer's domain were **not** part of that trial.

## 1. Prepare (before touching the server)

- Domain name for the customer, e.g. `erp.customer.lk`, pointing at the server; TLS comes from Coolify/Traefik (Let's Encrypt).
- A mailbox for sending (SMTP host, port, user, password) and a test address to receive the test message. Set up **SPF and DKIM** for the sending domain, or mail lands in spam.
- An S3-compatible bucket (private) and its keys, for attachments and the logo; another bucket/prefix for backups.
- The customer's **licence public key** and vendor billing URL/token (from the vendor billing project), unless the customer is on the free trial.
- Decide the passphrase for backups (`BACKUP_PASSPHRASE`). **Store a copy somewhere that is not this server** (password manager). Without it a backup cannot be read.

## 2. Create the resources in Coolify

1. New project `<customer>` → add the services of [deploy/compose.production.yaml](../../deploy/compose.production.yaml) (or deploy the file as a Docker Compose resource): `postgres`, `redis`, `clamav`, `web`, `worker`, `scheduler`, `migrate`, `backup`.
2. Fill every variable of [deploy/production.env.example](../../deploy/production.env.example) into Coolify's secret store. Generate `APP_KEY` once with `docker run --rm <image> artisan key:generate --show` and **keep a copy**: encrypted fields (NIC, MFA secrets) cannot be read without it.
3. Domain on the `web` service (port 8080), HTTPS on, forced redirect to https.
4. Deploy. `migrate` creates the schema; `web`, `worker`, `scheduler` start after it. If the preflight refuses to start, its message names the setting.

## 3. Load the starting data (once)

```
docker compose exec web php artisan db:seed --force        # roles, permissions, units, VAT 18%, chart of accounts, fiscal year (1 Apr–31 Mar), approval rules, message templates
docker compose exec web php artisan app:create-admin owner@customer.lk "Owner Name" --print-link
```

The second command prints a one-time link (valid 3 hours). Hand it to the owner privately (not in a group chat). They choose their own password (12+ characters) and are then asked to set up an authenticator app (admin accounts require it). If mail is already working, leave out `--print-link` and the link is e-mailed.

For the vendor's own access create a second account with `--role=super-admin`; it can reach the vendor-only options (appearance "Powered by", licence).

## 4. Set up the company (the owner, in the browser)

1. **Admin → Company details**: legal name, tax number (VAT/TIN), address; **Appearance**: logo, colours, document footer.
2. **Admin → Users**: add each person with their role(s); each gets an e-mail with a link to choose a password. Give people the least they need (docs/architecture/security.md §1).
3. **Approvals → Policies**: check amounts and approvers. Starting rules: purchase orders need one purchasing approval, and from LKR 100,000 also finance; sensitive messages need finance.
4. **Finance**: confirm the chart of accounts, VAT, the open fiscal year and bank accounts.
5. **Master data**: warehouses, items, customers, suppliers (import or enter).

## 5. Prove it works (15 minutes)

| Check | How | Expected |
|---|---|---|
| Health | `https://<domain>/api/v1/health/ready` | `{"status":"ok"}` (after the first 5 minutes the scheduler and worker must have shown life, see incident.md) |
| Headers | browser dev tools or `curl -I https://<domain>/login` | `Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options: DENY` |
| E-mail | Communications → Messages → a draft to your own address → send | arrives (not in spam) |
| Virus scan | upload the harmless EICAR test file to any attachment | refused as infected; a normal PDF is accepted |
| Backup | `docker compose exec backup backup.sh` | `backup ok: …`; the file appears in the bucket; **restore it once** into a scratch database ([backup-restore.md](backup-restore.md)) |
| Queue | approve something that needs an approval | the action runs within seconds (worker) |
| Restart | restart the whole project | everything comes back; nobody is signed out unexpectedly beyond session expiry |

## 6. Turn on later, when the customer is ready

- **WhatsApp**: Meta Business account, a number, approved templates → `WHATSAPP_*` settings; mark each template approved in Communications → Templates.
- **AI assistant**: `ANTHROPIC_API_KEY`; budgets (USD 5 per person, USD 50 for the company per month) are the defaults.
- **Mobile app**: needs the https address; a private Android install is enough (docs/architecture/mobile.md §4a).

Record in the customer's log: date, image tag, who did it, the backup passphrase location (not the passphrase), and the result of §5.
