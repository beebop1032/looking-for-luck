# Looking for Luck — web interface + scheduled sync + CLI, in one image.
#
#   docker compose up -d                 → http://localhost:8090
#   docker compose run --rm app app:euromillions:predict 5
#
# The default command ("serve") starts:
#   - FrankenPHP serving the web interface on :8080 inside the container
#     (published on 8090 by compose.yaml);
#   - supercronic, running app:sync every hour (it only downloads when a
#     draw is due, see docker/crontab).
# Any other command is passed to bin/console.

FROM dunglas/frankenphp:1-php8.4-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache supercronic tzdata \
    && install-php-extensions intl

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    TZ=Europe/Paris \
    LFL_DATA_ROOT=/data \
    SERVER_NAME=:8080

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-looking-for-luck.ini

WORKDIR /app

# Install dependencies first for better layer caching.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && APP_SECRET=build php bin/console cache:warmup \
    && addgroup -S lottery && adduser -S lottery -G lottery \
    && mkdir -p /data/euromillions \
    # Unprivileged port: FrankenPHP needs no capability.
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R lottery:lottery /app/var /data /config/caddy /data/caddy \
    && rm -rf /data/composer \
    && chmod +x docker/entrypoint.sh

USER lottery
VOLUME /data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/health || exit 1

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["serve"]
