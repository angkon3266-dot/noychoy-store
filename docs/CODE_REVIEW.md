# Code Review — noychoy-store
*Re-reviewed: 2026-07-16 (original 2026-07-15) · Laravel 13 / PHP 8.3 / Tailwind 4 / Alpine.js*

**Overall verdict:** Well-built codebase, and materially hardened since the first review. All five critical issues from the original report are now **fixed and verified** (commit `2bb5f01` + follow-ups). What remains is mostly tests, refactoring polish, and feature gaps.

---

## 1. Critical issues — ALL RESOLVED ✅

Re-verified in code on 2026-07-16:

| # | Original issue | Status |
|---|----------------|--------|
| 1.1 | No rate limiting on public endpoints | ✅ Fixed — named `otp` limiter (2/min per phone/email + 6/hr + 5/min per IP), `login` limiter (per account+IP with IP ceiling), admin login 5/min, checkout 5/min, reviews 5/10min, register, cart writes, push, love, lead all throttled |
| 1.2 | Order confirmation enumerable (sequential numbers, no auth) | ✅ Fixed — page now requires the placing session (`placed_orders`), the logged-in owner, or a signed URL; others bounce to the phone-verified `/track` page. `generateNumber()` race handled with retry on unique-constraint violation |
| 1.3 | Oversell — stock never checked, no locks | ✅ Fixed — `PlaceOrder::validateLines()` runs inside the transaction with `lockForUpdate()`, checks published status + stock ≥ qty (pre-orders exempt), throws `CheckoutException` with a friendly bounce to cart |
| 1.4 | Stale session prices charged at checkout | ✅ Fixed — live price compared per line; mismatches reprice the cart and bounce the customer to review |
| 1.5 | `LIKE '%phone%'` loose matching | ✅ Fixed — canonical `bd_phone()` exact matches in customer login, OTP (both flows), order tracking, verified-buyer check, CustomerInsight |

Also nice: the locked product rows now double as the cost snapshot (removed the duplicate fetch and per-line `Product::find`), and the view counter only increments once per session via query-builder (no model events → no cache-bust/Meta-sync churn).

### New minor notes on the fixes
- **`GET /track` is unthrottled.** It's the fallback for viewing orders and takes order_number + phone. Order numbers are sequential, so an attacker who knows a target's phone can find their order in a few hundred requests. Low risk, one-line fix: `throttle:20,1`.
- **No signed confirmation links are generated anywhere yet** — the `hasValidSignature()` branch is dead code until you add a signed URL to the invoice email/SMS ("view your order" link). Worth wiring up so email recipients can open the page on another device.
- Canonical uses `url()->current()` — fine, just be aware paginated pages all canonicalize to page 1 of themselves (acceptable, arguably ideal).

---

## 2. Performance & SEO — mostly resolved ✅

| Item | Status |
|------|--------|
| Homepage query caching | ✅ 10-min cache of the section plan (ids only — plays nice with the DB cache store) |
| `.htaccess` compression + browser caching | ✅ deflate + expires rules added |
| Double font download (Vite Bunny + Google) | ✅ Bundled families rejected from the Google Fonts URL |
| View-count write per pageview | ✅ Once per session, event-less increment |
| `sitemap.xml` | ✅ Route + controller, cached 1 hour; robots.txt references it |
| Canonical tags | ✅ In the shop layout |
| CRM/analytics indexes, memoised analytics | ✅ (commit `d09d8dc`) |

### Still open (from §3 of the original)
1. **Post-checkout side effects run inline** — SMS, invoice email, and Meta CAPI still execute synchronously inside `POST /checkout` (PlaceOrder.php:153–166). A slow SMS/HTTP call delays the customer's redirect. You already run a DB queue with a per-minute drain — fire an `OrderPlaced` event and queue these three.
2. **Search is still `LIKE '%term%'`** across name/sku/short_description. Fine at current catalog size; revisit (FULLTEXT or Scout+Meilisearch) past a few thousand products.
3. **Images** — WebP pipeline is good; `loading="lazy"` and explicit width/height are still inconsistent across storefront views; no `srcset`. Audit when convenient.
4. **DB-backed sessions/cache/queue** — fine on shared hosting; Redis is the first upgrade if you move to a VPS.

