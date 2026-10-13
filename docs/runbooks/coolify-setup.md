# Runbook: the Coolify server (what exists and how it was set up)

Server for the rehearsal and the first pilot. **No passwords, keys or tokens are written here** (they live in Coolify's secret store and in the owner's password manager).

## 1. The server

| | |
|---|---|
| Address | 91.99.129.228 (Hetzner Cloud, KVM). Names: `erp.dio.lk` (the ERP), `coolify.dio.lk` (the Coolify dashboard), `dio.lk` and `www.dio.lk` (the website); all with Let's Encrypt certificates issued by Coolify's proxy |
| System | Ubuntu 22.04.5 LTS, 3 vCPU, 3.8 GB RAM + 4 GB swap (swappiness 10), 75 GB disk |
| Set up on 2026-10-13 | security updates + reboot; swap file; `ufw` active (open from outside: **22, 80, 443 only**); Coolify v4.4.6 installed with its official installer (installs Docker 29); the dashboard has its own HTTPS address and ports 8000/6001/6002 are closed (see §7) |
| Access | SSH as root with a key created on the vendor's development machine (`~/.ssh/dio_server_ed25519`, never in the repository). **Password login is still enabled** until the owner agrees to turn it off (then: set `PasswordAuthentication no` in `/etc/ssh/sshd_config.d/50-cloud-init.conf`, restart `ssh`) |
| Outbound checks | SMTP 465/587 (Hostinger), the AI provider, the breached-password service and WhatsApp's servers are all reachable (some providers block them) |

Memory with the whole stack running (web, worker, scheduler, PostgreSQL, Redis, ClamAV, backup) plus Coolify: about **1.8 GB of 3.8 GB**. Comfortable for a rehearsal and a small pilot; watch it (`free -m`) and upgrade to 8 GB if it passes about 80% in normal use or when more than a handful of people work at once.

## 2. What is in Coolify

Project **DIO ERP (rehearsal)** → environment `production` → application **dio-erp** (type Docker Compose, public GitHub repository `kelumbandara/dio-aem-9001a`, branch `main`, file `/deploy/compose.coolify.yaml`). It builds the images on the server and runs: `web`, `worker`, `scheduler`, `migrate` (one-shot), `postgres`, `redis`, `clamav`, `backup`. The `web` service has the domain; all settings are entered on the application's *Environment variables* screen (list: `deploy/production.env.example`; the file `compose.coolify.yaml` names every one).

**Settings are runtime-only** (not build-time): Coolify reads the stack file at build time, and secrets are deliberately kept out of image builds. That is why `compose.coolify.yaml` does not use `${VAR:?}`: a missing value is caught at start by `app:preflight` and by PostgreSQL itself.

## 3. Rehearsal settings to change when the real domain is ready

The first deployment runs over plain HTTP on `http://erp.91.99.129.228.sslip.io` so the stack could be tested before `dio.lk` exists. These are the only deliberate departures from production; change them together:

| Variable | Rehearsal | Production |
|---|---|---|
| `APP_URL` | `http://erp.91.99.129.228.sslip.io` | `https://erp.dio.lk` (or the chosen name) |
| Domain on the `web` service | `http://erp.91.99.129.228.sslip.io` | `https://erp.dio.lk` (Coolify issues the certificate by itself once DNS points at the server) |
| `SESSION_SECURE_COOKIE` | `false` | `true` |
| `HSTS_SECONDS` | `0` | `31536000` |
| `MAIL_MAILER` + `MAIL_*` | `log` (nothing is sent) | `smtp` with the mailbox settings |
| `SKIP_PREFLIGHT` | `true` | remove (or `false`) |
| `ATTACHMENTS_DISK` | `attachments` (a volume on this server) | `s3` + bucket keys |

After changing them: close port 8000 in `ufw` once Coolify itself is reachable over its own HTTPS name (Settings → Instance's domain), then `ufw delete allow 8000/tcp`.

## 4. Day-to-day

- **Deploy** (after pushing to `main`): Coolify → the application → *Deploy*; or by API with a token that has the `deploy` permission. Follow [deploy.md](deploy.md): **a backup first** (`docker exec <backup container> backup.sh`).
- **Seed once, first admin once** (done on 2026-10-13): `docker exec <web container> php artisan db:seed --force` and `... app:create-admin <email> "<name>" --print-link`.
- **Artisan commands** on the server: `docker exec <container> php artisan …` (container names carry the application id; `docker ps` lists them).
- **Backups** run every night at 01:00 Sri Lanka time inside the `backup` container (volume `backups`). The off-site copy (S3) is **not set up yet**; until it is, the only copies are on this same server: do not treat that as a backup.
- **Coolify's own settings** are stored in `/data/coolify/source/.env` on the server; **keep a copy outside the server** (password manager).
- **Tokens:** the API tokens created for the setup should be revoked in Coolify (Keys & Tokens → API tokens) when the setup work is done.

## 5. First rehearsal results (2026-10-13)

Build and start from an empty database: succeeded (about 4 minutes on this server); all 41 migrations ran; seed and first administrator created (password to be chosen by the owner through the one-time link); `GET /api/v1/health/ready` → ok; Horizon running; **ClamAV: clean file passes, EICAR test file flagged infected**; security headers present on the real address; first encrypted backup written and checksummed; `app:preflight --strict` reports only the rehearsal departures listed in §3 (plus the local file storage and missing licence-key warnings).

Not yet done on this server: a restore drill, the S3 backup copy, real e-mail, HTTPS, the domain, a load test.

## 6. The website `dio.lk` (static)

Coolify service **dio.lk website** (compose given inline, image `php:8.3-apache`, 192 MB / 0.5 CPU cap, `.htaccess` and `mod_rewrite` enabled, Apache version hidden, security headers). The files are in **`/data/sites/dio.lk/`** on the server, mounted read-only into the container; the site is a single-page application (its `.htaccess` sends unknown paths to `index.html`). Domains `https://dio.lk` and `https://www.dio.lk`. Memory use at rest: about 30 MB.

**Updating the site:** copy the new files into `/data/sites/dio.lk/` with SFTP (WinSCP). Two accounts can do it: `root` (everything) and **`dio-site`**, an SFTP-only account locked into `/data/sites` (it can write only `dio.lk` and `incoming`; it has no shell; its password is known to the owner only). Zip files go in `/data/sites/incoming/` (not served), never into `dio.lk` (anything there is public). The `dio-site` account is configured by a `Match User dio-site` block at the end of `/etc/ssh/sshd_config` (backup: `sshd_config.bak-before-dio-site`).

Not backed up automatically (the nightly backup covers the ERP database only). The site is easy to rebuild from its source zip: keep that zip outside the server.

## 7. Closed ports and the Docker firewall catch

`ufw` alone does **not** protect ports that Docker publishes (Docker adds its own rules ahead of it): deleting the `ufw` rules for 8000/6001/6002 left them open to the internet until a rule was added in Docker's `DOCKER-USER` chain. The script `/usr/local/sbin/dio-docker-firewall.sh`, run at boot by the systemd unit `dio-docker-firewall.service` (after Docker), drops outside traffic to 8000 (dashboard by IP), 6001 and 6002 (realtime/terminal; Coolify reaches them through its proxy on `https://coolify.dio.lk`). Verified from outside: 80 and 443 and 22 open; 8000, 6001, 6002, 8080, 5432, 6379, 3310 closed. **Any new container that publishes a port is reachable from the internet regardless of `ufw`**: check with a port test after each new deployment, and prefer Coolify domains (the proxy) over published ports.
