#!/usr/bin/env bash
# Noychoy Store — one-shot update script. Run on the server: bash deploy.sh
set -e

echo "===== Deployment Started ====="

echo "→ Fetching latest code…"
git fetch origin

# Hard-reset to the remote so server-side local changes (e.g. package-lock.json
# touched by composer/npm) never block the update. .env, storage/ and the SQLite
# file are git-ignored, so they are NOT affected by this.
echo "→ Resetting working tree to origin/main…"
git reset --hard origin/main

echo "→ Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader

# Front-end assets (public/build) are committed to the repo — no npm step here.
# If you change CSS/JS, run `npm run build` on your PC, commit public/build, push.

echo "→ Running database migrations…"
php artisan migrate --force

echo "→ Clearing & rebuilding caches (config / routes / views / app cache)…"
php artisan optimize:clear
php artisan optimize

echo "✓ Deploy complete."
echo "  If styling/layout still looks stale, flush LiteSpeed Cache in cPanel."
