# Work log — 2026-08-01 → 2026-08-03

A record of what changed, why, and what is still open. Written so it can be
picked up cold: every claim was verified against the code, the test suite, a
browser session or the live server at the time, and the commit is named so you
can read the full reasoning in `git show <sha>`.

**State at the end of this run:** `e753b86` on `main`, deployed. **322 tests
passing.** Production doctor: *no problems found*.

---

## 1. Money-path bugs — `b75af87`, `f15c769`

Six defects, each reproduced before it was fixed and each now covered by a
regression test that fails without the fix.

| # | Bug | Effect |
|---|---|---|
| 1 | **Discounts could exceed the subtotal** | Checkout rendered `Total ৳-270 · saving 140%` and stored a **free order**, shipping included, gross profit −৳400 |
| 2 | **Courier status changes skipped every side effect** | A courier cancellation never returned stock; a courier delivery never paid loyalty points |
| 3 | **Sold-out items sold from a stale cart** | The manual `in_stock` toggle was never re-checked at checkout |
| 4 | **Stale quantity-offer snapshots** | A cart could keep claiming a withdrawn discount indefinitely |
| 5 | **"Coupon applied." with no discount** | Accepted against the raw subtotal, silently dropped against the real base |
| 6 | **Orphan customer records** | A member ordering to a different number created a junk customer row |

**The root cause of #1** is worth remembering: `Coupon::discountFor()` accepted a
base and then *ignored* it whenever a cart was passed. Every discount stage
computed against the raw subtotal independently, so a 20% offer plus a 20%
coupon took 20% of the original price twice.

**Fix:** `CartService::cascade()` applies discounts in order, each capped to
what remains of the subtotal, memoised once per request. One `discount()` call
used to cost **11 queries** (six identical); it is now under 5 for a whole cart
render.

Also fixed: `TransitionOrderStatus` now owns every status change, so the admin
panel and the Steadfast webhook cannot drift apart again.

---

## 2. Courier-driven order status — `6fecdfd`

Steadfast is the source of truth for what physically happened:

| Steadfast says | Order becomes | Editable after? |
|---|---|---|
| `delivered` | **Delivered** | ❌ locked |
| `cancelled` | **Cancelled** | ✅ yes |
| `partial_delivered` | **Cancelled** | ✅ yes |
| `pending` / `in_review` / `hold` | unchanged | ✅ yes |

Two judgement calls, both deliberate:

- **`partial_delivered` → Cancelled.** That string contains *both* "partial" and
  "delivered" — the rider handed over some items and returned the rest. It is a
  problem to deal with, not a completed sale, so it is checked first.
- **`*_approval_pending` states are ignored.** The rider has proposed an outcome
  the courier hasn't settled; acting on it would lock an order that can still
  change.

Delivered locks because the goods are gone and the COD is collected. Cancelled
deliberately does **not** lock — the shop still has to choose between re-ship,
refund, or write-off. The lock is enforced server-side, not just greyed out.

---

## 3. WooCommerce removed — `6222370`

Nothing was ever imported through it: **every `woo_id` was NULL** across all 109
products, 635 customers, 19 categories, 56 variants and 1 order, and no code
read them. Command, config, env keys and columns are all gone. The migration's
`down()` restores the same nullable+indexed columns.

> **Gotcha for future column drops:** the index must be dropped **first, in its
> own statement**. MySQL refuses to drop an indexed column; SQLite drops the
> column but leaves the index dangling and then errors on it.

---

## 4. BDCourier COD risk check — `6222370`, `f0ba3e6`, `b7314f5`

Only `POST /courier-check`. No plan, connection-test or legacy endpoints.

- **Order page:** Safe/Warning/Risky band, total/delivered/cancelled/rate,
  per-courier breakdown, fraud reports when `reports[]` is non-empty.
- **Orders list:** a **Courier History** column, filled by selecting rows and
  pressing *Check courier history*.

**Quota rules (deliberate — lookups cost money):**
- Rendering a page sends **zero** requests (asserted by a test)
- Deduped by phone — three orders from one buyer cost **one** credit
- Numbers checked in the last **48 h** are skipped
- Capped at 25 per bulk run; stops early on a rejected key or exhausted quota

**A number with no history is "No history", not "Risky."** A first-time buyer
isn't a bad one, and flagging them would train the shop to ignore the badge.

Thresholds (Safe ≥ 80 / Warning ≥ 50) are editable at
**System Config → Integrations**, next to Steadfast.

> **Why results are in a table, not the cache:** they *were* cached, and
> `deploy.sh` runs `optimize:clear`, which flushes the entire cache store — so
> every deploy threw away results the shop had paid for. They now live in
> `courier_checks`. There is a test that calls `Cache::flush()` and asserts the
> result survives.

---

## 5. Landing page templates — `b7314f5`

Four layouts at **Admin → Landing → New page**: single product sales page,
flash sale, lead capture, brand story. Each is a pre-arranged set of blocks the
renderer already supports, with COD-focused placeholder copy. Picking one copies
the blocks in; **nothing stays linked**, so editing never fights the template.

Each has a test that builds a real page and requests it through the storefront
route — a typo in a block type fails the suite instead of rendering nothing.

---

## 6. Four inverted Carbon comparisons — `e753b86`

