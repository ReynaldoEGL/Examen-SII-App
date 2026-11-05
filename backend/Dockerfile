FROM composer:2 AS composer_bin
FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libsqlite3-dev \
    unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pdo_sqlite sqlite3 zip opcache mbstring curl dom xml xmlwriter \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80
CMD ["sh", "-lc", "if [ ! -f vendor/autoload.php ]; then composer install --no-interaction --prefer-dist; fi; apache2-foreground"]
