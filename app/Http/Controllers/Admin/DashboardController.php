<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();

        $stats = [
            'orders_today' => Order::whereDate('created_at', $today)->count(),
            'pending' => Order::where('status', 'pending')->count(),
            'revenue_month' => Order::where('status', 'delivered')
                ->where('created_at', '>=', $monthStart)->sum('total'),
            'products' => Product::count(),
            'customers' => Customer::count(),
            'low_stock' => Product::where('manage_stock', true)->where('stock_quantity', '<=', 3)->count(),
        ];

        $recentOrders = Order::latest()->take(10)->get();

        $statusCounts = Order::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('admin.dashboard', compact('stats', 'recentOrders', 'statusCounts'));
    }
}
