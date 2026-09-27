FROM composer:2 AS composer
FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip && docker-php-ext-install pdo_pgsql && rm -rf /var/lib/apt/lists/* && a2enmod rewrite
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev && mkdir -p var/cache var/log && chown -R www-data:www-data var
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/gpix.ini
EXPOSE 80
