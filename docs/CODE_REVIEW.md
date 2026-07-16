# Code Review — noychoy-store
*Reviewed: 2026-07-15 · Laravel 13 / PHP 8.3 / Tailwind 4 / Alpine.js*

**Overall verdict:** This is a well-built codebase. Clear structure (Actions, Services, Modules), good comments, consistent style, sensible use of Laravel conventions. The issues below are mostly hardening, performance, and feature gaps — not rewrites.

---

## 1. Critical — fix these first

### 1.1 No rate limiting on any public endpoint
Nothing on the storefront is throttled. Verified: the only throttling in the app is inside the Meta admin module.

Highest-risk endpoints:
- **`POST /password/forgot` (SMS OTP)** — an attacker can loop this and drain your SMS credit / bomb any phone number.
- **`POST /login` (customer + admin)** — unlimited password guessing.
- **`POST /product/{slug}/review`** — unlimited spam + 4×5MB photo uploads per request = disk exhaustion.
- **`POST /checkout/lead`, `/cart/*`, `POST /checkout`** — DB-write spam, fake orders.

Fix (cheap): add `->middleware('throttle:5,1')` (tune per route) in `routes/web.php`, and `RateLimiter` with per-phone keys for the OTP route. Admin login deserves `throttle:5,1` + a lockout.

### 1.2 Order enumeration via sequential order numbers
`Order::generateNumber()` produces sequential 5-digit numbers (10001, 10002…), and `GET /order/{orderNumber}/confirmation` requires **no auth** — anyone can walk `/order/10001/confirmation`, `/order/10002/confirmation`… and see customer names, items bought, and totals. It also leaks your total order volume to competitors.

Fix options (pick one):
- Sign the confirmation URL (`URL::signedRoute`) when redirecting from checkout, and require the signature.
- Or gate the page behind a session flag / last 4 digits of the phone.
- Keep sequential numbers internally if you like them for ops — just don't let the number alone unlock the page.

Related: `generateNumber()` has a race — two simultaneous checkouts read the same max and the second insert dies on the unique constraint (customer sees a 500). Wrap in a retry, or generate inside the transaction with a lock.

### 1.3 Oversell: stock is never checked, only decremented
- `CartController::add()` / `CartService::add()` never check `stock_quantity`.
- `PlaceOrder::decrementStock()` decrements without `lockForUpdate()` and without a floor — stock can go negative, and two buyers can both purchase the last unit.

Fix: in `PlaceOrder`, inside the transaction, re-fetch each product/variant with `lockForUpdate()`, verify `stock_quantity >= qty` (when `manage_stock`), and reject with a friendly "only X left" message. Also check availability at cart-add time for better UX.

### 1.4 Cart prices are trusted from the session, never re-validated
`CartService::add()` snapshots price/offers into the session. `PlaceOrder` charges that snapshot. If you change a price, end a sale, or unpublish a product, a visitor with an old tab/session still buys at the stale price (sessions live 120 min+ and carts persist across visits).

Fix: in `PlaceOrder`, re-fetch current price + `status = published` per line inside the transaction; if anything changed, refresh the cart and bounce back to it with a notice.

### 1.5 Loose phone matching with `LIKE '%…%'`
Used in customer login, OTP lookup, order tracking, and review "verified buyer" checks, e.g.:

```php
Customer::where('phone', 'like', '%'.$phone.'%')   // login — phone is only validated as 'string'
```

