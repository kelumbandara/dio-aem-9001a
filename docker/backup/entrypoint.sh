#!/bin/sh
# "cron" (default): schedule backup.sh with BACKUP_CRON (UTC). Anything else is run as given (restore.sh, backup.sh, psql ...).
set -eu
if [ "${1:-cron}" = "cron" ]; then
  umask 077
  # cron does not pass the environment on, so keep it in a root-only file the job reads.
  env | grep -E '^(PG|BACKUP_|AWS_)' | sed "s/'/'\\''/g; s/=\(.*\)/='\1'/; s/^/export /" > /etc/backup.env
  echo "${BACKUP_CRON:-30 19 * * *} . /etc/backup.env; /usr/local/bin/backup.sh >> /proc/1/fd/1 2>&1" > /etc/crontabs/root
  echo "[backup] scheduled: ${BACKUP_CRON:-30 19 * * *} (UTC)"
  exec crond -f -l 8
fi
exec "$@"
