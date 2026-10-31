#!/bin/sh
# On a developer's machine: a release file from the committed code, with
# production dependencies and built assets. It contains no .env.
#   PHP=... COMPOSER=... sh deploy/hostinger/build-release.sh [output.tar.gz]
set -eu
[ -z "$(git status --porcelain)" ] || { echo "Commit first: the release is built from HEAD."; exit 1; }
SHA="$(git rev-parse --short HEAD)"
OUT="${1:-$(pwd)/shop-$SHA.tar.gz}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

git -c core.autocrlf=false archive HEAD -- . ':!tests' ':!docs' ':!resources/js/test' ':!docker' | tar -x -C "$TMP"
npm run build
rm -f public/hot
cp -r public/build "$TMP/public/build"
(cd "$TMP" && ${PHP:-php} ${COMPOSER:-composer} install --no-dev --no-interaction --no-scripts --optimize-autoloader --quiet)
rm -f "$TMP/bootstrap/cache/"*.php
echo "$SHA" > "$TMP/RELEASE"
tar -czf "$OUT" -C "$TMP" .
echo "$OUT"
