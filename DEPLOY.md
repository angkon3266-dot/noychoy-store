# Deploying Noychoy Store to cPanel

This is the production deploy + update guide for **nocyhoy.com** on shared cPanel
(CloudLinux + LiteSpeed). Pair it with [GITHUB-GUIDE.md](GITHUB-GUIDE.md) for the
Git side.

---

## A. One-time server setup

### 1. PHP version
cPanel → **Select PHP Version** → choose **PHP 8.3**.
Enable extensions: `pdo_mysql, mbstring, openssl, curl, zip, gd, intl, fileinfo, exif, bcmath`.

### 2. Database
cPanel → **MySQL Databases**:
- Create database (e.g. `noychoy_store`)
- Create a user + strong password
- Add the user to the database with **All Privileges**
- Note the *prefixed* names: `cpaneluser_noychoy_store`, `cpaneluser_noychoy`.

### 3. Get the code onto the server
Via SSH (Terminal in cPanel, or PuTTY):
```bash
cd ~
git clone https://github.com/<your-username>/noychoy-store.git
cd noychoy-store
```

### 4. Point the domain at /public
cPanel → **Domains** → manage `nocyhoy.com` → set **Document Root** to:
```
/home/<cpaneluser>/noychoy-store/public
```

### 5. Install dependencies + build
```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build      # or build locally and commit /public/build
```

### 6. Environment file
```bash
cp .env.example .env
nano .env
```
Set at least:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nocyhoy.com
DB_DATABASE=cpaneluser_noychoy_store
DB_USERNAME=cpaneluser_noychoy
DB_PASSWORD=********
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```
Then:
```bash
php artisan key:generate
```
(SMS / Steadfast / Meta keys are entered in **Admin → Integrations**, not here.)

### 7. Migrate + finish
```bash
php artisan migrate --force
php artisan storage:link
chmod -R 775 storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### 8. Cron jobs
cPanel → **Cron Jobs** → add (every minute):
```
* * * * * cd ~/noychoy-store && php artisan schedule:run >/dev/null 2>&1
* * * * * cd ~/noychoy-store && php artisan queue:work --stop-when-empty --tries=3 >/dev/null 2>&1
```

### 9. SSL
cPanel → **SSL/TLS Status** → run **AutoSSL** for the domain.

### 10. SMS IP whitelist
```bash
curl https://api.ipify.org
```
Add that IP to your KhudeBarta panel's allowed-IP list. (If WooCommerce already
runs on this server it shares the IP and SMS works immediately.)

---

## B. Deploying an UPDATE (after first setup)

Whenever you change code (see GITHUB-GUIDE.md to push from your PC), run on the server:
```bash
cd ~/noychoy-store
git pull
composer install --no-dev --optimize-autoloader   # only if vendor changed
npm install && npm run build                        # only if JS/CSS changed
php artisan migrate --force                          # only if new migrations
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
> Tip: if a page shows an old version or a Blade error after an update, run
> `php artisan view:clear` then `php artisan view:cache` again.

A ready-made script `deploy.sh` is included — run `bash deploy.sh` to do all of the above in one go.

---

## C. Before go-live checklist
- [ ] Import WooCommerce products/customers/orders (the `woo:import` command — ask to have it built)
- [ ] Set shipping rates (Admin → Settings)
- [ ] Enter Steadfast + KhudeBarta + Meta Pixel keys (Admin → Integrations)
- [ ] Place one test order end-to-end on a temp URL
- [ ] Switch DNS to the new server
