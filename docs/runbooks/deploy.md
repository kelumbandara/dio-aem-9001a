# Runbook: deploy a release (Coolify, one customer)

For the person who deploys. **Production deploys are never automatic**: a person starts every one of them, after a backup (CLAUDE.md, docs/architecture/deployment.md §3).

## What runs

One application image, started in four roles (the entrypoint refuses to start if the settings are unsafe — `php artisan app:preflight`):

| Service | Command | Notes |
|---|---|---|
| `web` | `web` | nginx + php-fpm, port 8080. Coolify's proxy (Traefik) terminates TLS and forwards to it |
| `worker` | `worker` | Laravel Horizon (queue: approvals, messages, push, AI) |
| `scheduler` | `scheduler` | `schedule:work` (outbox relay, approval reminders and expiry, licence check, audit-log upkeep, clean-up of old idempotency keys and attachments, the two heartbeats) |
| `migrate` | `migrate` | one-shot, **before** the others on every deploy |

Around them: `postgres` (16), `redis` (7, password), `clamav` (virus scan), `backup` (nightly encrypted dump). Reference definition: [deploy/compose.production.yaml](../../deploy/compose.production.yaml); every setting: [deploy/production.env.example](../../deploy/production.env.example). Settings and secrets live **only** in Coolify's secret store (never in the repository, never in chat).

## Before the first deploy of a new customer

Follow [first-install.md](first-install.md).

## Every release

1. **Tag the release** on `main` after CI is green (GitHub Actions: Pint, Larastan, all tests, composer/npm audit, image build). Version like `2026.10.1`.
2. **Back up now** (do not skip, even for "small" releases):
   `docker compose exec backup backup.sh` → note the file name it prints. Check it exists off the server too (`BACKUP_S3_URI`).
3. **Build and push the image** (CI does this on a tag; by hand: `docker build -t REGISTRY/dio-erp:2026.10.1 --build-arg RELEASE=2026.10.1 .`).
4. **Announce a short maintenance window** to the customer if the release contains migrations (see the release notes).
5. In Coolify set `APP_IMAGE` to the new tag and **Deploy**. The order is automatic: `migrate` runs first; `web`, `worker`, `scheduler` start only when it succeeded. If `migrate` fails, nothing new starts and the old containers keep running.
6. **Verify** (2 minutes):
   - `https://<customer-url>/up` → 200; `https://<customer-url>/api/v1/health/ready` → `"status":"ok"` for database, redis, storage, queue.
   - Sign in; open the dashboard; open one invoice.
   - `docker compose exec worker php artisan horizon:status` → "Horizon is running".
   - `docker compose exec web php artisan app:preflight` → "Preflight passed" (read the warnings).
   - Failed jobs: Horizon dashboard (`/horizon`, administrator) shows none new.
7. Write the release in the customer's log (date, tag, who, backup file name, result).

## Rollback

*The application* is rolled back by redeploying the previous image tag (`APP_IMAGE=<previous>`): this takes about a minute and loses nothing, **if the release had no migrations** (see "Releases with migrations").

### Releases with migrations

Down-migrations are not trusted in production. Migrations in this product are additive (new tables/columns; destructive changes need the owner's explicit approval and are announced), so the previous image normally still runs on the new schema. If it does not, or if data was damaged:

1. Put the site in maintenance mode: `docker compose exec web php artisan down` (the worker keeps running; stop it with Coolify if jobs would write).
2. Restore the backup taken in step 2 into a **new** database and check it ([backup-restore.md](backup-restore.md) §3), then point `DB_DATABASE` at it and redeploy the previous image tag. Anything entered after the backup is lost: tell the customer the time of the backup.
3. `php artisan up`, verify as in step 6.

## Emergency settings

- `SKIP_PREFLIGHT=true` starts the app even when preflight fails. Only to get a failing site back while fixing the setting; it is logged as a warning at every start. Remove it the same day.
- `php artisan down --secret=<word>` keeps the site closed to everyone except people who open `/<word>`.

## What to watch after a deploy

First 24 hours: failed-job count (Horizon), 5xx rate in the proxy log, `/api/v1/health/ready`, queue lag, disk space of the database volume, backup finished at 01:00 (Sri Lanka time) — see [incident.md](incident.md).
