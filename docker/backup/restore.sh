#!/bin/sh
# Restores an encrypted backup made by backup.sh into a database (docs/runbooks/backup-restore.md).
#
# Usage:  restore.sh <backup-file.dump.enc> <target-database>
#
# SAFETY: the target database must NOT exist (this script creates it) unless RESTORE_REPLACE=yes is set, in which case it is
# dropped first. Restoring over a live production database is a decision for a person, never a default.
#
# Environment:  PGHOST PGPORT PGUSER PGPASSWORD (the server to restore INTO), BACKUP_PASSPHRASE.
set -eu

file="${1:?usage: restore.sh <backup-file.dump.enc> <target-database>}"
target="${2:?usage: restore.sh <backup-file.dump.enc> <target-database>}"
: "${BACKUP_PASSPHRASE:?BACKUP_PASSPHRASE is required}"

# 1. is the file intact?
if [ -f "$file.sha256" ]; then
  ( cd "$(dirname "$file")" && sha256sum -c "$(basename "$file").sha256" >/dev/null ) || { echo "restore: the backup file is damaged (checksum differs)" >&2; exit 1; }
fi

# 2. is it the right passphrase? A PostgreSQL custom-format dump starts with "PGDMP"; anything else means wrong passphrase or damaged file.
magic="$(openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$file" 2>/dev/null | head -c 5 || true)"
[ "$magic" = "PGDMP" ] || { echo "restore: cannot read the backup: wrong BACKUP_PASSPHRASE, or the file is damaged. Nothing was changed." >&2; exit 1; }

# 3. the target database.
exists="$(psql -d postgres -Atc "select 1 from pg_database where datname='$target'")"
if [ -n "$exists" ]; then
  [ "${RESTORE_REPLACE:-no}" = "yes" ] || { echo "restore: database '$target' already exists; refusing to overwrite (set RESTORE_REPLACE=yes if you really mean it)" >&2; exit 1; }
  psql -d postgres -qc "drop database \"$target\" with (force)"
fi
psql -d postgres -qc "create database \"$target\""

# 4. decrypt -> restore, again without a readable copy on disk. A half-restored database is removed again.
if ! openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$file"   | pg_restore --no-owner --no-privileges --exit-on-error --dbname "$target"; then
  psql -d postgres -qc "drop database if exists \"$target\" with (force)" || true
  echo "restore: FAILED; the incomplete database '$target' was removed" >&2
  exit 1
fi

echo "restore ok: $file -> $target"
