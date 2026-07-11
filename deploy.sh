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

# Clear caches FIRST, before anything that could fail (migrations). This way a
# failure never leaves the site loading a stale/half-written config or route
# cache — the app just runs uncached, which still works.
echo "→ Clearing caches…"
php artisan optimize:clear

echo "→ Running database migrations…"
php artisan migrate --force

# Re-cache for performance. If caching fails for ANY reason, fall back to the
# cleared (uncached) state so the site keeps serving instead of loading a
# corrupt cache and 404-ing every route.
echo "→ Rebuilding caches (config / routes / views / events)…"
if ! php artisan optimize; then
    echo "⚠ optimize failed — reverting to uncached state so the site stays up."
    php artisan optimize:clear
fi

echo "✓ Deploy complete."
echo
echo "IMPORTANT — the CLI steps above do NOT clear the LiteSpeed page cache or the"
echo "web (lsphp) OPcache. If the site looks stale or returns 404/500 after a deploy:"
echo "  1. cPanel → LiteSpeed Web Cache Manager → Flush All."
echo "  2. cPanel → MultiPHP Manager (or Select PHP Version) → toggle the PHP"
echo "     version to restart lsphp and reset its OPcache."
