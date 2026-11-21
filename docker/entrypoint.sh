#!/bin/sh
# Container start-up: wait for the database, migrate, cache config, start background
# workers, then start Apache.
#
# Switch steps with environment variables:
#   RUN_MIGRATIONS=false   skip `php artisan migrate --force`
#   RUN_SEEDERS=true       also run `php artisan db:seed --force` (seeders are safe to repeat)
#   RUN_SCHEDULER=false    don't start the scheduler (backups, pruning old logs)
#   RUN_QUEUE=false        don't start a queue worker (emails, notifications)
set -e
cd /var/www/html

if [ ! -f vendor/autoload.php ] && command -v composer >/dev/null 2>&1; then
    echo "[entrypoint] vendor/ is missing - running composer install"
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ] && [ -f .env.example ] && [ "${APP_ENV:-local}" != "production" ]; then
    cp .env.example .env
fi

if [ -z "${APP_KEY}" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
    if [ "${APP_ENV:-local}" = "production" ]; then
        echo "[entrypoint] APP_KEY is not set. Create one with: php artisan key:generate --show" >&2
        exit 1
    fi
    php artisan key:generate --force
fi

mkdir -p storage/app/public storage/app/private storage/app/backups storage/framework/cache \
         storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Railway's database services give DATABASE_URL (or MYSQL_URL): use it when DB_URL is not set.
if [ -z "${DB_URL}" ]; then
    for candidate in "${DATABASE_URL}" "${MYSQL_URL}"; do
        if [ -n "${candidate}" ]; then
            export DB_URL="${candidate}"
            break
        fi
    done
fi
if [ -n "${DB_URL}" ] && [ -z "${DB_CONNECTION}" ]; then
    case "${DB_URL}" in
        postgres*) export DB_CONNECTION=pgsql ;;
        mysql*|mariadb*) export DB_CONNECTION=mysql ;;
    esac
fi
echo "[entrypoint] database: ${DB_CONNECTION:-sqlite}${DB_URL:+ (from a URL)}${DB_HOST:+ at ${DB_HOST}}"

# Wait up to 60 seconds for the database; say why when it never answers.
i=0
until php artisan db:show >/dev/null 2>&1; do
    i=$((i+1))
    if [ $i -ge 30 ]; then
        echo "[entrypoint] the database did not answer. The error:" >&2
        php artisan db:show 2>&1 | tail -n 5 >&2 || true
        echo "[entrypoint] check DB_CONNECTION and DB_URL (or DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)." >&2
        exit 1
    fi
    echo "[entrypoint] waiting for the database ($i/30)..."; sleep 2
done

# The old Node.js attendance database is not this app's database: migrating into it would
# clash with its tables. Give this app a new, empty database and copy the old data with
# php artisan attendance:import-legacy (LEGACY_DATABASE_URL).
if php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $s = Illuminate\Support\Facades\Schema::getFacadeRoot(); exit($s->hasTable("attendance") && ! $s->hasTable("migrations") ? 0 : 1);' 2>/dev/null; then
    echo "[entrypoint] this database belongs to the old attendance system (it has an \"attendance\" table)." >&2
    echo "[entrypoint] add a new database for this app, and set the old one as LEGACY_DATABASE_URL to import it." >&2
    exit 1
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan storage:link >/dev/null 2>&1 || true

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan optimize
else
    php artisan optimize:clear >/dev/null 2>&1 || true
fi

if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
    su -s /bin/sh www-data -c "php artisan schedule:work" &
fi
if [ "${RUN_QUEUE:-true}" = "true" ]; then
    su -s /bin/sh www-data -c "php artisan queue:work --sleep=3 --tries=3 --max-time=3600" &
fi

exec "$@"
