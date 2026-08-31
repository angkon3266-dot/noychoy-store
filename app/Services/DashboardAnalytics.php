<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Visit;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deeper dashboard analytics: profit, traffic funnel, retention and operations.
 *
 * Every figure is computed from the store's own data (no external analytics).
 * Results are cached briefly so opening the dashboard stays cheap on shared
 * hosting even as the orders table grows.
 */
class DashboardAnalytics
{
    /** Orders that count as real sales. */
    protected function sold()
    {
        // partially_delivered stays IN: the courier collected money on those
        // parcels, and excluding them would understate real revenue.
        return Order::whereNotIn('status', ['cancelled', 'returned']);
    }

    /**
     * Cache key prefix. Bumping the version makes every entry written by an
     * older build unreachable, so a bad payload can't outlive the fix.
     */
    protected const CACHE_PREFIX = 'dash.v3.';

    /**
     * Cache a computed figure.
     *
     * config/cache.php sets serializable_classes = false, so the cache will not
     * restore ANY object: a cached Collection, stdClass or Eloquent model comes
     * back as __PHP_Incomplete_Class and blows up at the point of use. Every
     * closure here must therefore return plain arrays and scalars.
     *
     * That contract is enforced on read rather than trusted: if a stored value
     * contains an object (an entry from before this fix, or a future mistake),
     * it is discarded and recomputed instead of being handed to the caller.
     */
    protected function remember(string $key, \Closure $fn, int $seconds = 300)
    {
        $cacheKey = self::CACHE_PREFIX.$key;
        $miss = new \stdClass;

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey, $miss);

        if ($cached !== $miss && self::isPlainData($cached)) {
            return $cached;
        }

        $fresh = $fn();

        if (! self::isPlainData($fresh)) {
            // A closure returned objects: serve it, but don't poison the cache.
            report(new \RuntimeException(
                "DashboardAnalytics::remember('{$key}') produced objects; not cached. "
                .'Return plain arrays — cache.serializable_classes is false.'
            ));

            return $fresh;
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, $fresh, $seconds);

