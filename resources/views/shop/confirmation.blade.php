@extends('layouts.shop')
@section('title', 'Order Confirmed')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-12">
    <div class="card p-8 text-center">
        <div class="mx-auto w-16 h-16 rounded-full bg-green-100 flex items-center justify-center">
            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
        </div>
        <h1 class="font-display text-3xl font-semibold mt-4">Thank you, {{ $order->customer_name }}!</h1>
        <p class="text-ink-700/70 mt-2">Your order <strong>{{ $order->order_number }}</strong> has been placed. We'll call you shortly to confirm.</p>

        <div class="text-left mt-8 border-t border-ink-100 pt-6 space-y-3">
            @foreach($order->items as $item)
                <div class="flex justify-between text-sm">
                    <span>{{ $item->name }} <span class="text-ink-700/50">× {{ $item->quantity }}</span></span>
                    <span class="font-medium">{{ money($item->subtotal) }}</span>
                </div>
            @endforeach
            <dl class="border-t border-ink-100 pt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-ink-700/70">Subtotal</dt><dd>{{ money($order->subtotal) }}</dd></div>
                @if($order->discount > 0)<div class="flex justify-between text-green-700"><dt>Discount</dt><dd>−{{ money($order->discount) }}</dd></div>@endif
                <div class="flex justify-between"><dt class="text-ink-700/70">Shipping</dt><dd>{{ money($order->shipping_cost) }}</dd></div>
                <div class="flex justify-between font-semibold text-base"><dt>Total (COD)</dt><dd>{{ money($order->total) }}</dd></div>
            </dl>
        </div>

        <div class="mt-8 flex justify-center gap-3">
            <a href="{{ route('shop') }}" class="btn-primary">Continue shopping</a>
            <a href="{{ route('track') }}?order_number={{ $order->order_number }}" class="btn-outline">Track order</a>
        </div>
    </div>
</div>

@push('meta-events')
<script>
track('Purchase', {
    value: {{ (float) $order->total }},
    currency: 'BDT',
    content_type: 'product',
    content_ids: {{ Js::from($order->items->map(fn($i) => $i->variant_id ? "prod-{$i->product_id}-var-{$i->variant_id}" : "prod-{$i->product_id}")->values()) }},
    num_items: {{ (int) $order->items->sum('quantity') }}
}, { eventID: '{{ $order->order_number }}' });
</script>
@endpush
@endsection
