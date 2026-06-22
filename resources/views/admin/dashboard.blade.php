@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
{{-- KPI cards --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    @php $cards = [
        ['Orders today', $stats['orders_today'], 'text-gold-700', money($stats['sales_today']).' sales'],
        ['To process', $stats['pending'] + $stats['processing'], 'text-amber-600', $stats['shipped'].' shipped'],
        ['Sales (month)', money($stats['sales_month']), 'text-ink-800', money($stats['revenue_month']).' delivered'],
        ['Avg. order value', money($stats['aov']), 'text-ink-800', 'last 30 days'],
        ['COD success', $stats['cod_success'] === null ? '—' : $stats['cod_success'].'%', 'text-green-700', 'delivered / resolved'],
        ['Customers', $stats['customers'], 'text-ink-800', $stats['repeat_rate'].'% repeat'],
        ['New customers', $stats['new_customers_month'], 'text-ink-800', 'this month'],
        ['Low stock', $stats['low_stock'], 'text-red-600', '≤ 3 left'],
    ]; @endphp
    @foreach($cards as [$label, $value, $color, $sub])
        <div class="card p-5">
            <div class="text-sm text-ink-700/60">{{ $label }}</div>
            <div class="text-2xl font-semibold mt-1 {{ $color }}">{{ $value }}</div>
            <div class="text-xs text-ink-700/40 mt-0.5">{{ $sub }}</div>
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-6">
    {{-- 7-day revenue chart --}}
    <div class="card p-5 lg:col-span-2">
        <h2 class="font-semibold mb-4">Sales · last 7 days</h2>
        <div class="flex items-end justify-between gap-3 h-44">
            @foreach($daily as $d)
                <div class="flex-1 flex flex-col items-center justify-end h-full">
                    <div class="text-[10px] text-ink-700/50 mb-1">{{ $d['total'] > 0 ? money($d['total']) : '' }}</div>
                    <div class="w-full rounded-t bg-gold-400/80" style="height: {{ $d['total'] > 0 ? max(4, round($d['total'] / $dailyMax * 100)) : 1 }}%"></div>
                    <div class="text-xs text-ink-700/50 mt-2">{{ $d['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Order status breakdown --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-4">Orders by status</h2>
        <div class="space-y-2">
            @foreach(\App\Models\Order::STATUSES as $key => $label)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-700/70">{{ $label }}</span>
                    <span class="badge bg-ink-100 text-ink-700">{{ $statusCounts[$key] ?? 0 }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
    {{-- Top products --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Top products · 30 days</h2>
        @forelse($topProducts as $p)
            <div class="flex items-center justify-between py-2 border-b border-ink-50 last:border-0 text-sm">
                <span class="truncate pr-3">{{ $p->name }}</span>
                <span class="shrink-0 text-ink-700/60">{{ $p->qty }} sold · {{ money($p->revenue) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No sales in the last 30 days yet.</p>
        @endforelse
    </div>

    {{-- Low stock --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Low stock alerts</h2>
        @forelse($lowStockProducts as $p)
            <div class="flex items-center justify-between py-2 border-b border-ink-50 last:border-0 text-sm">
                <a href="{{ route('admin.products.edit', $p) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $p->name }}</a>
                <span class="badge {{ $p->stock_quantity == 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ $p->stock_quantity }} left</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">Everything's well stocked. 🎉</p>
        @endforelse
    </div>
</div>

{{-- Recent orders --}}
<div class="card mt-6 overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-ink-100">
        <h2 class="font-semibold">Recent orders</h2>
        <a href="{{ route('admin.orders.index') }}" class="text-sm text-gold-700 hover:underline">All orders →</a>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Date</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($recentOrders as $order)
                <tr class="hover:bg-ink-50">
                    <td class="px-5 py-3"><a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-gold-700 hover:underline">{{ $order->order_number }}</a></td>
                    <td class="px-5 py-3">{{ $order->customer_name }}<div class="text-xs text-ink-700/50">{{ $order->customer_phone }}</div></td>
                    <td class="px-5 py-3">{{ money($order->total) }}</td>
                    <td class="px-5 py-3"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></td>
                    <td class="px-5 py-3 text-ink-700/60">{{ $order->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-8 text-center text-ink-700/50">No orders yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
