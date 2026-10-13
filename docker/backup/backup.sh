#!/bin/sh
# Nightly database backup (docs/runbooks/backup-restore.md).
#
# Dumps the PostgreSQL database in PostgreSQL's compressed custom format, encrypts it, writes it to BACKUP_DIR, optionally copies it to
# S3-compatible storage, and prunes old files (7 daily, 4 weekly, 12 monthly). Nothing is ever printed that contains a secret.
#
# Environment (all required unless marked optional):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE   the database to back up (standard libpq variables)
#   BACKUP_PASSPHRASE                            encrypts the dump (AES-256, keep a copy of it OUTSIDE the server: without it a backup is useless)
#   BACKUP_DIR                                   where files go (default /backups)
#   BACKUP_S3_URI                                optional, e.g. s3://my-bucket/dio-erp/  (needs the aws CLI and its credentials in the environment)
#   BACKUP_S3_ENDPOINT                           optional, for non-AWS S3 (e.g. Wasabi, MinIO)
set -eu

: "${PGDATABASE:?PGDATABASE is required}"
: "${BACKUP_PASSPHRASE:?BACKUP_PASSPHRASE is required}"
dir="${BACKUP_DIR:-/backups}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
name="${PGDATABASE}-${stamp}.dump.enc"
mkdir -p "$dir"

# 1. dump -> encrypt, in one pipe so no readable copy of the data ever touches the disk.
pg_dump --format=custom --no-owner --no-privileges "$PGDATABASE" \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE -out "$dir/$name.part"
mv "$dir/$name.part" "$dir/$name"

# 2. a checksum next to it, so a later restore can tell a damaged file from a wrong passphrase.
( cd "$dir" && sha256sum "$name" > "$name.sha256" )

# 3. optional off-site copy.
if [ -n "${BACKUP_S3_URI:-}" ]; then
  endpoint=""
  [ -n "${BACKUP_S3_ENDPOINT:-}" ] && endpoint="--endpoint-url $BACKUP_S3_ENDPOINT"
  # shellcheck disable=SC2086
  aws s3 cp $endpoint "$dir/$name" "${BACKUP_S3_URI%/}/$name"
  # shellcheck disable=SC2086
  aws s3 cp $endpoint "$dir/$name.sha256" "${BACKUP_S3_URI%/}/$name.sha256"
fi

# 4. retention: keep every file of the last 7 days, the newest of each of the last 4 weeks, the newest of each of the last 12 months.
keep_list="$(mktemp)"
ls -1 "$dir"/"${PGDATABASE}"-*.dump.enc 2>/dev/null | sort -r | awk -v now="$(date -u +%s)" '
  { f=$0; match(f, /[0-9]{8}T[0-9]{6}Z/); s=substr(f, RSTART, 8);
    cmd="date -u -d " s " +%s"; cmd | getline t; close(cmd);
    age=(now-t)/86400;
    wk=int(age/7); mo=int(age/30);
    if (age<7) print f;
    else if (age<28 && !(("w" wk) in seenw)) { seenw["w" wk]=1; print f }
    else if (age<365 && !(("m" mo) in seenm)) { seenm["m" mo]=1; print f }
  }' > "$keep_list"
for f in "$dir"/"${PGDATABASE}"-*.dump.enc; do
  [ -e "$f" ] || continue
  if ! grep -qxF "$f" "$keep_list"; then rm -f "$f" "$f.sha256"; fi
done
rm -f "$keep_list"

size="$(wc -c < "$dir/$name" | tr -d ' ')"
echo "backup ok: $name ($size bytes)"
