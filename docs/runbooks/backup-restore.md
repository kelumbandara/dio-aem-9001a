# Runbook: backup and restore

**No go-live without a backup that has been restored and checked** (docs/architecture/deployment.md §4). A backup that was never restored is a hope, not a backup.

## 1. What is backed up

| What | How | Where | Kept |
|---|---|---|---|
| The database (everything: ledgers, documents, users, settings, audit log) | `backup.sh`: `pg_dump` custom format → AES-256 encrypted with `BACKUP_PASSPHRASE` → file + SHA-256 checksum | `backups` volume, and off-site S3-compatible bucket (`BACKUP_S3_URI`) | every file for 7 days, then about one per week up to 4 weeks, one per month up to 12 months |
| Attachments and logo | object storage (`ATTACHMENTS_DISK=s3`): turn on **versioning** and replication on the bucket (provider setting) | the bucket | provider policy |
| Settings and secrets | Coolify secret store; **`APP_KEY` and `BACKUP_PASSPHRASE` copies in the owner's/vendor's password manager** | outside the server | — |
| Redis | not backed up: it holds queues, caches, rate limits only; the outbox that guarantees events lives in the database | — | — |

The backup service (`docker/backup`) runs every night at **01:00 Sri Lanka time** (19:30 UTC; `BACKUP_CRON` to change). It needs no application access, only the database credentials.

Dumps are taken while the system is in use: PostgreSQL gives a consistent snapshot, nobody has to stop working.

## 2. Take a backup by hand (before every deploy, before risky work)

```
docker compose exec backup backup.sh
# backup ok: dio_erp-20261013T043218Z.dump.enc (601232 bytes)
```

## 3. Restore (and the drill)

A restore **always goes into a new database first**. Look at it, then decide whether to switch the application to it.

```
# list what there is
docker compose exec backup ls -la /backups
# restore into a new database named restore_20261013 (it must not exist)
docker compose exec backup restore.sh /backups/dio_erp-20261013T043218Z.dump.enc restore_20261013
```

`restore.sh` checks the checksum, checks the passphrase **before** it touches anything, refuses to overwrite an existing database (unless `RESTORE_REPLACE=yes`), and removes a half-restored database if the restore fails.

Then **check the restored copy** (from a throwaway app container, so the live site is untouched):

```
docker run --rm --network <project network> --env-file production.env -e DB_DATABASE=restore_20261013 -e SKIP_PREFLIGHT=true \
   <image> artisan finance:reconcile
docker run … artisan inventory:reconcile
```

Both must report no discrepancies. Also compare with the live database: the number of rows in the key tables (`journal_lines`, `stock_ledger`, `sales_invoices`, `users`, `audit_logs`) and the newest document number of each type.

**To switch the live application to the restored database** (only after the owner agrees; anything entered after the backup is lost): stop `worker` and `scheduler`, set `DB_DATABASE=restore_20261013`, redeploy, verify ([deploy.md](deploy.md) step 6), start `worker` and `scheduler`. Keep the damaged database until the owner says it can be dropped.

### Restore drill — required before go-live and every quarter

