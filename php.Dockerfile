ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-cli AS base

# Add the `unzip` package which PIE uses to extract .zip files
RUN export DEBIAN_FRONTEND="noninteractive"; \
    set -eux; \
    apt-get update; apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

# Copy the pie.phar from the latest `:bin` release
COPY --from=ghcr.io/php/pie:bin /pie /usr/bin/pie

# Use PIE to install an extension...
RUN pie install phpredis/phpredis

FROM base AS composer-install

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY ./composer.json ./composer.lock /app/

WORKDIR /app

RUN composer install --optimize-autoloader

FROM base AS final

WORKDIR /app

COPY --from=composer-install /app /app
COPY . /app
