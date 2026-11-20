# syntax=docker/dockerfile:1
#
# One image for local development and production (Railway, any Docker host).
#
#   docker compose up -d --build              # local: app + MySQL + Mailpit (target "dev")
#   docker build -t my-app .                  # production image (target "prod", the default)
#
# Stages:
#   base    PHP + Apache + the extensions Laravel needs
#   dev     base + Composer + Node (the code is mounted by docker-compose.yml)
#   vendor  composer install --no-dev
#   assets  npm ci && npm run build
#   prod    base + the app + vendor + built assets

ARG PHP_VERSION=8.5

# ---------------------------------------------------------------- base
FROM php:${PHP_VERSION}-apache AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql pdo_pgsql bcmath exif gd intl opcache pcntl zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client unzip \
    && rm -rf /var/lib/apt/lists/*

# Apache: serve /public, allow .htaccess, listen on $PORT (Railway sets it).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=80
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite headers \
    && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-security.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint
WORKDIR /var/www/html
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]

# ---------------------------------------------------------------- dev
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs git \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------- vendor
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------- assets
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ---------------------------------------------------------------- prod
FROM base AS prod
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
RUN echo 'opcache.validate_timestamps = 0' > /usr/local/etc/php/conf.d/zz-prod.ini
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN rm -f bootstrap/cache/*.php public/hot \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
