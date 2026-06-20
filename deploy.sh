#!/usr/bin/env bash
# Noychoy Store — one-shot update script. Run on the server: bash deploy.sh
set -e

echo "→ Pulling latest code…"
git pull

echo "→ Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader

if [ -f package.json ]; then
  echo "→ Building front-end assets…"
  npm install --no-audit --no-fund
  npm run build
fi

echo "→ Running database migrations…"
php artisan migrate --force

echo "→ Refreshing caches…"
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✓ Deploy complete."
