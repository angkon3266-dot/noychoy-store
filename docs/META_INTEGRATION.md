# Meta Commerce Manager Integration

A native Laravel module that syncs your store's products into a Meta (Facebook /
Instagram) Commerce Catalog — the same capability as the official
"Facebook for WooCommerce" plugin, built directly into this platform.

It is multi-tenant by design: **every client enters their own Meta credentials
in the admin UI — nothing is hardcoded.**

---

## 1. What it does

- **Two connection modes**
  - **Development Mode (manual)** — paste a System User long-lived token. Best
    for testing or before you have a production Meta App.
  - **Production Mode (OAuth)** — "Connect with Facebook", pick your Business &
    Catalog, no token copy/paste.
- **Automatic sync** on every product create / update / delete / restore (price,
  sale price, stock, SKU, name, description, category, brand, images, URL, …).
- **Variable products**: each active variation is synced independently (SKU,
  price, stock, images, attributes) as an `item_group`.
- **Queued** — nothing syncs inside a web request. Retries with backoff, failed
  job handling, batch processing with a live progress bar.
- **Sync Logs** with product, action, status, Meta response, API error, retry
  count, execution time, plus search & filters.
- **Scheduled** hourly retry of failures + daily full-catalog verification.
- **Webhooks** endpoint for Meta subscription verification + event delivery.
- **Secondary security wall**: a separate password (bcrypt), 5-attempt lockout
  for 15 minutes, full access audit log, Super-Admin only.

---

## 2. Installation

The module ships with the application. To activate it on a server:

```bash
php artisan migrate            # creates meta_sync_states, meta_sync_logs, meta_access_logs
php artisan config:clear
php artisan queue:table        # only if the jobs/job_batches tables don't exist yet
```

A queue worker **must** be running (see §7) and the scheduler cron installed
(see §8).

### Environment variables

All per-store credentials are entered in the admin UI. Only these optional,
vendor-level values live in `.env`:

```dotenv
# Graph API (optional overrides)
META_GRAPH_VERSION=v21.0

# Production Mode OAuth — your Meta App (leave blank to offer Dev Mode only)
META_APP_ID=
META_APP_SECRET=
META_LOGIN_CONFIG_ID=            # optional: Facebook Login for Business config id

# Webhooks (optional)
META_WEBHOOK_VERIFY_TOKEN=some-random-string

# Catalog defaults
META_CURRENCY=BDT
META_DEFAULT_BRAND="Your Store"
META_GOOGLE_CATEGORY=            # optional default google_product_category
```

---

## 3. Configuration (admin)

Open **Admin → Marketing → Meta**. (The Marketing Center hub lists every
channel; Meta is live, the others show "Coming soon".) On first entry you'll be
asked to **set a security password** (separate from your login). After that, the
module is locked behind that password (auto-locks after inactivity; 5 wrong
tries = 15-min lockout).

The Meta module has four tabs:

- **Dashboard** — connection status + health (token / Graph API / webhook /
  queue), stat cards (synced / pending / failed / never synced / success rate /
  today / last sync / avg API response), the OAuth wizard, settings and sync
  buttons.
- **Sync Logs** — search + status/action/date/product filters, row-level retry,
  retry-all-failed, and CSV export.
- **Queue** — live waiting/running/completed/failed counts, pause/resume, retry.
- **Webhook** — callback URL, verify-token + verification status, last event.

Single products can also be synced/removed and their log viewed directly from
the **product edit page** (Meta status card).

### Development Mode

1. Enter **Business ID**, **Catalog ID**, **System User Access Token**, and
   optionally **Pixel ID**.
2. Toggle **Enable Meta Integration**.
3. Click **Save**, then **Test Connection**.
4. Click **Sync All Products** to import your catalog.

The token is stored **encrypted (AES-256)** via Laravel `Crypt`. Leaving the
token field blank on save keeps the previously-stored token.

### Production Mode (OAuth)

