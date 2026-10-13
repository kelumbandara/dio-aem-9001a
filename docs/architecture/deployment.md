# Deployment and Infrastructure

## 1. Targets

- **Local dev (now):** Windows + XAMPP (Apache/phpMyAdmin) for convenience; app runs with
  `php artisan serve` + Vite. Dev needs **PHP ≥ 8.3** and PostgreSQL (see ADR-0003, ADR-0005):
  the existing XAMPP PHP is 8.2.12 and has no `pgsql` extension, so a second PHP 8.3 install
  (or Docker/WSL2) is required. Until chosen, no code is scaffolded.
- **Production:** Ubuntu server(s), Docker, **Coolify** (git push → build → deploy), one Coolify
  project/environment per customer.

## 2. Per-customer deployment unit

```
Coolify project: <customer>
  app        (Laravel: php-fpm + nginx)  — image from shared codebase, tag = release
  worker     (queue: php artisan horizon)
  scheduler  (php artisan schedule:work)
  postgres   (managed or container, own credentials, volume)
  redis      (own instance)
  storage    (S3-compatible bucket/prefix, own credentials)
  [mqtt]     (IoT phase; EMQX/Mosquitto)
```

Environment per customer: `APP_KEY`, DB creds, Redis, S3, mail, WhatsApp, AI provider key,
`MODULES_PROFILE`, locale/timezone defaults. All secrets in Coolify secret store.

### Processes in Sprint 2

The same image runs three roles: `app` (web), `worker` (`php artisan queue:work redis --tries=3`,
supervised/restarted by Coolify) and `scheduler` (`php artisan schedule:work`). Approvals, the outbox relay,
reminders, expiry, audit partition maintenance and attachment purge all depend on the worker and the scheduler
being up – put alerts on both. Redis is required (queue, cache, rate limits); use `REDIS_CLIENT=phpredis` in the
image if the extension is installed, otherwise `predis`.

**Laravel Horizon (installed after Sprint 14, owner's choice 2026-10-13).** Run `php artisan horizon` as the `worker`
process on Linux (it needs `pcntl`/`posix`, which Windows PHP lacks, so the Windows dev machine keeps using
`queue:work redis --tries=3` through `scripts/dev/run.ps1`; Horizon's own code could not be run there). Settings in
`config/horizon.php` (3 tries, 90 s timeout, up to 10 processes in production); `horizon:snapshot` is scheduled every
5 minutes for the metrics. The dashboard (`/horizon`) is limited by `App\Providers\HorizonServiceProvider` to people
with `core.module.manage` (the administrator); it does not check MFA by itself beyond the normal sign-in.
Horizon needs Redis (`QUEUE_CONNECTION=redis`). After each deploy run `php artisan horizon:terminate` so workers restart.

**Virus scan (ClamAV).** Set `CLAMAV_ENABLED=true` with `CLAMAV_HOST`/`CLAMAV_PORT` (the `clamd` daemon, port 3310; run
it as another container with the `clamav/clamav` image). Files are streamed to the daemon; an infected file is refused
and audited; if the daemon cannot be reached the upload is refused (503, "virus scan not available") instead of being
stored unscanned. With it off, scans are recorded as "skipped".

## 3. Build and release (as built in Sprint 15)

- **One image, four roles.** `Dockerfile` (root): Node stage builds the Vite assets, a PHP stage installs Composer packages without dev packages, the runtime stage is `php:8.3-fpm-alpine` with nginx, supervisor, opcache, `pdo_pgsql`, `bcmath`, `intl`, `gd`, `zip`, `pcntl`, `posix`; runs as `www-data`, no `.env` in the image, ~310 MB. `docker/entrypoint.sh <role>`: `web` (nginx + php-fpm, :8080), `worker` (Horizon), `scheduler` (`schedule:work`), `migrate` (one-shot), `artisan …`.
- **Fail fast.** Every role first runs `php artisan app:preflight`; a production deployment with debug on, no APP_KEY, http APP_URL, insecure cookies, a non-Redis queue/cache, a mail driver that sends nowhere, a remote database without SSL, no HSTS, no declared proxy, or licensing switched off **does not start**. It names the setting, never the value. Weaker-but-working settings (local file storage, no virus scan, no breach check) only warn. The migrate role tolerates pending migrations; a brand-new database is handled.
- **Reference stack** `deploy/compose.production.yaml` (+ `deploy/production.env.example`): web, worker, scheduler, migrate, postgres 16, redis 7 with password, clamav, backup sidecar. The same pieces are created as Coolify resources.
- GitHub Actions (`.github/workflows/ci.yml`): Pint, Larastan, all tests with a PostgreSQL service, composer audit; a second job builds the production image, checks it is non-root, caches routes, and that an unsafe configuration refuses to start. Release tag → image → Coolify deploy **started by a person** (no automatic production deploys).
- Migration strategy: the `migrate` role runs `php artisan migrate --force` before `web`/`worker`/`scheduler` start (compose `depends_on: service_completed_successfully`); additive changes; destructive migrations need the owner's explicit approval.
- Rollback: redeploy the previous image tag; the database only by restoring a backup (down-migrations are not trusted in production). **Back up before every deploy.** Steps: [runbooks/deploy.md](../runbooks/deploy.md).

## 4. Backup and restore (as built in Sprint 15)

- `docker/backup`: nightly (01:00 Sri Lanka time) `pg_dump` custom format → AES-256 (`BACKUP_PASSPHRASE`) → file + SHA-256 → optional S3-compatible copy; retention 7 daily, then about one a week to 4 weeks, one a month to 12 months. `restore.sh` verifies checksum and passphrase before touching anything, refuses to overwrite, removes a half-restored database.
- Attachments: object storage with bucket versioning (provider setting). Redis is not backed up (queues and caches only; the event outbox is in PostgreSQL).
- Runbook and the drill record: [runbooks/backup-restore.md](../runbooks/backup-restore.md). **No go-live without a restored-and-checked backup**; the drill is repeated every quarter.

## 5. Health and monitoring (as built in Sprint 15)

- `GET /up` (process) and `GET /api/v1/health` (live): always 200 while the process answers.
- `GET /api/v1/health/ready`: 503 `{"status":"unavailable"}` when PostgreSQL or Redis does not answer, or — in production — when the **scheduler** or the **queue worker** has shown no sign of life for 5 minutes (each stamps a heartbeat every minute). The answer never says which; the log line does. Point the uptime monitor at it.
- Horizon dashboard `/horizon` for the administrator (`core.module.manage`; Horizon only runs on Linux). Not built: Sentry-compatible error tracking, disk/DB size alerts, AI cost alerts (the in-app AI budgets exist); the vendor sets these up with the hosting provider.
- Operations guide: [runbooks/incident.md](../runbooks/incident.md).

## 6. Environments

local → CI → staging (per customer optional) → production. `.env.example` documents every local variable and `deploy/production.env.example` every production variable; the production configuration is validated at start by `app:preflight` (fail fast).

## 7. Git

Developer's own GitHub account/org; remote to be added later by the owner (credentials are
never stored in the repo or requested by Claude into files). Branching: `main` (protected),
feature branches, PR + CI required.
