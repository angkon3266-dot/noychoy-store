<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Visit;
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
        return Order::whereNotIn('status', ['cancelled', 'returned']);
    }

    protected function remember(string $key, \Closure $fn, int $seconds = 300)
    {
        return \Illuminate\Support\Facades\Cache::remember('dash.'.$key, $seconds, $fn);
    }

    // ── 1. Revenue & profit depth ───────────────────────────────────────────

    /**
     * Profit for a window, using the cost snapshot stored on each order item
     * (cost + transport captured at purchase time, so past profit stays true
     * even after supplier prices change).
     *
     * @return array{revenue:float,cost:float,profit:float,margin:?float,items:int,orders:int}
     */
    public function profit(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        $row = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', ['cancelled', 'returned'])
            ->whereBetween('orders.created_at', [$from, $to])
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
    public function periodComparison(int $days = 30): array
    {
        return $this->remember("cmp.$days", function () use ($days) {
            $now = now();
            $curFrom = $now->copy()->subDays($days);
            $prevFrom = $now->copy()->subDays($days * 2);

            $cur = $this->profit($curFrom, $now);
            $prev = $this->profit($prevFrom, $curFrom);

            $pct = fn ($a, $b) => $b > 0 ? round(($a - $b) / $b * 100, 1) : ($a > 0 ? null : 0.0);

            return [
                'current' => $cur,
                'previous' => $prev,
                'revenue_change' => $pct($cur['revenue'], $prev['revenue']),
                'profit_change' => $pct($cur['profit'], $prev['profit']),
                'aov' => $cur['orders'] ? round($cur['revenue'] / $cur['orders']) : 0,
                'items_per_order' => $cur['orders'] ? round($cur['items'] / $cur['orders'], 1) : 0,
                'discount_given' => (float) $this->sold()->where('created_at', '>=', $curFrom)->sum('discount'),
            ];
        });
    }

    // ── 2. Traffic & conversion funnel ──────────────────────────────────────

    /**
     * Visitors → product views → add to cart → checkout → orders, with the
     * drop-off at each step. Visitors are distinct cookie tokens.
     *
     * @return array{steps:array<int,array{label:string,value:int,pct:?float}>,visitors:int,conversion:?float,tracking:bool}
     */
    public function funnel(int $days = 30): array
    {
        return $this->remember("funnel.$days", function () use ($days) {
            $from = now()->subDays($days);

            $distinct = fn (?string $event = null) => Visit::where('created_at', '>=', $from)
                ->when($event, fn ($q) => $q->where('event', $event))
                ->distinct()->count('visitor_token');

            $visitors = $distinct();
            $viewed = $distinct('product');
            $carted = $distinct('cart_add');
            $checkout = $distinct('checkout_start');
            $orders = (int) $this->sold()->where('created_at', '>=', $from)->count();

            $pct = fn ($v) => $visitors > 0 ? round($v / $visitors * 100, 1) : null;

            return [
                'visitors' => $visitors,
                'tracking' => Visit::where('created_at', '>=', $from)->exists(),
                'conversion' => $visitors > 0 ? round($orders / $visitors * 100, 2) : null,
                'steps' => [
                    ['label' => 'Visitors', 'value' => $visitors, 'pct' => $visitors ? 100.0 : null],
                    ['label' => 'Viewed a product', 'value' => $viewed, 'pct' => $pct($viewed)],
                    ['label' => 'Added to cart', 'value' => $carted, 'pct' => $pct($carted)],
                    ['label' => 'Started checkout', 'value' => $checkout, 'pct' => $pct($checkout)],
                    ['label' => 'Ordered', 'value' => $orders, 'pct' => $pct($orders)],
                ],
            ];
        });
    }

    /** Visitors per day for the last N days (mini chart). */
    public function visitorsByDay(int $days = 14): \Illuminate\Support\Collection
    {
        return $this->remember("visitors.$days", function () use ($days) {
            $rows = Visit::where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->selectRaw('DATE(created_at) as d, COUNT(DISTINCT visitor_token) as c')
                ->groupBy('d')->pluck('c', 'd');

            return collect(range($days - 1, 0))->map(fn ($i) => [
                'label' => now()->subDays($i)->format('d M'),
                'value' => (int) ($rows[now()->subDays($i)->toDateString()] ?? 0),
            ]);
        });
    }

    /**
     * Where visitors come from, by channel, joined to what each channel
     * actually earned — so "Facebook sent 800 people" sits next to "and they
     * spent ৳40,000", which is the number that decides the ad budget.
     *
     * @return \Illuminate\Support\Collection<int, array{channel:string,label:string,visitors:int,orders:int,revenue:float,rate:?float}>
     */
    public function trafficSources(int $days = 30, int $limit = 8): \Illuminate\Support\Collection
    {
        return $this->remember("src.$days.$limit", function () use ($days, $limit) {
            $from = now()->subDays($days);

            $visitors = Visit::where('created_at', '>=', $from)
                ->selectRaw("COALESCE(NULLIF(source, ''), 'direct') as channel, COUNT(DISTINCT visitor_token) as c")
                ->groupBy('channel')->pluck('c', 'channel');

            $sales = $this->sold()->where('created_at', '>=', $from)
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
                ->take($limit)->values();
        });
    }

    /**
     * Campaigns (utm_campaign) that produced orders — tells you which specific
     * ad or post is working, not just which platform.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function topCampaigns(int $days = 30, int $limit = 6): \Illuminate\Support\Collection
    {
        return $this->remember("camp.$days", fn () => $this->sold()
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('source_campaign')->where('source_campaign', '!=', '')
            ->selectRaw('source_campaign, source_channel, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('source_campaign', 'source_channel')
            ->orderByDesc('revenue')->take($limit)->get());
    }

    /**
     * Products getting attention but not selling — the highest-leverage fix
     * list (better photos, price, or copy).
     */
    public function viewedNotSold(int $days = 30, int $limit = 6): \Illuminate\Support\Collection
    {
        return $this->remember("vns.$days", function () use ($days, $limit) {
            $from = now()->subDays($days);

            $sold = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', ['cancelled', 'returned'])
                ->where('orders.created_at', '>=', $from)
                ->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id')
                // Alias the aggregate: pluck() can't read a raw expression off
                // the result rows (it throws "Undefined property").
                ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
                ->pluck('qty', 'product_id');

            $views = Visit::where('event', 'product')->where('created_at', '>=', $from)
                ->whereNotNull('product_id')
                ->selectRaw('product_id, COUNT(*) as v')
                ->groupBy('product_id')->orderByDesc('v')->take(40)->pluck('v', 'product_id');

            if ($views->isEmpty()) {
                return collect();
            }

            $products = Product::whereIn('id', $views->keys())->get(['id', 'name', 'slug', 'price'])->keyBy('id');

            return $views->map(fn ($v, $id) => [
                'product' => $products->get($id),
                'views' => (int) $v,
                'sold' => (int) ($sold[$id] ?? 0),
            ])->filter(fn ($r) => $r['product'] && $r['sold'] === 0)
                ->sortByDesc('views')->take($limit)->values();
        });
    }

    // ── 3. Customer & retention ─────────────────────────────────────────────

    /**
     * @return array{new_revenue:float,repeat_revenue:float,repeat_share:?float,clv:float,
     *               repeat_customers:int,one_time:int,avg_days_to_second:?float,at_risk:int}
     */
    public function retention(int $days = 90): array
    {
        return $this->remember("ret.$days", function () use ($days) {
            $from = now()->subDays($days);

            // An order is "repeat" when that customer had an earlier order.
            $orders = $this->sold()->where('created_at', '>=', $from)
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
        });
    }

    // ── 4. Operations & inventory ───────────────────────────────────────────

    /**
     * @return array{cod_success:?float,cancelled:int,returned:int,delivered:int,
     *               pending_aging:array,dead_stock:\Illuminate\Support\Collection,stock_cover:\Illuminate\Support\Collection}
     */
    public function operations(int $days = 30): array
    {
        return $this->remember("ops.$days", function () use ($days) {
            $from = now()->subDays($days);

            $delivered = Order::where('status', 'delivered')->where('created_at', '>=', $from)->count();
            $cancelled = Order::where('status', 'cancelled')->where('created_at', '>=', $from)->count();
            $returned = Order::where('status', 'returned')->where('created_at', '>=', $from)->count();
            $resolved = $delivered + $cancelled + $returned;

            // How long unfulfilled orders have been sitting.
            $open = Order::whereIn('status', ['pending', 'processing'])->get(['created_at']);
            $aging = [
                'today' => $open->filter(fn ($o) => $o->created_at->isToday())->count(),
                '1_3' => $open->filter(fn ($o) => $o->created_at->lt(now()->startOfDay()) && $o->created_at->gte(now()->subDays(3)))->count(),
                'over_3' => $open->filter(fn ($o) => $o->created_at->lt(now()->subDays(3)))->count(),
            ];

            // Units sold per product in the window → days of stock remaining.
            $velocity = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', ['cancelled', 'returned'])
                ->where('orders.created_at', '>=', $from)
                ->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id')
                // Alias the aggregate: pluck() can't read a raw expression off
                // the result rows (it throws "Undefined property").
                ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
                ->pluck('qty', 'product_id');

            $stocked = Product::where('manage_stock', true)->where('stock_quantity', '>', 0)
                ->get(['id', 'name', 'slug', 'stock_quantity']);

            $cover = $stocked->map(function ($p) use ($velocity, $days) {
                $sold = (float) ($velocity[$p->id] ?? 0);
                $perDay = $sold / max(1, $days);

                return [
                    'product' => $p,
                    'per_day' => round($perDay, 2),
                    'days_left' => $perDay > 0 ? (int) floor($p->stock_quantity / $perDay) : null,
                ];
            })->filter(fn ($r) => $r['days_left'] !== null)->sortBy('days_left')->take(6)->values();

            // Stock that hasn't sold at all in the window (cash sitting still).
            $deadStock = $stocked->filter(fn ($p) => ! isset($velocity[$p->id]))
                ->sortByDesc('stock_quantity')->take(6)->values();

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
