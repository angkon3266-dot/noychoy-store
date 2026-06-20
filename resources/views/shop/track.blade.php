@extends('layouts.shop')
@section('title', 'Track Order')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-12">
    <h1 class="font-display text-3xl font-semibold mb-6 text-center">Track your order</h1>

    <form method="GET" class="card p-6 grid sm:grid-cols-2 gap-4">
        <div>
            <label class="label">Order number</label>
            <input name="order_number" value="{{ request('order_number') }}" placeholder="NOY-260615-0001" class="input" required>
        </div>
        <div>
            <label class="label">Mobile number</label>
            <input name="phone" value="{{ request('phone') }}" placeholder="01XXXXXXXXX" class="input" required>
        </div>
        <div class="sm:col-span-2"><button class="btn-primary w-full">Track</button></div>
    </form>

    @if(request('order_number') && !$order)
        <p class="text-center text-red-600 mt-6 text-sm">No order found with those details.</p>
    @endif

    @if($order)
        <div class="card p-6 mt-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-xl font-semibold">{{ $order->order_number }}</h2>
                <span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span>
            </div>
            @if($order->shipment?->tracking_code)
                <p class="text-sm mt-2 text-ink-700/70">Courier tracking: <strong>{{ $order->shipment->tracking_code }}</strong> ({{ $order->shipment->status }})</p>
            @endif

            <ol class="mt-6 space-y-4 border-l-2 border-gold-200 pl-4">
                @foreach($order->history as $h)
                    <li>
                        <div class="font-medium capitalize">{{ $h->status }}</div>
                        @if($h->note)<div class="text-sm text-ink-700/60">{{ $h->note }}</div>@endif
                        <div class="text-xs text-ink-700/40">{{ $h->created_at->format('d M Y, g:i a') }}</div>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>
@endsection
