@extends('layouts.shop')
@section('title', 'Your Cart')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8">
    <h1 class="font-display text-3xl font-semibold mb-6">Your cart</h1>

    @if($cart->isEmpty())
        <div class="card p-12 text-center">
            <p class="text-ink-700/60">Your cart is empty.</p>
            <a href="{{ route('shop') }}" class="btn-primary mt-4">Start shopping</a>
        </div>
    @else
        @php($freeThreshold = config('store.shipping.free_threshold'))
        @if(theme('free_shipping_bar') && $freeThreshold)
            @php($remaining = max(0, $freeThreshold - $cart->subtotal()))
            <div class="card p-4 mb-6">
                @if($remaining > 0)
                    <p class="text-sm text-center mb-2">Add <strong>{{ money($remaining) }}</strong> more to unlock <strong>free delivery!</strong></p>
                @else
                    <p class="text-sm text-center mb-2 text-green-700 font-medium">🎉 You've unlocked free delivery!</p>
                @endif
                <div class="h-2 rounded-full bg-gold-100 overflow-hidden">
                    <div class="h-full bg-gold-500 transition-all" style="width: {{ min(100, (int) ($cart->subtotal() / $freeThreshold * 100)) }}%"></div>
                </div>
            </div>
        @endif
        <div class="grid lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-4">
                @foreach($cart->items() as $item)
                    <div class="card p-4 flex gap-4 items-center">
                        <div class="w-20 h-20 rounded-lg bg-gold-100 overflow-hidden shrink-0">
                            @if($item['image'])<img src="{{ $item['image'] }}" class="w-full h-full object-cover" alt="">@endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('product.show', $item['slug']) }}" class="font-medium hover:text-gold-700">{{ $item['name'] }}</a>
                            @if(!empty($item['attributes']))
                                <p class="text-xs text-ink-700/60">{{ collect($item['attributes'])->map(fn($v,$k)=>"$v")->implode(', ') }}</p>
                            @endif
                            <p class="text-sm text-gold-700 font-semibold mt-1">{{ money($item['price']) }}</p>
                            @if(($pct = $cart->lineOfferPercent($item)) > 0)
                                <p class="mt-1 inline-block badge bg-green-100 text-green-700 text-[11px]">Bundle offer: −{{ rtrim(rtrim(number_format($pct,2),'0'),'.') }}% &nbsp;(you save {{ money($cart->lineOfferSaving($item)) }})</p>
                            @endif
                        </div>
                        <form action="{{ route('cart.update') }}" method="POST" class="flex items-center">
                            @csrf @method('PATCH')
                            <input type="hidden" name="key" value="{{ $item['key'] }}">
                            <input type="number" name="qty" value="{{ $item['qty'] }}" min="0" max="99"
                                   onchange="this.form.submit()" class="input w-16 text-center py-1.5">
                        </form>
                        <div class="text-right w-24">
                            <p class="font-semibold">{{ money($item['price'] * $item['qty']) }}</p>
                            <form action="{{ route('cart.remove') }}" method="POST">
                                @csrf @method('DELETE')
                                <input type="hidden" name="key" value="{{ $item['key'] }}">
                                <button class="text-xs text-red-600 hover:underline mt-1">Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="card p-6 h-fit">
                <h2 class="font-display text-xl font-semibold mb-4">Summary</h2>

                @if($coupon = $cart->coupon())
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-green-700">Coupon {{ $coupon->code }}</span>
                        <form action="{{ route('cart.coupon.remove') }}" method="POST">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">remove</button></form>
                    </div>
                @else
                    <form action="{{ route('cart.coupon') }}" method="POST" class="flex gap-2 mb-4">
                        @csrf
                        <input name="code" placeholder="Coupon code" class="input py-2">
                        <button class="btn-outline">Apply</button>
                    </form>
                @endif

                <dl class="space-y-2 text-sm border-t border-ink-100 pt-4">
                    <div class="flex justify-between"><dt class="text-ink-700/70">Subtotal</dt><dd>{{ money($cart->subtotal()) }}</dd></div>
                    @if($cart->offerDiscount() > 0)
                        <div class="flex justify-between text-green-700"><dt>Bundle offers</dt><dd>−{{ money($cart->offerDiscount()) }}</dd></div>
                    @endif
                    @if($cart->couponDiscount() > 0)
                        <div class="flex justify-between text-green-700"><dt>Coupon</dt><dd>−{{ money($cart->couponDiscount()) }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-ink-700/70">Shipping</dt><dd class="text-ink-700/60">calculated at checkout</dd></div>
                    <div class="flex justify-between font-semibold text-base border-t border-ink-100 pt-3">
                        <dt>Estimated total</dt><dd>{{ money($cart->subtotal() - $cart->discount()) }}</dd>
                    </div>
                </dl>

                <a href="{{ route('checkout') }}" class="btn-primary w-full mt-6">Proceed to checkout</a>
                <a href="{{ route('shop') }}" class="block text-center text-sm text-gold-700 hover:underline mt-3">Continue shopping</a>
            </div>
        </div>
    @endif
</div>
@endsection
