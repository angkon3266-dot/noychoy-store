---
type: seo
topic: strategy
updated: 2026-07-18
---

# SEO strategy

## Market & language
Target: Bangladesh, English-language queries with Bangla-mixed intent
("gold plated earrings price in bd", "bridal jewellery set bd", "cash on
delivery jewelry"). Note both spellings: **jewelry/jewellery** — use one per
page (title = the primary keyword's spelling), never both awkwardly.

## Keyword architecture
- **Homepage:** brand + "online jewelry shop Bangladesh".
- **Collection pages:** category head terms — "earrings price in Bangladesh",
  "gold plated ring bd", "bridal jewellery set". These pages carry the SEO
  weight; give each 80–150 words of real intro copy (see collection files).
- **Product pages:** long-tail — material + type + occasion ("rhodium plated
  cubic zirconia stud earrings"). Primary keyword goes in meta title, first
  sentence, and one image alt.
- **Best sellers page** (`/best-sellers`) targets "best selling jewelry bd" —
  keep it linked from the homepage.

## Technical facts (already implemented — rely on them)
- Canonical tags strip filter/sort query strings.
- `sitemap.xml` auto-generates (1-hour cache); robots.txt references it and
  blocks /admin, /cart, /checkout, /account.
- Every page emits a meta description (product copy → tagline fallback).
- Product pages emit OG product tags (price, availability, brand from the
  store-name setting).
- Homepage browser title is editable: Admin → Appearance → Homepage content.

## Gaps worth closing (in order)
1. JSON-LD Product schema (name, image, offers, brand; AggregateRating once
   reviews accrue) — rich results in Google.
2. Collection intro copy (thin category pages rank poorly).
3. Content angles from `marketing/` doubling as blog/landing pages
   ("CZ vs diamond — honest comparison") targeting research queries.

## Rules for AI-generated meta
- Meta title ≤ 60 chars: `{Primary Keyword} — {Store Name}`.
- Meta description ≤ 160 chars: benefit + trust (COD) + soft CTA.
- Never keyword-stuff; one primary + one secondary per page.
