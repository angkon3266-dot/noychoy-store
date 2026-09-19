<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Visit;
use App\Services\DashboardAnalytics;
use App\Services\DashboardInsights;
use App\Services\LoyaltyService;
use App\Support\DashboardLayout;
use App\Support\DateRange;
use App\Support\ExpenseReport;
use App\Support\ProductThumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * The deep-analysis groups the old ⚙ picker offered, in its display order.
     *
     * Superseded on 2026-09-18 by per-block arrangement (App\Support\
     * DashboardBlocks, where each block names the group it came from as
     * `legacy_panel`). Kept because the store-wide `dashboard_panels` setting
     * saved through it still decides what an admin who has never arranged
     * the dashboard sees, and because savePanels() below still accepts it.
     */
    public const PANELS = [
        'profit' => 'Revenue & profit',
        'funnel' => 'Traffic & conversion funnel',
        'retention' => 'Customers & retention',
        'operations' => 'Operations & inventory',
    ];

    /**
     * Save this admin's arrangement of the dashboard — the order of every
     * block and which of them are hidden (owner, 2026-09-18).
     *
     * Posted as JSON by the arrange bar, which then reloads; answered with a
     * redirect for anything that is not an XHR so a plain form would work too.
     */
    public function saveLayout(Request $request)
    {
        $request->validate([
            'order' => ['nullable', 'array'],
            'order.*' => ['string', 'max:64'],
            'hidden' => ['nullable', 'array'],
            'hidden.*' => ['string', 'max:64'],
        ]);

        // Unknown keys are dropped and repeats collapsed here, before the
        // save: the resolver would have filtered them on every read anyway,
        // but a stored layout that is already clean is one less thing to
        // reason about when a block is renamed later.
        $request->user()->forceFill([
            'dashboard_layout' => DashboardLayout::normalise($request->input('order'), $request->input('hidden')),
        ])->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', 'Dashboard layout saved.');
    }

    /** Forget this admin's arrangement: the default order, nothing hidden. */
    public function resetLayout(Request $request)
    {
        // An explicit empty layout, not null: null means "never arranged", which
        // re-applies the old store-wide ⚙ tick-list and hid blocks the button
        // had just promised to show (2026-09-18 review).
        $request->user()->forceFill(['dashboard_layout' => DashboardLayout::normalise([], [])])->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', 'Dashboard layout reset.');
    }

    /**
     * The old ⚙ form: which deep-analysis groups to show.
     *
     * Until 2026-09-18 this wrote the store-wide `dashboard_panels` setting.
     * Now it is translated into the calling admin's own layout — the blocks
     * of an unticked group hidden, the rest shown, the order untouched — so a
     * page rendered before the deploy still saves something sensible, and no
     * longer changes what every other admin sees. The setting itself is left
     * as it was: it is still the default for admins who have never arranged.
     */
    public function savePanels(Request $request)
    {
        $chosen = array_values(array_intersect(
            array_keys(self::PANELS),
            (array) $request->input('panels', [])
        ));

        $request->user()->forceFill([
            'dashboard_layout' => DashboardLayout::withLegacyPanels($request->user(), $chosen),
        ])->save();

        return back()->with('success', 'Dashboard layout saved.');
    }

    /**
     * Who is on the storefront right now — polled by the dashboard's live card.
     *
     * Kept out of index() on purpose: this is the one figure that is worthless
     * the moment it is a minute old, and re-rendering the whole dashboard to
     * refresh it would be absurd.
     */
    public function live(DashboardAnalytics $analytics)
    {
        try {
            return response()->json($analytics->liveVisitors());
        } catch (\Throwable $e) {
            report($e);

            // A polling endpoint that 500s every ten seconds would bury the log.
            return response()->json(['count' => 0, 'window' => 5, 'rows' => [], 'error' => true]);
        }
    }

    public function index(DashboardAnalytics $analytics, DashboardInsights $insights, Request $request)
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
        $resolved = Order::whereIn('status', ['delivered', 'partially_delivered', 'cancelled', 'returned'])->count();
        $deliveredAll = Order::where('status', 'delivered')->count();
        $codSuccess = $resolved ? round($deliveredAll / $resolved * 100) : null;

        $totalCustomers = Customer::count();
        $repeatCustomers = Customer::where('total_orders', '>', 1)->count();

        // The owner's own threshold (Appearance → Conversion features), the one
        // the bell alerts and the shop's "only N left" badge already read. The
        // dashboard had 3 written in, so the two could disagree about what low
        // stock is.
        $lowStockAt = max(1, (int) (theme('low_stock_threshold') ?: 3));

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
            'low_stock' => Product::where('manage_stock', true)->where('stock_quantity', '<=', $lowStockAt)->count(),
            'low_stock_at' => $lowStockAt,
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

        $lowStockProducts = Product::where('manage_stock', true)->where('stock_quantity', '<=', $lowStockAt)
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

        // This admin's arrangement of the blocks (owner, 2026-09-18): the
        // order they render in and which are hidden. A hidden block is not
        // rendered, and the analytics only it reads are not computed — the
        // registry says which $deep key each block needs, and a key is
        // computed when any VISIBLE block needs it. Hiding the funnel really
        // does skip the visits-table scans, which is the point on a host
        // where that table is the biggest one.
        $layout = DashboardLayout::for($request->user());
        $needed = DashboardLayout::needs($layout);

        // Each analytics key is computed defensively: analytics are decoration,
        // and one failing (a migration that hasn't run, an odd row) must not
        // take the whole dashboard down with it. One that errors is reported
        // and its block simply doesn't render.
        // false, never null, on failure: null means "not wanted", and the page
        // turns false into a one-line "couldn't be computed" card instead of
        // a block that quietly vanishes as if there were nothing to show.
        $safe = function (callable $fn, $fallback = false) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                report($e);

                return $fallback;
            }
        };
        $want = fn (string $key) => in_array($key, $needed, true);

        // Null means "not wanted by any visible block"; each partial guards on
        // its own key, so a collection-typed key falls back to collect() only
        // when it was actually computed and failed.
        $deep = [
            'profit' => $want('profit') ? $safe(fn () => $analytics->periodComparison($range)) : null,
            // Salaries and the net result are the owner's: only someone who
            // may open Admin → Expenses gets the figures computed at all.
            'netProfit' => $want('netProfit') && $request->user()?->canAccess('expenses')
                ? $safe(fn () => ExpenseReport::for($range)) : null,
            'funnel' => $want('funnel') ? $safe(fn () => $analytics->funnel($range)) : null,
            // collect(), not null: the chart @foreaches this directly.
            'series' => $want('series') ? $safe(fn () => $analytics->funnelByDay($range), collect()) : null,
            'sources' => $want('sources') ? $safe(fn () => $analytics->trafficSources($range), collect()) : null,
            'ads' => $want('ads') ? $safe(fn () => $analytics->adPerformance($range), collect()) : null,
            'viewedNotSold' => $want('viewedNotSold') ? $safe(fn () => $analytics->viewedNotSold($range), collect()) : null,
            'retention' => $want('retention') ? $safe(fn () => $analytics->retention($range)) : null,
            'operations' => $want('operations') ? $safe(fn () => $analytics->operations($range)) : null,

            // The second layer (owner, 2026-09-18: "add other analytical info
            // on the dashboard"): App\Services\DashboardInsights, one key per
            // block, computed on the same terms as the keys above — only when
            // a visible block reads it, and never allowed to take the page
            // down. The call list is a to-do list and has no window; the
            // rest follow the chosen range. A block whose key came back null
            // because its insight threw renders nothing, and the page shows
            // it as "nothing to show right now" in arrange mode.
            'callList' => $want('callList') ? $safe(fn () => $insights->callList()) : null,
            'cashAtCourier' => $want('cashAtCourier') ? $safe(fn () => $insights->cashAtCourier($range)) : null,
            'deliverySpeed' => $want('deliverySpeed') ? $safe(fn () => $insights->deliverySpeed($range)) : null,
            'stockHealth' => $want('stockHealth') ? $safe(fn () => $insights->stockHealth($range)) : null,
            'discountLeakage' => $want('discountLeakage') ? $safe(fn () => $insights->discountLeakage($range)) : null,
            'channelEconomics' => $want('channelEconomics') ? $safe(fn () => $insights->channelEconomics($range)) : null,
            'topEarners' => $want('topEarners') ? $safe(fn () => $insights->topEarners($range)) : null,
            'earnersByCategory' => $want('earnersByCategory') ? $safe(fn () => $insights->earnersByCategory($range)) : null,
            'leadRecovery' => $want('leadRecovery') ? $safe(fn () => $insights->leadRecovery($range)) : null,
            'assistantAndSms' => $want('assistantAndSms') ? $safe(fn () => $insights->assistantAndSms($range)) : null,
            'orderClock' => $want('orderClock') ? $safe(fn () => $insights->orderClock($range)) : null,
        ];

        // A picture beside every product the lists name (owner, 2026-09-19:
        // "add product image for easy ref"), for all of them in one query.
        // Looked up here rather than cached inside each report — see
        // ProductThumbs::for(). Only the lists that were computed contribute.
        $rowIds = fn ($rows) => collect(is_iterable($rows) ? $rows : [])->pluck('id');
        $thumbs = $safe(fn () => ProductThumbs::for([
            ...$lowStockProducts->pluck('id'),
            ...$mostLoved->pluck('id'),
            ...$rowIds(is_array($deep['operations']) ? ($deep['operations']['stock_cover'] ?? []) : []),
            ...$rowIds(is_array($deep['operations']) ? ($deep['operations']['dead_stock'] ?? []) : []),
            ...$rowIds($deep['viewedNotSold']),
            ...$rowIds(is_array($deep['topEarners']) ? ($deep['topEarners']['rows'] ?? []) : []),
        ]), []);

        // Unique visitors: all-time as the headline, plus the chosen window.
        $stats['visitors_total'] = (int) $safe(fn () => Visit::distinct()->count('visitor_token'), 0);
        $stats['visitors_today'] = (int) $safe(fn () => Visit::whereDate('created_at', $today)->distinct()->count('visitor_token'), 0);
        $stats['visitors_period'] = (int) $safe(fn () => $range->constrain(Visit::query())->distinct()->count('visitor_token'), 0);

        return view('admin.dashboard', compact(
            'stats', 'recentOrders', 'statusCounts', 'daily', 'dailyMax', 'lowStockProducts',
            'mostLoved', 'totalLoves', 'topCategories', 'catMax', 'topCustomers', 'pointsOutstanding', 'pointsLiability',
            'unreadMessages', 'recentMessages', 'deep', 'layout', 'range', 'thumbs'
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
            ->map(function ($chunk) use ($start, $byDay, $buckets, $days) {
                $first = $start->copy()->addDays($chunk->first());

                return [
                    // One day per bar keeps the weekday initial the chart used
                    // to show; grouped bars need the date to stay readable.
                    //
                    // Past a week the weekday alone repeats — thirty bars read
                    // "Mon Tue … Mon" — so the day of the month rides along.
                    // Since 17 Sep 2026 the chart reads a tapped bar out by its
                    // label on a phone, and "Wed · ৳4,500" could be any of four
                    // Wednesdays; "Wed 10 · ৳4,500" is one.
                    'label' => $buckets === 1
                        ? $first->format($days > 7 ? 'D j' : 'D')
                        : $first->format('j M'),
                    'total' => (float) $chunk->sum(
                        fn ($i) => (float) ($byDay[$start->copy()->addDays($i)->toDateString()] ?? 0)
                    ),
                ];
            })->values();
    }
}
