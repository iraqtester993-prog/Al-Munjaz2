#!/bin/bash
set -euo pipefail

ROOT=/home/ourqiq
RELEASES="$ROOT/releases"
RELEASE_NAME=almunjaz-20260905-document-preview-rejection-r10
NEW_RELEASE="$RELEASES/$RELEASE_NAME"
ARCHIVE="$RELEASES/$RELEASE_NAME.zip"
CURRENT_RELEASE="$(readlink -f "$RELEASES/current")"
CURRENT_APP="$CURRENT_RELEASE/backend"
NEW_APP="$NEW_RELEASE/backend"
SHARED_STORAGE="$ROOT/shared/storage"

test -d "$CURRENT_APP"
test -d "$SHARED_STORAGE"
test ! -e "$NEW_RELEASE"

mkdir -p "$NEW_RELEASE"
unzip -q "$ARCHIVE" -d "$NEW_RELEASE"
test -f "$NEW_APP/artisan"
test -f "$CURRENT_APP/.env"
test -d "$CURRENT_APP/vendor"

cp "$CURRENT_APP/.env" "$NEW_APP/.env"
cp -a "$CURRENT_APP/vendor" "$NEW_APP/vendor"
rm -rf "$NEW_APP/storage"
ln -s "$SHARED_STORAGE" "$NEW_APP/storage"
mkdir -p "$NEW_APP/bootstrap/cache"

cd "$NEW_APP"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

ln -sfn "$CURRENT_RELEASE" "$RELEASES/previous"
ln -sfn "$NEW_RELEASE" "$RELEASES/current"

ln -sfn "$NEW_APP/public/assets" "$ROOT/public_html/mobile/assets"
ln -sfn "$NEW_APP/public/build" "$ROOT/public_html/mobile/build"
ln -sfn "$NEW_APP/storage/app/public" "$ROOT/public_html/mobile/storage"
ln -sfn "$NEW_APP/public/assets" "$ROOT/public_html/admin/assets"
ln -sfn "$NEW_APP/public/build" "$ROOT/public_html/admin/build"
ln -sfn "$NEW_APP/storage/app/public" "$ROOT/public_html/admin/storage"

printf 'DEPLOYMENT_RESULT=success\n'
printf 'DEPLOYMENT_CURRENT_RELEASE=%s\n' "$(readlink -f "$RELEASES/current")"
