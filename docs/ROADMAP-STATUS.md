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
| Header's only filled button sells logistics | **Done (code) / Todo (setting)** | code + setting | Admin → Menu shipped `Track order` as the *placeholder*, nudging the owner to spend the store's one primary button on logistics. Placeholder changed, the default is now **Shop gifts**, and the field is explained. **The live label is a stored setting — still says “Track Orders” until you clear or change it in Admin → Menu.** Deliberately not overwritten: it is your button. Track order is already in the mobile drawer and the footer |
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
| Verified-buyer flag | **Partial** | code | Column exists; confirm it is set from the order |
| **Post-delivery review request** | **Todo** | code | *The whole gap.* Nothing fires on `delivered`. `DripService` / `NotificationService` / `SmsService` all exist to carry it — this is wiring a trigger, not building a system |

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
| Bulk "tag selected products" | **Todo** | code | Still the real barrier: collections are only as good as the tags they match. Products already carry tags (admin form, quick-edit, CSV import, MCP/API) — what is missing is a “tag these 20 at once” action |

---

## Step 05 — Squeeze the server

| Item | Status | Owner | What remains |
|---|---|---|---|
| Pageview logging off the request path | **Done** | code | Moved to `terminating()` |
| Homepage query caching, N+1 sweep, setting memo | **Done** | code | `ce4c9c7` |
| Cache off the database | **Done** | infra | `DEPLOY.md` and the 2026-08-01 SSH check both record **Redis** in production *(dossier stale — the local `.env` says `database`, but that is the dev environment)* |
| Sessions off the database | **Todo** | infra + code | Genuinely still DB: a SELECT + UPDATE per request, plus a 2% sweep DELETE. There is no `SESSION_DRIVER` field in the dashboard, so this is a server `.env` edit or a new config-schema field |
| **Synchronous Meta CAPI POST on PDP + checkout** | **Todo** | code | **The biggest remaining TTFB item.** A product-page or checkout render blocks on `graph.facebook.com` with a **10-second timeout** before a byte of HTML is written. Costs nothing while CAPI is off; dominates TTFB when on. `Purchase` is already queued — the other two events should follow |
| Fonts converted to WOFF2 | **Todo** | code | Long-cache headers shipped in `ce4c9c7`, but **no transcode exists at all** — uploads are stored byte-for-byte. TTF is still accepted with no warning |
| Edge/page cache for guest HTML | **Todo** | infra | Cloudflare page rules or LiteSpeed |

---

## Step 06 — Sharing cards and accessibility

| Item | Status | Owner | What remains |
|---|---|---|---|
| Per-page Open Graph cards | **Partial** | code | `og:*` is emitted **only** inside the product-route branch of `inertia.blade.php`. Home, category, discover and the legal pages share one description |
| Canonical + structured data | **Partial** | code | Canonical present; JSON-LD coverage incomplete |
| Off-screen menus stay tabbable | **Partial** | code | The complete `aria-hidden` + `tabIndex` pattern already ships on the homepage hero, and `MiniCart` has half of it — so this is copying a house pattern, not inventing one. `inert` appears nowhere; the focus trap is a regression against the old Alpine drawer |
| Focus reset after Inertia navigation | **Todo** | code | Focus is dropped on every navigation |
| Icon-only buttons without a name | **Partial** | code | Several missing `aria-label` |
| Contrast against WCAG AA | **Todo** | code | Some brand text colours fail on white |
| Skip link, landmarks, heading order | **Partial** | code | |

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
| Push prompt fires 1.5s after landing | **Todo** | code | Asking before giving anything earns a permanent Block |
| Post-registration push prompt is dead | **Todo** | code | **Found during this audit.** Guests set `localStorage.wp_asked` on their *first* page view, so the intended post-signup prompt can never fire. Any fix must use per-trigger keys |
| Checkout captures phone on blur, undisclosed | **Todo** | code + content | The privacy page actively contradicts it, describing capture at order time |
| Corrected phone numbers never re-captured | **Todo** | code | **Found during this audit.** The lead guard latches after the first POST, so a typo'd number is the one kept — the exact case the feature exists for |
| Public tracker shows staff notes verbatim | **Todo** | code | Includes system notes like "Order amount amended". Needs a visibility flag; there is no column for it today |
| Exact per-variant stock in page props | **Todo** | code | The UI only ever uses it as a boolean — a one-line change plus three call sites |
| Checkout errors only as a top banner | **Todo** | code | The server *does* send per-field messages; `Checkout.jsx` flattens the keys away. The house pattern already exists in `Account/Profile.jsx` |
| Confirmation page ends the conversation | **Partial** | code | COD total and track link exist; no delivery estimate and no "keep ৳X ready" line. The estimate settings and a ready-made builder already exist |
| Account order detail eager-loads unused history | **Todo** | code | One wasted query per view |
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