---

## 3. Code quality & structure — still open

In order of value:

1. **Tests remain essentially absent** — still only the two skeleton `ExampleTest`s. This is now the #1 item on the list. The new checkout logic (`validateLines()`, repricing, order-number retry, limiter keys) is exactly the kind of concurrency-sensitive money code that regresses silently. Start with: checkout happy path, oversell rejection, reprice bounce, `CartService` discount stacking, coupon limits, points redemption.
2. **Extract Form Requests + a `BdPhone` rule.** The phone regex is still duplicated inline (checkout, register, lead, OTP). One rule class, four call sites.
3. **`CatalogController::show()` is still ~90 lines** (loves, recently-viewed, view counting, tracking, template resolution, Alpine payload). Extract a `ProductPageData` view-model.
4. **Guest email backfill** — `Customer::firstOrCreate` only sets email on creation; a repeat guest who adds an email on a later order never gets it saved to their customer record. One `fill`+`save` when blank.
5. **Duplicate base-query build in catalog pages** — `index()`/`bestSellers()` still construct the same filtered query twice (products + filter groups). Build once, `clone`.

Good as-is: Action classes, transaction boundaries, cost snapshotting, moderated reviews, CAPI/Pixel dedup, `SystemConfig` audit/versioning, Pint, `.env` hygiene, thoughtful comments throughout.

---

## 4. Missing e-commerce features (unchanged, by impact)

1. **Online payments — the big one.** Still 100% COD (`payment_method => 'cod'` hardcoded). For BD: **bKash Checkout, Nagad, and/or SSLCommerz**. Even optional partial advance payment on COD cuts fake-order/return losses — you already run courier fraud checks, prepayment is the natural next lever.
2. **Customer order cancellation** — no self-serve cancel while status = `processing`; saves support calls and courier fees.
3. **Return / exchange request flow** — statuses exist but no customer-facing request form (reason + photos; you already have review photo upload infrastructure to reuse).
4. **Invoice PDF download** on the confirmation/account order pages (you currently only email it).
5. **Google Analytics 4** — tracking is Meta-only; GA4 gives funnel data Meta won't.
6. **Bangla localization** — English-only storefront; a `lang/bn` toggle is mostly translation work in Laravel.
7. **Back-in-stock via SMS/email** — push-only today; push opt-in rates are low on iOS.
8. **Shipping zones** — inside/outside Dhaka is coarse; you already collect district, so a district rate table + delivery-time estimates is low-hanging.
9. **Admin 2FA** — the panel controls SMS sending, Meta tokens, and system config.
10. **Product Q&A** — cheap to add on top of the review infrastructure.

---

## 5. Updated order of work

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 1 | Checkout + CartService test suite | 1–2 days | Protects everything just fixed |
| 2 | Queue post-checkout SMS/email/CAPI | Hours | Checkout latency |
| 3 | Throttle `GET /track` + signed link in invoice email | Hours | Closes the last enumeration path |
| 4 | `BdPhone` rule + Form Requests | Hours | Deduplication |
| 5 | bKash/Nagad/SSLCommerz | Days | Revenue |
| 6 | Order cancellation + return requests | 1–2 days | Support load |
| 7 | Guest email backfill, catalog query dedupe, `ProductPageData` extraction | Hours | Hygiene |
| 8 | GA4, Bangla, shipping zones, admin 2FA, image srcset audit | Ongoing | Growth |

---

*History: v1 (2026-07-15) identified 5 critical security/correctness issues, 8 performance items, 2 SEO gaps. v2 (2026-07-16) verified all criticals and most performance/SEO items fixed in commits `9b5a568`…`67b8ca9`.*
