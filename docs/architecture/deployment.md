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

## 3. Build and release

- Multi-stage Dockerfile (composer install --no-dev, Vite build, opcache, non-root user).
- GitHub Actions: lint (Pint), static analysis (Larastan), tests (Pest, PG service), architecture
  tests, composer/npm audit, build image, push to registry. Release tag → Coolify deploy webhook
  (manual approval for production; **no automatic production deploys**).
- Migration strategy: `php artisan migrate --force` on deploy as a pre-start step; expand/contract
  for breaking schema changes; destructive migrations require explicit approval.
- Rollback: redeploy previous image tag; DB rollback only via restoring backup or compensating
  migration (down migrations are not trusted in prod). Take a backup before each migration.

## 4. Backup and restore

- Nightly `pg_dump` (+ WAL archiving where available) to external object storage, encrypted,
  retention e.g. 7 daily / 4 weekly / 12 monthly. Attachments bucket versioned/replicated.
- Restore runbook in `docs/runbooks/restore.md` (written in Sprint 0/1) and a **restore drill
  on a staging copy before any go-live**; record date/result.
- No production go-live without verified backup + rollback.

## 5. Health and monitoring

`/health/live` (process), `/health/ready` (DB, Redis, storage, queue lag) — no secrets in output.
Horizon dashboard (admin + MFA, once installed), failed-job alerts, Sentry-compatible error tracking, uptime
checks, disk/DB size alerts, AI cost alerts.

## 6. Environments

local → CI → staging (per customer optional) → production. `.env.example` documents every
variable; production config validated at boot (fail fast).

## 7. Git

Developer's own GitHub account/org; remote to be added later by the owner (credentials are
never stored in the repo or requested by Claude into files). Branching: `main` (protected),
feature branches, PR + CI required.