Requires `META_APP_ID` / `META_APP_SECRET` **and** a **Facebook Login for
Business** configuration (`META_LOGIN_CONFIG_ID`). Click **Connect with
Facebook**, authenticate, choose your Business + Catalog. Automatic sync turns
on. Use **Reconnect** to refresh the token, **Disconnect** to clear credentials
(sync history is preserved).

> **Why Login for Business?** `catalog_management` / `business_management` are
> **not** valid *standard* Facebook Login scopes — passing them in the OAuth
> `scope` parameter returns **"Invalid Scopes"**. In Meta's current flow those
> asset permissions are granted through a **Login-for-Business configuration**
> (referenced by `config_id`), not the `scope` param. This integration sends
> `config_id` and **no** `scope` when it is set; without it, the standard-login
> fallback requests only `public_profile` (which cannot read catalogs — use
> Development Mode, or configure Login for Business).

---

## 4. Meta App setup (Production Mode)

1. Go to <https://developers.facebook.com/apps> → **Create App** → *Business*.
2. Add the **Facebook Login for Business** product.
3. In its settings, add the **Valid OAuth Redirect URI**:
   `https://YOUR-DOMAIN/admin/meta/oauth/callback`
4. Create a **Login configuration** with the **Token type = System User** and the
   assets/permissions **`business_management`** and **`catalog_management`**.
   Copy its **Configuration ID**.
5. Set env: `META_APP_ID`, `META_APP_SECRET`, and
   **`META_LOGIN_CONFIG_ID`** = the configuration ID.
   (`business_management` / `catalog_management` still need **App Review +
   Business Verification** to work for accounts other than your app's own
   admins/developers/testers.)

You can set all three under **Admin → System Config → Meta** instead of `.env`.

> Don't have a configuration yet? Use **Development Mode** with a System User
> token — it needs no app review and works today.

### Advanced: standard-login scopes

If you deliberately run without a `config_id`, set `META_OAUTH_SCOPES`
(comma-separated) to the standard scopes your app supports. Default is
`public_profile`. Do **not** put `catalog_management` / `business_management`
here — they are not valid standard-login scopes.

---

## 5. System User creation & token generation (Development Mode)

1. **Business Settings** → **Users → System Users** → **Add** (choose *Admin* or
   *Employee*).
2. **Assign assets** → add your **Catalog** with *Manage catalog* permission.
3. Click **Generate new token**, select your App, and tick **`catalog_management`**
   (and `business_management`).
4. Choose **Never** for expiry to get a long-lived server-to-server token.
5. Copy the token into the admin **System User Access Token** field.

**Required permissions:** `catalog_management` (mandatory),
`business_management` (recommended). The Test Connection button verifies these.

Find your **Business ID** in Business Settings → *Business Info*, and your
**Catalog ID** in **Commerce Manager → Catalog → Settings**.

---

## 6. Field mapping

| Meta field | Source |
|---|---|
| `retailer_id` | `prod-{id}` (or `prod-{id}-var-{variantId}`) |
| `title` | product name (+ variant label) |
| `description` | description / short description (HTML stripped) |
| `availability` | stock / preorder state |
| `condition` | `new` (configurable) |
| `price` | `compare_at_price` if higher, else `price` |
| `sale_price` | `price` when a higher compare-at exists |
| `inventory` | `stock_quantity` (if stock-managed) |
| `brand` | product "Brand" custom field, else default |
| `product_type` | category path (`Parent > Child`) |
| `google_product_category` | `META_GOOGLE_CATEGORY` default |
| `image_link` / `additional_image_link` | primary + up to 20 gallery images |
| `link` | product page URL |
| `color` / `size` / `material` / `pattern` / `gender` | product colors + variant attributes |
| `mpn` | SKU |
| `custom_label_0` | `bestseller` flag |

Each sync option toggle (images, inventory, price, categories, variations, …)
can suppress the corresponding fields.

---

## 7. Queue setup

Sync runs on Laravel queues (this app uses the **database** driver). Run a
worker as a persistent process:

```bash
php artisan queue:work --tries=5 --backoff=60,300,900,1800 --timeout=120
```

