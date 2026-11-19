#!/usr/bin/env bash
# Checks after installing or updating under Docker (docs/docker-deployment.md, step 6). Run on the server:
#
#   bash deploy/docker/check.sh                        checks https://<SITE_ADDRESS of .env>
#   bash deploy/docker/check.sh https://localhost -k   another address; extra arguments go to curl (-k: do not
#                                                      verify the certificate, for a local test)
#
# Every line is «ok» or «FAIL»; the exit status is the number of failures.
set -uo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$PROJECT_DIR"

SITE="$(sed -n 's/^SITE_ADDRESS=//p' .env 2>/dev/null | tr -d '"\r' | tail -1)"
BASE="${1:-https://${SITE:-rec.1005.date}}"
[ $# -gt 0 ] && shift
CURL_OPTIONS=("$@")

failed=0
check() {   # check <what> <command…>: ok when the command succeeds
    local what="$1"; shift
    if "$@" > /dev/null 2>&1; then echo "  ok    $what"; else echo "  FAIL  $what"; failed=$((failed + 1)); fi
}

fetch()        { curl --silent --show-error --max-time 20 "${CURL_OPTIONS[@]}" "$@"; }
status_is()    { local want="$1"; shift; [ "$(fetch --output /dev/null --write-out '%{http_code}' "$@")" = "$want" ]; }
redirects()    { case "$(fetch --output /dev/null --write-out '%{http_code}' "$1")" in 301|302|307|308) return 0 ;; *) return 1 ;; esac; }
header_sent()  { fetch --output /dev/null --dump-header - "$2" | grep -qi "^$1"; }
body_lacks()   { ! fetch "$2" | grep -qi "$1"; }
running()      { docker compose ps --status running --services | grep -qx "$1"; }
healthy()      { docker compose ps "$1" | grep -q '(healthy)'; }
in_app()       { docker compose exec -T app "$@"; }
env_is()       { [ "$(in_app printenv "$1" | tr -d '\r')" = "$2" ]; }
php_is_ready() { in_app php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2 && !array_diff(["pdo_pgsql", "mbstring", "intl", "gd", "zip", "xml", "bcmath", "Zend OPcache"], get_loaded_extensions()) ? 0 : 1);'; }
migrated()     { local out; out="$(in_app php artisan migrate:status)" && ! grep -q Pending <<< "$out"; }
seeded()       { docker compose exec -T db sh -c 'psql -U postgres -d "$APP_DB_NAME" -Atc "select count(*) > 0 from roles"' | grep -q '^t'; }
scheduled()    { docker compose exec -T scheduler php artisan schedule:list | grep -q 'exams:sweep'; }

echo "1. The containers"
for service in db app scheduler web; do
    check "$service is running" running "$service"
done
check "db is healthy"  healthy db
check "app is healthy" healthy app

echo "2. The application"
check "APP_ENV is production"          env_is APP_ENV production
check "APP_DEBUG is false"             env_is APP_DEBUG false
check "PHP 8.2 with its extensions"    php_is_ready
check "every migration ran"            migrated
check "the catalogue is seeded"        seeded
check "the scheduler has its jobs"     scheduled
check "the stored files are writable"  in_app test -w storage/app/private
check "the logs are writable"          in_app test -w storage/logs

echo "3. The site: $BASE"
check "the API answers (GET /api/v1/ping: 200)"          status_is 200 "$BASE/api/v1/ping"
check "a request without a token is refused (401)"       status_is 401 --header 'Accept: application/json' "$BASE/api/v1/staff/auth/me"
check "the frontend answers (GET /: 200)"                status_is 200 "$BASE/"
check "the settings are not served (/.env)"              body_lacks 'APP_KEY' "$BASE/.env"
check "the other documentation pages are not served (/docs)" body_lacks 'scribe\|openapi\|postman' "$BASE/docs"
check "a missing desktop update file is 404"           status_is 404 "$BASE/desktop/no-such-release.yml"
check "the desktop folder is not listed (/desktop/)"     status_is 404 "$BASE/desktop/"
check "a missing installer is not cached"               header_sent 'cache-control: no-store' "$BASE/desktop/no-such-release.exe"
check "X-Content-Type-Options is sent"                   header_sent 'x-content-type-options: nosniff' "$BASE/api/v1/ping"
check "X-Frame-Options is sent"                          header_sent 'x-frame-options: deny' "$BASE/api/v1/ping"
case "$BASE" in
    https://*)
        check "HSTS is sent"                             header_sent 'strict-transport-security' "$BASE/api/v1/ping"
        check "HTTP redirects to HTTPS"                  redirects "http://${BASE#https://}/"
        ;;
esac

echo
if [ "$failed" -eq 0 ]; then
    echo "All checks passed."
else
    echo "$failed check(s) failed. Look at: docker compose ps   and   docker compose logs --tail=50 app web"
fi
exit "$failed"
