<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\DashboardAnalytics;
use App\Services\LoyaltyService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Optional deep-analysis panels, in display order. */
    public const PANELS = [
        'profit' => 'Revenue & profit',
        'funnel' => 'Traffic & conversion funnel',
        'retention' => 'Customers & retention',
        'operations' => 'Operations & inventory',
    ];

    /** Save which panels this admin wants to see. */
    public function savePanels(Request $request)
    {
        $chosen = array_values(array_intersect(
            array_keys(self::PANELS),
            (array) $request->input('panels', [])
        ));
        Setting::put('dashboard_panels', $chosen);

        return back()->with('success', 'Dashboard layout saved.');
    }

    public function index(DashboardAnalytics $analytics, Request $request)
    {
        $today = now()->startOfDay();

        // The window every time-based figure below reports on. Live queue
        // counts (pending/processing/shipped), stock and the customer base are
        // deliberately NOT filtered: they describe the state of the shop right
        // now, and "0 pending" because you picked "Today" would be a lie.
        $range = DateRange::fromRequest($request);

        // Orders that count as "real sales" (exclude cancelled / returned).
        $sold = fn () => $range->constrain(Order::whereNotIn('status', ['cancelled', 'returned']));

        $deliveredPeriod = $sold()->where('status', 'delivered')->sum('total');
        $salesPeriod = $sold()->sum('total');
        $periodOrders = $sold()->get(['total']);
        $aov = $periodOrders->count() ? round($periodOrders->avg('total'), 0) : 0;

        // COD delivery success across resolved shipments.
        $resolved = Order::whereIn('status', ['delivered', 'cancelled', 'returned'])->count();
        $deliveredAll = Order::where('status', 'delivered')->count();
        $codSuccess = $resolved ? round($deliveredAll / $resolved * 100) : null;

        $totalCustomers = Customer::count();
        $repeatCustomers = Customer::where('total_orders', '>', 1)->count();

        $stats = [
            // Orders/sales for the chosen window. Still called *_period rather
            // than *_month now that the window is the admin's to pick.
            'orders_period' => $sold()->count(),
            'sales_period' => $salesPeriod,
            'revenue_period' => $deliveredPeriod,
            // Today's figures stay pinned to today whatever the filter says —
            // they are the "how is it going right now" pair.
            'orders_today' => Order::whereDate('created_at', $today)->count(),
            'sales_today' => Order::whereNotIn('status', ['cancelled', 'returned'])
                ->whereDate('created_at', $today)->sum('total'),
            'pending' => Order::where('status', 'pending')->count(),
            'processing' => Order::where('status', 'processing')->count(),
            // "Gone to the courier" — booking used to write `shipped` directly,
            // so both statuses belong in this figure for it to keep its meaning.
            'shipped' => Order::whereIn('status', ['booked', 'shipped'])->count(),
            'aov' => $aov,
            'cod_success' => $codSuccess,
            'products' => Product::count(),
            'customers' => $totalCustomers,
            'repeat_rate' => $totalCustomers ? round($repeatCustomers / $totalCustomers * 100) : 0,
            'new_customers_period' => $range->constrain(Customer::query())->count(),
            'low_stock' => Product::where('manage_stock', true)->where('stock_quantity', '<=', 3)->count(),
            // Inventory on hand: units + what that stock cost (landed = cost + transport).
            'stock_units' => (int) Product::where('manage_stock', true)->where('stock_quantity', '>', 0)->sum('stock_quantity'),
            'stock_cost_value' => (float) Product::where('manage_stock', true)->where('stock_quantity', '>', 0)
                ->selectRaw('COALESCE(SUM(stock_quantity * (COALESCE(cost_price, 0) + COALESCE(transport_cost, 0))), 0) as v')
                ->value('v'),
        ];

        // Revenue over the window, as a mini bar chart. Days are grouped once
        // the window is longer than the chart can usefully draw — see
        // DashboardAnalytics::CHART_BUCKETS for the same treatment of visitors.
        $daily = $this->revenueSeries($range);
        $dailyMax = max(1, $daily->max('total'));

        // Top products in the window, by units sold on non-cancelled orders.
        $inRange = fn ($q) => $range->constrain($q->whereNotIn('status', ['cancelled', 'returned']));

        $topProducts = OrderItem::query()
            ->whereHas('order', $inRange)
            ->select('name', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(subtotal) as revenue'))
            ->groupBy('name')->orderByDesc('qty')->take(5)->get();

        // Best-selling categories in the window, by units sold.
        $topCategories = OrderItem::query()
            ->whereHas('order', $inRange)
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select('categories.name', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.subtotal) as revenue'))
            ->groupBy('categories.name')->orderByDesc('qty')->take(5)->get();
        $catMax = max(1, (float) $topCategories->max('qty'));

        // Most valuable customers (lifetime spend) — for retention/VIP outreach.
        $topCustomers = Customer::where('total_orders', '>', 0)
            ->orderByDesc('total_spent')->take(5)
            ->get(['id', 'name', 'phone', 'total_spent', 'total_orders', 'points']);

        // Outstanding loyalty-points liability (what redemption would cost).
        $pointsOutstanding = (int) Customer::sum('points');
        $pointsLiability = app(LoyaltyService::class)->pointsValue($pointsOutstanding);

        $lowStockProducts = Product::where('manage_stock', true)->where('stock_quantity', '<=', 3)
            ->orderBy('stock_quantity')->take(5)->get(['id', 'name', 'slug', 'stock_quantity']);

        // Most-loved products (by love reactions received).
        $mostLoved = Product::where('loves_count', '>', 0)
            ->orderByDesc('loves_count')
            ->take(8)->get(['id', 'name', 'slug', 'loves_count']);
        $totalLoves = (int) Product::sum('loves_count');

        $recentOrders = Order::latest()->take(10)->get();

        // Contact-form inbox, surfaced here so new messages are seen immediately.
        $unreadMessages = ContactMessage::where('is_read', false)->count();
        $recentMessages = ContactMessage::where('is_read', false)->latest()->take(6)->get();

        $statusCounts = Order::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Deep-analysis panels (admin chooses which are visible; all on by default).
        $saved = Setting::get('dashboard_panels', null);
        $panels = is_array($saved) ? $saved : array_keys(self::PANELS);

        // Each panel is computed defensively: analytics are decoration, and one
        // panel failing (a migration that hasn't run, an odd row) must not take
        // the whole dashboard down with it. A panel that errors is reported and
        // simply doesn't render.
        $safe = function (callable $fn, $fallback = null) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                report($e);

                return $fallback;
            }
        };
        $on = fn (string $panel) => in_array($panel, $panels, true);

        $deep = [
            'profit' => $on('profit') ? $safe(fn () => $analytics->periodComparison($range)) : null,
            'funnel' => $on('funnel') ? $safe(fn () => $analytics->funnel($range)) : null,
            // collect(), not null: the funnel panel @foreaches this directly.
            'visitorsByDay' => $on('funnel') ? $safe(fn () => $analytics->visitorsByDay($range), collect()) : null,
            'sources' => $on('funnel') ? $safe(fn () => $analytics->trafficSources($range), collect()) : null,
            'campaigns' => $on('funnel') ? $safe(fn () => $analytics->topCampaigns($range), collect()) : collect(),
            'viewedNotSold' => $on('funnel') ? $safe(fn () => $analytics->viewedNotSold($range), collect()) : null,
            'retention' => $on('retention') ? $safe(fn () => $analytics->retention($range)) : null,
            'operations' => $on('operations') ? $safe(fn () => $analytics->operations($range)) : null,
        ];

        // Unique visitors: all-time as the headline, plus the chosen window.
        $stats['visitors_total'] = (int) $safe(fn () => Visit::distinct()->count('visitor_token'), 0);
        $stats['visitors_today'] = (int) $safe(fn () => Visit::whereDate('created_at', $today)->distinct()->count('visitor_token'), 0);
        $stats['visitors_period'] = (int) $safe(fn () => $range->constrain(Visit::query())->distinct()->count('visitor_token'), 0);

        return view('admin.dashboard', compact(
            'stats', 'recentOrders', 'statusCounts', 'daily', 'dailyMax', 'topProducts', 'lowStockProducts',
            'mostLoved', 'totalLoves', 'topCategories', 'catMax', 'topCustomers', 'pointsOutstanding', 'pointsLiability',
            'unreadMessages', 'recentMessages', 'deep', 'panels', 'range'
        ));
    }

    /**
     * Revenue per bucket across the window, for the mini bar chart.
     *
     * Unlike visitor counts, revenue sums cleanly across grouped days, so a
     * long window loses no accuracy from bucketing — only resolution.
     *
     * @return Collection<int,array{label:string,total:float}>
     */
    protected function revenueSeries(DateRange $range): Collection
    {
        $byDay = $range->constrain(Order::whereNotIn('status', ['cancelled', 'returned']))
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(total) as t'))
            ->groupBy('d')->pluck('t', 'd');

        $start = $range->start ?? Carbon::parse(
            Order::min('created_at') ?: now()
        )->startOfDay();
        $end = $range->end ?? now()->endOfDay();

        $days = max(1, (int) $start->diffInDays($end) + 1);
        $buckets = (int) max(1, ceil($days / 30));   // at most 30 bars

        return collect(range(0, $days - 1))
            ->chunk($buckets)
            ->map(function ($chunk) use ($start, $byDay, $buckets) {
                $first = $start->copy()->addDays($chunk->first());

                return [
                    // One day per bar keeps the weekday initial the chart used
                    // to show; grouped bars need the date to stay readable.
                    'label' => $buckets === 1 ? $first->format('D') : $first->format('j M'),
                    'total' => (float) $chunk->sum(
                        fn ($i) => (float) ($byDay[$start->copy()->addDays($i)->toDateString()] ?? 0)
                    ),
                ];
            })->values();
    }
}
