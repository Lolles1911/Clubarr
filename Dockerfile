# syntax=docker/dockerfile:1
# Clubarr: one container with nginx, PHP-FPM, the scheduler and the queue.
# Everything that must be kept lives in /config.

FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
 && find vendor -type d -name .git -prune -exec rm -rf {} +

FROM php:8.4-fpm-alpine3.20

ARG VERSION=dev
LABEL org.opencontainers.image.title="Clubarr" \
      org.opencontainers.image.description="Invitations, accounts and a member page for Plex, Jellyfin, Emby and Silo" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/Lolles1911/clubarr" \
      org.opencontainers.image.version="${VERSION}"

RUN apk add --no-cache nginx supervisor su-exec shadow tzdata icu-libs libzip sqlite-libs libpq \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev sqlite-dev postgresql-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_mysql pdo_pgsql pdo_sqlite opcache pcntl zip bcmath \
 && apk del .build \
 && rm -rf /var/cache/apk/* /tmp/* \
 && addgroup -g 1000 clubarr && adduser -D -H -u 1000 -G clubarr -s /sbin/nologin clubarr

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-clubarr.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-clubarr.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/clubarr-entrypoint

WORKDIR /app
COPY --from=vendor /app /app
RUN rm -rf tests docker .github .env* database/*.sqlite* \
 && php artisan filament:assets --no-interaction \
 && chmod +x /usr/local/bin/clubarr-entrypoint \
 && chown -R clubarr:clubarr /app/storage /app/bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/config/clubarr.sqlite \
    SESSION_DRIVER=database \
    SESSION_ENCRYPT=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    CLUBARR_SETUP_TOKEN_PATH=/config/setup-token \
    PUID=1000 \
    PGID=1000 \
    TZ=UTC

VOLUME /config
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["clubarr-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
