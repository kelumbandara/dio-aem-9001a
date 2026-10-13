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
| 2026-10-13 | **the real server** (Coolify, `erp.dio.lk` stack) | dio_erp-20261013T133144Z.dump.enc | 608 KB (freshly seeded: 1 user, 139 accounts, 226 audit rows, 41 migrations) | 3 s | `finance:reconcile` and `inventory:reconcile` logic run on the restored copy: 0 discrepancies; row counts of 8 key tables equal; 45 of 45 triggers present | passed. Restored into a scratch database beside the real one, checked, dropped. Backup kept on the same server only (owner's decision 2026-10-13: off-site copy later) |

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
