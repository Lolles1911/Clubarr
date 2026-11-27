#!/bin/sh
# Starts as root only to match PUID/PGID (Unraid: 99/100) and own /config;
# everything after runs as the clubarr user.
set -eu

PUID="${PUID:-1000}"
PGID="${PGID:-1000}"
if [ "$(id -u)" = "0" ]; then
    [ "$(id -g clubarr)" = "$PGID" ] || groupmod -o -g "$PGID" clubarr
    [ "$(id -u clubarr)" = "$PUID" ] || usermod -o -u "$PUID" clubarr
    mkdir -p /config
    chown clubarr:clubarr /config
    chown -R clubarr:clubarr /app/storage /app/bootstrap/cache
    # nginx and php-fpm write their temp/pid files here.
    mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx
    chown -R clubarr:clubarr /run/nginx /var/lib/nginx /var/log/nginx
fi

run() { if [ "$(id -u)" = "0" ]; then su-exec clubarr "$@"; else "$@"; fi; }

# The encryption key: made once, kept in /config, readable only by Clubarr.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s /config/app.key ]; then
        run sh -c 'umask 077; echo "base64:$(head -c 32 /dev/urandom | base64)" > /config/app.key'
        echo "Clubarr: made a new encryption key in /config/app.key. Back it up with the database."
    fi
    APP_KEY="$(cat /config/app.key)"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -e "${DB_DATABASE:-/config/clubarr.sqlite}" ]; then
    run touch "${DB_DATABASE:-/config/clubarr.sqlite}"
fi
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    chmod 600 "${DB_DATABASE:-/config/clubarr.sqlite}" 2>/dev/null || true
fi

run php artisan migrate --force --no-interaction
run php artisan optimize --no-interaction >/dev/null
run php artisan clubarr:setup-token

exec "$@"
