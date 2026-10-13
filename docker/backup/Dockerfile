# Backup sidecar: pg_dump + openssl + aws-cli and the two scripts. Build from the repository root:
#   docker build -f docker/backup/Dockerfile -t dio-erp-backup:<release> .
# Runs backup.sh every night (BACKUP_CRON, UTC; default 19:30 UTC = 01:00 in Sri Lanka) or any command you give it:
#   docker run --rm --env-file backup.env -v backups:/backups dio-erp-backup restore.sh /backups/<file> <new-database>
FROM postgres:16-alpine
RUN apk add --no-cache openssl aws-cli tzdata coreutils
COPY docker/backup/backup.sh docker/backup/restore.sh docker/backup/entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["cron"]
