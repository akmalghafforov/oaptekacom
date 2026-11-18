FROM php:8.4.27-fpm-alpine AS development
RUN php -r 'if (PHP_VERSION !== "8.4.27") { fwrite(STDERR, "Require PHP 8.4.27; got ".PHP_VERSION.PHP_EOL); exit(1); }'
COPY docker/security.ini /usr/local/etc/php/conf.d/zz-security.ini
RUN apk add --no-cache nodejs npm chromium postgresql-dev libzip-dev oniguruma-dev freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip mbstring bcmath opcache gd
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser
WORKDIR /var/www
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts || true
COPY . .
RUN mkdir -p storage/app/private storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm"]

FROM php:8.4.27-fpm-alpine AS production-base
RUN php -r 'if (PHP_VERSION !== "8.4.27") { fwrite(STDERR, "Require PHP 8.4.27; got ".PHP_VERSION.PHP_EOL); exit(1); }'
COPY docker/security.ini /usr/local/etc/php/conf.d/zz-security.ini
RUN apk add --no-cache nodejs chromium libpq libzip oniguruma libxml2 freetype libjpeg-turbo libpng \
    && apk add --no-cache --virtual .build-deps postgresql-dev libzip-dev oniguruma-dev libxml2-dev freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip mbstring bcmath opcache gd pcntl dom simplexml xml xmlreader xmlwriter \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www

# Compile the locked dependency tree locally; source is bind-mounted at runtime.
FROM production-base AS production-vendor
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && composer check-platform-reqs --no-dev \
    && mv vendor /opt/oapteka-vendor \
    && cp composer.lock /opt/oapteka-composer.lock

FROM node:22-alpine AS production-browser
WORKDIR /opt/browser
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts

FROM production-base AS production
ENV PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser
COPY --from=production-browser /opt/browser/node_modules /var/www/node_modules
COPY --from=production-vendor --chown=www-data:www-data /opt/oapteka-vendor /opt/oapteka-vendor
COPY --from=production-vendor /opt/oapteka-composer.lock /opt/oapteka-composer.lock
RUN mkdir -p vendor storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data vendor storage bootstrap/cache
COPY docker/php.production.ini /usr/local/etc/php/conf.d/production.ini
COPY docker/production-entrypoint.sh /usr/local/bin/production-entrypoint
USER www-data
ENTRYPOINT ["sh", "/usr/local/bin/production-entrypoint"]
CMD ["php-fpm"]

FROM nginx:stable-alpine AS production-nginx
RUN sed -i '/REMOTE_ADDR/d' /etc/nginx/fastcgi_params
COPY docker/nginx/production.conf /etc/nginx/templates/default.conf.template
