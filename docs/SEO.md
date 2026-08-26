# SEO — meridianeclat.shop

How this shop gets found in Bangladesh. Two halves: what the code now does
(shipped, tested, nothing to configure), and what only the owner can do
(accounts, words, and one Cloudflare setting).

Written 26 August 2026. The audit behind it is at the bottom.

---

## 1. What was actually wrong

The site was not "unoptimised". It was **invisible to anything that does not
run JavaScript**, and it did not say anywhere that it sells in Bangladesh.

**The document that left the server was empty.** The browsing pages are React
over Inertia, so a crawler fetching a product page received a JSON blob and
`<div id="app"></div>`: zero `<h1>`, zero `<a href>`, zero words of product
copy. Verified against production on 26 Aug:

```
$ curl -s https://meridianeclat.shop/product/turquoise-rose-heart-earrings-2 \
    | grep -c '<h1'      # 0
    | grep -c '<a href'  # 0
```

Google renders JavaScript, eventually and inconsistently, and gives
JS-dependent pages a slower, lower-priority second crawl. Bing largely does not
render at all. Neither do the AI answer engines. And a site with no
server-rendered links has **no crawlable link graph** — internal linking, the
main lever for telling Google which of 130 pages matter, was doing nothing.

**Nothing said "Bangladesh".** `<html lang="en">`, `og:locale` `en_GB` — the
shop was literally telling Facebook it was British. No country, no currency in
schema, no shipping details, no geo signals. Every title was the bare product
name, when the query a Bangladeshi shopper actually types is *"<product> price
in bangladesh"*.

**Three settings were fake.** Admin → Appearance → "Homepage SEO title" wrote
to a key the save loop discarded, so whatever the owner typed was thrown away
and a hardcoded fallback shipped. (The same class of bug as the SEO settings
fixed in an earlier pass.)

**Everything with a `?` was a duplicate.** `?sort=`, `?colors[]=`,
`?price_range[]=` and every combination were crawlable and self-canonicalising.
Worse, page 2 of a grid canonicalised to page 1 — telling Google the two were
the same page and discarding every product only reachable from page 2 onward.

---

## 2. What the code does now

All of it is covered by `tests/Feature/SeoTest.php` (22 tests).

### A crawlable page under the React one

`resources/views/partials/seo-body.blade.php` renders real HTML *inside*
`<div id="app">`: heading, breadcrumb, price, availability, the product copy,
the grid's products, the category spine and the footer links. React's
`createRoot().render()` clears the container the moment it mounts, so it is a
genuine pre-hydration fallback — **not hidden text, not cloaking**: a crawler
and a JS-less visitor see exactly what a normal visitor sees for the first few
hundred milliseconds. On a slow mobile connection it also replaces the blank
white screen with the page's heading and price while the bundle downloads.

Server-side rendering the real React tree would be better. It needs a Node
process the shared host does not have; if that ever changes, Inertia SSR
replaces both this and the shell.

### One SEO head, shared

`partials/seo-head.blade.php` now owns title, description, robots, canonical,
hreflang, OG/Twitter and JSON-LD for **both** layouts. Previously this was
copy-pasted between `inertia.blade.php` and `layouts/shop.blade.php` — two
copies that had already drifted, so a fix to one silently missed half the site.

### Bangladesh, said out loud

| Signal | Now |
|---|---|
| `<html lang>` | `en-BD` |
| `og:locale` | `en_BD` (+ `bn_BD` alternate) |
| `hreflang` | `en-bd` + `x-default`, self-referencing |
| `geo.region` / `geo.placename` | `BD-C` / Dhaka, Bangladesh |
| Organization schema | `OnlineStore`, `addressCountry: BD`, `areaServed: Bangladesh`, `currenciesAccepted: BDT`, `paymentAccepted: Cash on Delivery`, price range, contact point in `bn`/`en` |
| Titles | `<name> — Price in Bangladesh \| <store>` when no meta title is written |
| Descriptions | lead copy + `Price ৳1,450. Cash on delivery all over Bangladesh.` |

Admin-written meta titles and descriptions always win; these only fill blanks.

### Structured data worth being chosen for

