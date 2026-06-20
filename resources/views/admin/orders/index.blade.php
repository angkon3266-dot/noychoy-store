@extends('layouts.admin')
@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')
<form method="GET" class="flex flex-wrap gap-2 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="Order #, name or phone…" class="input py-2 w-64">
    <select name="status" onchange="this.form.submit()" class="input py-2">
        <option value="">All statuses</option>
        @foreach($statuses as $key => $label)
            <option value="{{ $key }}" @selected(request('status')==$key)>{{ $label }}</option>
        @endforeach
    </select>
    <button class="btn-outline">Search</button>
</form>

<div class="card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Items</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Date</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($orders as $order)
                @php($repeat = ($orderCounts[$order->customer_phone] ?? 1) > 1)
                <tr class="cursor-pointer {{ $repeat ? 'bg-violet-50 hover:bg-violet-100' : 'hover:bg-ink-50' }}" onclick="window.location='{{ route('admin.orders.show', $order) }}'">
                    <td class="px-4 py-3 font-medium text-gold-700">
                        {{ $order->order_number }}
                        @if($repeat)<span class="ml-1 align-middle badge bg-violet-100 text-violet-700 text-[10px]" title="Returning customer — {{ $orderCounts[$order->customer_phone] }} orders total">🔁 Repeat</span>@endif
                    </td>
                    <td class="px-4 py-3">{{ $order->customer_name }}<div class="text-xs text-ink-700/50">{{ $order->customer_phone }}</div></td>
                    <td class="px-4 py-3">{{ $order->items_count }}</td>
                    <td class="px-4 py-3">{{ money($order->total) }}</td>
                    <td class="px-4 py-3"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></td>
                    <td class="px-4 py-3 text-ink-700/60">{{ $order->created_at->format('d M, g:i a') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No orders found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $orders->links() }}</div>
@endsection
