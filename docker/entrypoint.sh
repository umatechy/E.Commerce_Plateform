#!/bin/sh
# Umar Techy E-Commerce — start of every app container (app, worker, scheduler).
#
# Refuses to start without the settings that cannot have a safe default,
# makes sure the storage folders exist in the mounted volumes, and builds
# Laravel's caches from this container's environment. It never migrates the
# database: that is a separate, deliberate deployment step.
set -eu
cd /var/www/html

missing=""
for name in APP_KEY APP_URL DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD; do
    eval "value=\${$name:-}"
    if [ -z "$value" ]; then
        missing="$missing $name"
    fi
done
if [ -n "$missing" ]; then
    echo "umartechy-entrypoint: not starting, these settings are empty:$missing" >&2
    exit 1
fi
if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEBUG:-false}" = "true" ]; then
    echo "umartechy-entrypoint: not starting, APP_DEBUG must be false in production" >&2
    exit 1
fi

# The volumes start empty on a new server.
mkdir -p storage/app/public storage/app/private storage/logs

if [ "${APP_OPTIMIZE:-true}" = "true" ]; then
    php artisan optimize --no-ansi --quiet
fi

exec "$@"
