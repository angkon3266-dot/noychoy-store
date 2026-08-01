# Code Review — noychoy-store
*v3: 2026-08-01 (v1 2026-07-15 · v2 2026-07-16) · Laravel 13 / PHP 8.3 / Tailwind 4 / Alpine.js*

**Overall verdict:** A solid, well-commented codebase. The five critical security
issues from v1 remain fixed. This round audited the **money and inventory paths**
end to end — code, HTTP tests, a real browser, and production over SSH — and
found six live defects, all now fixed and covered by regression tests.

> **How to read this file.** Every claim below was verified on 2026-08-01 against
> the code, the test suite, a browser session, and production. v2 of this document
> had drifted badly out of date (it claimed tests were absent when 212 were
> passing), so treat any undated assertion here as stale on sight and re-check it.

---

## 1. Fixed this round (commit `b75af87` + follow-up)

Each of these was reproduced before being fixed, and each has a regression test
that fails without its fix.

### 1.1 Discounts could exceed the subtotal — free orders 🔴
Every discount stage computed against the **raw** subtotal independently, so a
20% quantity offer plus a 20% coupon took 20% of the original price *twice*.
`Coupon::discountFor()` accepted a base and then ignored it whenever a cart was
passed. Stacked far enough the checkout page rendered **"Total ৳-270 · saving
৳1,400 (140% off)"** and stored a free order — shipping included, gross profit
−৳400.

**Fix:** `CartService::cascade()` applies discounts in order, each capped to what
remains of the subtotal; `Coupon::discountFor()` now respects its base. A coupon
can narrow its base, never widen it.

### 1.2 Courier status changes skipped every side effect 🔴
Stock restoration and the loyalty award lived inside `Admin\OrderController`,
while `SteadfastWebhookController` wrote `status` directly. A courier
cancellation therefore **never returned its stock** and a courier delivery
**never paid the customer's points** — and courier callbacks are the normal path
for a COD store.

**Fix:** `App\Actions\TransitionOrderStatus` owns history, stock
release/re-reserve, the loyalty award and the web push. Both the admin panel and
the webhook call it, so the two cannot drift again.

### 1.3 Sold-out items sold from a stale cart 🟠
The stock check only ran inside `if ($product->manage_stock)`, so the manual
`in_stock` toggle was never consulted at checkout. An item added while available
and then marked sold out still went through. Retired variants (`is_active =
false`) sold the same way. *(The product page has always hidden "Add to cart"
correctly — this was only reachable via a cart that predated the change.)*

### 1.4 Stale quantity-offer snapshots 🟠
Offer tiers are captured into the cart line when an item is added, and checkout
re-validated the price but never the tiers — so a session could keep claiming a
discount the admin had withdrawn. Checkout now refreshes the snapshot and makes
the customer re-confirm **only when the offer got worse**; a better offer applies
silently. Totals also moved to *after* validation, so a corrected price or offer
is reflected in the order rather than baked in beforehand.

### 1.5 "Coupon applied." with no discount 🟠
`applyCoupon()` validated against the raw subtotal while `CartService`
re-validated against the real (lower) base, so a coupon over its minimum spend
was accepted, reported as applied, and then silently discounted nothing.
`couponBase()` is now public and both sides use it.

### 1.6 Orphan customer records 🟠
`Customer::firstOrCreate()` ran *before* the `auth()` check, so a logged-in
member ordering to a different number — a gift — created a junk customer row with
zero orders, polluting segments and CRM counts.

### Also fixed
- Cart-add resolved products by slug **without** the published scope (`addMany()`
  already scoped correctly).
- `ProductVariant::effective_price` lazy-loaded its parent product **inside the
  row-locked checkout transaction**; the relation is now eager-loaded.
- One `discount()` call cost **11 queries**, six of them the identical
  member-usage count. The cascade is memoised per request (`CartService` is a
  singleton); every mutating method clears it.

---

## 2. Still fixed from earlier rounds ✅

Re-verified 2026-08-01:

