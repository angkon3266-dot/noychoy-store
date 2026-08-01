# Deploying Noychoy Store

Production deploy + update guide. Everything here was verified against the live
server and the current code on **2026-08-01**.

## The current setup, at a glance

| | |
|---|---|
| **Live domain** | `https://meridianeclat.shop` |
| **Host** | Hostnin shared cPanel (CloudLinux + LiteSpeed) |
| **SSH host alias** | `hostnin` |
| **Project path** | `/home/noycuuae/repositories/noychoy-store` (`~/repositories/noychoy-store`) |
| **Document root** | `/home/noycuuae/repositories/noychoy-store/public` |
| **PHP** | 8.3 (`alt-php83` for the web, `/opt/alt/php83/usr/bin/php` on the CLI) |
| **Database** | MySQL |
| **Cache** | Redis (`phpredis`) |
| **Sessions / Queue** | database |
| **Cron entries** | exactly **one** — see [§4](#4-how-queued-jobs-actually-run) |

> The path is `~/repositories/noychoy-store`, **not** `~/noychoy-store`. The
> domain is `meridianeclat.shop`, **not** `nocyhoy.com` (that was the old
> WooCommerce store this app replaced).

---

## Deployment flow — the 5-minute checklist

Everything you need for a routine deploy. The rest of this guide is background;
if you're in a hurry, this section is enough.

**1 · Push your work from your PC**
```bash
git push origin main
```
Changed any CSS or JS first? `npm run build`, then commit `public/build` — there
is no `npm` step on the server. *(You don't pull on the server: `deploy.sh` does
that for you in step 2.)*

**2 · Run the deploy script**
```bash
ssh hostnin "cd ~/repositories/noychoy-store && bash deploy.sh"
```
It stops on the first error. If it stops, fix that before going on.

**3 · Verify migrations ran**
```bash
ssh hostnin "cd ~/repositories/noychoy-store && php artisan migrate:status | tail -5"
```
Every row should read `Ran`. `deploy.sh` prints `Nothing to migrate` when there
was nothing new — that's fine.

**4 · Verify queue health**
```bash
ssh hostnin "cd ~/repositories/noychoy-store && php artisan queue:monitor default && php artisan queue:why"
```
Expect `[database] default … [0] OK`. A pending count that keeps climbing means
the scheduler isn't running ([§4](#4-how-queued-jobs-actually-run)).
`queue:why` prints the exception behind anything that failed.

**5 · Flush the LiteSpeed page cache** — cPanel → **LiteSpeed Web Cache Manager
→ Flush All**. Needed whenever visible page output changed (Blade, content,
assets).

**6 · Reset OPcache** — cPanel → **MultiPHP Manager** → toggle the PHP version
away and back. Needed whenever **any `.php` file changed**, which is most
deploys. Skipping this is the #1 reason "my change didn't go live" — the CLI and
the web server run separate PHP processes, and the web one is still holding your
old code.

**7 · Browser smoke test** — open <https://meridianeclat.shop> and check:
home page loads → a product page → add to cart → cart totals look right. Then
`/admin` still logs in. Two minutes, and it catches almost everything.

> **Rollback:** if something is badly wrong, redeploy the previous commit:
> ```bash
> ssh hostnin "cd ~/repositories/noychoy-store && git reset --hard <previous-sha> && composer install --no-dev --optimize-autoloader && php artisan optimize:clear && php artisan optimize"
> ```
> Then repeat steps 5 and 6. Note this does **not** undo a migration — check
> whether the bad deploy ran one before rolling back.

---

## 1. Deploying an update — the normal case

This is the whole thing. From your PC, push your work:

```bash
git push origin main
```

Then run the deploy script on the server:

```bash
ssh hostnin "cd ~/repositories/noychoy-store && bash deploy.sh"
```

Then do the **two cPanel steps in [§2](#2-after-every-deploy-two-manual-steps)** —
they are not optional, and PHP changes will not go live without the second one.

**If any step fails, stop and read the error.** `deploy.sh` uses `set -e`, so it
halts on the first failure rather than half-deploying.

### Changed any CSS or JavaScript?

Built assets in `public/build` are **committed to the repo** — there is no `npm`
step on the server. So before pushing:

```bash
npm run build
git add public/build && git commit -m "Rebuild assets" && git push origin main
```

Skip this and the site silently keeps serving the old bundle. This also applies
when you add a new arbitrary Tailwind class, since Tailwind only compiles classes
it can see at build time.

---

## 2. After every deploy: two manual steps

`deploy.sh` runs on the CLI, which has its own PHP process. The **web** server
runs a separate PHP (`lsphp`) with its own opcode cache, and LiteSpeed serves
some pages from a full-page cache. Neither is reachable from the command line.

1. **cPanel → LiteSpeed Web Cache Manager → Flush All**
   Clears cached HTML pages so visitors see the new content.

2. **cPanel → MultiPHP Manager (or Select PHP Version) → toggle the PHP version**
   (switch it away and back). This restarts `lsphp` and resets **OPcache**, which
   is holding the *old compiled PHP*. **Until you do this, your code changes are
   not live**, no matter what the deploy script printed.

If the site looks stale, 404s every route, or 500s right after a deploy, it is
almost always one of these two.

---

## 3. What `deploy.sh` actually does

You do not need to run these by hand — this is just so the output makes sense.

| Step | Command | Why |
|---|---|---|
| 1 | `git fetch origin` | Get the new code. |
| 2 | `git reset --hard origin/main` | Match the remote exactly, so a stray server-side edit (a `package-lock.json` touched by composer) can't block the update. `.env`, `storage/` and the SQLite file are git-ignored and are **not** touched. |
| 3 | `composer install --no-dev --optimize-autoloader` | Production dependencies only. |
| 4 | `php artisan optimize:clear` | Clears **config, routes, views, events, compiled files and the application cache** — *before* anything that could fail, so a failure never leaves a half-written cache behind. |
| 5 | `php artisan migrate --force` | Applies new migrations. `--force` skips the "are you sure" prompt, which is required for a non-interactive run. |
| 6 | `php artisan optimize` | Rebuilds the config/route/view/event caches. If this fails for any reason the script falls back to `optimize:clear`, so the site keeps serving uncached rather than loading a corrupt cache. |

Because step 4 already clears the **Redis application cache**, there is **no need
to restart Redis** after a deploy — and on shared hosting you could not restart
it anyway, since it is a server-wide service.

There is also **no need to run `php artisan queue:restart`.** Workers here are
short-lived by design (see below) — they exit within ~50 seconds and the next one
starts on fresh code automatically. A `queue:restart` would only matter for a
long-running daemon, and there isn't one.

---

## 4. How queued jobs actually run

**There is exactly one cron entry, and it is the scheduler:**

```cron
* * * * * /opt/alt/php83/usr/bin/php /home/noycuuae/repositories/noychoy-store/artisan schedule:run >> /dev/null 2>&1
```

**You do not need a second cron for `queue:work`.** The scheduler starts the
queue worker itself — `routes/console.php` schedules this every minute:

```
queue:work database --queue=default --stop-when-empty --max-time=50 --sleep=1
```

- `--stop-when-empty` — exits the moment the queue is clear, so it costs nothing
  when idle.
- `--max-time=50` — never overruns the next minute's tick.
- `withoutOverlapping()` — two workers can't pile up.

That worker drains the **`default`** queue, which is where *every* job in this
app lands: order confirmation SMS, invoice emails, Meta Conversions API events,
Meta catalog syncs, knowledge-file syncs. Adding a second `queue:work` cron would
just start a duplicate worker racing the first one for the same jobs — wasted CPU
on a shared host, no benefit.

> ⚠️ **Don't set `META_SYNC_QUEUE` unless you know what you're doing.**
> The scheduled worker's `--queue` comes from `config('meta.sync.queue')`, which
> is `env('META_SYNC_QUEUE', 'default')`. It is deliberately **unset**, so it
> resolves to `default`. If you set it to anything else, the scheduled worker
> will drain only *that* queue and the `default` queue — order SMS, invoices,
> CAPI — will **stop being processed, silently**. If you ever do need a separate
> Meta queue, you must add a second scheduled worker for `default`.

There is also a best-effort instant kick (`App\Services\Meta\MetaQueueRunner`)
that spawns a detached one-shot worker right after a Meta sync is dispatched, so
the merchant sees progress immediately instead of waiting for the next tick. It
is optional by design — it does nothing if `exec()` is disabled — and the
scheduler remains the guarantee.

### Checking the queue is healthy

```bash
ssh hostnin "cd ~/repositories/noychoy-store && php artisan queue:monitor default"
```

To see what failed and *why* (this project ships a custom command for it, because
`queue:failed` doesn't show the exception):

```bash
ssh hostnin "cd ~/repositories/noychoy-store && php artisan queue:why"
```

Other scheduled work (Meta retry/verify, new-arrival notifications, win-back,
abandoned-cart pushes, drip campaigns, log pruning) is defined in
`routes/console.php`. To see it all with next-run times:

```bash
ssh hostnin "cd ~/repositories/noychoy-store && php artisan schedule:list"
```

---

## 5. One-time server setup (reference)

Already done for the current site. Kept for rebuilding, or setting up a second store.

### 5.1 PHP
cPanel → **Select PHP Version** → **8.3**. Enable:
`pdo_mysql, mbstring, openssl, curl, zip, gd, intl, fileinfo, exif, bcmath`
(plus `redis` if you intend to use the Redis cache store).

### 5.2 Database
cPanel → **MySQL Databases**: create a database and a user, add the user to the
database with **All Privileges**. Note the cPanel-prefixed names.

### 5.3 Get the code on the server
```bash
ssh hostnin
mkdir -p ~/repositories && cd ~/repositories
git clone https://github.com/angkon3266-dot/noychoy-store.git
cd noychoy-store
```

### 5.4 Point the domain at `/public`
cPanel → **Domains** → set the **Document Root** to:
```
/home/<cpaneluser>/repositories/noychoy-store/public
```
Laravel must be served from `public/` — never from the project root, or your
`.env` becomes web-reachable.

### 5.5 Dependencies
```bash
composer install --no-dev --optimize-autoloader
```
No `npm` — `public/build` is committed.

### 5.6 Environment file
```bash
cp .env.example .env
nano .env
```
Set at least:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain
DB_DATABASE=cpaneluser_dbname
DB_USERNAME=cpaneluser_dbuser
DB_PASSWORD=********
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=redis          # or `database` if Redis isn't available
```
Then:
```bash
php artisan key:generate
```
SMS, Steadfast and Meta keys are **not** set here — enter them in
**Admin → Integrations** and **Admin → System Config**.

### 5.7 Migrate and finish
```bash
php artisan migrate --force
php artisan db:seed --force       # first admin: admin@noychoy.com / password
php artisan storage:link
chmod -R 775 storage bootstrap/cache
php artisan optimize
```
**Change the seeded admin password immediately** after your first login.

### 5.8 Cron
cPanel → **Cron Jobs** → add the single entry from [§4](#4-how-queued-jobs-actually-run).
Use the absolute PHP binary path (`/opt/alt/php83/usr/bin/php`) — cron's `PATH`
often points at a different, older PHP.

### 5.9 SSL
cPanel → **SSL/TLS Status** → run **AutoSSL**.

### 5.10 SMS IP whitelist
```bash
curl https://api.ipify.org
```
Add that IP to the KhudeBarta panel's allowed-IP list.

---

## 6. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Code changes not live after a deploy | **OPcache.** Toggle the PHP version in cPanel ([§2](#2-after-every-deploy-two-manual-steps)). This is the single most common one. |
| Page content stale | LiteSpeed page cache — Flush All. |
| Every route 404s | A bad route cache. `php artisan optimize:clear`, then `php artisan optimize`. |
| CSS/JS changes missing | You didn't run `npm run build` and commit `public/build`. |
| Order SMS / invoices not sending | Queue not draining. Check `queue:why` and `schedule:list`; confirm the cron exists and `META_SYNC_QUEUE` is unset ([§4](#4-how-queued-jobs-actually-run)). |
| Blade error right after deploy | `php artisan view:clear`, then `php artisan optimize`. |
| `git reset --hard` worry | It only touches tracked files. `.env`, `storage/` and uploads are git-ignored and safe. |

---

## Related guides

- [GITHUB-GUIDE.md](GITHUB-GUIDE.md) — pushing from your PC, for absolute beginners.
- [docs/SYSTEM_CONFIG.md](docs/SYSTEM_CONFIG.md) — managing config from the admin panel instead of `.env`.
- [docs/META_INTEGRATION.md](docs/META_INTEGRATION.md) — Meta catalog sync, Pixel and Conversions API.
- [docs/CODE_REVIEW.md](docs/CODE_REVIEW.md) — current state of the codebase, open items.

> The WooCommerce migration guide and the Namecheap deploy guide were removed on
> 2026-08-01, and WooCommerce support itself was removed on 2026-08-02: the
> `woo:import` command, its config and its database columns are all gone. The
> site does not run on Namecheap.
