# Roadmap status — Meridian Éclat storefront

*Tracker for the review dossier of **22 August 2026** ("The storefront is React
now. Here is everything else I found." — 119 findings, 8 reviewers).*

**Source dossier:** <https://claude.ai/code/artifact/f6acf911-295f-4b77-8320-ce7fba20864b>

---

## How to read and maintain this file

This is the **single place that says what is done**. The dossier is a snapshot
and goes stale the moment something ships; this file is re-verified against the
code.

- Every status below was re-checked against the repository on **2026-08-22**,
  after commit `7daefff`, by a fan-out audit whose claims were then
  adversarially re-verified. Where the dossier and the code disagreed, **the
  code won** — those rows are marked *(dossier stale)*.
- Status vocabulary:
  - **Done** — shipped, wired, reachable by a real user.
  - **Partial** — the mechanism exists but is unwired, unused, or only covers
    half the finding. The *what remains* column is the actual work.
  - **Todo** — nothing exists yet.
- **Owner** says who can close it:
  - `code` — a developer change in this repo.
  - `setting` — the store owner flips it in the admin panel. **No deploy.**
  - `content` — the store owner writes words.
  - `infra` — cPanel / server / DNS.
- When you finish something, change the status **and** add a line to the
  changelog at the bottom with the commit sha. Do not delete rows — a row that
  reads *Done* with a date is the evidence.

> **Read production from the database, not the browser.** LiteSpeed serves
> cached HTML until it is flushed in cPanel, so a page can show a setting that
> was changed long ago. One row in this file was wrong for exactly that reason.
> To check a live setting, ssh in and read it: `php artisan tinker --execute="…"`.

> Two caveats that apply to the whole file. Production's `.env` is gitignored,
> so any claim about live environment values is inferred from `DEPLOY.md` and
> must be confirmed on the server. And `deploy.sh` cannot flush the LiteSpeed
> page cache or reset the lsphp OPcache — both are cPanel steps after every
> deploy.

---

## Owner-only — nothing in this repo can do these

| # | Action | Where | Why it is blocking |
|---|---|---|---|
| 1 | **Flush LiteSpeed page cache** | cPanel to LiteSpeed Web Cache Manager to Flush All | Visitors are still being served the pre-React homepage intermittently |
| 2 | **Reset lsphp OPcache** | cPanel to MultiPHP Manager, toggle the PHP version away and back | PHP changes are not live for everyone until this runs |
| 3 | **Decide the free-delivery promise** | Admin to Settings (see step 01) | The banner promises free delivery over ৳3,000; checkout does not honour it. This changes your margins, so it is your call — not a bug to be fixed by guessing |
| 4 | **Rename the social handles** | Facebook / Instagram / Messenger accounts | The links themselves are admin settings, but the *accounts* are `Noychoylove` / `_noychoy_` and only you can rename them |

---

## Step 01 — Settle the free-delivery promise

**Roadmap:** *"Flush the caches, then settle the free-delivery promise."*

| Item | Status | Owner | What remains |
|---|---|---|---|
| Checkout honours one server-side verdict | **Done** | code | `CartService::hasFreeShipping()` is the single authority; cart progress bar, checkout badge, zone picker and order total all read it (`7daefff`) |
| Threshold settable without SSH | **Done** | code | Was env-only (`FREE_SHIPPING_THRESHOLD`); now a field in Admin → Settings beside the shipping rates |
| Banner cannot drift from checkout | **Done** | code | Announcement messages accept a `{free_delivery}` placeholder that prints the live threshold, and the whole message is **dropped** while the promise is off. A migration rewrote the stored banner's literal `৳3000` into the placeholder — conservatively: only a message that mentions free delivery *and* contains exactly one amount |
| Threshold has regression tests | **Done** | code | `tests/Feature/FreeDeliveryPromiseTest.php` |
| **Set the actual number** | **Todo** | setting | Admin → Settings → *Free delivery over*. Leave blank to disable; the banner line then disappears rather than lying. Verified in a browser both ways: at ৳5,550 the zone picker is replaced by “Free delivery unlocked” and shipping is ৳0; with the field blank the picker returns, shipping is ৳130, and the banner stops mentioning free delivery |

---

## Step 02 — Fix the brand leaks

**Roadmap:** *"Social handles, footer wording, the header's Track Orders button,
and the default copy."* The dossier called this "all admin settings — no code".
That is **half right**: the live wrong values are settings, but the shipped
defaults, the emoji icon system and the palette are code.

| Item | Status | Owner | What remains |
|---|---|---|---|
| Social URLs + footer legal line | **Todo** | setting | Not hardcoded anywhere — the `config/theme.php` defaults are `null`. `Noychoylove` etc. are rows in the production `settings` table. Admin → Appearance → Footer, and → Conversion for Messenger |
| Repo-level brand leaks | **Done** | code | `.env.example` shipped `APP_NAME=Noychoy` and a typo'd `hello@nocyhoy.com`; the seeder created `admin@noychoy.com`; `app.css` called it the "Noychoy jewelry palette". All corrected |
| `footer_about` default duplicated | **Done** | code | The string lived in both `config/theme.php` and `HandleInertiaRequests.php`, so editing the config alone changed nothing. Now one source |
| Header's only filled button sells logistics | **Done** | code + setting | Admin → Menu shipped `Track order` as the *placeholder*, nudging the owner to spend the store's one primary button on logistics. Placeholder changed, the default is now **Shop gifts**, and the field is explained. *Correction: the live setting already reads “Shop Gift” — an earlier note here said “Track Orders”, read off a LiteSpeed-cached page rather than the database. Checked directly on the server since.* |
| Emoji as the icon system | **Done** | code | ~45 emoji literals across the React storefront replaced with the stroke icon set (`Icons.jsx` extended from 19 to 34 marks). The trust-badge and feature-strip settings now take an **icon name** from a picker instead of demanding an emoji, and a migration remapped the emoji already stored in the live settings — unrecognised glyphs are left alone rather than guessed, and still render. `IconSetParityTest` fails the build if the PHP picker list drifts from the JS set. **Not swept:** the three emoji in the legacy Blade trust strip (`resources/js/app.js`), which dies with the dead-Blade deletion in step 07 |
| Palette roles muddled | **Partial** | code | Semantic `success` / `danger` / `warning` / `promo` tokens added, warmed toward the gold, and **all 136** stock Tailwind red/violet/green/amber classes across 19 storefront files swept onto them — so statuses now follow a palette change instead of clashing with it. **Remaining:** the announcement bar and member bar still carry their own literal `#161618`/`#f5edda` (which happen to equal ink-900/gold-100, so they look right but will not follow a palette swap), and `card_border_color` is a sixth colour. Both need a “follow brand colours” option in Appearance rather than a silent default change |
| Favicon + app icon | **Done** | code | `public/favicon.ico` was a **0-byte file**; the admin layout ignored the uploaded favicon; the manifest emitted one `sizes:"any"` icon and hardcoded the gold palette. All fixed |
| Brand story / About page | **Done** | code | A `brand_story` landing template already shipped but had no URL and nothing linked to it. `/about` now routes, and the footer links it |
| "Cash on delivery / nationwide" repeated 6x | **Partial** | code + content | The four hardcoded repeats in `Home.jsx` (hero trust line, gift-finder blurb, the three gift promises, the four gifting steps) are now `home_content` settings, so they can be edited at all — previously they could not. **Writing the real copy is yours** — Appearance → Homepage content |
| Naming: Ring/Rings, Jewellery/Jewelry | **Todo** | content | Pure content. Renaming a category is safe — the model only generates a slug when blank, so `/category/ring` keeps working and no redirects are needed |

---

## Step 03 — Start collecting reviews

**Roadmap:** *"Ask after delivery."* — **the machinery is almost entirely built.**
*(dossier stale: it read as though this were unbuilt)*

| Item | Status | Owner | What remains |
|---|---|---|---|
| Customer review submission | **Done** | code | Form and endpoint exist, rate-limited 5/10min |
| Points awarded for an approved review | **Done** | code | The reward the dossier said "already exists" — confirmed |
| Rating aggregation + star display | **Done** | code | The all-five-gold-stars bug was the missing theme shades; fixed in `0fb357a` |
| Admin moderation | **Done** | code | pending / approved / hidden |
| Verified-buyer flag | **Done** | code | It *is* set from the order — `ReviewController::store()` matches the typed phone against the orders table. The new invite page prefills that phone, so a review arriving from our own request lands verified. Confirmed end to end against the database (`7d7fa1f`) |
| **Post-delivery review request** | **Done (code) / Todo (setting)** | code + setting | `reviews:request` runs daily, finds orders the courier confirmed delivered, waits out a delay and queues `SendReviewRequest` — SMS plus email where there is an address — carrying a signed `order.review` link that works for a guest. Delivery time is read from `order_status_history`, so pre-existing orders need no backfill. **Off until you switch it on** in Admin → Notifications: it spends SMS credit. Three brakes: a delay window, a max-age window so the first run does not text every historical order, and a per-run cap. `php artisan reviews:request --dry` lists who would be asked (`7d7fa1f`) |

---

## Step 04 — Make gifts findable

*(dossier partly stale — the price filter it asked for already exists)*

| Item | Status | Owner | What remains |
|---|---|---|---|
| Price/budget filtering | **Done** | code | `price_min` / `price_max` / `price_range[]` are honoured *(dossier stale)* |
| Gift finder by budget | **Done** | code | Built, linked, honoured by the catalog (`7daefff` un-hid it) |
| Products can carry tags today | **Done** | code | Admin form, quick-edit, CSV import, **and** the MCP/API layer can all set tags |
| Budget inputs in the catalog UI | **Partial** | code | `price_min`/`price_max` work but are URL-only — no min/max box in the sidebar. A budget *collection* is now the better answer for the common bands |
| In-stock / on-sale toggles | **Partial** | code | Configurable and query-honoured, but never rendered |
| Tags sidebar group | **Partial** | setting | Works as a URL param; the sidebar group is off by default |
| Search misses tags, description, category | **Done** | code | `scopeSearch` now covers tags, the long description and the category name. `gift` returns products again |
| **`q` is silently dropped on category pages** | **Done** | code | `category()` now calls `->search()` like the other two catalog routes. Regression test pins both the count and `searchQuery` |
| Occasion concept on products | **Done** | code | **Collections.** A saved rule set (tag / price / category / stock / on-sale / colour / flags, matched all-or-any) with its own page, image, SEO and menu slot. Smart collections keep themselves up to date; manual ones are a picked list; a smart one can also pin products on top |
| Occasion tiles link to unfiltered `/shop` | **Done (code) / Todo (content)** | code + content | Appearance → occasion tiles now has a “Point this tile at a collection” picker. **Building the collections and tagging products is yours** — the tiles still land on `/shop` until you do |
| Bulk "tag selected products" | **Done** | code | Add / Remove / Replace in the product list's existing bulk bar, sharing its checkboxes and select-all. Replace refuses an empty box, so there is no way to wipe every tag off 200 products by accident. The writer joins with `", "` — one of the two separators the collection matcher recognises — and a test pins that a tag written here is matched by a live `tag is` rule while still never dragging `gift-card` in behind `gift` (`7d7fa1f`) |

---

## Step 05 — Squeeze the server

| Item | Status | Owner | What remains |
|---|---|---|---|
| Pageview logging off the request path | **Done** | code | Moved to `terminating()` |
| Homepage query caching, N+1 sweep, setting memo | **Done** | code | `ce4c9c7` |
| Cache off the database | **Done** | infra | `DEPLOY.md` and the 2026-08-01 SSH check both record **Redis** in production *(dossier stale — the local `.env` says `database`, but that is the dev environment)* |
| Sessions off the database | **Done** | code + infra | `session:to-redis` copies live sessions across without logging anyone out — the two handlers do not store the same bytes (database base64-encodes, cache stores raw), so a column copy would have corrupted every session silently. Sessions get their own Redis database, because `cache:clear` is a whole-database FLUSHDB and `deploy.sh` runs it every deploy. `SESSION_CONNECTION` is only read under the redis driver, so a rollback cannot 500 the site (`61c9435`) |
| **Synchronous Meta CAPI POST on PDP + checkout** | **Done** | code | ViewContent, InitiateCheckout and AddToCart moved to `terminating()` — the same mechanism the pageview insert already uses — with the browser signals snapshotted eagerly so the payload cannot depend on how much of the request is still standing. Deliberately *not* queued: a live check found ~390 ViewContents in three days, which would swamp the same queue that carries order SMS. Deferred sends cap at 5s because they still hold an lsphp worker; Purchase keeps its full 10s and stays queued, since `send()` never throws and a timeout there is silent permanent loss (`61c9435`) |
| Fonts converted to WOFF2 | **Todo** | code | Long-cache headers shipped in `ce4c9c7`, but **no transcode exists at all** — uploads are stored byte-for-byte. TTF is still accepted with no warning |
| Edge/page cache for guest HTML | **Todo** | infra | Cloudflare page rules or LiteSpeed |

---

## Step 06 — Sharing cards and accessibility

| Item | Status | Owner | What remains |
|---|---|---|---|
| Per-page Open Graph cards | **Done** | code | Every page type emits a card; image resolves category image → page LCP image → shop logo. Verified live on `/` and `/shop` (`77123bd`) |
| Canonical + structured data | **Partial** | code | Canonical present; JSON-LD coverage incomplete |
| Off-screen menus stay tabbable | **Done** | code | Both overlays are `inert` when closed — measured in a browser, the sixteen drawer links can no longer take focus. Open, they behave like the dialogs they claimed to be: focus in, Tab trapped, Escape out, focus back to the trigger. `role="dialog"` moved off the full-screen wrapper, which had been announcing an open modal on every page (`5d60183`) |
| Focus reset after Inertia navigation | **Done** | code | Skip link, focus to `<main>` and an aria-live route announcer (`77123bd`) — *(row was stale)* |
| Icon-only buttons without a name | **Done** | code | Swept the storefront. The cart button announced as a bare number (element content outranks `title`); the product-card Add button had no name at all on phones, where its visible label is `display:none` (`5d60183`) |
| Contrast against WCAG AA | **Done** | code | New `ink-500` token (4.83:1 on white) for struck-through prices and other real text; ~150 low-alpha text classes raised to `/70`, the break-even step for 4.5:1 — `/65` still fails. `ink-400` stays on borders, glyphs and disabled controls, which are exempt (`5d60183`) |
| Skip link, landmarks, heading order | **Done** | code | Footer headings were h3s on h1-only pages, a site-wide skip. Homepage builder blocks with a blank title now emit an `sr-only` h2 rather than inventing visible copy. Every nav landmark named; form labels associated across auth, profile, addresses, tracking and checkout (`5d60183`) |

---

## Step 07 — Then, and only then, the admin panel

| Item | Status | Owner | What remains |
|---|---|---|---|
| Admin as React/Inertia | **Todo** | code | **Zero** admin screens are Inertia today — every one is Blade. This is the ~40-screen job the dossier said to leave until last. Start with the order page |
| Live admin notifications without reload | **Done** | code | The bell polls a JSON feed every 25s (`7daefff`) — the one piece already done |
| ~2,500 lines of dead pre-React Blade | **Todo** | code | Delete after the React storefront has soaked another week |
| Dead "Product page template" setting | **Todo** | code | Inert end-to-end — the test suite itself proves it changes nothing. Remove the picker, validation, config block and category dropdowns |
| Homepage still forks Blade vs React | **Partial** | code + setting | React only while the homepage template setting is `couture` |
| Inertia page tests | **Partial** | code | Only a few of the React pages assert component + props |
| Any JS test harness | **Todo** | code | No vitest/jest at all |
| MCP product lifecycle | **Partial** | code | Create / publish / edit / photos / archive / search all work. Variants, offers, category *creation*, orders and customers are deliberately still admin-only |

---

## Loose findings not on the numbered roadmap

| Item | Status | Owner | Note |
|---|---|---|---|
| Push prompt fires 1.5s after landing | **Done** | code | Waits for register or order (`77123bd`) — *(row was stale)* |
| Post-registration push prompt is dead | **Done** | code | **The stated cause was wrong.** The script lives in the root Blade view, which an Inertia visit never re-renders — so the trigger could never be *observed*, whatever the localStorage key did. It arrives as a shared prop now, with one key per trigger so register and order each fire once instead of whichever came first silencing the other (`5d60183`) |
| Checkout captures phone on blur, undisclosed | **Done (code) / Todo (content)** | code + content | Checkout now says so under the field (`77123bd`). **The privacy page still describes capture at order time** — that wording is yours to correct |
| Corrected phone numbers never re-captured | **Done** | code | Keys on the canonical number instead of a boolean latch, so a corrected number re-sends; the lead row also stores the canonical form, so it can match the order that eventually arrives (`61c9435`) |
| Public tracker shows staff notes verbatim | **Done** | code | Notes no longer leave the server for the public tracker (`77123bd`) |
| Exact per-variant stock in page props | **Done** | code | Now a boolean. `PlaceOrder` still re-validates the real quantity, so a client that no longer knows it cannot over-order — pinned by a test (`5d60183`) |
| Checkout errors only as a top banner | **Done** | code | Every field renders its own message with `aria-invalid` / `aria-describedby`, and focus jumps to the first invalid field. The banner summarises instead of duplicating, and still lists anything with no input on the page (`61c9435`) |
| Confirmation page ends the conversation | **Done** | code | Says what happens next: we call, the courier delivers on a named window, keep exactly this much ready. **The "ready-made builder" did not exist** — the same three lines of Carbon were pasted in two places and neither knew that inside Dhaka is faster or that nobody delivers on Friday. `App\Support\DeliveryEstimate` is now the one builder, skips non-delivery days, and the four windows are editable in Appearance (they were config-only, so effectively hardcoded). A stale window is dropped rather than quoting a past date (`5d60183`) |
| Account order detail eager-loads unused history | **Done** | code | Load removed; the page renders neither notes nor the status trail (`5d60183`) |
| Zero social proof on 105 products | **Todo** | content | Closes itself once step 03's request fires |

---

## Changelog

| Date | Commit | What |
|---|---|---|
| 2026-08-22 | `0fb357a` | Stars, floats, zone default, CSRF, privacy |
| 2026-08-22 | `ce4c9c7` | Bundle split, LCP preload, font caching |
| 2026-08-22 | `7daefff` | Gift-card settings, free-delivery checkout, member nudge, live admin bell |
| 2026-08-22 | `9de7081` | Steps 01 and 02 closed on the code side; roadmap re-audited. 508 tests (was 485) |
| 2026-08-22 | *this change* | Step 04: Collections — Shopify-style smart product groups, in the menu, the sitemap and the occasion tiles. 538 tests |
| 2026-08-23 | `77123bd` | Sharing cards on every page, keyboard/screen-reader pass, two privacy fixes. 541 tests |
| 2026-08-23 | `61c9435` | Checkout badge + field errors, Meta CAPI off the render path, sessions ready for Redis. 556 tests |
| 2026-08-23 | `7d7fa1f` | Step 03 post-delivery review requests; step 04 bulk product tagging. 580 tests |
| 2026-08-23 | `5d60183` | Step 06 keyboard access + contrast; confirmation page delivery estimate and COD-ready line. 592 tests |
| 2026-08-23 | `51a470a` | **New audit, 91 verified findings.** Money leaks: coupon overcharge, points destroyed on cancel, Meta AddToCart blind outside the PDP, UTC vs Dhaka delivery dates, review-request backlog burn, guest cart-recovery SMS, cart delivery cost, real co-purchase suggestions. 604 tests |
| 2026-08-23 | `3497553` | Admin: correct a delivery address, take an order by hand, partial deliveries, integration health alerts, Reviews 500 on a deleted product, Steadfast off the page-load path. 615 tests |
| 2026-08-23 | `9a7aad3` | Landing-page icon names printed as text; Meta token + 1,200 phone numbers purged from production logs; admin role gate and the first negative authz tests. 631 tests |
| 2026-08-23 | `f833695` | Speed: brand fonts actually served (146 KB, not 268 KB force-preloaded), filter sidebar cached with versioned busting, Deals of the Day cached, related products hydrate only the winners. 638 tests |

---

## What shipped in this change

Roadmap steps **01** and **02**, everything on them that is not the owner's
decision to make.

**Step 01 — the free-delivery promise.** The threshold was env-only, so the
store owner could not set it without SSH, and the banner was arbitrary free
text with no connection to it. `free_shipping_threshold()` is now the single
place the number is resolved; the cart, the checkout, the order total and the
banner all read it. It is a field in Admin → Settings beside the shipping
rates, and blank means off. A `{free_delivery}` placeholder prints the live
amount, and the message is hidden entirely while the promise is off — so the
bar cannot advertise something the checkout will not honour. **What is
deliberately not done: choosing the number.** That is a margin decision.

**Step 02 — the brand leaks.** Repo-level `Noychoy` defaults corrected; the
duplicated `footer_about` default collapsed to one source; the header CTA
placeholder no longer nudges toward order tracking and a commercial label
defaults in; the emoji icon system replaced by the storefront's own stroke
icons, including a migration for emoji already saved in the live settings; 136
stock Tailwind status colours swept onto brand-warmed semantic tokens; a real
favicon shipped (the repo's was a **0-byte file**) and the manifest fixed to
emit installable 192/512 maskable icons and follow the store palette; `/about`
added so the brand-story template that already shipped finally has a URL and a
footer link; and the homepage's hardcoded "cash on delivery" repeats made
editable.

### Collections (step 04)

A **collection** is a saved rule set with its own page, image, SEO and menu slot
— the thing the roadmap needed for "make gifts findable", and the answer to
occasion tiles that dead-end on the full catalogue.

- **Rules**: tag, title, SKU, description, colour, price, compare-at price,
  stock, weight, category, in-stock, on-sale, featured, bestseller, pre-order,
  has-options, date added — matched **all** or **any**. The vocabulary lives in
  one place (`App\Support\CollectionRules`); the admin dropdowns, the query
  compiler and the validation all read it, so adding a rule is a one-file change.
- **Smart or manual**, mirroring `CustomerSegment`. A smart collection can also
  pin products, which show on top of whatever the rules match.
- **Empty means empty.** A smart collection with no usable rules returns
  nothing, deliberately: showing the whole catalogue under a name like "Eid
  Gifts" looks like it worked when it did not.
- **Whole-tag matching.** `tag is gift` does not match `gift-card`. The
  catalogue stores both `Gift` and `gift`, so matching is case-insensitive.
- **Live preview** in the admin: the match count updates as you build the rules,
  before anything is saved, and it tells you how many rows it had to ignore.
- **Menu**: a menu item, a dropdown child or a mega-menu link can point at a
  collection by name instead of a typed URL. Resolved once at save time —
  `site_menu()` runs on every request, so resolving there would be a query per
  item per page. Renaming a collection keeps its URL, so nav links never break.
- Collections are in the **sitemap** (cache key bumped so a warm cache cannot
  keep serving the old one) and are SPA-navigable.

Three bugs fixed in the same pass, all confirmed against the code first:

1. `/category/{slug}?q=` **silently dropped the search term** while the page
   still rendered a search-results state and fired a Meta Pixel `Search` event.
2. Storefront search **missed tags and the long description**, which is why
   searching "gift" returned nothing.
3. `/feed/meta.csv?category=x` **leaked draft products to Meta**: an
   `orWhereHas` outside a closure compiled to `(published AND pivot) OR primary`.
   Also fixed a latent `$type` crash in the menu sanitiser from the same
   `??`-only-guards-the-check pattern.

**Verified:** 508 tests passing, assets rebuilt and committed, and the promise
chain checked in a browser in both directions — ৳5,550 unlocks free delivery
and replaces the zone picker, and with the promise off the picker returns,
shipping is ৳130 and the banner stops mentioning it. No console errors.