A short input can match the *wrong* customer (login then still needs their password, but OTP would text a reset code for someone else's account). You already store phones canonically via `bd_phone()` — normalize the input the same way and use exact `where('phone', $phone)`. Also `LIKE '%…'` can never use the `customer_phone` index.

---

## 2. Code quality & structure

**Good:** `PlaceOrder` as an Action with a DB transaction, cost snapshotting on order items, moderated reviews, CAPI/Pixel dedup via shared event ids, `SystemConfig` with audit/versioning, `.env` properly ignored, Pint present.

Improvements, in rough order of value:

1. **Tests are essentially absent** — only the two skeleton `ExampleTest`s exist, while `CartService` (456 lines of discount/points/offer stacking) and `PlaceOrder` handle real money. This is the single highest-leverage improvement. Start with: feature test for checkout happy path, unit tests for `CartService::discount()` stacking rules, coupon limits, and points redemption. These also make every fix in §1 safe to ship.
2. **Extract Form Requests.** The BD phone regex is copy-pasted in at least 4 places (checkout, register, lead, OTP). One `BdPhone` rule class + Form Requests (`StoreCheckoutRequest`, etc.) removes the duplication and slims controllers.
3. **`CatalogController::show()` does too much** (~80 lines: loves, recently-viewed, tracking, template resolution, Alpine payload). Extract a `ProductPageData` view-model/service so the controller reads as intent.
4. **Duplicate query building in catalog pages:** `index()`/`bestSellers()` build the same base query twice (once for products, once for filters). Build once and `clone`.
5. **`decrementStock()` runs `Product::find()` per line item** inside the loop — you already fetched those products for cost snapshotting; reuse that collection.
6. **Post-checkout side effects run inline** — invoice email, SMS, and Meta CAPI all execute synchronously during `POST /checkout`, adding latency (an SMS/HTTP timeout slows the redirect). You already run a database queue with a per-minute drain — dispatch these as queued jobs/listeners for an `OrderPlaced` event.
7. **`customer_email` on `firstOrCreate` only** — if a guest re-orders with an email after previously ordering without one, the email is never backfilled onto the Customer record. Minor CRM leak.

---

## 3. Performance & experience

1. **Cache the homepage queries.** Every hit runs ~8+ product queries, including a `SUM(quantity) GROUP BY` over all order items for auto-bestsellers. Wrap the lot in `Cache::remember('home.v1', 600, …)` and bust on product/order save. Biggest single win for perceived speed.
2. **`$product->increment('views')` on every product view** is a synchronous write per pageview (and skews "popular" sort via bots). Throttle per session (only count once per session per product) or batch via cache counter flushed by the scheduler.
3. **Search is `LIKE '%term%'` across 3 columns** — fine at your current catalog size; when it grows past a few thousand products, add a MySQL FULLTEXT index (or Laravel Scout + Meilisearch) for the search + suggest endpoints.
4. **No browser-caching / compression rules in `public/.htaccess`.** On Namecheap shared hosting Apache serves your static files — add `mod_deflate` + `mod_expires` (long `Cache-Control` for `/build/*` which is content-hashed, and for `storage` images). Cheap, real LCP improvement.
5. **Images:** WebP conversion via `ImageOptimizer` is great. Audit remaining `<img>` tags — `loading="lazy"` appears in only ~8 views and explicit `width`/`height` (CLS prevention) is inconsistent. Consider `srcset` with a couple of sizes for product cards; you're already generating derivatives.
6. **Fonts load twice:** Vite bundles Bunny fonts (Instrument Sans, Playfair) *and* the layout conditionally loads the same families from Google Fonts. Pick one source — otherwise many visitors download both.
7. **Production drivers:** sessions, cache, and queue all sit on the database (and `.env.example` defaults to SQLite). Under COD-rush traffic every pageview does session writes to the DB. On shared hosting this is acceptable, but if you move to a VPS, Redis for cache/session is the first upgrade.
8. **Mini-cart endpoint (`/cart/mini`)** recomputes all offers/discounts per poll; it's fine now, but avoid polling it on a timer from the frontend (event-driven refresh only).

---

## 4. SEO gaps (verified)

- **No `sitemap.xml`** — the config flag `seo.sitemap_enabled` exists but no route/generator does. Add a route serving products + categories + pages (cache it).
- **No canonical tags** — filter/sort query strings (`?sort=`, filter params) create infinite duplicate URLs of the same catalog page. Add `<link rel="canonical">` to the layout.
- Already good: OG/product meta tags, JSON-LD in the layout, Meta catalog feed, robots.txt.

---

## 5. Missing e-commerce features (by impact)

1. **Online payments — the big one.** The store is 100% COD (`payment_method => 'cod'` hardcoded). For BD: **bKash Checkout, Nagad, and/or SSLCommerz** (cards + mobile banking in one gateway). Even partial advance payment on COD orders cuts fake-order/return losses dramatically — and you already have a fraud-checker package for couriers, so prepayment is the natural next lever.
2. **Customer order cancellation** — no route lets a customer cancel a just-placed order; they have to call you. A "cancel within X minutes / while status = processing" button saves support time and courier fees.
3. **Return / exchange request flow** — statuses exist (`returned`), but there's no customer-facing way to request one with a reason + photos. Ties into the review photo upload you already have.
4. **Invoice PDF download** — you email an invoice; add a downloadable PDF on the confirmation/account order page.
5. **Google Analytics 4** — tracking is Meta-only. GA4 (or at least gtag ecommerce events) gives you funnel data Meta won't show you.
6. **Bangla localization** — the storefront is English-only; for a BD COD audience a `lang/bn` toggle measurably lifts conversion. Laravel's localization makes this mostly translation work.
7. **Back-in-stock via SMS/email** — you have push-based `StockWatcher`; push permission rates are low on iOS/Safari. Offer phone-number capture as fallback.
8. **Shipping zones** — inside/outside Dhaka is coarse; a district-based rate table (you already collect district) enables accurate charges and delivery-time estimates ("Delivery in 2–3 days to Chattogram").
9. **Admin 2FA** — the admin panel controls SMS sending, Meta tokens, and system config; a TOTP second factor is warranted.
10. **Product Q&A** — common on BD stores; low effort given your review infrastructure.

Already covered (nice work): loyalty points, coupons + auto offers, member pricing, abandoned-cart recovery (push + lead capture), drip campaigns, RFM segmentation, wishlist ("loved"), guest order tracking, pre-orders, courier fraud check, Meta catalog sync + CAPI, web push, review moderation with verified-buyer detection.

---

## 6. Suggested order of work

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 1 | Rate limiting (OTP, logins, reviews, checkout) | Hours | Security/cost |
| 2 | Signed/gated order confirmation URL | Hours | Privacy |
| 3 | Stock check + `lockForUpdate` + price re-validation in `PlaceOrder` | ~1 day | Money correctness |
| 4 | Exact phone matching via `bd_phone()` everywhere | Hours | Security |
| 5 | Checkout + CartService test suite | 1–2 days | Everything above, safely |
| 6 | Homepage caching + `.htaccess` cache/compress + font dedupe | Hours | Speed |
| 7 | Sitemap + canonicals | Hours | SEO |
| 8 | bKash/Nagad/SSLCommerz | Days | Revenue |
| 9 | Order cancellation + return requests | 1–2 days | Support load |
| 10 | GA4, Bangla, shipping zones, admin 2FA | Ongoing | Growth |
