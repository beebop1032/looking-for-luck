#!/bin/sh
# Deploys (or updates) Looking for Luck on a server reachable over SSH.
#
#   deploy/deploy.sh user@server [directory]
#
# On the server: clones or updates the repository, keeps .env.prod (creating
# it from the example on the first run), then rebuilds and restarts the
# containers. Needs git and Docker (with Compose) on the server.
set -eu

target=${1:?Usage: deploy/deploy.sh user@server [directory]}
dir=${2:-looking-for-luck}
repo=${LFL_REPOSITORY:-https://github.com/beebop1032/looking-for-luck.git}

ssh "$target" sh -s -- "$dir" "$repo" <<'REMOTE'
set -eu
dir=$1
repo=$2

if [ -d "$dir/.git" ]; then
    git -C "$dir" pull --ff-only
else
    git clone "$repo" "$dir"
fi
cd "$dir"

if [ ! -f .env.prod ]; then
    cp deploy/.env.prod.example .env.prod
    chmod 600 .env.prod
    echo "Created $dir/.env.prod: set LFL_DOMAIN (and LFL_GA4_ID), then run this script again."
    exit 1
fi
if ! grep -q '^APP_SECRET=.' .env.prod; then
    secret=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
    sed -i "s/^APP_SECRET=.*/APP_SECRET=$secret/" .env.prod
fi

docker compose -f compose.yaml -f deploy/compose.prod.yaml --env-file .env.prod up -d --build --remove-orphans
docker image prune -f >/dev/null
docker compose -f compose.yaml -f deploy/compose.prod.yaml --env-file .env.prod ps
REMOTE
