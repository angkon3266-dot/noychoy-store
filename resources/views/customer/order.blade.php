@extends('layouts.shop')
@section('title', $order->order_number)

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10">
    <a href="{{ route('account.orders') }}" class="text-sm text-gold-700 hover:underline">← Back to orders</a>
    <div class="card p-6 mt-4">
        <div class="flex items-center justify-between">
            <h1 class="font-display text-2xl font-semibold">{{ $order->order_number }}</h1>
            <span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span>
        </div>
        <p class="text-sm text-ink-700/60 mt-1">{{ $order->created_at->format('d M Y, g:i a') }}</p>

        <div class="mt-6 space-y-3">
            @foreach($order->items as $item)
                <div class="flex justify-between text-sm">
                    <span>{{ $item->name }} <span class="text-ink-700/50">× {{ $item->quantity }}</span></span>
                    <span class="font-medium">{{ money($item->subtotal) }}</span>
                </div>
            @endforeach
        </div>
        <dl class="border-t border-ink-100 mt-4 pt-4 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-ink-700/70">Subtotal</dt><dd>{{ money($order->subtotal) }}</dd></div>
            @if($order->discount > 0)<div class="flex justify-between text-green-700"><dt>Discount</dt><dd>−{{ money($order->discount) }}</dd></div>@endif
            <div class="flex justify-between"><dt class="text-ink-700/70">Shipping</dt><dd>{{ money($order->shipping_cost) }}</dd></div>
            <div class="flex justify-between font-semibold text-base"><dt>Total</dt><dd>{{ money($order->total) }}</dd></div>
        </dl>

        <div class="mt-6 text-sm">
            <h2 class="font-medium mb-1">Delivery address</h2>
            <p class="text-ink-700/70">{{ $order->customer_name }}, {{ $order->customer_phone }}<br>{{ $order->shipping_address }}{{ $order->area ? ', '.$order->area : '' }}{{ $order->district ? ', '.$order->district : '' }}</p>
        </div>
        @include('shop._order-tracking', ['tracking' => $tracking])
    </div>
</div>
@endsection
