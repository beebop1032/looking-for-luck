# Looking for Luck — CLI image
#
#   docker build -t looking-for-luck .
#   docker run --rm -v lottery-data:/app/var looking-for-luck app:euromillions:predict 5
#
# Or with compose:
#
#   docker compose run --rm app app:euromillions:stats

FROM php:8.4-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Install dependencies first for better layer caching.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php bin/console cache:warmup \
    && addgroup -S lottery && adduser -S lottery -G lottery \
    && chown -R lottery:lottery /app/var

USER lottery

ENTRYPOINT ["php", "bin/console"]
CMD ["list", "app:euromillions"]
