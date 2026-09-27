FROM php:8.4-fpm-alpine AS development
RUN apk add --no-cache postgresql-dev libzip-dev oniguruma-dev freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip mbstring bcmath opcache gd
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts || true
COPY . .
RUN mkdir -p storage/app/private storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm"]

FROM node:24-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-fpm-alpine AS production
RUN apk add --no-cache postgresql-dev libzip-dev oniguruma-dev freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip mbstring bcmath opcache gd pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY . .
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=assets /build/public/build ./public/build
COPY docker/production-entrypoint.sh /usr/local/bin/production-entrypoint
USER www-data
ENTRYPOINT ["sh", "/usr/local/bin/production-entrypoint"]
CMD ["php-fpm"]

FROM nginx:stable-alpine AS production-nginx
RUN sed -i '/REMOTE_ADDR/d' /etc/nginx/fastcgi_params
COPY --from=production /var/www/public /var/www/public
COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf
