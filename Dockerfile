FROM php:8.4-apache

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && php -r 'foreach (["mbstring", "pdo_mysql", "xmlreader"] as $extension) { if (!extension_loaded($extension)) exit(1); }' \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/dev-delivery
COPY bin/ bin/
COPY database/ database/
COPY public/ public/
COPY resources/ resources/
COPY src/ src/
COPY deploy/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/dev-delivery.ini
