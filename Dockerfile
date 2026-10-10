# Symfony 8 and OpenSpout in composer.lock require PHP >= 8.4.1.
# PHP 8.5 also matches the existing local application runtime.
FROM php:8.5-fpm-alpine AS php-runtime

RUN apk add --no-cache \
        ca-certificates curl freetype libjpeg-turbo libpng libzip \
        nginx oniguruma sqlite-libs supervisor su-exec \
    && apk add --no-cache --virtual .extension-build-deps \
        $PHPIZE_DEPS curl-dev freetype-dev libjpeg-turbo-dev libpng-dev \
        libxml2-dev libzip-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && extensions="$(php -r '$required = ["bcmath", "curl", "dom", "gd", "mbstring", "pcntl", "pdo_mysql", "pdo_sqlite", "xmlreader", "xmlwriter", "zip"]; echo implode(" ", array_filter($required, fn ($extension) => !extension_loaded($extension)));')" \
    && if [ -n "$extensions" ]; then docker-php-ext-install -j2 $extensions; fi \
    && apk del --no-network .extension-build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html

FROM php-runtime AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./

# No Composer lifecycle scripts: the project's setup scripts run migrations.
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
        --no-scripts --no-autoloader

COPY . ./

RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && composer check-platform-reqs --no-dev

FROM php-runtime AS application

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000 \
    RUN_MIGRATIONS=false \
    RUN_REVERB=false \
    RUN_SCHEDULER=false \
    RUN_QUEUE_WORKER=false

COPY . ./
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-application.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-application.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/application-entrypoint

RUN mkdir -p resources/views bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod -R ug+rwX bootstrap/cache storage \
    && ln -s /var/www/html/storage/app/public public/storage \
    && sed -i 's/\r$//' /usr/local/bin/application-entrypoint \
    && chmod +x /usr/local/bin/application-entrypoint

EXPOSE 10000

STOPSIGNAL SIGTERM

ENTRYPOINT ["application-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