| Item | Status |
|---|---|
| Rate limiting on public endpoints | ✅ named `otp`/`login` limiters, checkout 10/min, reviews 5/10min, cart writes 60/min |
| Order confirmation not enumerable | ✅ session, owner, or signed URL |
| **Signed confirmation links are now actually generated** | ✅ `SendOrderPlacedEffects` emails a `URL::signedRoute` — no longer dead code |
| Oversell / row locks at checkout | ✅ `lockForUpdate()` inside the transaction |
| Stale session prices | ✅ repriced and bounced |
| `bd_phone()` exact matching | ✅ no `LIKE '%phone%'` left |
| **`GET /track` throttled** | ✅ `throttle:20,1` |
| **Post-checkout SMS / invoice / CAPI queued** | ✅ `SendOrderPlacedEffects`, with `$deleteWhenMissingModels` |
| **`BdPhone` rule extracted** | ✅ one rule class, 7 call sites — no inline regex left |
| Guest email backfill | ✅ filled on a later order |
| Homepage caching, sitemap, canonicals, compression | ✅ |

---

## 3. Test suite

**233 tests / 626 assertions, all passing** across 34 feature files plus unit
tests. v2's "tests are essentially absent" is long obsolete.

Money-path coverage now includes `DiscountCapTest`, `OrderStatusTransitionTest`,
`CheckoutAvailabilityTest`, `StaleOfferSnapshotTest`, `CheckoutTest`,
`CartServiceTest`, `OrderNumberTest`, `QuantityOfferTest`.

---

## 4. Still open

In rough order of value.

1. **Online payments — still 100% COD** (`payment_method => 'cod'` is hardcoded).
   For Bangladesh: bKash Checkout, Nagad, or SSLCommerz. Even optional partial
   advance payment cuts fake-order and return losses.
2. **Customer order cancellation** — no self-serve cancel while `processing`.
3. **Return / exchange request flow** — statuses exist, no customer-facing form.
4. **Form Requests** — only `Meta/` and `SystemConfig/` have them; storefront
   controllers still validate inline. (`BdPhone` is done; this is the rest.)
5. **`CatalogController::show()` is ~74 lines** — extract a `ProductPageData`
   view-model (loves, recently-viewed, view counting, tracking, Alpine payload).
6. **Duplicate base-query build** in `index()`/`bestSellers()` — build once, clone.
7. **Search is `LIKE '%term%'`** across name/sku/short_description. Fine at 109
   products; revisit (FULLTEXT or Scout) past a few thousand.
8. **Images** — WebP pipeline is good, but `loading="lazy"` and explicit
   width/height are inconsistent and there is no `srcset`.
9. **Invoice PDF download** on the confirmation and account order pages.
10. **GA4**, **Bangla localization**, **district-level shipping zones**,
    **admin 2FA**, **back-in-stock via SMS/email**, **product Q&A**.

---

## 5. Production notes (checked 2026-08-01 over SSH)

- 109 products, 635 customers, 1 real order — effectively
  pre-launch.
- **0 active coupons and 0 products with quantity offers**, so §1.1 was armed but
  never fired in production. It would have on the first campaign.
- Steadfast is fully configured with a live consignment, so §1.2 *was* live.
- Queue drains via the scheduler (`queue:work --stop-when-empty --max-time=50`
  every minute) on the `default` queue, not a second cron entry. Production lives
  at `~/repositories/noychoy-store` and serves `meridianeclat.shop`.
  `DEPLOY.md` was rewritten on 2026-08-01 to match all of this.
- Cache is Redis, sessions and queue are database, `APP_DEBUG=false`. Correct.
- 8 failed jobs are pre-fix residue from 2026-07-27 (all `SendOrderPlacedEffects`
  `ModelNotFoundException`, from before `$deleteWhenMissingModels` landed the
  same evening). Safe to clear.

> **After every deploy**, `deploy.sh` cannot flush the **LiteSpeed page cache**
> or reset the **lsphp OPcache** — do both in cPanel or PHP changes will not take
> effect on the live site.

---

*History: v1 (2026-07-15) found 5 critical security/correctness issues. v2
(2026-07-16) verified those fixed. v3 (2026-08-01) audited the money and
inventory paths against code, tests, a browser and production; found and fixed 6
live defects; corrected v2's stale claims about tests, queued side effects and
`/track` throttling.*
