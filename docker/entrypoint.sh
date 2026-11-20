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

# Wait up to 60 seconds for the database.
i=0
until php artisan db:show >/dev/null 2>&1 || [ $i -ge 30 ]; do
    i=$((i+1)); echo "[entrypoint] waiting for the database ($i/30)..."; sleep 2
done

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
