#!/bin/sh
set -e

# Symfony needs a secret (sessions, CSRF); generate one if none is provided.
if [ -z "${APP_SECRET:-}" ]; then
    APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
    export APP_SECRET
fi

if [ "${1:-serve}" = "serve" ]; then
    echo "🍀 Looking for Luck — web interface listening on :8080 (http://localhost:8090 with docker compose)"

    # Warm the draw cache without delaying the web server.
    php bin/console app:sync --force --no-ansi --quiet || echo "Initial sync failed (offline?), will retry on schedule." &

    supercronic -quiet /app/docker/crontab &

    exec frankenphp run --config /app/docker/Caddyfile
fi

exec php bin/console "$@"
