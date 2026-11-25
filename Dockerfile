FROM php:8.4-apache

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && php -r 'exit(extension_loaded("mbstring") ? 0 : 1);' \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/learning-cloud
COPY bin/ bin/
COPY database/ database/
COPY public/ public/
COPY resources/ resources/
COPY src/ src/
COPY deploy/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/learning-cloud.ini
COPY deploy/sweep.sh /usr/local/bin/learning-cloud-sweep
