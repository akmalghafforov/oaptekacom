#!/bin/sh
set -eu

mkdir -p \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

exec setpriv --reuid=www-data --regid=www-data --clear-groups \
    sh -c '
        php artisan config:cache --no-interaction
        php artisan route:cache --no-interaction
        php artisan view:cache --no-interaction
        exec "$@"
    ' production-entrypoint "$@"
