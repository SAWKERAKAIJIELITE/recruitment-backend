#!/usr/bin/env bash
# Backup of the recruitment system (docs/deployment.md, step 13): the database and the stored files.
# Run as root (cron: /etc/cron.d/recruitment). Install: sudo install -m 750 deploy/backup.sh /usr/local/sbin/recruitment-backup
#
#   recruitment-backup            one backup now, then the ones older than KEEP_DAYS are deleted
#
# Writes to BACKUP_DIR:  db-<stamp>.dump    (pg_dump custom format: pg_restore reads it)
#                        files-<stamp>.tar.gz  (storage/app: imports, documents, vacancy files; and .env)
# Copy BACKUP_DIR to another machine as well: a backup on the same disk is lost with the disk.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/recruitment}"
# The database the application uses (DB_DATABASE in its .env), unless DB_NAME is given.
DB_NAME="${DB_NAME:-$(sed -n 's/^DB_DATABASE=//p' "$APP_DIR/.env" | tr -d '"' | tail -1)}"
[ -n "$DB_NAME" ] || { echo "DB_DATABASE not found in $APP_DIR/.env" >&2; exit 1; }
BACKUP_DIR="${BACKUP_DIR:-/var/backups/recruitment}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"

umask 077
mkdir -p "$BACKUP_DIR"

# The database: a consistent snapshot while the application keeps running.
runuser -u postgres -- pg_dump --format=custom --no-owner --dbname="$DB_NAME" > "$BACKUP_DIR/db-$STAMP.dump.part"
mv "$BACKUP_DIR/db-$STAMP.dump.part" "$BACKUP_DIR/db-$STAMP.dump"

# The files the database points at, and the settings (APP_KEY included).
tar --create --gzip --file="$BACKUP_DIR/files-$STAMP.tar.gz.part" --directory="$APP_DIR" storage/app .env
mv "$BACKUP_DIR/files-$STAMP.tar.gz.part" "$BACKUP_DIR/files-$STAMP.tar.gz"

# Check what was written: a dump pg_restore cannot list, or an unreadable archive, fails the run.
pg_restore --list "$BACKUP_DIR/db-$STAMP.dump" > /dev/null
tar --list --gzip --file="$BACKUP_DIR/files-$STAMP.tar.gz" > /dev/null

find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'files-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete

echo "backup $STAMP of $DB_NAME: $(du -h "$BACKUP_DIR/db-$STAMP.dump" | cut -f1) database, $(du -h "$BACKUP_DIR/files-$STAMP.tar.gz" | cut -f1) files"
