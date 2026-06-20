@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
<div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
    @php $cards = [
        ['Orders today', $stats['orders_today'], 'text-gold-700'],
        ['Pending orders', $stats['pending'], 'text-amber-600'],
        ['Revenue (month)', money($stats['revenue_month']), 'text-green-700'],
        ['Products', $stats['products'], 'text-ink-800'],
        ['Customers', $stats['customers'], 'text-ink-800'],
        ['Low stock', $stats['low_stock'], 'text-red-600'],
    ]; @endphp
    @foreach($cards as [$label, $value, $color])
        <div class="card p-5">
            <div class="text-sm text-ink-700/60">{{ $label }}</div>
            <div class="text-2xl font-semibold mt-1 {{ $color }}">{{ $value }}</div>
        </div>
    @endforeach
</div>

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
