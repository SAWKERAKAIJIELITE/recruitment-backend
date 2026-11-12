#!/usr/bin/env bash
# Backup of the recruitment system under Docker (docs/docker-deployment.md, step 8): the database and the stored
# files, taken while the containers run. Run on the server, as a user who may use docker:
#
#   bash deploy/docker/backup.sh          one backup now, then the ones older than KEEP_DAYS are deleted
#
# Writes to BACKUP_DIR:  db-<stamp>.dump       (pg_dump custom format: pg_restore reads it)
#                        files-<stamp>.tar.gz  (storage/app: imports, documents, vacancy files)
#                        env-<stamp>           (.env, with APP_KEY and the passwords)
# Copy BACKUP_DIR to another machine as well: a backup on the same disk is lost with the disk.
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/recruitment}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"

cd "$PROJECT_DIR"
DB_NAME="${DB_NAME:-$(sed -n 's/^DB_DATABASE=//p' .env | tr -d '"\r' | tail -1)}"
DB_NAME="${DB_NAME:-recruitment}"

umask 077
mkdir -p "$BACKUP_DIR"

# The database: a consistent snapshot while the application keeps running.
docker compose exec -T db pg_dump --username postgres --format=custom --no-owner --dbname="$DB_NAME" \
    > "$BACKUP_DIR/db-$STAMP.dump.part"
mv "$BACKUP_DIR/db-$STAMP.dump.part" "$BACKUP_DIR/db-$STAMP.dump"

# The files the database points at, and the settings (APP_KEY included).
docker compose exec -T app tar --create --gzip --file=- --directory=/var/www/recruitment storage/app \
    > "$BACKUP_DIR/files-$STAMP.tar.gz.part"
mv "$BACKUP_DIR/files-$STAMP.tar.gz.part" "$BACKUP_DIR/files-$STAMP.tar.gz"
cp .env "$BACKUP_DIR/env-$STAMP"

# Check what was written: a dump pg_restore cannot list, or an unreadable archive, fails the run.
docker compose exec -T db pg_restore --list < "$BACKUP_DIR/db-$STAMP.dump" > /dev/null
tar --list --gzip --file="$BACKUP_DIR/files-$STAMP.tar.gz" > /dev/null

find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'files-*.tar.gz' -o -name 'env-*' \) \
     -mtime +"$KEEP_DAYS" -delete

echo "backup $STAMP of $DB_NAME: $(du -h "$BACKUP_DIR/db-$STAMP.dump" | cut -f1) database, $(du -h "$BACKUP_DIR/files-$STAMP.tar.gz" | cut -f1) files"
