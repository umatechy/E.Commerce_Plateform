# Module 20 Phase 43 "Docker Readiness" — architecture-ready, NOT
# mandatory on day one (Hostinger-compatible managed hosting/VPS
# remains the initial target). Multi-stage: Node build stage for the
# React/TypeScript/Inertia frontend, then a PHP-FPM runtime stage.
# NOT EXECUTED — ENVIRONMENT LIMITATION: this image has never been
# built or run in this Claude App sandbox (no Docker daemon available).

# --- Stage 1: frontend build ---
FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources/ resources/
COPY vite.config.* tsconfig*.json ./
RUN npm run build

# --- Stage 2: PHP-FPM runtime ---
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache \
        libpng-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring gd zip bcmath intl opcache \
    && pecl install redis && docker-php-ext-enable redis

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN php -r "readfile('https://getcomposer.org/installer');" | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
COPY --from=frontend-build /app/public/build public/build

# Non-root deployment user (Module 20 Phase 35 "SSH / Server Access" —
# least-privilege principle extended to the container process too).
RUN addgroup -g 1000 umartechy && adduser -D -u 1000 -G umartechy umartechy \
    && chown -R umartechy:umartechy /var/www/html/storage /var/www/html/bootstrap/cache
USER umartechy

EXPOSE 9000
CMD ["php-fpm"]
