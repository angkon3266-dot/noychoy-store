# Traffic analytics — how the store knows where a visitor came from

Everything here is first-party: one row per pageview in `visits`, no external
analytics provider, no IP address stored. The dashboard's **Traffic &
conversion funnel** panel is the only consumer.

---

## The pieces

| Where | What it does |
|---|---|
| `App\Http\Middleware\TrackVisit` | Gives every browser a 2-year `visitor_token` cookie and records one row per storefront pageview, after the response is flushed. Skips admin, bots, and the owner's own browsing |
| `App\Support\TrafficSource` | Turns a request into a channel, campaign, ad and referrer |
| `App\Models\Visit::attributionFor()` | Reads a visitor's history and stamps first- and last-touch onto their order |
| `App\Services\DashboardAnalytics` | The dashboard's funnel, daily series, channel table, ad table and live card |
| `visits:reclassify` | Re-files historic rows after the classifier learns something new |

`Visit::record()` never throws into a request, and every dashboard panel is
wrapped so one failing panel costs you that panel and not the page.

---

## How a channel is decided

Strongest signal first (`TrafficSource::resolve`):

1. **`utm_source`** — you set it, so it wins. Matched first against whole-value
   aliases (`fb`, `ig`, `an`, `msg`, `wa`, `tt`, `yt`…), then as a substring
   against known hosts, so `Facebook_Mobile_Feed` still lands on Facebook.
2. **A click id** — `fbclid`, `gclid`, `ttclid`, `msclkid`. These survive when
   the referrer does not, which is the usual case in a mobile in-app browser.
3. **The referrer host**.
4. **Nothing** → `direct`.

### Paid vs organic

A referrer host tells you the *platform*, never whether money changed hands. A
click counts as paid when any of these hold:

- `utm_medium` is one of `cpc`, `ppc`, `paid`, `paid_social`, `ads`, `display`, `cpm`
- the URL carries an ad id (`ad_id`, `adset_id`, `campaign_id`, `utm_id`)
- `utm_campaign` is 12+ digits and nothing else — Meta's `{{campaign.id}}` macro
  resolves to exactly that, and nobody names a campaign that way
  (`TrafficSource::isPlatformCampaignId`)

`visits:reclassify` applies the *same* predicate to old rows, so a given ad
click sits under the same channel regardless of when it happened.

### Why "Other website" once meant Facebook

Meta's `{{site_source_name}}` macro resolves to `fb` / `ig` / `an` / `msg`. The
classifier used to match only full platform names, so every tagged ad click fell
through to `referral` — on 30 August 2026 that was 83 of 179 visitors, with
`m.facebook.com` sitting right there in the referrer. Fixed in `dd3d5bd`; the
history was re-filed with `visits:reclassify`.

Rows still in **Other website** with no referrer host cannot be recovered: the
app that sent them stripped the referrer, and `utm_source` was not stored before
that change. They age out.

---

## Tag your links like this

In **Meta Ads Manager → the ad → URL parameters**:

```
utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&ad_id={{ad.id}}
```

Meta substitutes the real values on click. Using `{{campaign.name}}` rather than
`{{campaign.id}}` is what makes the **Ads & campaigns** table readable — an id
like `120250686619310682` is correct but tells you nothing at a glance.

For a boosted post or any hand-made link, append something you will recognise
later:

```
?utm_source=facebook&utm_medium=social&utm_campaign=eid-sale
```

The same UTM tags work on SMS and email links (`utm_medium=sms`), which is the
only way those two ever appear as a channel.

---

## What each panel means

**Conversion funnel** — unique visitors at each step across the window. Someone
who visits on three days is one visitor here.

**Traffic & conversion over time** — the same five steps, one point per day.
Days are grouped once the window exceeds 60 points. Per-day distinct visitors
are *summed* across a bucket, so the chart's visitor total can exceed the
funnel's unique count; that is deliberate, and why the headline figure is
counted separately.

**Where visitors come from** — channel, visitors, orders and revenue. Revenue
comes from `orders.source_channel` (last touch), visitors from `visits.source`.
Open a row for the sites and campaigns underneath it.

**Ads & campaigns** — starts from *visits*, not orders, so an ad that sent 400
people and sold nothing appears rather than being absent. Rows shaded red sent
real traffic (20+ visitors) and made no sale. An order matches an ad on
campaign + `utm_content`; with no ad-level match it falls back to the campaign
total, but only for rows that have no ad of their own, so ads in one campaign
never each claim the same sales.

**Viewed but never bought** — product pageviews in the window with zero units
sold. Interest exists; the photos, price or copy are the blocker.

**On the site right now** — the latest row per visitor in the last 5 minutes,
polled from `admin/dashboard/live` every 12 seconds. Not cached: a stale live
panel is worse than none. Polling pauses while the tab is hidden.

---

## Caching

`DashboardAnalytics::remember()` caches every panel for 5 minutes (1 hour for a
window that has closed). `config/cache.php` sets `serializable_classes = false`,
so **every cached value must be plain arrays and scalars** — a cached
Collection or model returns as `__PHP_Incomplete_Class` and fatals at the point
of use. The contract is enforced on read, and `DashboardCacheTest` pins it.

Bump `CACHE_PREFIX` when a payload's shape changes, so entries written by an
older build become unreachable rather than being served to new code.
