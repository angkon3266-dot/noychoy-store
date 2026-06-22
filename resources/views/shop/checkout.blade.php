@extends('layouts.shop')
@section('title', 'Checkout')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8"
     x-data="{ inside: {{ old('is_inside_dhaka', $address->is_inside_dhaka ?? false) ? 'true' : 'false' }},
               sub: {{ $cart->subtotal() - $cart->discount() }},
               get ship(){ {{ ($t = config('store.shipping.free_threshold')) !== null ? "if ({$cart->subtotal()} >= {$t}) return 0;" : '' }} return this.inside ? {{ \App\Models\Setting::get('shipping_inside', config('store.shipping.inside_dhaka')) }} : {{ \App\Models\Setting::get('shipping_outside', config('store.shipping.outside_dhaka')) }} },
               leadSent: false,
               captureLead(phone, name, email){
                   if (this.leadSent) return;
                   if (!/^(\+?880|0)1[3-9]\d{8}$/.test((phone||'').trim())) return;
                   this.leadSent = true;
                   fetch('{{ route('checkout.lead') }}', {
                       method: 'POST',
                       headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                       body: JSON.stringify({ phone, name, email })
                   }).catch(() => { this.leadSent = false; });
               },
               get total(){ return this.sub + this.ship } }">
    <h1 class="font-display text-3xl font-semibold mb-6">Checkout</h1>

    @if($errors->any())
        <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm mb-6">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form action="{{ route('checkout.store') }}" method="POST" class="grid lg:grid-cols-3 gap-8">
        @csrf
        <div class="lg:col-span-2 card p-6 space-y-4">
            <h2 class="font-display text-xl font-semibold">Delivery details</h2>
            @guest('customer')
                <p class="text-sm text-ink-700/60">Have an account? <a href="{{ route('customer.login') }}" class="text-gold-700 hover:underline">Log in</a> for faster checkout.</p>
            @endguest

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Full name *</label>
                    <input name="name" x-ref="cname" value="{{ old('name', $customer->name ?? '') }}" class="input" required>
                </div>
                <div>
                    <label class="label">Mobile number *</label>
                    <input name="phone" x-ref="phone" value="{{ old('phone', $customer->phone ?? '') }}" placeholder="01XXXXXXXXX" class="input" required
                           @blur="captureLead($el.value, $refs.cname?.value, $refs.cemail?.value)">
                </div>
            </div>
            <div>
                <label class="label">Full address *</label>
                <textarea name="address" rows="2" class="input" required>{{ old('address', $address->address ?? '') }}</textarea>
            </div>
            <div>
                <label class="label">Area / Thana</label>
                <input name="area" value="{{ old('area', $address->area ?? '') }}" class="input">
            </div>

            <div>
                <span class="label">Delivery zone</span>
                <div class="flex gap-3">
                    <label class="flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm" :class="inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'">
                        <input type="radio" name="is_inside_dhaka" value="1" x-model="inside" @change="inside=true" class="sr-only" {{ old('is_inside_dhaka') ? 'checked' : '' }}>
                        Inside Dhaka — ৳{{ \App\Models\Setting::get('shipping_inside', config('store.shipping.inside_dhaka')) }}
                    </label>
                    <label class="flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm" :class="!inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'">
                        <input type="radio" name="is_inside_dhaka" value="0" @change="inside=false" class="sr-only" {{ old('is_inside_dhaka') ? '' : 'checked' }}>
                        Outside Dhaka — ৳{{ \App\Models\Setting::get('shipping_outside', config('store.shipping.outside_dhaka')) }}
                    </label>
                </div>
            </div>

            <div>
                <label class="label">Order note (optional)</label>
                <textarea name="notes" rows="2" class="input">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="card p-6 h-fit">
            <h2 class="font-display text-xl font-semibold mb-4">Your order</h2>
            <div class="space-y-3 max-h-64 overflow-y-auto">
                @foreach($cart->items() as $item)
                    <div class="flex justify-between text-sm gap-2">
                        <span class="text-ink-700/80">{{ $item['name'] }} <span class="text-ink-700/50">× {{ $item['qty'] }}</span></span>
                        <span class="font-medium shrink-0">{{ money($item['price'] * $item['qty']) }}</span>
                    </div>
                @endforeach
            </div>
            <dl class="space-y-2 text-sm border-t border-ink-100 mt-4 pt-4">
                <div class="flex justify-between"><dt class="text-ink-700/70">Subtotal</dt><dd>{{ money($cart->subtotal()) }}</dd></div>
                @forelse($cart->discountLines() as $line)
                    <div class="flex justify-between text-green-700"><dt>{{ $line['label'] }}</dt><dd>−{{ money($line['amount']) }}</dd></div>
                @empty
                    @if($cart->discount() > 0)<div class="flex justify-between text-green-700"><dt>Discount</dt><dd>−{{ money($cart->discount()) }}</dd></div>@endif
                @endforelse
                <div class="flex justify-between"><dt class="text-ink-700/70">Shipping</dt><dd>৳<span x-text="ship"></span></dd></div>
                <div class="flex justify-between font-semibold text-base border-t border-ink-100 pt-3"><dt>Total</dt><dd>৳<span x-text="total.toLocaleString()"></span></dd></div>
            </dl>
            @foreach($cart->offerHints() as $hint)
                <div class="mt-3 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 text-xs">🎁 {{ $hint }}</div>
            @endforeach

            <div class="mt-4 rounded-md bg-gold-100/60 p-3 text-sm">💵 <strong>Cash on Delivery</strong> — pay when you receive your order.</div>
            <button type="submit" class="btn-primary w-full mt-6">Place order</button>

            {{-- Trust strip (editable in Admin → Appearance → Trust badges) --}}
            <x-trust-strip class="mt-4" />
            <p class="mt-3 text-center text-xs text-ink-700/50">No advance payment needed · We call to confirm every order</p>
        </div>
    </form>
</div>

@push('meta-events')
<script>track('InitiateCheckout', {value: {{ $cart->subtotal() - $cart->discount() }}, currency: 'BDT', num_items: {{ $cart->count() }}});</script>
@endpush
@endsection
