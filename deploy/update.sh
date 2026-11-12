#!/usr/bin/env bash
# Update the recruitment backend to a new version (docs/deployment.md, step 15). Run as root:
#
#   sudo /var/www/recruitment/deploy/update.sh <tag-or-commit>      e.g. sudo .../update.sh v1.3.0
#
# A backup first, then maintenance mode, the code, the dependencies, the migrations, the caches, PHP reloaded, live.
# If a step fails the system stays in maintenance mode: fix and run again, or roll back (the guide, step 15).
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/recruitment}"
APP_USER="${APP_USER:-recruitment}"
PHP_FPM="${PHP_FPM:-php8.3-fpm}"
TARGET="${1:?usage: update.sh <tag-or-commit>}"

as_app() { sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $1"; }

echo "== the current version: $(as_app 'git describe --tags --always')"
/usr/local/sbin/recruitment-backup

as_app 'php artisan down --retry=60'
as_app "git fetch --tags origin && git checkout --detach '$TARGET'"
as_app 'composer install --no-dev --optimize-autoloader --no-interaction'
as_app 'php artisan migrate --force'
as_app 'php artisan optimize'
systemctl reload "$PHP_FPM"
as_app 'php artisan up'

echo "== now at: $(as_app 'git describe --tags --always')"
