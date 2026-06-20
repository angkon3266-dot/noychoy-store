# Importing your WooCommerce data

This migrates **categories, products (with images & variations), customers, and
orders** from your current WordPress/WooCommerce store into the Laravel store.

It uses the WooCommerce REST API — no SQL export needed. It's **idempotent**:
every record is matched by its WooCommerce id, so you can run it as many times as
you like without creating duplicates.

---

## Step 1 — Get WooCommerce API keys (on your old WordPress site)

1. Log into **WordPress admin** of your current store.
2. Go to **WooCommerce → Settings → Advanced → REST API**.
3. Click **Add key**.
   - Description: `Laravel migration`
   - User: pick an admin user
   - Permissions: **Read** (read-only is enough and safe)
4. Click **Generate API key**.
5. Copy the **Consumer key** (`ck_…`) and **Consumer secret** (`cs_…`) — they're
   shown only once.

Your **Store URL** is just your current site address, e.g. `https://nocyhoy.com`
(or the temporary URL if the old site is on a staging domain).

---

## Step 2 — Put the keys in `.env`

On the server (or locally if testing), edit `.env`:
```
WC_STORE_URL=https://your-old-store.com
WC_CONSUMER_KEY=ck_xxxxxxxxxxxxxxxxxxxx
WC_CONSUMER_SECRET=cs_xxxxxxxxxxxxxxxxxxxx
```

> The old WordPress store must be **online and reachable** during the import,
> because we pull data (and download product images) from it live.

---

## Step 3 — Run the import

Test first with a small batch:
```bash
php artisan woo:import --products --limit=5
```
Check the storefront — if those 5 products look right, run the full import:
```bash
php artisan woo:import --all
```

### Useful options
| Command | What it does |
|---|---|
| `php artisan woo:import --all` | Everything: categories → products → customers → orders |
| `php artisan woo:import --products` | Categories + products only |
| `php artisan woo:import --customers` | Customers only |
| `php artisan woo:import --orders` | Orders only |
| `--no-images` | Skip downloading images (much faster; link them later) |
| `--limit=20` | Stop after 20 of each type (for testing) |

**Order matters:** import **products before orders** so order line items can link
to the right products. `--all` already does this in the correct order.

---

## Notes
- **Images** are downloaded from the old store and converted to **WebP** automatically.
  If a download fails, the original image URL is kept so nothing breaks.
- If the old store has **thousands of products/images**, shared hosting may hit a
  time limit. In that case run `--no-images` first (fast), then re-run without it,
  or run in chunks using `--limit` while it resumes by `woo_id`.
- Imported orders are prefixed `WC-` (e.g. `WC-1042`) and keep their original date,
  so your repeat-customer detection and reports stay accurate.
- Re-running is safe — it updates existing records instead of duplicating them.

## After import
1. Spot-check products, prices, images, and a few orders in the admin.
2. Set cost prices / margins where you want them (not in Woo).
3. You're ready to go live.
