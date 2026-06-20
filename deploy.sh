#!/usr/bin/env bash
# Noychoy Store — one-shot update script. Run on the server: bash deploy.sh
set -e

echo "→ Pulling latest code…"
git pull

echo "→ Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader

# NOTE: front-end assets (public/build) are committed to the repo, so there is
# NO npm build step here. If you change CSS/JS, run `npm run build` on your PC
# and commit public/build, then `git push` and re-run this script.

echo "→ Running database migrations…"
php artisan migrate --force

echo "→ Refreshing caches…"
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✓ Deploy complete."