        return $fresh;
    }

    /** True when $value is built only from scalars, null and arrays. */
    protected static function isPlainData(mixed $value, int $depth = 0): bool
    {
        if ($depth > 6) {
            return false;               // too deep to vouch for — treat as unsafe
        }
        if (is_object($value)) {
            return false;               // includes __PHP_Incomplete_Class
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isPlainData($item, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    // ── 1. Revenue & profit depth ───────────────────────────────────────────

    /**
     * Profit for a window, using the cost snapshot stored on each order item
     * (cost + transport captured at purchase time, so past profit stays true
     * even after supplier prices change).
     *
     * @return array{revenue:float,cost:float,profit:float,margin:?float,items:int,orders:int}
     */
    public function profit(DateRange $range): array
    {
        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', ['cancelled', 'returned']);

        $row = $range->constrain($query, 'orders.created_at')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as revenue')
            ->selectRaw('COALESCE(SUM((COALESCE(order_items.cost_price,0) + COALESCE(order_items.transport_cost,0)) * order_items.quantity), 0) as cost')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as items')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders')
            ->first();

        $revenue = (float) ($row->revenue ?? 0);
        $cost = (float) ($row->cost ?? 0);
        $profit = $revenue - $cost;

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            'items' => (int) ($row->items ?? 0),
            'orders' => (int) ($row->orders ?? 0),
        ];
    }

    /**
     * This period vs the one immediately before it (same length).
     *
     * @return array{current:array,previous:array,revenue_change:?float,profit_change:?float,aov:float,items_per_order:float}
     */
    public function periodComparison(DateRange $range): array
    {
        return $this->remember('cmp.'.$range->cacheKey(), function () use ($range) {
            $previous = $range->previous();

            $cur = $this->profit($range);
            $prev = $previous ? $this->profit($previous) : null;

            $pct = fn ($a, $b) => $b > 0 ? round(($a - $b) / $b * 100, 1) : ($a > 0 ? null : 0.0);

            return [
                'current' => $cur,
                'previous' => $prev,
                // Null rather than 0 when there is nothing to compare against —
                // "Maximum" has no earlier period, and 0% would read as "flat"
                // when the honest answer is "not applicable".
                'revenue_change' => $prev ? $pct($cur['revenue'], $prev['revenue']) : null,
                'profit_change' => $prev ? $pct($cur['profit'], $prev['profit']) : null,
                'aov' => $cur['orders'] ? round($cur['revenue'] / $cur['orders']) : 0,
                'items_per_order' => $cur['orders'] ? round($cur['items'] / $cur['orders'], 1) : 0,
                'discount_given' => (float) $range->constrain($this->sold())->sum('discount'),
            ];
        }, $range->cacheSeconds());
    }

    // ── 2. Traffic & conversion funnel ──────────────────────────────────────

    /**
     * Visitors → product views → add to cart → checkout → orders, with the
     * drop-off at each step. Visitors are distinct cookie tokens.
     *
     * @return array{steps:array<int,array{label:string,value:int,pct:?float}>,visitors:int,conversion:?float,tracking:bool}
     */
    public function funnel(DateRange $range): array
    {
        return $this->remember('funnel.'.$range->cacheKey(), function () use ($range) {
            $distinct = fn (?string $event = null) => $range->constrain(Visit::query())
                ->when($event, fn ($q) => $q->where('event', $event))
                ->distinct()->count('visitor_token');

            $visitors = $distinct();
            $viewed = $distinct('product');
            $carted = $distinct('cart_add');
            $checkout = $distinct('checkout_start');
            $orders = (int) $range->constrain($this->sold())->count();

            $pct = fn ($v) => $visitors > 0 ? round($v / $visitors * 100, 1) : null;

            // What each step was worth. Note the deliberate mismatch with the
            // counts above: a count is *people* (distinct visitors), a value is
            // *money* (summed over every event). One shopper who added three
            // pieces is one person carrying three items' worth.
            $money = $this->funnelValue($range);
            $revenue = round((float) $range->constrain($this->sold())->sum('total'), 2);

            return [
                'visitors' => $visitors,
                'tracking' => $range->constrain(Visit::query())->exists(),
                'conversion' => $visitors > 0 ? round($orders / $visitors * 100, 2) : null,
                'revenue' => $revenue,
                // How much reached checkout and did not become an order. This is
                // the recoverable number — what the abandoned-cart chase is for.
                'abandoned' => $money['checkout_start'] === null
                    ? null
                    : round(max(0, $money['checkout_start'] - $revenue), 2),
                // Events whose money was never recorded, so the panel can say
                // "of the N we measured" instead of under-reporting silently.
                'unmeasured' => $money['cart_add_missing'] + $money['checkout_start_missing'],
                'steps' => [
                    ['label' => 'Visitors', 'count' => $visitors, 'pct' => $visitors ? 100.0 : null, 'money' => null],
                    ['label' => 'Viewed a product', 'count' => $viewed, 'pct' => $pct($viewed), 'money' => null],
                    ['label' => 'Added to cart', 'count' => $carted, 'pct' => $pct($carted), 'money' => $money['cart_add'], 'unmeasured' => $money['cart_add_missing']],
                    ['label' => 'Started checkout', 'count' => $checkout, 'pct' => $pct($checkout), 'money' => $money['checkout_start'], 'unmeasured' => $money['checkout_start_missing']],
                    ['label' => 'Ordered', 'count' => $orders, 'pct' => $pct($orders), 'money' => $revenue],
                ],
            ];
        }, $range->cacheSeconds());
    }

    /**
     * What the cart and checkout steps were worth in this window.
     *
     * Events recorded before the `value` column existed carry null, and null is
     * not zero — summing them as zero would report a real ৳4,500 checkout as
     * nothing. So each step also reports how many of its events went
     * unmeasured, and the panel says so rather than quietly under-counting. The
     * caveat disappears on its own as the old rows age out of the window.
     *
     * @return array{cart_add:?float, checkout_start:?float, cart_add_missing:int, checkout_start_missing:int}
     */
    protected function funnelValue(DateRange $range): array
    {
        $blank = [
            'cart_add' => null, 'checkout_start' => null,
            'cart_add_missing' => 0, 'checkout_start_missing' => 0,
        ];

        if (! $this->visitsHaveValueColumn()) {
            return $blank;
        }

        $rows = $range->constrain(Visit::whereIn('event', ['cart_add', 'checkout_start']))
            ->selectRaw('event, SUM(value) as total, SUM(value IS NULL) as unmeasured, COUNT(*) as events')
            ->groupBy('event')->get()->keyBy('event');

        $out = $blank;

        foreach (['cart_add', 'checkout_start'] as $event) {
            $row = $rows[$event] ?? null;

            if (! $row) {
                continue;
            }

            $measured = (int) $row->events - (int) $row->unmeasured;

            $out[$event] = $measured > 0 ? round((float) $row->total, 2) : null;
            $out[$event.'_missing'] = (int) $row->unmeasured;
        }

        return $out;
    }

    /** Whether the funnel-value column exists yet (see the visits migration). */
    protected function visitsHaveValueColumn(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('visits', 'value');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Most bars the visitors chart will draw before it starts grouping days. */
    protected const CHART_BUCKETS = 60;

    /**
     * The whole funnel over time: visitors, product views, add-to-carts,
     * checkouts started and orders, one point per day.
     *
     * A visitor count on its own only says whether traffic moved. Plotted
     * against the steps beneath it, the same chart says *where* it stopped —
     * a spike in visitors with a flat product-view line is a bad audience, a
     * flat line at add-to-cart is a price or a photo problem.
     *
     * Days are grouped once the window is longer than the chart can draw —
     * "Maximum" on a store with two years of data would otherwise be 700
     * one-pixel points. Grouping sums each day's distinct visitors, so a bucket
     * counts someone twice if they returned on two different days within it;
     * that is fine for a shape-of-traffic chart and wrong for a headline
     * figure, which is why the unique total is counted separately.
     *
     * @return \Illuminate\Support\Collection<int, array{label:string, visitors:int, viewed:int, carted:int, checkout:int, orders:int}>
     */
    public function funnelByDay(DateRange $range): \Illuminate\Support\Collection
    {
        // Cached as a plain array, hydrated on the way out (see remember()).
        return collect($this->remember('series.'.$range->cacheKey(), function () use ($range) {
            $perDay = fn (?string $event) => $range->constrain(Visit::query())
                ->when($event, fn ($q) => $q->where('event', $event))
                ->selectRaw('DATE(created_at) as d, COUNT(DISTINCT visitor_token) as c')
                ->groupBy('d')->pluck('c', 'd');

            $series = [
                'visitors' => $perDay(null),
                'viewed' => $perDay('product'),
                'carted' => $perDay('cart_add'),
                'checkout' => $perDay('checkout_start'),
                'orders' => $range->constrain($this->sold())
                    ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
                    ->groupBy('d')->pluck('c', 'd'),
            ];

            // An unbounded window has to find its own start. Orders can predate
            // the visits table (tracking was added later), so take the earlier
            // of the two or the chart would begin after the store's first sale.
            $earliest = collect([Visit::min('created_at'), Order::min('created_at')])
                ->filter()->map(fn ($t) => Carbon::parse($t))->min();

            $start = $range->start ?? ($earliest ?: now())->copy()->startOfDay();
            $end = $range->end ?? now()->endOfDay();

            return $this->bucketDays($start, $end, $series);
        }, $range->cacheSeconds()));
    }

    /**
     * Roll a set of date-keyed counts up into at most CHART_BUCKETS points.
     *
     * @param  array<string, \Illuminate\Support\Collection<string,int>>  $series
     * @return array<int, array<string, string|int>>
     */
    protected function bucketDays(Carbon $start, Carbon $end, array $series): array
    {
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $perBucket = (int) max(1, ceil($days / self::CHART_BUCKETS));

        $out = [];
        for ($i = 0; $i < $days; $i += $perBucket) {
            $bucketStart = $start->copy()->addDays($i);
            $bucketEnd = min($days - 1, $i + $perBucket - 1);

            $row = [
                'label' => $perBucket === 1
                    ? $bucketStart->format('d M')
                    : $bucketStart->format('d M').'–'.$start->copy()->addDays($bucketEnd)->format('d M'),
            ];

            foreach ($series as $name => $counts) {
                $value = 0;
                for ($d = $i; $d <= $bucketEnd; $d++) {
                    $value += (int) ($counts[$start->copy()->addDays($d)->toDateString()] ?? 0);
                }
                $row[$name] = $value;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Where visitors come from, by channel, joined to what each channel
     * actually earned — so "Facebook sent 800 people" sits next to "and they
     * spent ৳40,000", which is the number that decides the ad budget.
     *
     * Each channel carries the sites and campaigns underneath it. Without that,
     * a row reading "Other website — 83 visitors" is a dead end: it is the one
     * channel whose whole meaning is "we could not name this", and naming it is
     * exactly what the store needs in order to act on it.
     *
     * @return \Illuminate\Support\Collection<int, array{channel:string,label:string,visitors:int,orders:int,revenue:float,rate:?float,sites:array,campaigns:array}>
     */
    public function trafficSources(DateRange $range, int $limit = 8): \Illuminate\Support\Collection
    {
        return collect($this->remember('src.'.$range->cacheKey().'.'.$limit, function () use ($range, $limit) {
            $visitors = $range->constrain(Visit::query())
                ->selectRaw("COALESCE(NULLIF(source, ''), 'direct') as channel, COUNT(DISTINCT visitor_token) as c")
                ->groupBy('channel')->pluck('c', 'channel');

            $sales = $range->constrain($this->sold())
                ->selectRaw("COALESCE(NULLIF(source_channel, ''), 'direct') as channel, COUNT(*) as orders, SUM(total) as revenue")
                ->groupBy('channel')->get()->keyBy('channel');

            // The referring sites behind each channel, biggest first.
            $sites = $range->constrain(Visit::query())
                ->whereNotNull('referrer_host')->where('referrer_host', '!=', '')
                ->selectRaw("COALESCE(NULLIF(source, ''), 'direct') as channel, referrer_host, COUNT(DISTINCT visitor_token) as c")
                ->groupBy('channel', 'referrer_host')->orderByDesc('c')->get()
                ->groupBy('channel');

            // …and the campaigns, which is what names an untagged-looking
            // channel when the referrer was stripped (most mobile ad clicks).
            $campaigns = $range->constrain(Visit::query())
                ->whereNotNull('campaign')->where('campaign', '!=', '')
                ->selectRaw("COALESCE(NULLIF(source, ''), 'direct') as channel, campaign, COUNT(DISTINCT visitor_token) as c")
                ->groupBy('channel', 'campaign')->orderByDesc('c')->get()
                ->groupBy('channel');

            $top = fn ($grouped, string $channel, string $field) => collect($grouped[$channel] ?? [])
                ->take(4)
                ->map(fn ($r) => ['name' => (string) $r->{$field}, 'visitors' => (int) $r->c])
                ->values()->all();

            return $visitors->keys()->merge($sales->keys())->unique()
                ->map(function ($channel) use ($visitors, $sales, $sites, $campaigns, $top) {
                    $v = (int) ($visitors[$channel] ?? 0);
                    $row = $sales[$channel] ?? null;
                    $orders = (int) ($row->orders ?? 0);

                    return [
                        'channel' => $channel,
                        'label' => \App\Support\TrafficSource::label($channel),
                        'visitors' => $v,
                        'orders' => $orders,
                        'revenue' => round((float) ($row->revenue ?? 0), 2),
                        // Conversion is only meaningful when we saw the visits.
                        'rate' => $v > 0 ? round($orders / $v * 100, 1) : null,
                        'sites' => $top($sites, $channel, 'referrer_host'),
                        'campaigns' => $top($campaigns, $channel, 'campaign'),
                    ];
                })
                ->sortByDesc(fn ($r) => [$r['revenue'], $r['visitors']])
                ->take($limit)->values()->all();
        }, $range->cacheSeconds()));
    }

    /**
     * Which ad sent the traffic, and what it earned.
     *
     * `topCampaigns()` above starts from orders, so an ad that spent all week
     * sending people who bought nothing never appears — precisely the ad you
     * most want to see. This starts from visits instead and joins the orders
     * on, so a campaign with 400 visitors and zero sales shows up as the
     * expensive mistake it is.
     *
     * @return \Illuminate\Support\Collection<int, array{campaign:string,ad:?string,channel:string,visitors:int,orders:int,revenue:float,rate:?float}>
     */
    public function adPerformance(DateRange $range, int $limit = 12): \Illuminate\Support\Collection
    {
        return collect($this->remember('ads.'.$range->cacheKey().'.'.$limit, function () use ($range, $limit) {
            $hasContent = $this->visitsHaveAdColumns();

            $visits = $range->constrain(Visit::query())
                ->whereNotNull('campaign')->where('campaign', '!=', '')
                ->selectRaw(
                    'campaign, '
                    .($hasContent ? 'content' : 'NULL as content').', '
                    ."COALESCE(NULLIF(source, ''), 'direct') as channel, "
                    .'COUNT(DISTINCT visitor_token) as visitors'
                )
                // Only group by a column that exists — on a server that hasn't
                // run the migration, naming it here is a SQL error, not a null.
                ->groupBy(...array_filter(['campaign', $hasContent ? 'content' : null, 'channel']))
                ->orderByDesc('visitors')->take(60)->get();

            $orderContent = $this->ordersHaveAdColumn();

            $sales = $range->constrain($this->sold())
                ->whereNotNull('source_campaign')->where('source_campaign', '!=', '')
                ->selectRaw(
                    'source_campaign, '
                    .($orderContent ? 'source_content' : 'NULL as source_content').', '
                    .'source_channel, COUNT(*) as orders, SUM(total) as revenue'
                )
                ->groupBy(...array_filter(['source_campaign', $orderContent ? 'source_content' : null, 'source_channel']))
                ->get();

            if ($visits->isEmpty() && $sales->isEmpty()) {
                return [];
            }

            // Keyed on campaign+ad so an order lands on the exact creative when
            // both sides carry one, and on the campaign when they don't.
            $key = fn ($campaign, $content) => $campaign.'|'.($content ?: '');
            $byKey = $sales->groupBy(fn ($r) => $key($r->source_campaign, $r->source_content));
            $byCampaign = $sales->groupBy('source_campaign');

            $rows = $visits->map(function ($v) use ($byKey, $byCampaign, $key) {
                $exact = collect($byKey[$key($v->campaign, $v->content)] ?? []);
                $orders = (int) $exact->sum('orders');
                $revenue = (float) $exact->sum('revenue');

                // No ad-level match: fall back to the campaign total, but only
                // for a row that has no ad of its own — otherwise every ad in
                // the campaign would claim the same sales.
                if ($exact->isEmpty() && blank($v->content)) {
                    $all = collect($byCampaign[$v->campaign] ?? []);
                    $orders = (int) $all->sum('orders');
                    $revenue = (float) $all->sum('revenue');
                }

                $visitors = (int) $v->visitors;

                return [
                    'campaign' => (string) $v->campaign,
                    'ad' => filled($v->content) ? (string) $v->content : null,
                    'channel' => (string) $v->channel,
                    'visitors' => $visitors,
                    'orders' => $orders,
                    'revenue' => round($revenue, 2),
                    'rate' => $visitors > 0 ? round($orders / $visitors * 100, 1) : null,
                ];
            });

            // Campaigns that only appear on orders. Attribution predates these
            // ad columns, and an order can be stamped with a campaign whose
            // visits have since been pruned — dropping those would quietly
            // under-report revenue.
            $seen = $rows->pluck('campaign')->unique()->flip();

            $orphans = $sales->reject(fn ($s) => $seen->has($s->source_campaign))
                ->groupBy('source_campaign')
                ->map(fn ($group, $campaign) => [
                    'campaign' => (string) $campaign,
                    'ad' => null,
                    'channel' => (string) ($group->first()->source_channel ?: 'direct'),
                    'visitors' => 0,
                    'orders' => (int) $group->sum('orders'),
                    'revenue' => round((float) $group->sum('revenue'), 2),
                    'rate' => null,
                ])->values();

            return $rows->concat($orphans)
                ->sortByDesc(fn ($r) => [$r['revenue'], $r['visitors']])
                ->take($limit)->values()->all();
        }, $range->cacheSeconds()));
    }

    /** Whether the ad-detail columns exist yet (see the visits migration). */
    protected function visitsHaveAdColumns(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('visits', 'content');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function ordersHaveAdColumn(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('orders', 'source_content');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Who is on the storefront right now, and what they are looking at.
     *
     * Deliberately not cached: a five-minute-stale "live" panel is worse than
     * no live panel. The query is a single indexed range scan over a table that
     * only holds pageviews, so it stays cheap even when polled.
     *
     * @return array{count:int, window:int, rows:array<int, array<string,mixed>>}
     */
    public function liveVisitors(int $minutes = 5, int $limit = 25): array
    {
        $since = now()->subMinutes($minutes);

        // The latest row per visitor, via their highest id in the window —
        // MAX(id) rather than MAX(created_at) so two hits in the same second
        // still resolve to one, definite row.
        $latestIds = Visit::query()->where('created_at', '>=', $since)
            ->selectRaw('MAX(id) as id')->groupBy('visitor_token')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return ['count' => 0, 'window' => $minutes, 'rows' => []];
        }

        $rows = Visit::whereIn('id', $latestIds)->latest('id')->take($limit)->get();

        // When each of them first showed up in the window, for "on site 4 min".
        $firstSeen = Visit::whereIn('visitor_token', $rows->pluck('visitor_token'))
            ->where('created_at', '>=', $since)
            ->selectRaw('visitor_token, MIN(created_at) as t')
            ->groupBy('visitor_token')->pluck('t', 'visitor_token');

        $products = Product::whereIn('id', $rows->pluck('product_id')->filter()->unique())
            ->get(['id', 'name', 'slug'])->keyBy('id');

        return [
            'count' => $latestIds->count(),
            'window' => $minutes,
            'rows' => $rows->map(function ($v) use ($products, $firstSeen) {
                $product = $v->product_id ? $products->get($v->product_id) : null;
                $firstAt = $firstSeen[$v->visitor_token] ?? null;

                return [
                    'product' => $product?->name,
                    'product_slug' => $product?->slug,
                    'path' => $v->path === '' || $v->path === null ? '/' : '/'.ltrim($v->path, '/'),
                    'where' => $product?->name ?? self::describePath($v->path),
                    'channel' => $v->source ?: 'direct',
                    'channel_label' => \App\Support\TrafficSource::label($v->source),
                    'campaign' => $v->campaign,
                    'seconds_ago' => max(0, (int) $v->created_at->diffInSeconds(now())),
                    'minutes_on_site' => $firstAt
                        ? max(0, (int) Carbon::parse($firstAt)->diffInMinutes(now()))
                        : 0,
                ];
            })->values()->all(),
        ];
    }

    /** A storefront path as something a shopkeeper would say out loud. */
    protected static function describePath(?string $path): string
    {
        $path = trim((string) $path, '/');

        return match (true) {
            $path === '' => 'Home page',
            $path === 'cart' => 'Cart',
            str_starts_with($path, 'checkout') => 'Checkout',
            str_starts_with($path, 'category/') => 'Category · '.str_replace('-', ' ', substr($path, 9)),
            str_starts_with($path, 'collection/') => 'Collection · '.str_replace('-', ' ', substr($path, 11)),
            str_starts_with($path, 'search') => 'Search',
            str_starts_with($path, 'account') => 'Their account',
            default => '/'.$path,
        };
    }

    /**
     * Products getting attention but not selling — the highest-leverage fix
     * list (better photos, price, or copy).
     */
    public function viewedNotSold(DateRange $range, int $limit = 6): \Illuminate\Support\Collection
    {
        return collect($this->remember('vns.'.$range->cacheKey(), function () use ($range, $limit) {
            $sold = $range->constrain(
                OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereNull('orders.deleted_at')
                    ->whereNotIn('orders.status', ['cancelled', 'returned']),
                'orders.created_at',
            )
                ->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id')
                // Alias the aggregate: pluck() can't read a raw expression off
                // the result rows (it throws "Undefined property").
                ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
                ->pluck('qty', 'product_id');

            $views = $range->constrain(Visit::where('event', 'product'))
                ->whereNotNull('product_id')
                ->selectRaw('product_id, COUNT(*) as v')
                ->groupBy('product_id')->orderByDesc('v')->take(40)->pluck('v', 'product_id');

            if ($views->isEmpty()) {
                return [];
            }

            $products = Product::whereIn('id', $views->keys())->get(['id', 'name', 'slug', 'price'])->keyBy('id');

            // Scalars only: an Eloquent model cannot survive this cache.
            // The slug travels with the id because Product::getRouteKeyName()
            // is 'slug' — an admin link built from the id 404s.
            return $views->map(fn ($v, $id) => [
                'id' => (int) $id,
                'slug' => (string) ($products->get($id)->slug ?? ''),
                'name' => (string) ($products->get($id)->name ?? ''),
                'views' => (int) $v,
                'sold' => (int) ($sold[$id] ?? 0),
            ])->filter(fn ($r) => $r['name'] !== '' && $r['sold'] === 0)
                ->sortByDesc('views')->take($limit)->values()->all();
        }, $range->cacheSeconds()));
    }

    // ── 3. Customer & retention ─────────────────────────────────────────────

    /**
     * @return array{new_revenue:float,repeat_revenue:float,repeat_share:?float,clv:float,
     *               repeat_customers:int,one_time:int,avg_days_to_second:?float,at_risk:int}
     */
    public function retention(DateRange $range): array
    {
        return $this->remember('ret.'.$range->cacheKey(), function () use ($range) {
            // Only the new-vs-repeat revenue split is windowed. Lifetime value,
            // repeat counts and the at-risk list are properties of the customer
            // base rather than of a period, so they stay global whatever the
            // dashboard filter says.
            $orders = $range->constrain($this->sold())
                ->whereNotNull('customer_id')
                ->get(['customer_id', 'total', 'created_at']);

            $firstOrderAt = Order::whereIn('customer_id', $orders->pluck('customer_id')->unique())
                ->whereNotIn('status', ['cancelled', 'returned'])
                ->selectRaw('customer_id, MIN(created_at) as first_at')
                ->groupBy('customer_id')->pluck('first_at', 'customer_id');

            $newRevenue = 0.0;
            $repeatRevenue = 0.0;
            foreach ($orders as $o) {
                $first = $firstOrderAt[$o->customer_id] ?? null;
                $isFirst = $first && Carbon::parse($first)->equalTo($o->created_at);
                $isFirst ? $newRevenue += (float) $o->total : $repeatRevenue += (float) $o->total;
            }
            $total = $newRevenue + $repeatRevenue;

            $buyers = Customer::where('total_orders', '>', 0);
            $repeatCustomers = (clone $buyers)->where('total_orders', '>', 1)->count();
            $oneTime = (clone $buyers)->where('total_orders', 1)->count();
            $clv = (float) (clone $buyers)->avg('total_spent');

            // Average gap between a customer's 1st and 2nd order (repeat speed).
            $seconds = Order::whereNotIn('status', ['cancelled', 'returned'])
                ->whereNotNull('customer_id')
                ->selectRaw('customer_id, MIN(created_at) as a, MAX(created_at) as b, COUNT(*) as c')
                ->groupBy('customer_id')->having('c', '>', 1)->get();
            $avgDays = $seconds->isEmpty() ? null : round($seconds->avg(
                fn ($r) => Carbon::parse($r->a)->diffInDays(Carbon::parse($r->b))
            ), 1);

            // Bought before, but quiet far longer than the typical repeat gap.
            $atRisk = Customer::where('total_orders', '>', 0)
                ->where('last_order_at', '<', now()->subDays(60))->count();

            return [
                'new_revenue' => $newRevenue,
                'repeat_revenue' => $repeatRevenue,
                'repeat_share' => $total > 0 ? round($repeatRevenue / $total * 100, 1) : null,
                'clv' => round($clv, 0),
                'repeat_customers' => $repeatCustomers,
                'one_time' => $oneTime,
                'avg_days_to_second' => $avgDays,
                'at_risk' => $atRisk,
            ];
        }, $range->cacheSeconds());
    }

    // ── 4. Operations & inventory ───────────────────────────────────────────

    /**
     * @return array{cod_success:?float,cancelled:int,returned:int,delivered:int,
     *               pending_aging:array,dead_stock:\Illuminate\Support\Collection,stock_cover:\Illuminate\Support\Collection}
     */
    public function operations(DateRange $range): array
    {
        return $this->remember('ops.'.$range->cacheKey(), function () use ($range) {
            $counted = fn (string $status) => $range->constrain(Order::where('status', $status))->count();

            $delivered = $counted('delivered');
            $cancelled = $counted('cancelled');
            $returned = $counted('returned');
            $resolved = $delivered + $cancelled + $returned;

            // How long unfulfilled orders have been sitting.
            $open = Order::whereIn('status', ['pending', 'processing'])->get(['created_at']);
            $aging = [
                'today' => $open->filter(fn ($o) => $o->created_at->isToday())->count(),
                '1_3' => $open->filter(fn ($o) => $o->created_at->lt(now()->startOfDay()) && $o->created_at->gte(now()->subDays(3)))->count(),
                'over_3' => $open->filter(fn ($o) => $o->created_at->lt(now()->subDays(3)))->count(),
            ];

            // Units sold per product in the window → days of stock remaining.
            $velocity = $range->constrain(
                OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereNull('orders.deleted_at')
                    ->whereNotIn('orders.status', ['cancelled', 'returned']),
                'orders.created_at',
            )
                ->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id')
                // Alias the aggregate: pluck() can't read a raw expression off
                // the result rows (it throws "Undefined property").
                ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
                ->pluck('qty', 'product_id');

            $stocked = Product::where('manage_stock', true)->where('stock_quantity', '>', 0)
                ->get(['id', 'name', 'slug', 'stock_quantity']);

            // "Days left" needs a per-day rate, so an unbounded window has to
            // measure itself: fall back to the age of the oldest order rather
            // than dividing by a made-up number.
            $spanDays = $range->days() ?? max(1, (int) Carbon::parse(
                Order::min('created_at') ?: now()
            )->diffInDays(now()) + 1);

            $cover = $stocked->map(function ($p) use ($velocity, $spanDays) {
                $sold = (float) ($velocity[$p->id] ?? 0);
                $perDay = $sold / max(1, $spanDays);

                // Scalars only — Product models cannot survive this cache. The
                // slug comes along because admin product links are keyed on it.
                return [
                    'id' => (int) $p->id,
                    'slug' => (string) $p->slug,
                    'name' => (string) $p->name,
                    'stock_quantity' => (int) $p->stock_quantity,
                    'per_day' => round($perDay, 2),
                    'days_left' => $perDay > 0 ? (int) floor($p->stock_quantity / $perDay) : null,
                ];
            })->filter(fn ($r) => $r['days_left'] !== null)->sortBy('days_left')->take(6)->values()->all();

            // Stock that hasn't sold at all in the window (cash sitting still).
            $deadStock = $stocked->filter(fn ($p) => ! isset($velocity[$p->id]))
                ->sortByDesc('stock_quantity')->take(6)
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'slug' => (string) $p->slug,
                    'name' => (string) $p->name,
                    'stock_quantity' => (int) $p->stock_quantity,
                ])->values()->all();

            return [
                'cod_success' => $resolved ? round($delivered / $resolved * 100, 1) : null,
                'delivered' => $delivered,
                'cancelled' => $cancelled,
                'returned' => $returned,
                'pending_aging' => $aging,
                'stock_cover' => $cover,
                'dead_stock' => $deadStock,
            ];
        });
    }
}
