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
    protected const CACHE_PREFIX = 'dash.v2.';

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

            return [
                'visitors' => $visitors,
                'tracking' => $range->constrain(Visit::query())->exists(),
                'conversion' => $visitors > 0 ? round($orders / $visitors * 100, 2) : null,
                'steps' => [
                    ['label' => 'Visitors', 'value' => $visitors, 'pct' => $visitors ? 100.0 : null],
                    ['label' => 'Viewed a product', 'value' => $viewed, 'pct' => $pct($viewed)],
                    ['label' => 'Added to cart', 'value' => $carted, 'pct' => $pct($carted)],
                    ['label' => 'Started checkout', 'value' => $checkout, 'pct' => $pct($checkout)],
                    ['label' => 'Ordered', 'value' => $orders, 'pct' => $pct($orders)],
                ],
            ];
        }, $range->cacheSeconds());
    }

    /** Most bars the visitors chart will draw before it starts grouping days. */
    protected const CHART_BUCKETS = 60;

    /**
     * Visitors over the window, as a small bar chart.
     *
     * Days are grouped once the window is longer than the chart can show —
     * "Maximum" on a store with two years of data would otherwise be 700
     * one-pixel bars. Grouping sums each day's distinct visitors, so a bucket
     * counts someone twice if they returned on two different days within it;
     * that is fine for a shape-of-traffic chart and wrong for a headline
     * figure, which is why the unique total is counted separately.
     */
    public function visitorsByDay(DateRange $range): \Illuminate\Support\Collection
    {
        // Cached as a plain array, hydrated on the way out (see remember()).
        return collect($this->remember('visitors.'.$range->cacheKey(), function () use ($range) {
            $rows = $range->constrain(Visit::query())
                ->selectRaw('DATE(created_at) as d, COUNT(DISTINCT visitor_token) as c')
                ->groupBy('d')->pluck('c', 'd');

            $start = $range->start
                ?? Carbon::parse(Visit::min('created_at') ?: now())->startOfDay();
            $end = $range->end ?? now()->endOfDay();

            $days = max(1, (int) $start->diffInDays($end) + 1);
            $perBucket = (int) max(1, ceil($days / self::CHART_BUCKETS));

            $out = [];
            for ($i = 0; $i < $days; $i += $perBucket) {
                $bucketStart = $start->copy()->addDays($i);
                $bucketEnd = min($days - 1, $i + $perBucket - 1);

                $value = 0;
                for ($d = $i; $d <= $bucketEnd; $d++) {
                    $value += (int) ($rows[$start->copy()->addDays($d)->toDateString()] ?? 0);
                }

                $out[] = [
                    'label' => $perBucket === 1
                        ? $bucketStart->format('d M')
                        : $bucketStart->format('d M').'–'.$start->copy()->addDays($bucketEnd)->format('d M'),
                    'value' => $value,
                ];
            }

            return $out;
        }, $range->cacheSeconds()));
    }

    /**
     * Where visitors come from, by channel, joined to what each channel
     * actually earned — so "Facebook sent 800 people" sits next to "and they
     * spent ৳40,000", which is the number that decides the ad budget.
     *
     * @return \Illuminate\Support\Collection<int, array{channel:string,label:string,visitors:int,orders:int,revenue:float,rate:?float}>
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

            return $visitors->keys()->merge($sales->keys())->unique()
                ->map(function ($channel) use ($visitors, $sales) {
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
                    ];
                })
                ->sortByDesc(fn ($r) => [$r['revenue'], $r['visitors']])
                ->take($limit)->values()->all();
        }, $range->cacheSeconds()));
    }

    /**
     * Campaigns (utm_campaign) that produced orders — tells you which specific
     * ad or post is working, not just which platform.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function topCampaigns(DateRange $range, int $limit = 6): \Illuminate\Support\Collection
    {
        // ->get() yields stdClass rows, which the cache cannot restore — map to
        // plain arrays before storing.
        return collect($this->remember('camp.'.$range->cacheKey(), fn () => $range->constrain($this->sold())
            ->whereNotNull('source_campaign')->where('source_campaign', '!=', '')
            ->selectRaw('source_campaign, source_channel, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('source_campaign', 'source_channel')
            ->orderByDesc('revenue')->take($limit)->get()
            ->map(fn ($r) => [
                'source_campaign' => (string) $r->source_campaign,
                'source_channel' => (string) $r->source_channel,
                'orders' => (int) $r->orders,
                'revenue' => (float) $r->revenue,
            ])->all(), $range->cacheSeconds()));
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
            return $views->map(fn ($v, $id) => [
                'id' => (int) $id,
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

                // Scalars only — Product models cannot survive this cache.
                return [
                    'id' => (int) $p->id,
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