One `@graph` per page instead of loose scripts, so the offer can point at the
Organization by `@id` rather than repeating it. The Product offer gained the
fields Google's shopping surfaces actually rank on: `itemCondition`,
`priceValidUntil`, `seller`, `areaServed`, real `OfferShippingDetails` (৳70
inside Dhaka 1–2 days, ৳130 outside 2–4 days, from the shop's own config) and
`MerchantReturnPolicy`. Categories and collections now emit `BreadcrumbList`
and `ItemList`; every page emits `WebSite` with a `SearchAction`, which is what
makes a sitelinks search box possible under the brand result.

Two deliberate omissions, both honesty rather than oversight:

- `aggregateRating` is emitted only when real reviews exist. A zero-count
  rating is a rich-results policy violation.
- `returnFees` is left unset (`config/seo.php`). The shipped refund policy only
  accepts returns for damaged or wrong items, so any value would be a claim the
  policy page does not make. **Decide it, then set `SEO_RETURN_FEES`.**

### Indexation

- Filtered / sorted / searched grids → `noindex, follow` and **no canonical**.
  A `noindex` plus a canonical pointing elsewhere is two contradictory
  instructions; the page says one thing.
- Page 2 canonicalises to page 2.
- Indexable pages ask for `max-image-preview:large` — this is a jewelry shop,
  the photography is the product, and Images and Discover are a large share of
  Bangladeshi mobile discovery.
- Cart, checkout, account, login, track, order pages → `noindex, follow`.
- `robots.txt` blocks the utility paths and **deliberately does not block
  facets** — a page Google may not fetch is a page whose `noindex` Google never
  reads. On a 134-URL catalogue crawl budget is not the constraint.

### Sitemap

Adds `image:image` for every product photo (Google Images cannot rank an image
it never crawls, and these were only reachable through a React gallery),
landing pages, and a real `lastmod` on the homepage.

---

## 3. What only you can do

Ordered by impact. Nothing here needs a developer.

### 3.1 Unblock the AI crawlers — Cloudflare, 2 minutes

Cloudflare is injecting a **managed `robots.txt`** ahead of the app's, and it
currently blocks `GPTBot`, `ClaudeBot`, `Google-Extended`, `Bytespider`,
`Amazonbot`, `CCBot`, `meta-externalagent` and `Applebot-Extended` from the
whole site:

```
User-agent: GPTBot
Disallow: /
```

That is Cloudflare's default, not a decision anyone here made. It means
ChatGPT, Claude, Perplexity and Meta AI cannot read the shop — and "where can I
buy affordable jewellery in Dhaka with cash on delivery" is exactly the kind of
question those tools now answer with a shortlist. Note the shop is not paid for
being in that shortlist, which is the point.

Cloudflare dashboard → the `meridianeclat.shop` zone → **AI Crawl Control** (or
Settings → Managed robots.txt) → turn the AI-crawler block **off**, or allow
the ones you want. `Google-Extended` in particular affects AI Overviews, which
sit above the normal results.

Trade-off, stated plainly: allowing them means the shop's copy can be used for
model training and grounding. For a store that wants to be recommended, that is
the deal. If you would rather not, allow `Google-Extended`, `GPTBot` and
`ClaudeBot` (retrieval/answering) and keep `CCBot` and `Bytespider` blocked.

### 3.2 Search Console and Bing — 20 minutes, do this first

Neither is set up, which means nobody here can see a single real query.

1. [Google Search Console](https://search.google.com/search-console) → add
   `meridianeclat.shop` as a **Domain** property (DNS TXT via Cloudflare).
2. Submit `https://meridianeclat.shop/sitemap.xml`.
3. Use **URL Inspection → Test live URL → View tested page** on one product.
   You should now see the heading, price and links in the raw HTML.
4. [Bing Webmaster Tools](https://www.bing.com/webmasters) — import from GSC in
   one click. Bing does not render JS well, so it is the clearest read on
   whether the new server-rendered shell is doing its job.

Then leave them alone for three weeks. Indexing is not fast.

### 3.3 Fix the category taxonomy — highest-value content work

The live sitemap shows the catalogue competing with itself:

- `/category/bracelet` **and** `/category/bracelet-2`
- `/category/women`, `/category/women-jewelry`, `/category/jewellery-for-her`
- `/category/dummy` — a placeholder, currently in the sitemap and indexable

Three near-identical pages for one intent is keyword cannibalisation: Google
splits the signals between them and ranks none of them well. In Admin →
Categories:

- Delete `dummy`.
- Merge the duplicates into one page each, and deactivate the losers
  (deactivating removes them from the sitemap automatically).
- Give every surviving category a **written description**. It is the only body
  copy those pages have, it becomes the meta description, and it is where the
  words a shopper actually searches for belong.

### 3.4 Write for the query, not the product

Bangladeshi search behaviour is specific and the catalogue currently ignores it.

**Price-led.** `<thing> price in bangladesh`, `<thing> price in bd`, `<thing>
দাম` dominate commercial intent here. The titles now carry the qualifier
automatically; the *descriptions* should carry the number.

**Occasion-led.** Search here spikes hard around occasions, and the catalogue
has no page for any of them. These are collections you can build today in
Admin → Collections, each with its own page, description and menu slot:

| Collection | Aim at |
|---|---|
| Bridal & wedding jewellery | `bridal jewellery bangladesh`, holud / bou bhat sets |
| Eid collection | seasonal, build it 6 weeks before each Eid |
| Gifts for her under ৳1,500 | `gift for wife bd`, `birthday gift for girlfriend bd` |
| Everyday / office wear | the repeat-purchase segment |
| Pohela Boishakh | red-and-white, seasonal |

Six weeks of lead time is the rule — a page published the week of Eid ranks for
nothing.

**Category-led.** `artificial jewellery bangladesh`, `imitation jewellery price
in bd`, `cubic zirconia ring bangladesh`, `925 silver ring price in bangladesh`.
Two of these already map to existing categories that have no description.

**Bengali.** Roughly half of Bangladeshi mobile search is Bengali or
transliterated Bengali (`kanher dul`, `golar har`, `আংটি`). The site is
English-only and there is no cheap fix — a real Bengali version means
translated product copy and `hreflang` wiring, which is a project, not a
setting. The pragmatic middle: put the common Bengali and transliterated terms
into category descriptions and product tags, where they cost nothing. Say if
you want the full bilingual build costed.

### 3.5 Product copy and photos

- **Every product needs its own description.** Products sharing boilerplate
  compete with each other and read as thin content. The description now feeds
  the meta description *and* the crawlable shell, so it is doing three jobs.
- **Image alt text** is stored per image and mostly empty. Alt text is how
  Google Images understands a photo, and Images is where jewelry gets found.
- **Reviews.** Zero reviews means no star rating in results — the single most
  visible difference between two listings. The loyalty points-for-reviews
  mechanism already exists; use it.

### 3.6 Google Merchant Center — worth checking

Free product listings put the catalogue on the Shopping tab, Images and Lens at
no cost. Bangladesh appears in Google's regional-availability list, but
availability changes and beta conditions apply, so **verify inside your own
Merchant Center account** before planning around it.

The shop already generates a Meta catalogue feed at `/feed/meta.csv`. A Google
feed is a close cousin of that controller — say the word and it is a small
change, but it is only worth building once you have confirmed the account
accepts Bangladesh.

### 3.7 Google Business Profile — probably not eligible

Worth stating so nobody wastes a week on it. Google Business Profile is **not
for online-only businesses**; it requires in-person customer contact. Delivery
via a third-party courier (Steadfast) does not count as the shop meeting the
customer. If you have an office or showroom customers can visit by appointment,
you qualify as a service-area business and it is worth doing. Otherwise, skip
it and put the effort into 3.3 and 3.4.

### 3.8 Links

Backlinks remain the hardest ranking factor and there is no shortcut. What
works for a Bangladeshi shop, roughly in order of effort:

- Bangladeshi business directories and marketplace profiles that allow a link.
- The shop's own Facebook and Instagram bios (already set) — these are `sameAs`
  in the Organization schema now, which helps Google connect the entities.
- Bangladeshi lifestyle and wedding bloggers / Facebook page owners: gifted
  pieces in exchange for an honest post with a link.
- Being genuinely useful: a jewellery care guide, a ring-size guide in Bengali,
  a "what to buy for a holud" piece. These earn links and rank for the
  informational queries that precede a purchase.

Do not buy links.

---

## 4. Measuring it

Check monthly in Search Console, not weekly. What to watch:

- **Coverage** — are all ~134 URLs indexed? Filtered URLs should *not* appear.
- **Queries containing "price"** — the clearest proof the new titles are
  matching real intent.
- **Impressions before clicks.** Impressions rise first, by weeks. A flat click
  count with rising impressions means the titles and descriptions need work,
  not the rankings.
- **Bangladesh share of impressions** — should approach 100%.

Realistic timeline: re-crawling and re-indexing 3–6 weeks, ranking movement
2–4 months, occasion collections only as good as their lead time.

---

## 5. Audit record

Audited against production on 26 August 2026 (`curl`, live `robots.txt`, live
sitemap, rendered DOM). Findings and fixes:

| Finding | Severity | Status |
|---|---|---|
| Body server-renders no heading, copy or links | Critical | Fixed — pre-hydration shell |
| React overwrote the server `<title>` on mount | Critical | Fixed — `seoTitle` prop shared with `Layout.jsx` |
| No Organization / WebSite schema anywhere | High | Fixed |
| Offer had price and availability only | High | Fixed — shipping, returns, condition, seller, validity |
| `og:locale` `en_GB`, `<html lang="en">`, no geo | High | Fixed |
| Titles carried no market qualifier | High | Fixed |
| Page 2 canonicalised to page 1 | High | Fixed |
| Facets crawlable and self-canonical | Medium | Fixed — `noindex, follow`, no canonical |
| Empty page title rendered "Store — Store" | Medium | Fixed |
| Homepage SEO title/description discarded on save | Medium | Fixed |
| SEO head duplicated and drifted across two layouts | Medium | Fixed — one partial |
| Landing pages absent from sitemap; duplicate OG tags | Medium | Fixed |
| No product images in sitemap | Medium | Fixed |
| JSON-LD printed unescaped (`</script>` breakout) | Medium | Fixed — `JSON_HEX_TAG` |
| Cloudflare blocks all AI crawlers | High | **Owner — §3.1** |
| No Search Console / Bing property | High | **Owner — §3.2** |
| Duplicate + placeholder categories | High | **Owner — §3.3** |
| No occasion or price-band collections | High | **Owner — §3.4** |
| Empty image alt text, no reviews | Medium | **Owner — §3.5** |
