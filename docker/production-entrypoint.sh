#!/bin/sh
set -eu
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
exec "$@"
