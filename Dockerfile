# syntax=docker/dockerfile:1
#
# Umar Techy E-Commerce — production images (docs/deployment/production.md).
#
#   docker build --target app -t umartechy-ecom-app .   PHP-FPM: the app, queue worker and scheduler
#   docker build --target web -t umartechy-ecom-web .   Nginx: static files, PHP to the app container
#
# Nothing secret is built in: .dockerignore keeps .env and keys out, and every
# production value comes from the server's environment at run time.

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# --- Frontend: React/TypeScript/Inertia, Tailwind through PostCSS ---------
FROM node:${NODE_VERSION}-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources/ resources/
# The Tailwind and PostCSS configs are needed, or app.css ships unprocessed.
COPY vite.config.ts tsconfig.json tailwind.config.ts postcss.config.js ./
RUN npm run build

# --- PHP runtime with the extensions the app uses --------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base
# gd with JPEG/PNG/WebP (uploaded images are re-encoded), pdo_mysql, intl,
# zip (CSV/exports), bcmath, opcache, pcntl (queue worker signals), exif,
# redis. sodium and mbstring are built into the official image.
# default-mysql-client: mysqldump/mysql for the platform's own backups and
# restore rehearsals. libfcgi-bin: cgi-fcgi for the PHP-FPM health check.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev \
        default-mysql-client libfcgi-bin unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip bcmath intl opcache pcntl exif \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# --- The application --------------------------------------------------------
FROM php-base AS app
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_FPM_MAX_CHILDREN=12 \
    PHP_FPM_START_SERVERS=3 \
    PHP_FPM_MIN_SPARE=2 \
    PHP_FPM_MAX_SPARE=5

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY . .
COPY --from=frontend /app/public/build public/build

RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && rm -rf public/storage public/hot \
    && ln -s ../storage/app/public public/storage \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && groupadd --gid 1000 umartechy \
    && useradd --uid 1000 --gid umartechy --create-home --shell /usr/sbin/nologin umartechy \
    && chown -R umartechy:umartechy storage bootstrap/cache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-umartechy.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-umartechy.conf
COPY docker/entrypoint.sh /usr/local/bin/umartechy-entrypoint
# A checkout on Windows may carry CRLF line endings; the shell needs LF.
RUN sed -i 's/\r$//' /usr/local/bin/umartechy-entrypoint && chmod 0755 /usr/local/bin/umartechy-entrypoint

USER umartechy
EXPOSE 9000
ENTRYPOINT ["umartechy-entrypoint"]
CMD ["php-fpm"]

# --- Web server: Nginx in front of the app container ------------------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /var/www/html/public/
COPY --from=frontend /app/public/build /var/www/html/public/build
# Uploaded files: the app's storage volume is mounted read-only at
# /var/www/html/storage/app (docker-compose.prod.yml).
RUN rm -rf /var/www/html/public/storage /var/www/html/public/hot \
    && ln -s /var/www/html/storage/app/public /var/www/html/public/storage
EXPOSE 8080
