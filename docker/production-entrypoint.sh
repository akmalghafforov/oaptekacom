#!/bin/sh
set -eu
umask 027

case "${1:-}" in
    storage-init)
        mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
        chown -R www-data:www-data storage bootstrap/cache
        chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache
        chmod 700 storage/app/private
        exit 0
        ;;
    app-init)
        # Clear file caches only: optimize:clear also clears the database cache store.
        rm -f bootstrap/cache/*.php
        php artisan package:discover --no-interaction
        php artisan config:cache --no-interaction
        php artisan route:cache --no-interaction
        php artisan view:cache --no-interaction
        exit 0
        ;;
    release)
        shift
        # Ephemeral paths keep one-off commands independent of the serving release's caches.
        release_cache_directory=$(mktemp -d /tmp/oapteka-release.XXXXXX)
        export APP_CONFIG_CACHE="$release_cache_directory/config.php"
        export APP_ROUTES_CACHE="$release_cache_directory/routes.php"
        export APP_SERVICES_CACHE="$release_cache_directory/services.php"
        export APP_PACKAGES_CACHE="$release_cache_directory/packages.php"
        export APP_EVENTS_CACHE="$release_cache_directory/events.php"
        exec php artisan "$@"
        ;;
    queue)
        exec php artisan queue:work "--queue=${PRICE_LIST_QUEUE:-price-list-imports},default" --sleep=2 --tries=3 --timeout=120 --no-interaction
        ;;
esac

exec "$@"
