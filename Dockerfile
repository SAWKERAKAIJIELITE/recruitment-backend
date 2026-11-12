# The recruitment backend's app image (docs/docker-deployment.md): PHP-FPM 8.2 with the code and its dependencies,
# also used by the scheduler. The web server's image is deploy/docker/web.Dockerfile.
#
# Build both with: docker compose build

ARG PHP_VERSION=8.2.35

# ---- the PHP dependencies (no development packages) ---------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# The platform is the app image's, checked there (composer check-platform-reqs below), not this helper image's.
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# ---- the application ----------------------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS app

# pdo_pgsql: the database; intl (and the built-in mbstring): Arabic text; gd, zip (and the built-in xml): Excel
# files (PhpSpreadsheet) and PDFs (mPDF); bcmath, opcache, pcntl: Laravel.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd bcmath opcache pcntl \
 && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/docker/php/app.ini      /usr/local/etc/php/conf.d/90-recruitment.ini
COPY deploy/docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-recruitment.conf
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/recruitment
COPY --from=vendor /app/vendor ./vendor
COPY . .

# The autoloader, Laravel's package discovery, and a check that this PHP has every extension the packages need.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
 && composer check-platform-reqs --no-dev \
 && mkdir -p storage/app/private storage/app/public storage/logs storage/framework/cache/data storage/framework/sessions \
             storage/framework/views bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && install -m 755 deploy/docker/entrypoint.sh /usr/local/bin/recruitment-entrypoint

# PHP never runs as root: FPM, the scheduler and every `docker compose exec app php artisan …`.
USER www-data
EXPOSE 9000
ENTRYPOINT ["recruitment-entrypoint"]
CMD ["php-fpm"]
