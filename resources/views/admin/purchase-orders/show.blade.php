@extends('layouts.admin')
@section('title', $order->po_number)
@section('heading', 'PO · '.$order->po_number)

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<div class="flex flex-wrap items-center gap-3 mb-4">
    <span class="badge capitalize {{ $order->status === 'received' ? 'bg-green-100 text-green-700' : ($order->status === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $order->statusLabel() }}</span>
    <form action="{{ route('admin.purchase-orders.status', $order) }}" method="POST" class="flex items-center gap-2">
        @csrf
        <select name="status" onchange="this.form.submit()" class="input py-1.5 text-sm">
            @foreach($statuses as $key => $label)
                <option value="{{ $key }}" @selected($order->status==$key)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('admin.purchase-orders.export', $order) }}" class="btn-outline text-sm py-1.5 ml-auto">⬇ Export Excel</a>
    <a href="{{ route('admin.purchase-orders.edit', $order) }}" class="btn-outline text-sm py-1.5">Edit</a>
    <form action="{{ route('admin.purchase-orders.destroy', $order) }}" method="POST" onsubmit="return confirm('Delete this purchase order?')">
        @csrf @method('DELETE')
        <button class="btn-outline text-sm py-1.5 text-red-600">Delete</button>
    </form>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Product</th><th class="px-4 py-3">Qty</th><th class="px-4 py-3">Received</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3 text-right">Line</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($order->items as $it)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-2">
                                @if($it->image_url)<img src="{{ $it->image_url }}" alt="" class="w-9 h-9 rounded object-cover shrink-0">@endif
                                <div class="min-w-0">
                                    <div class="font-medium">
                                        @if($it->product_link)<a href="{{ $it->product_link }}" target="_blank" rel="noopener" class="text-gold-700 hover:underline">{{ $it->product_name }}</a>@else{{ $it->product_name }}@endif
                                    </div>
                                    @if($it->sku)<div class="text-xs text-ink-700/50">{{ $it->sku }}</div>@endif
                                    @if($it->variants)
                                        <ul class="text-xs text-ink-700/60 mt-0.5">
                                            @foreach($it->variants as $v)
                                                <li>{{ collect($v['attrs'] ?? [])->map(fn ($val, $k) => "$k: $val")->implode(', ') }} — ×{{ $v['qty'] ?? 0 }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $it->qty }}</td>
                        <td class="px-4 py-3">{{ $it->received_qty }}@if($it->received_qty < $it->qty)<span class="text-amber-600 text-xs"> (−{{ $it->qty - $it->received_qty }})</span>@endif</td>
                        <td class="px-4 py-3">{{ $order->currency }} {{ number_format($it->unit_cost, 2) }}</td>
                        <td class="px-4 py-3 text-right">{{ $order->currency }} {{ number_format($it->lineTotal(), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-ink-700/50">No items on this PO.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
        <div class="card p-5">
            <h2 class="font-semibold mb-2">Supplier</h2>
            @if($order->supplier)
                <p class="font-medium">{{ $order->supplier->name }}</p>
                <p class="text-xs text-ink-700/60">{{ $order->supplier->country }}@if($order->supplier->contact) · {{ $order->supplier->contact }}@endif</p>
                <p class="text-xs text-ink-700/60">{{ $order->supplier->phone }}@if($order->supplier->wechat) · WeChat {{ $order->supplier->wechat }}@endif</p>
            @else
                <p class="text-sm text-ink-700/50">—</p>
            @endif
        </div>

        <div class="card p-5">
            <h2 class="font-semibold mb-2">Totals</h2>
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-ink-700/60">Items</dt><dd>{{ $order->currency }} {{ number_format($order->itemsSubtotal(), 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-700/60">Courier</dt><dd>{{ $order->currency }} {{ number_format($order->courier_cost, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-700/60">Processing ({{ rtrim(rtrim(number_format((float) $order->processing_pct, 2), '0'), '.') }}%)</dt><dd>—</dd></div>
                <div class="flex justify-between font-semibold border-t border-ink-100 pt-2"><dt>Total</dt><dd>{{ $order->currency }} {{ number_format($order->total_cost, 2) }}</dd></div>
                @if($order->totalInBdt())<div class="flex justify-between text-xs text-ink-700/50"><dt>≈ in BDT</dt><dd>{{ money($order->totalInBdt()) }}</dd></div>@endif
            </dl>
            @if($order->courier_name || $order->courier_tracking)
                <p class="text-xs text-ink-700/60 mt-3 border-t border-ink-100 pt-2">Courier: {{ $order->courier_name }} @if($order->courier_tracking)· {{ $order->courier_tracking }}@endif</p>
            @endif
            <div class="text-xs text-ink-700/50 mt-2">
                Ordered: {{ $order->ordered_at?->format('d M Y') ?? '—' }} · Expected: {{ $order->expected_at?->format('d M Y') ?? '—' }}
                @if($order->arrived_at) · Arrived: {{ $order->arrived_at->format('d M Y') }}@endif
            </div>
        </div>

        @if($order->notes)
            <div class="card p-5"><h2 class="font-semibold mb-2">Notes</h2><p class="text-sm text-ink-700/70 whitespace-pre-line">{{ $order->notes }}</p></div>
        @endif
    </div>
</div>
@endsection