Record each drill here (or in the customer's log): date, who, which backup file, time to restore, result of both reconciliations, row-count comparison, problems.

| Date | Environment | Backup | Data size | Restore time | Reconciliations | Result |
|---|---|---|---|---|---|---|
| 2026-10-13 | development machine (Docker, PostgreSQL 16) | see Sprint 15 handover | see Sprint 15 handover | see Sprint 15 handover | see Sprint 15 handover | drill of the scripts on realistic volume (238,469 rows, 5.6 MB, restore 14 s) |
| 2026-10-13 | **the real server** (Coolify stack, then named `erp.dio.lk`) | dio_erp-20261013T133144Z.dump.enc | 608 KB (freshly seeded: 1 user, 139 accounts, 226 audit rows, 41 migrations) | 3 s | `finance:reconcile` and `inventory:reconcile` logic run on the restored copy: 0 discrepancies; row counts of 8 key tables equal; 45 of 45 triggers present | passed. Restored into a scratch database beside the real one, checked, dropped. Backup kept on the same server only (owner's decision 2026-10-13: off-site copy later) |

## 4. When a backup fails

- The job prints `backup ok` or fails loudly; Coolify shows the container's last run. Alert on "no new file in `backups` for 26 hours" (the vendor sets this up with the uptime monitor).
- Common causes: disk full (check the `backups` volume), wrong database credentials after a change, the off-site bucket keys expired (the local file is still written; fix the keys and copy the missing files).
- A backup file with a wrong or lost passphrase **cannot be recovered**. That is why the passphrase is stored outside the server.

## 5. Moving to a new server (disaster recovery)

1. New Coolify project from the same `deploy/compose.production.yaml`; same `APP_KEY` and settings.
2. Start only `postgres` and `redis`; restore the newest backup ([§3](#3-restore-and-the-drill)) into the production database name; check with the reconciliations.
3. Point the object storage settings at the same bucket; deploy the image tag that matches the backup (or newer; migrations run first).
4. Move the DNS name; verify ([deploy.md](deploy.md) step 6).
Expected time: the restore time in the drill table plus about an hour of setup.

## 6. Downloadable copies (Database backups page)

Administrators (`admin`, `super-admin`) can open **Database backups** in the Admin menu to make a copy now, see the saved copies and download one. These are in addition to the nightly encrypted backups above, not a replacement.

- **What they are:** `pg_dump` in PostgreSQL's compressed custom format, **not encrypted**, kept on the server in `storage/app/private/db-backups/` (the `appstorage` volume, which survives deploys), with a `.sha256` checksum beside each file. The newest 8 are kept (`DB_BACKUP_KEEP`).
- **Every Monday at 08:00 (Sri Lanka time)** the scheduler makes a new copy and sends the administrators a notification to download it. A failed copy also notifies them.
- **Audit:** making and downloading a copy are recorded in the audit log (who, which file).
- **Restore a downloaded copy** into a NEW, empty database (never over the live one without a backup first):
  `createdb restore_check` then `pg_restore --no-owner --dbname restore_check dio_db-YYYYMMDDTHHMMSSZ.dump`. Verify with the reconciliations ([§3](#3-restore-and-the-drill)).
- **Handling:** the file holds every customer, price, account and password hash and is readable by anyone who has it. Keep it private, delete old downloads, and do not e-mail it.
- Uploaded files (attachments) are not part of these copies; they are planned for the cloud storage later.
- The image needs `pg_dump` (`postgresql16-client` in the Dockerfile, matching the server); the first deploy after this change rebuilds it.

### 6.1 Restoring a copy from the page (super-admin only)

**Database backups** → **Restore** on a row. Only the super-admin sees the button (permission `core.backup.restore`, held by nobody else). It asks for the word `RESTORE`, the person's own password and a fresh authenticator code.

What happens, in order (`DatabaseRestoreService`):
1. A **safety copy** of what is live now is saved (listed with the badge "Made before a restore", kept: the newest 5).
2. The chosen copy is loaded into a **new scratch database** and checked (it must hold users and the migration history). A copy made by a **newer release** than the running code is refused. The live database is untouched up to here, so any failure here changes nothing.
3. The system goes into maintenance mode, connections are shut out and the two databases **swap names in one transaction**. The previous live database stays whole as `<name>_before_restore_<time>` (the newest two are kept, older ones dropped).
4. Migrations run (an older copy is brought forward), roles/permissions are re-applied, caches are cleared, maintenance ends. If this step fails the swap is **reversed** automatically.
5. Everyone is signed out (the sessions table is part of the database); the audit log of the restored database gets an entry `core.backup_restored` (file, safety copy, who).

**Undo a restore:** restore the "made before a restore" copy. Or, by hand over SSH, rename the kept `<name>_before_restore_<time>` database back (stop web, worker and scheduler first).

**Emergency (web page not usable):** `docker exec <web container> php artisan database:restore` lists the copies; `... database:restore <file> --force` restores one.

**Limits:** it runs while you wait (about a minute for a small database; nginx stops a request after 120 s, so for a database of many gigabytes use the command line). Jobs waiting in Redis at that moment are not rolled back. Uploaded files are not part of the copies.

**Tested for real on 2026-10-14** (throwaway container against a scratch copy of the production database on the Coolify server): restore removes a user added after the copy, keeps the old database, writes the audit entry, ends maintenance; undo brings the user back; a copy containing a migration unknown to the code is refused with nothing changed; a failing load leaves the live database alone. Done with both a PostgreSQL 18 and a 16 client.

**PostgreSQL client version:** the image installs `postgresql16-client` to match the server (PostgreSQL 16). Copies made earlier by a 17/18 client contain `SET transaction_timeout`, which a 16 server rejects; the restore removes only that line, so those copies still load. Raise the client together with the server version.
