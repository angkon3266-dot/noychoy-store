# Deploying to Namecheap Shared Hosting (cPanel + SSH)

Step-by-step for **nocyhoy.com** on Namecheap shared hosting. Assets are
pre-built and committed, so **no `npm` is needed on the server**.

Throughout, replace `USER` with your cPanel username and `nocyhoy.com` with your domain.

---

## Step 1 — Enable SSH access
1. Namecheap account → **Hosting List → Manage** → cPanel.
2. In cPanel search for **"SSH Access"** (or use the **Terminal** tile if your plan shows it).
3. If using an external client (PuTTY / Windows Terminal): under **SSH Access → Manage SSH Keys**, generate or import a key, then **Authorize** it.
4. Connect:
   ```bash
   ssh USER@nocyhoy.com -p 21098
   ```
   (Namecheap shared SSH port is usually **21098**, not 22. The exact host/port is shown in cPanel → "SSH Access".)

If the cPanel **Terminal** tile exists, you can just use that in the browser instead.

---

## Step 2 — Set the PHP version
cPanel → **Select PHP Version** (or **MultiPHP Manager**):
- Set the domain to **PHP 8.3**.
- Tick extensions: `pdo_mysql, mbstring, openssl, curl, zip, gd, intl, fileinfo, exif, bcmath`.

---

## Step 3 — Create the database
cPanel → **MySQL Databases**:
1. Create database → e.g. `noychoy_store` (real name becomes `USER_noychoy_store`).
2. Create a user + strong password (real name `USER_noychoy`).
3. **Add user to database → All Privileges.**
4. Write down: DB name, DB user, password.

---

## Step 4 — Get the code (via Git)
Over SSH, into your home folder (NOT public_html):
```bash
cd ~
git clone https://github.com/YOURNAME/noychoy-store.git
cd noychoy-store
```
(First clone of a private repo asks you to log in — use a GitHub Personal Access
Token as the password, see GITHUB-GUIDE.md.)

---

## Step 5 — Install PHP dependencies
Try:
```bash
composer install --no-dev --optimize-autoloader
```
If `composer` isn't found, use the full PHP path (Namecheap):
```bash
/usr/local/bin/ea-php83 /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader
```
If that path doesn't exist either, install Composer locally:
```bash
curl -sS https://getcomposer.org/installer | ea-php83
ea-php83 composer.phar install --no-dev --optimize-autoloader
```
> No `npm` step needed — `public/build` is already in the repo.

---

## Step 6 — Configure `.env`
```bash
cp .env.example .env
nano .env
```
Set:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nocyhoy.com
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=USER_noychoy_store
DB_USERNAME=USER_noychoy
DB_PASSWORD=your-password
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```
Generate the app key:
```bash
php artisan key:generate
```
(SMS/Steadfast/Meta keys go in **Admin → Integrations** after launch; or fill the
`KHUDEBARTA_*` / `STEADFAST_*` / `META_*` lines now as fallback.)

---

## Step 7 — Point the domain at Laravel's `/public`

Namecheap serves your **primary domain** from `~/public_html`, and that path can't
be changed in the UI. So make `public_html` a link to the app's `public` folder:

```bash
cd ~
# Back up whatever is currently in public_html (old WordPress, etc.)
mv public_html public_html_OLD_backup
# Link public_html to the Laravel public folder
ln -s ~/noychoy-store/public public_html
```
> ⚠️ This replaces the site currently served at your domain. Only do it when you're
> ready to switch from the old store. Your old files stay safe in `public_html_OLD_backup`.

**Alternative (if you can't/don't want to symlink):** copy the *contents* of
`~/noychoy-store/public` into `~/public_html`, then edit `~/public_html/index.php`
and change the two `require __DIR__.'/../...'` lines to point to `~/noychoy-store/...`.
Ask me and I'll give you the exact two-line edit.

---

## Step 8 — Finalize Laravel
```bash
cd ~/noychoy-store
php artisan migrate --force
php artisan storage:link
chmod -R 775 storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## Step 9 — Import your store data
Follow **WOO-IMPORT.md**:
```bash
php artisan woo:import --all
```

---

## Step 10 — SSL + go live
1. cPanel → **SSL/TLS Status** → run **AutoSSL** (free, included).
2. Visit `https://nocyhoy.com`, log into `/admin` (`admin@noychoy.com` / `password` — change it!).
3. Place one test order end-to-end.

---

## Step 11 — Cron jobs
cPanel → **Cron Jobs** → add two (every minute). Use the full PHP path:
```
* * * * * cd ~/noychoy-store && /usr/local/bin/ea-php83 artisan schedule:run >/dev/null 2>&1
* * * * * cd ~/noychoy-store && /usr/local/bin/ea-php83 artisan queue:work --stop-when-empty --tries=3 >/dev/null 2>&1
```

---

## Step 12 — SMS IP whitelist
```bash
curl https://api.ipify.org
```
Add that IP to your **KhudeBarta** panel's allowed IPs (if your old store ran on
this same server, the IP is already whitelisted).

---

## Updating later
After you `git push` new changes from your PC:
```bash
cd ~/noychoy-store && bash deploy.sh
```
(`deploy.sh` pulls, installs PHP deps, migrates, and refreshes caches — no npm.)

## First-login security
- Change the admin password immediately.
- Set `APP_DEBUG=false` (already in Step 6) so errors aren't shown publicly.
