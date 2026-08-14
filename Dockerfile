FROM node:24-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM composer:2.9 AS composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts

FROM php:8.5-fpm-alpine AS app
RUN apk add --no-cache ca-certificates icu-libs libzip libpng oniguruma \
    && update-ca-certificates \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev oniguruma-dev \
    && docker-php-ext-install bcmath intl pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=composer --chown=www-data:www-data /build/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /build/public/build ./public/build
COPY docker/php/php.ini /usr/local/etc/php/conf.d/sync-sus.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-sync-sus.conf
COPY docker/php/entrypoint.sh /usr/local/bin/sync-sus-entrypoint

RUN mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod 0555 /usr/local/bin/sync-sus-entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/sync-sus-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

FROM alpine:3.22 AS backup
RUN apk add --no-cache bash coreutils gzip mysql-client openssl tar
COPY docker/backup/backup.sh /usr/local/bin/sync-sus-backup
RUN chmod 0555 /usr/local/bin/sync-sus-backup
ENTRYPOINT ["/usr/local/bin/sync-sus-backup"]

FROM app AS railway
USER root
RUN apk add --no-cache gettext-envsubst nginx supervisor su-exec \
    && mkdir -p /run/nginx
COPY docker/railway/start.sh /usr/local/bin/sync-sus-railway
COPY docker/railway/nginx.conf.template /etc/nginx/http.d/default.conf.template
COPY docker/railway/supervisord.conf /etc/supervisord.conf
RUN chmod 0555 /usr/local/bin/sync-sus-railway
EXPOSE 8080
CMD ["/usr/local/bin/sync-sus-railway"]
