#!/bin/sh
# Start of the app and scheduler containers (docs/docker-deployment.md).
#
#   php-fpm (the app)  the schema is brought up to date, the catalogue seeded on a new database, the caches built
#   anything else      the caches are built, then the command runs (the scheduler, a one-off artisan command)
#
# AUTO_MIGRATE=false in .env turns the automatic migration off (then: docker compose exec app php artisan migrate --force).
set -eu

cd /var/www/recruitment

if [ -z "${APP_KEY:-}" ]; then
    # Without a key nothing can start; `key:generate --show` is how the key is made, so let that one through.
    case "$*" in
        *key:generate*) exec "$@" ;;
    esac
    echo "APP_KEY is empty: this container did not receive it." >&2
    echo "Set APP_KEY=base64:… in the platform's environment variables (Openship) or in .env, then deploy again." >&2
    echo "A new key: docker compose run --rm --no-deps app php artisan key:generate --show" >&2
    # Names only, never values: which settings this container did receive (to see what the platform passes).
    echo "Settings received with a value: $(env | grep -E '^(APP|DB|LOG|REPORTS|EXAMS)_[A-Z_]*=.' | cut -d= -f1 | sort | tr '\n' ' ')" >&2
    exit 1
fi

# "yes" when the database has no `migrations` table yet (a new installation), "no" otherwise; fails when the
# database cannot be reached.
database_is_new() {
    php -r '
        $pdo = new PDO(
            sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE")),
            getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $missing = $pdo->query("SELECT to_regclass(" . $pdo->quote("public.migrations") . ") IS NULL")->fetchColumn();
        echo $missing ? "yes" : "no";
    '
}

if [ "${1:-}" = "php-fpm" ] && [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    # The database container is healthy before this one starts (docker-compose.yml); a few tries cover a restart.
    tries=0
    until new="$(database_is_new)"; do
        tries=$((tries + 1))
        [ "$tries" -lt 30 ] || { echo "The database cannot be reached (DB_HOST, DB_PASSWORD in .env?)." >&2; exit 1; }
        sleep 2
    done

    php artisan migrate --force

    # The catalogue (roles, permissions, reference lists) once, on a new database. Later:
    # docker compose exec app php artisan db:seed --force  (adds what is missing).
    if [ "$new" = "yes" ]; then
        php artisan db:seed --force
    fi
fi

# Configuration, events, routes and views, cached from this container's environment.
php artisan optimize

exec "$@"
