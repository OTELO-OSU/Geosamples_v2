# Backend Symfony (même socle qu'ORDaR v2 : PHP 8.3 FPM Alpine + pdo_pgsql)
FROM composer:2 AS composer

FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libpq libzip postgresql-client openssl su-exec \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql intl zip opcache \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php-fpm/php.ini /usr/local/etc/php/conf.d/zz-geosamples.ini
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-geosamples.conf

WORKDIR /var/www/backend
ENV APP_ENV=prod COMPOSER_ALLOW_SUPERUSER=1

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader

COPY backend/ ./
RUN rm -f .env.local && composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log var/uploads config/jwt \
    && chown -R www-data:www-data var config/jwt

COPY docker/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php-fpm/crontab /etc/crontabs/root
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