**Carbon 3 made `$a->diffInX($b)` signed** (it returns `b − a`); Carbon 2
returned an absolute value. Eleven call sites were audited; seven run
past → future and were already correct. Four were inverted:

🔴 **`MetaSecurityGate` — the Meta module's password wall never re-armed.**
`now()->diffInMinutes($unlockedAt) < $ttl` was negative for any past unlock, so
it was *always* true. Once unlocked, a session stayed unlocked indefinitely,
however long it sat idle — despite the docs promising an inactivity timeout.

The other three (`MetaIntegrationController`, `MetaStats`, `MetaTokenManager`)
are why the dashboard said **"Access token expires soon (1 month from now)"**
from the moment you connected. It was never about urgency; the threshold could
not evaluate false.

All four now compare instants against a threshold (`lte`, `gt`) — no sign to get
wrong. **If you write another expiry check, do the same.**

Also: the expired-token message told Production-mode merchants to "generate a
new System User token" — the wrong screen for an OAuth connection. Now
mode-aware.

**Product serial race** (same commit): production logged two
`Duplicate entry … for key products_serial_unique` 500s. `max(serial)+1` is a
read-then-write race. Fixed with the stepped-retry pattern already used for
order numbers — each retry moves to the *next* number, because retrying the same
one just reproduces the identical error.

---

## 7. Production housekeeping — 2026-08-03

- **`storage/logs`: 220 MB → 5.3 MB.** 216 MB was a single orphaned
  `laravel.log` written before daily rotation was adopted, untouched since
  28 Jul. Archived to `laravel.log.20260803-023128.gz` (1.7 MB) and removed via
  `php artisan logs:prune --archive-legacy`. Nothing was lost.
- **8 failed jobs cleared.** Root cause established, not guessed:

  > All 8 were `SendOrderPlacedEffects` failing with `ModelNotFoundException`,
  > every one between **15:27:42 and 15:49:12 on 2026-07-27**. The
  > `$deleteWhenMissingModels` guard landed in `98fe547` at **22:13 the same
  > day** — 6.5 hours *after* the last failure. **Zero failures since.**
  >
  > What happened: the scheduler was down (cron ran `php-cgi`, which has no
  > redis extension), leaving a 788-job backlog. Test orders were deleted while
  > their jobs sat in that backlog; `SerializesModels` then couldn't restore the
  > Order. Historical, already fixed, safe to clear.

---

## Still open

**Decided against building** (2026-08-02): multi-niche support for restaurants /
salons / doctors. Boutiques already work; salons and doctors need a bookings
model, not a relabel.

1. **Online payments — still 100% COD** (`payment_method => 'cod'` hardcoded).
   bKash / Nagad / SSLCommerz. Even partial advance payment cuts fake-order loss.
2. **Meta OAuth tokens do not auto-refresh.** Long-lived user tokens last
   ~60 days, and nothing renews them, so **merchants must reconnect every
   ~60 days**. That is our implementation, not a Meta limitation — see the
   analysis below.
3. **Landing pages:** an inline COD checkout block and a gallery block were
   scoped but not built. The four templates use the existing blocks.
4. **`meta-debug.log` is 3.5 MB and orphaned** (last written 27 Jul).
   `logs:prune --archive-legacy` only handles `laravel.log`.
5. **Two parallel Meta credential stores** — `MetaSettings` (in `settings`) vs
   `MetaConnection`/`MetaTokenManager` (in `meta_connections`). Unify before any
   multi-tenancy work.
6. Customer order cancellation; return/exchange flow; Form Requests for
   storefront controllers; GA4; Bangla localisation; district shipping zones;
   admin 2FA.

### Meta token architecture (researched 2026-08-02, against Meta's docs)

- The dashboard warning refers to the **long-lived OAuth user token**, stored as
  a plain ISO string in the `meta` settings row (only the token itself is
  encrypted). Computed from `expires_in` — 60 days.
- **Nothing refreshes it.** `fb_exchange_token` appears only in the initial
  callback. `meta_connections.refresh_token` is never written; Meta doesn't
  issue refresh tokens anyway.
- **The dedicated CAPI token is already optional** — `MetaSettings::capiToken()`
  falls back to the OAuth token, so CAPI works on one credential. Keep it as an
  escape hatch until `ads_management` clears App Review.
- **Recommended SaaS path:** Meta Business Extension (FBE) onboarding → convert
  to a **System User token** → scheduled pre-expiry refresh. That is the only
  route to "Connect with Facebook → everything works" with no reconnects.

---

## Operational notes

**Deploy** (full detail in [DEPLOY.md](../DEPLOY.md)):

```bash
git push origin main
ssh hostnin "cd ~/repositories/noychoy-store && bash deploy.sh"
```

Then **always** in cPanel — the second one is not optional:
1. LiteSpeed Web Cache Manager → Flush All
2. MultiPHP Manager → toggle the PHP version (resets OPcache; **without it your
   PHP changes are not live**)

**Health check:** `php artisan app:doctor` on the server answers most "is
something wrong" questions in one command. `php artisan queue:why` prints the
exception behind each failed job.

**A test that fails on the clock:** `DashboardRangeTest` broke because it created
an order "2 hours ago" and asserted it counted as today — false between midnight
and 02:00 UTC. Fixed by pinning the clock. If a time-based test fails, check the
hour before assuming a code change caused it.
