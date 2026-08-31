FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


FROM gcr.io/cloud-sql-connectors/cloud-sql-proxy:2.24.1 AS cloudsql-proxy


FROM php:8.3-apache-bookworm AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=database \
    FORGE_RUNTIME_ROLE=web

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_pgsql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY --from=cloudsql-proxy /cloud-sql-proxy /usr/local/bin/cloud-sql-proxy

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/forge-entrypoint.sh /usr/local/bin/forge-entrypoint
RUN chmod 0755 /usr/local/bin/forge-entrypoint /usr/local/bin/cloud-sql-proxy

EXPOSE 8080

ENTRYPOINT ["forge-entrypoint"]
