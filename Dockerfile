# Pin every external image to an immutable Linux/amd64 digest. This prevents a
# later rebuild from silently changing native libraries underneath the runtime.
FROM node:22-bookworm-slim@sha256:4d676821dff059fd00d277ee4261ef34ea712317fed0737c03941481b5760c96 AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


FROM gcr.io/cloud-sql-connectors/cloud-sql-proxy:2.24.1@sha256:48db501efb291f48f26f1d2cdb341e847a6264da9c29fa4187eb2e50e4ef43bd AS cloudsql-proxy


FROM php:8.3-apache-bookworm@sha256:c0c560dab1bcd301960148d726701954ff83f40dceb9b678fa3078bcc3c4f208 AS runtime

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

COPY --from=composer:2.8@sha256:0d264a0f1e5be23ba363447768df7b30c33d542711ea12e37770ed7b13bf4eaa /usr/bin/composer /usr/local/bin/composer
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

# Fail the build if the native runtime pieces cannot be read. This catches a
# broken image layer before Cloud Run performs its startup probe.
RUN php -v >/dev/null \
    && cloud-sql-proxy --version >/dev/null \
    && sha256sum /lib/x86_64-linux-gnu/libcrypto.so.3 \
        /lib/x86_64-linux-gnu/libpsl.so.5 >/dev/null

EXPOSE 8080

ENTRYPOINT ["forge-entrypoint"]
