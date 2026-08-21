FROM php:8.5-apache

# Host UID/GID so files written inside the container stay editable on Ubuntu.
ARG UID=1000
ARG GID=1000

# Build dependencies for the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# pdo_mysql  - database
# zip        - composer package extraction
# opcache    - bytecode cache, big performance win
# pcntl      - lets queue:work handle SIGTERM and shut down cleanly
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        zip \
        pcntl

# phpredis: the C extension, not the pure-PHP predis package.
RUN pecl install redis \
    && docker-php-ext-enable redis

# Step debugging from the IDE. Left inert by xdebug.mode=off in php.ini -- the
# XDEBUG_MODE env var in docker-compose.yml turns it on for a run.
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN a2enmod rewrite headers

COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Remap www-data to the host user. Apache still starts as root (it needs to
# bind port 80) and drops to www-data for worker processes.
RUN groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} www-data \
    && chown -R www-data:www-data /var/www

WORKDIR /var/www/html