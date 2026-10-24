#!/usr/bin/env bash
# Umar Techy E-Commerce — build and start (or update) the production project.
#
# Touches only the "umartechy-ecom" Compose project: its own images,
# containers, network and volumes. It never runs "down", never deletes a
# volume and never prunes Docker, so other applications on the server are
# left alone. Before migrations on an existing database it saves a dump.
#
#   cd /opt/umartechy-ecommerce
#   bash deploy/production/deploy.sh                 # update
#   bash deploy/production/deploy.sh --first-install # a new, empty database: also seeds the reference data
set -euo pipefail

PROJECT=umartechy-ecom
COMPOSE=(docker compose -p "$PROJECT" -f docker-compose.prod.yml)
# The server's web server is a container on another project's network: the shop's
# web container joins it too (deploy/production/compose.shared-proxy.yml).
SHARED_PROXY_NETWORK="$(grep -E '^SHARED_PROXY_NETWORK=' .env 2>/dev/null | cut -d= -f2- || true)"
if [ -n "$SHARED_PROXY_NETWORK" ]; then
    COMPOSE+=(-f deploy/production/compose.shared-proxy.yml)
fi
FIRST_INSTALL=0
[ "${1:-}" = "--first-install" ] && FIRST_INSTALL=1

die() { echo "deploy: $*" >&2; exit 1; }

# --- Checks before anything is built -----------------------------------------
[ -f docker-compose.prod.yml ] || die "run this from the project folder (docker-compose.prod.yml not found)"
for file in .env mysql.env; do
    [ -f "$file" ] || die "$file is missing (see deploy/production/*.example)"
    mode="$(stat -c '%a' "$file")"
    [ "$mode" = "600" ] || die "$file must be mode 600 (it is $mode): chmod 600 $file"
    ! grep -v '^[[:space:]]*#' "$file" | grep -q 'CHANGE' || die "$file still has CHANGE placeholders"
done
grep -q '^APP_ENV=production' .env || die "APP_ENV must be production"
grep -q '^APP_DEBUG=false' .env || die "APP_DEBUG must be false"
"${COMPOSE[@]}" config --quiet || die "the Compose configuration is not valid"
if [ -n "$SHARED_PROXY_NETWORK" ]; then
    docker network inspect "$SHARED_PROXY_NETWORK" >/dev/null 2>&1 || die "SHARED_PROXY_NETWORK=$SHARED_PROXY_NETWORK does not exist on this server"
fi

port="$(grep '^ECOM_HTTP_PORT=' .env | cut -d= -f2)"
tag="$(git rev-parse --short HEAD)"
previous="$(cat .deployed-tag 2>/dev/null || true)"
echo "deploy: building $tag (running now: ${previous:-none})"

# --- Build -------------------------------------------------------------------
IMAGE_TAG="$tag" "${COMPOSE[@]}" build app shop-web

# --- Database and Redis ------------------------------------------------------
IMAGE_TAG="$tag" "${COMPOSE[@]}" up -d --wait mysql redis

if [ "$FIRST_INSTALL" = 0 ]; then
    mkdir -p backups && chmod 700 backups
    dump="backups/pre-deploy-$(date -u +%Y%m%dT%H%M%SZ)-${previous:-unknown}.sql.gz"
    echo "deploy: saving the database to $dump before migrating"
    "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --events --no-tablespaces "$MYSQL_DATABASE"' | gzip > "$dump"
    chmod 600 "$dump"
    [ -s "$dump" ] || die "the pre-deploy dump is empty; nothing was migrated"
fi

# --- Migrations (and reference data on a first install) ---------------------
IMAGE_TAG="$tag" "${COMPOSE[@]}" run --rm --no-deps app php artisan migrate --force
if [ "$FIRST_INSTALL" = 1 ]; then
    # Permissions, packages, themes and help articles only — no demo data.
    IMAGE_TAG="$tag" "${COMPOSE[@]}" run --rm --no-deps app php artisan db:seed --force
fi

# --- Application -------------------------------------------------------------
IMAGE_TAG="$tag" "${COMPOSE[@]}" up -d --wait app worker scheduler shop-web
"${COMPOSE[@]}" exec -T app php artisan queue:restart >/dev/null

# --- Check through the published port, as the server's Nginx will ------------
for attempt in $(seq 1 20); do
    if curl -fsS "http://127.0.0.1:${port}/api/v1/public/health" >/dev/null; then
        echo "$tag" > .deployed-tag
        [ -n "$previous" ] && [ "$previous" != "$tag" ] && echo "$previous" > .previous-tag
        echo "deploy: $tag is up and healthy on 127.0.0.1:${port}"
        [ -n "$previous" ] && echo "deploy: to go back: bash deploy/production/rollback.sh"
        exit 0
    fi
    sleep 3
done
"${COMPOSE[@]}" ps
die "the health check did not pass; see: docker compose -p $PROJECT -f docker-compose.prod.yml logs --tail=100 app shop-web"
