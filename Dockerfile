# syntax=docker/dockerfile:1.7
#
# Production image of DIO AI ERP + MES (one image, several roles: web, worker, scheduler, migrate).
# See docs/runbooks/deploy.md. Build:  docker build -t dio-erp:<release> .
#
# Stage 1: front-end assets.  Stage 2: PHP dependencies (no dev packages).  Stage 3: the runtime.

FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
# Tailwind scans the Blade views of every module for class names.
COPY app ./app
RUN npm run build


FROM php:8.3-fpm-alpine AS base
# Extensions the product needs: PostgreSQL, bcmath (money), intl, gd + zip (documents, logos), pcntl/posix (Horizon), opcache.
RUN apk add --no-cache icu-libs libpq libzip libpng libjpeg-turbo freetype nginx supervisor curl tini postgresql16-client \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath intl gd zip pcntl opcache \
 && apk del .build-deps \
 && rm -rf /tmp/* /var/cache/apk/*


FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
# Scripts are skipped here (the app code is not copied yet); they run once in the final stage.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader


FROM base AS runtime
ARG RELEASE=dev
ENV RELEASE=${RELEASE} \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-dio.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-dio.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Writable places only: storage, cache. Everything else stays read-only to the app user.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && rm -f /usr/bin/composer \
 && rm -rf tests mobile docs node_modules .git .github docker/*.md \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /var/lib/nginx/tmp /var/log/nginx /run/nginx \
 && chmod +x /usr/local/bin/entrypoint \
 && chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /var/log/nginx /run/nginx

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["web"]
