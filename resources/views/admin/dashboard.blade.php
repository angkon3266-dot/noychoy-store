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
        ['Stock on hand', number_format($stats['stock_units']).' pcs', 'text-ink-800', money($stats['stock_cost_value']).' at cost'],
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

    {{-- Best-selling categories --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Best-selling categories · 30 days</h2>
        @forelse($topCategories as $cat)
            <div class="py-1.5">
                <div class="flex items-center justify-between text-sm mb-1">
                    <span class="truncate pr-3">{{ $cat->name }}</span>
                    <span class="shrink-0 text-ink-700/60">{{ $cat->qty }} sold · {{ money($cat->revenue) }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ round($cat->qty / $catMax * 100) }}%"></div></div>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No category sales in the last 30 days yet.</p>
        @endforelse
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
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

    {{-- Most valuable customers --}}
    <div class="card p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Top customers</h2>
            <span class="text-xs text-ink-700/50">Points liability: {{ money($pointsLiability) }} ({{ number_format($pointsOutstanding) }} pts)</span>
        </div>
        @forelse($topCustomers as $c)
            <div class="flex items-center justify-between py-2 border-b border-ink-50 last:border-0 text-sm">
                <a href="{{ route('admin.customers.show', $c) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $c->name }} <span class="text-ink-700/40">· {{ $c->total_orders }} orders</span></a>
                <span class="shrink-0 text-ink-700/60">{{ money($c->total_spent) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No customer sales yet.</p>
        @endforelse
    </div>
</div>

{{-- Most loved products --}}
<div class="card p-5 mt-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold flex items-center gap-2">
            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
            Most loved products
        </h2>
        <span class="text-sm text-ink-700/60">{{ number_format($totalLoves) }} total ❤️</span>
    </div>
    @forelse($mostLoved as $p)
        @php $lovedMax = max(1, $mostLoved->max('loves_count')); @endphp
        <div class="flex items-center gap-3 py-2 border-b border-ink-50 last:border-0 text-sm">
            <a href="{{ route('admin.products.edit', $p) }}" class="text-gold-700 hover:underline truncate w-48 shrink-0">{{ $p->name }}</a>
            <div class="flex-1 h-2 rounded-full bg-ink-50 overflow-hidden">
                <div class="h-full rounded-full bg-red-400" style="width: {{ round($p->loves_count / $lovedMax * 100) }}%"></div>
            </div>
            <span class="shrink-0 font-medium text-red-600 w-16 text-right">❤️ {{ number_format($p->loves_count) }}</span>
        </div>
    @empty
        <p class="text-sm text-ink-700/50">No love reactions yet. They'll appear here as customers tap the ❤️ on products.</p>
    @endforelse
</div>

{{-- Contact messages inbox --}}
@if($unreadMessages > 0)
<div class="card mt-6 overflow-hidden border-gold-200">
    <div class="flex items-center justify-between px-5 py-4 border-b border-ink-100 bg-gold-50/60">
        <h2 class="font-semibold flex items-center gap-2">📨 New messages
            <span class="min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold inline-flex items-center justify-center">{{ $unreadMessages }}</span>
        </h2>
        <a href="{{ route('admin.messages') }}" class="text-sm text-gold-700 hover:underline">All messages →</a>
    </div>
    <div class="divide-y divide-ink-100">
        @foreach($recentMessages as $m)
            <div class="px-5 py-3 flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ $m->name }}
                        <span class="text-xs font-normal text-ink-700/50">· {{ $m->phone ?: $m->email }} · {{ $m->created_at->diffForHumans() }}</span>
                    </p>
                    @if($m->subject)<p class="text-xs font-medium text-ink-700/70 mt-0.5">{{ $m->subject }}</p>@endif
                    <p class="text-sm text-ink-700/70 mt-0.5">{{ \Illuminate\Support\Str::limit($m->message, 160) }}</p>
                </div>
                <form action="{{ route('admin.messages.read', $m) }}" method="POST" class="shrink-0">
                    @csrf
                    <button class="text-xs text-gold-700 hover:underline whitespace-nowrap">Mark read</button>
                </form>
            </div>
        @endforeach
    </div>
</div>
@endif

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
