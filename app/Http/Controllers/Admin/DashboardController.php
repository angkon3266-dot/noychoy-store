<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $last30 = now()->subDays(30);

        // Orders that count as "real sales" (exclude cancelled / returned).
        $sold = fn () => Order::whereNotIn('status', ['cancelled', 'returned']);

        $deliveredMonth = (clone $sold)()->where('status', 'delivered')->where('created_at', '>=', $monthStart)->sum('total');
        $salesMonth = $sold()->where('created_at', '>=', $monthStart)->sum('total');
        $orders30 = $sold()->where('created_at', '>=', $last30)->get(['total']);
        $aov = $orders30->count() ? round($orders30->avg('total'), 0) : 0;

        // COD delivery success across resolved shipments.
        $resolved = Order::whereIn('status', ['delivered', 'cancelled', 'returned'])->count();
        $deliveredAll = Order::where('status', 'delivered')->count();
        $codSuccess = $resolved ? round($deliveredAll / $resolved * 100) : null;

        $totalCustomers = Customer::count();
        $repeatCustomers = Customer::where('total_orders', '>', 1)->count();

        $stats = [
            'orders_today' => Order::whereDate('created_at', $today)->count(),
            'sales_today' => $sold()->whereDate('created_at', $today)->sum('total'),
            'pending' => Order::where('status', 'pending')->count(),
            'processing' => Order::where('status', 'processing')->count(),
            'shipped' => Order::where('status', 'shipped')->count(),
            'sales_month' => $salesMonth,
            'revenue_month' => $deliveredMonth,
            'aov' => $aov,
            'cod_success' => $codSuccess,
            'products' => Product::count(),
            'customers' => $totalCustomers,
            'repeat_rate' => $totalCustomers ? round($repeatCustomers / $totalCustomers * 100) : 0,
            'new_customers_month' => Customer::where('created_at', '>=', $monthStart)->count(),
            'low_stock' => Product::where('manage_stock', true)->where('stock_quantity', '<=', 3)->count(),
        ];

        // Last 7 days revenue (for a mini bar chart).
        $revenueByDay = $sold()
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(total) as t'))
            ->groupBy('d')->pluck('t', 'd');
        $daily = collect(range(6, 0))->map(function ($i) use ($revenueByDay) {
            $date = now()->subDays($i)->toDateString();
            return ['label' => now()->subDays($i)->format('D'), 'total' => (float) ($revenueByDay[$date] ?? 0)];
        });
        $dailyMax = max(1, $daily->max('total'));

        // Top products (last 30 days, by units sold on non-cancelled orders).
        $topProducts = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned'])->where('created_at', '>=', $last30))
            ->select('name', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(subtotal) as revenue'))
            ->groupBy('name')->orderByDesc('qty')->take(5)->get();

        $lowStockProducts = Product::where('manage_stock', true)->where('stock_quantity', '<=', 3)
            ->orderBy('stock_quantity')->take(5)->get(['id', 'name', 'slug', 'stock_quantity']);

        $recentOrders = Order::latest()->take(10)->get();

        $statusCounts = Order::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('admin.dashboard', compact(
            'stats', 'recentOrders', 'statusCounts', 'daily', 'dailyMax', 'topProducts', 'lowStockProducts'
        ));
    }
}
