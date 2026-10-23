#!/bin/sh
# Container entrypoint shared by the app (php-fpm), queue and scheduler services.
#   RUN_MIGRATIONS=true  migrate the database, then mark the stack ready (set on the "app" service only)
#   WAIT_FOR_READY=true  wait for that marker before starting (queue, scheduler)
set -e
cd /var/www/html

READY=storage/framework/.ready

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with:  docker compose run --rm --no-deps app php artisan key:generate --show" >&2
    exit 1
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    rm -f "$READY"
    if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ] && [ ! -f "$DB_DATABASE" ]; then
        touch "$DB_DATABASE"
    fi
    php artisan migrate --force
fi

if [ "$WAIT_FOR_READY" = "true" ]; then
    echo "Waiting for the app service to finish migrating..."
    until [ -f "$READY" ]; do sleep 2; done
fi

# Each container keeps its own compiled config/routes (bootstrap/cache is not shared).
php artisan config:cache
php artisan route:cache

if [ "$RUN_MIGRATIONS" = "true" ]; then
    touch "$READY"
fi

exec "$@"