On shared cPanel, add it as a **Cron job** that keeps the worker alive, or use
`queue:work --stop-when-empty` on a frequent schedule. Batches and failed jobs
use the `job_batches` / `failed_jobs` tables (already migrated).

Retry failed jobs manually any time:

```bash
php artisan queue:retry all
```

…or use **Sync Logs → Retry failed** in the UI.

---

## 8. Cron setup (scheduler)

Add the single Laravel scheduler entry to your server's crontab:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

This drives:

- **Hourly** — `RetryFailedMetaSyncs` (re-queues failed products).
- **Daily 03:30** — `VerifyCatalogSync` (re-queues anything not in sync).

---

## 9. Webhooks (optional)

In your Meta App → **Webhooks**, subscribe the relevant object and set:

- **Callback URL:** `https://YOUR-DOMAIN/webhooks/meta`
- **Verify token:** the value of `META_WEBHOOK_VERIFY_TOKEN`

The endpoint verifies the subscription handshake and validates the
`X-Hub-Signature-256` HMAC on delivery.

---

## 10. Security

- Access token stored **encrypted** (`Crypt::encryptString`).
- Secondary **security password** stored with **bcrypt** (`Hash::make`), never
  reversible; changeable from the module; 5-attempt → 15-minute lockout;
  per-IP rate limiting; every attempt written to `meta_access_logs`.
- Module routes are **Super-Admin only** (role `admin`), enforced by the
  `admin` middleware section gate, the `MetaSecurityGate` middleware, and the
  `meta.access` policy/gate (also used by the Form Requests).
- Credentials are never rendered back to the browser (token field shows a
  masked placeholder; `safeSnapshot()` strips token + password).

---

## 11. Architecture

```
config/meta.php                      static + OAuth app config
app/Services/Meta/
  MetaSettings.php                   encrypted, cached per-store config store
  MetaGraphClient.php                thin Graph API transport (+ error mapping)
  MetaProductMapper.php              Product/Variant → catalog item data
  MetaCatalogService.php             orchestration: test, sync, remove, verify
  Data/ConnectionStatus.php          DTO for Test Connection
  Exceptions/MetaApiException.php    categorised API errors (retryable?)
app/Jobs/Meta/                       SyncProductToMeta, RemoveProductFromMeta,
                                     RetryFailedMetaSyncs, VerifyCatalogSync
app/Observers/                       MetaProductObserver, MetaVariantObserver
app/Http/Middleware/MetaSecurityGate.php
app/Http/Requests/Meta/              MetaSettingsRequest, MetaSecurityPasswordRequest
app/Policies/MetaPolicy.php
app/Http/Controllers/Admin/          MetaIntegrationController, MetaSecurityController,
                                     MetaSyncLogController, MetaOAuthController
app/Http/Controllers/MetaWebhookController.php
app/Models/                          MetaSyncState, MetaSyncLog, MetaAccessLog
resources/views/admin/meta/          index, unlock, logs, select-catalog, partials
```

Auto-sync is driven by model **observers** (idempotent — the mapper hashes the
payload so unchanged products are skipped before any API call). Everything
heavy is dispatched to **queue jobs**; controllers only validate and dispatch.

---

## 12. Troubleshooting

| Symptom | Fix |
|---|---|
| ❌ Invalid Token | Regenerate the System User token; ensure it hasn't been revoked. |
| ❌ Token Expired | Generate a **Never**-expiry System User token. |
| ❌ Missing Permission | Add `catalog_management` to the token; assign the Catalog to the System User. |
| ❌ Catalog Not Found | Check the Catalog ID; confirm the token's business owns it. |
| ❌ Connection Failed | Server can't reach graph.facebook.com — check outbound HTTPS/firewall. |
| Nothing syncs | Confirm the **queue worker** is running and **Enable** + **Auto-sync** are on. |
| Products missing from catalog | They may be draft/out-of-stock/hidden — enable the matching **Sync option**. |
| Batch stuck | Check `failed_jobs`; use **Retry failed**; inspect **Sync Logs → Details**. |

Errors never crash the storefront: API failures are caught, categorised, logged,
and retried (for transient errors) — the product page and admin keep working.
