#!/bin/sh
set -eu
umask 027

case "${1:-}" in
    storage-init)
        mkdir -p vendor storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
        chown -R www-data:www-data storage bootstrap/cache vendor
        chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache vendor
        chmod 700 storage/app/private
        exit 0
        ;;
    dependencies)
        export COMPOSER_HOME="$(mktemp -d /tmp/oapteka-composer.XXXXXX)"
        export COMPOSER_CACHE_DIR="$COMPOSER_HOME/cache"
        trap 'rm -rf "$COMPOSER_HOME"' EXIT
        composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
        composer check-platform-reqs --no-dev
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
        exec php artisan queue:work "--queue=${GMAIL_IMPORTS_QUEUE:-gmail-imports},${PRICE_LIST_QUEUE:-price-list-imports},default" --sleep=2 --tries=3 --timeout=120 --no-interaction
        ;;
esac

exec "$@"
