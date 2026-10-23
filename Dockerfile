# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# 1) PHP dependencies (production only) and the full source tree
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts --ignore-platform-reqs

# ---------------------------------------------------------------------------
# 2) app: PHP-FPM. Also used for the queue worker and the scheduler.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache ca-certificates tzdata \
    && install-php-extensions redis opcache \
    && cp /usr/share/zoneinfo/Africa/Cairo /etc/localtime \
    && echo "Africa/Cairo" > /etc/timezone

ENV TZ=Africa/Cairo

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./

# /data holds the SQLite database (a volume); storage/ holds logs.
RUN mkdir -p /data storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /data storage bootstrap/cache \
    && php artisan package:discover --ansi

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# 3) web: nginx serving public/ and passing PHP to the app container
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/html/public
