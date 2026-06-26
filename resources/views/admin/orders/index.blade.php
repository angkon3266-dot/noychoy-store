@extends('layouts.admin')
@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')
<div x-data="{ sel: [], pageIds: [{{ $orders->pluck('id')->implode(',') }}],
               get allChecked(){ return this.pageIds.length && this.sel.length === this.pageIds.length },
               toggleAll(e){ this.sel = e.target.checked ? [...this.pageIds] : [] } }">

    <form method="GET" class="flex flex-wrap gap-2 mb-4">
        <input name="q" value="{{ request('q') }}" placeholder="Order #, name or phone…" class="input py-2 w-64">
        <select name="status" onchange="this.form.submit()" class="input py-2">
            <option value="">All statuses</option>
            @foreach($statuses as $key => $label)
                <option value="{{ $key }}" @selected(request('status')==$key)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn-outline">Search</button>
        <a href="{{ route('admin.orders.labels') }}" target="_blank" class="btn-outline whitespace-nowrap ml-auto">🖨 Print all labels</a>
    </form>

    {{-- Bulk action bar --}}
    <div x-show="sel.length" x-cloak
         class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3">
        <span class="text-sm font-medium"><span x-text="sel.length"></span> selected</span>

        <form action="{{ route('admin.orders.bulk-steadfast') }}" method="POST" class="inline"
              onsubmit="return confirm('Send the selected orders to Steadfast? Orders already booked are skipped.')">
            @csrf
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-primary py-2 text-sm">🚚 Send to Steadfast</button>
        </form>

        <button type="button" class="btn-outline py-2 text-sm"
                @click="window.open('{{ route('admin.orders.labels') }}?ids=' + sel.join(','), '_blank')">
            🖨 Print labels
        </button>

        <form action="{{ route('admin.orders.merge') }}" method="POST" class="inline"
              onsubmit="return confirm('Merge the selected orders into one? This combines their items under the earliest order number and removes the others.')">
            @csrf
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-outline py-2 text-sm" x-show="sel.length >= 2">🔗 Merge orders</button>
        </form>

        <button type="button" class="text-sm text-ink-700/60 hover:underline ml-auto" @click="sel = []">Clear</button>
    </div>

    <div class="grid xl:grid-cols-[1fr_320px] gap-6 items-start">
    <div class="min-w-0">
    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-3 py-3 w-8"><input type="checkbox" :checked="allChecked" @change="toggleAll($event)"></th>
                    <th class="px-4 py-3">Order</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Items</th>
                    <th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($orders as $order)
                    @php
                        $repeat = ($orderCounts[$order->customer_phone] ?? 1) > 1;
                        $booked = $order->shipment && $order->shipment->consignment_id;
                    @endphp
                    <tr class="cursor-pointer {{ $repeat ? 'bg-violet-50 hover:bg-violet-100' : 'hover:bg-ink-50' }}" onclick="window.location='{{ route('admin.orders.show', $order) }}'">
                        <td class="px-3 py-3" onclick="event.stopPropagation()">
                            <input type="checkbox" value="{{ $order->id }}" x-model.number="sel">
                        </td>
                        <td class="px-4 py-3 font-medium text-gold-700">
                            {{ $order->order_number }}
                            @if($repeat)<span class="ml-1 align-middle badge bg-violet-100 text-violet-700 text-[10px]" title="Returning customer — {{ $orderCounts[$order->customer_phone] }} orders total">🔁 Repeat</span>@endif
                            @if($booked)<div class="text-[10px] text-emerald-700 mt-0.5">📦 {{ $order->shipment->consignment_id }}</div>@endif
                        </td>
                        <td class="px-4 py-3">{{ $order->customer_name }}<div class="text-xs text-ink-700/50">{{ $order->customer_phone }}</div></td>
                        <td class="px-4 py-3">{{ $order->items_count }}</td>
                        <td class="px-4 py-3">{{ money($order->total) }}</td>
                        <td class="px-4 py-3" onclick="event.stopPropagation()">
                            <form action="{{ route('admin.orders.status', $order) }}" method="POST">
                                @csrf
                                <select name="status" onchange="this.form.submit()"
                                        class="rounded-md border border-ink-200 bg-white px-2 py-1 text-xs capitalize">
                                    @foreach($statuses as $key => $label)
                                        <option value="{{ $key }}" @selected($order->status==$key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $order->created_at->format('d M, g:i a') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No orders found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $orders->links() }}</div>
    </div>

    {{-- Processing fulfilment queue (products to prepare) --}}
    <aside class="card p-4 xl:sticky xl:top-4">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">To prepare · Processing</h2>
            <span class="badge bg-amber-100 text-amber-700 text-[10px]">{{ $processingItems->sum('qty') }} units</span>
        </div>
        <p class="text-xs text-ink-700/50 mb-3">Items across all orders currently in <strong>Processing</strong>.</p>
        @forelse($processingItems as $it)
            <div class="flex items-start justify-between gap-2 py-2 border-b border-ink-50 last:border-0 text-sm">
                <div class="min-w-0">
                    <div class="truncate">{{ $it->name }}</div>
                    <div class="text-[11px] text-ink-700/45">
                        @if($it->product_id)ID #{{ $processingSerials[$it->product_id] ?? $it->product_id }}@else (deleted) @endif
                        · {{ $it->orders }} order{{ $it->orders > 1 ? 's' : '' }}
                    </div>
                </div>
                <span class="shrink-0 font-semibold text-gold-700">×{{ $it->qty }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">Nothing in processing right now. 🎉</p>
        @endforelse
    </aside>
    </div>
</div>
@endsection
