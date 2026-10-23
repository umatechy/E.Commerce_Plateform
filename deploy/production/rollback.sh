#!/usr/bin/env bash
# Umar Techy E-Commerce — go back to the previously deployed images.
#
# Restarts only this project's app, worker, scheduler and web containers on
# the images recorded by deploy.sh. The database is not touched: migrations
# here only add tables and columns, so older code runs on a newer schema. If
# a release ever needs its migration undone, restore the pre-deploy dump
# (backups/pre-deploy-*.sql.gz) by hand, as described in
# docs/deployment/production.md.
#
#   cd /opt/umartechy-ecommerce && bash deploy/production/rollback.sh [tag]
set -euo pipefail

PROJECT=umartechy-ecom
COMPOSE=(docker compose -p "$PROJECT" -f docker-compose.prod.yml)

tag="${1:-$(cat .previous-tag 2>/dev/null || true)}"
[ -n "$tag" ] || { echo "rollback: no previous tag recorded; pass one: rollback.sh <tag>" >&2; exit 1; }
docker image inspect "umartechy-ecom-app:$tag" >/dev/null 2>&1 || { echo "rollback: image umartechy-ecom-app:$tag is not on this server" >&2; exit 1; }

IMAGE_TAG="$tag" "${COMPOSE[@]}" up -d --wait --no-build app worker scheduler web
"${COMPOSE[@]}" exec -T app php artisan queue:restart >/dev/null
echo "$tag" > .deployed-tag
port="$(grep '^ECOM_HTTP_PORT=' .env | cut -d= -f2)"
curl -fsS "http://127.0.0.1:${port}/api/v1/public/health" >/dev/null && echo "rollback: $tag is running and healthy" || echo "rollback: $tag is running; the health check did not pass yet" >&2
