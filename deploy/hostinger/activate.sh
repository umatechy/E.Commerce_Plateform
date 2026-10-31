#!/bin/sh
# On the server: unpack a release, check the server, migrate, switch to it.
# Stops at the first problem; the running release stays until the switch.
#   sh activate.sh ~/shop-<sha>.tar.gz
set -eu
SHOP="${SHOP:-$HOME/shop}"
PHP_BIN="$(cat "$SHOP/shared/php-bin" 2>/dev/null || echo php)"
FILE="$1"
NAME="$(basename "$FILE" .tar.gz)"
RELEASE="$SHOP/releases/$NAME"

[ -f "$SHOP/shared/.env" ] || { echo "$SHOP/shared/.env is missing."; exit 1; }
mkdir -p "$RELEASE"
tar -xzf "$FILE" -C "$RELEASE"
mkdir -p "$SHOP/shared/storage/app/public" "$SHOP/shared/storage/framework/cache" "$SHOP/shared/storage/framework/sessions" "$SHOP/shared/storage/framework/views" "$SHOP/shared/storage/logs"
rm -rf "$RELEASE/storage"
ln -s "$SHOP/shared/storage" "$RELEASE/storage"
ln -s "$SHOP/shared/.env" "$RELEASE/.env"
rm -f "$RELEASE/public/storage"
ln -s "$SHOP/shared/storage/app/public" "$RELEASE/public/storage"

cd "$RELEASE"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan hosting:preflight
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan help:sync

ln -sfn "$RELEASE" "$SHOP/current.new"
mv -T "$SHOP/current.new" "$SHOP/current"
"$PHP_BIN" artisan queue:restart
echo "Live: $NAME"
