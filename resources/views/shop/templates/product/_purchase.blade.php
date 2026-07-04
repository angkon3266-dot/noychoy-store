{{-- Conversion-optimized buy box. Expects Alpine `productPage` scope on an ancestor. --}}
@php
    $lowStock = theme('urgency_low_stock') && $product->manage_stock
        && $product->stock_quantity > 0 && $product->stock_quantity <= (int) theme('low_stock_threshold', 5);
@endphp

<div>
    <h1 class="font-display text-3xl font-semibold">{{ $product->name }}</h1>

    {{-- Rating summary (display) --}}
    @php
        $avgRating = $product->average_rating;
        $reviewCount = $product->review_count;
    @endphp
    <a href="#reviews" class="mt-2 flex items-center gap-2 text-sm group">
        <div class="flex text-gold-500">
            @for($i = 1; $i <= 5; $i++)<svg class="w-4 h-4 {{ $avgRating && $i > round($avgRating) ? 'text-ink-200' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.96a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.37 2.448a1 1 0 00-.363 1.118l1.287 3.96c.3.922-.755 1.688-1.54 1.118l-3.37-2.448a1 1 0 00-1.175 0l-3.37 2.448c-.784.57-1.838-.196-1.539-1.118l1.287-3.96a1 1 0 00-.363-1.118L2.05 9.387c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.96z"/></svg>@endfor
        </div>
        <span class="text-ink-700/50 group-hover:text-gold-700">{{ $reviewCount ? $avgRating.' · '.$reviewCount.' review'.($reviewCount > 1 ? 's' : '') : 'No reviews yet — be the first' }}</span>
    </a>

    {{-- Love / heart reaction --}}
    <x-love-button :product="$product" :loved="$loved ?? false" :count="$lovesCount ?? $product->loves_count" />

    <div class="mt-4 flex items-baseline gap-3 flex-wrap">
        <span class="text-2xl font-semibold text-gold-700" x-text="priceText()"></span>
        @if($product->is_on_sale)
            <span class="text-ink-400 line-through">{{ money($product->compare_at_price) }}</span>
            <span class="badge bg-red-100 text-red-700">Save {{ $product->discount_percent }}%</span>
        @endif
        <template x-if="offerPercent > 0">
            <span class="badge bg-green-100 text-green-700">Bundle price · save <span x-text="fmt(savings)"></span></span>
        </template>
    </div>

    @if($product->short_description)
        <p class="mt-4 text-ink-700/80">{{ $product->short_description }}</p>
    @endif

    {{-- Quantity / bundle offers --}}
    @if(!empty($product->offerTiers()))
        <div class="mt-5 rounded-xl border border-gold-200 bg-gold-50/60 p-4">
            <p class="text-sm font-semibold text-ink-800 flex items-center gap-1.5">🎁 Buy more, save more</p>
            <div class="mt-3 grid gap-2">
                @foreach($product->offerTiers() as $tier)
                    <button type="button" @click="qty = Math.max(qty, {{ $tier['min_qty'] }})"
                        class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm transition"
                        :class="qty >= {{ $tier['min_qty'] }} ? 'border-green-500 bg-green-50' : 'border-ink-100 hover:border-gold-300 bg-white'">
                        <span>Buy <strong>{{ $tier['min_qty'] }}+</strong> &amp; get <strong>{{ rtrim(rtrim(number_format($tier['percent'],2),'0'),'.') }}% off</strong></span>
                        <span class="text-xs text-green-700 font-medium" x-show="qty >= {{ $tier['min_qty'] }}">✓ applied</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Active offers (auto-applied at checkout) --}}
    @php
        $pdpOffers = \App\Models\Offer::active()->where('show_on_pdp', true)->get();
    @endphp
    @if($pdpOffers->isNotEmpty())
        <div class="mt-5 rounded-xl border border-green-200 bg-green-50/70 p-4">
            <p class="text-sm font-semibold text-green-800 flex items-center gap-1.5">🎉 Offers on this order</p>
            <ul class="mt-2 space-y-1.5">
                @foreach($pdpOffers as $o)
                    <li class="flex items-start gap-2 text-sm text-ink-800">
                        <span class="text-green-600 mt-0.5">✓</span>
                        <span>
                            @if($o->badge_label)<span class="badge bg-green-600 text-white text-[10px] mr-1">{{ $o->badge_label }}</span>@endif
                            <strong>{{ $o->title }}</strong>@if($o->description) — <span class="text-ink-700/70">{{ $o->description }}</span>@endif
                            @if($o->members_only) @guest('customer') · <a href="{{ route('customer.register') }}" class="text-gold-700 underline">Register to unlock</a>@endguest @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Delivery estimate --}}
    @if(theme('show_delivery_estimate'))
        @php
            $dmin = (int) theme('delivery_days_min', 2);
            $dmax = (int) theme('delivery_days_max', 4);
            $from = now()->addDays($dmin);
            $to = now()->addDays($dmax);
        @endphp
        <div class="mt-4 flex items-center gap-2 text-sm text-ink-700/80">
            <svg class="w-5 h-5 text-gold-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-6"/></svg>
            <span>Order now — estimated delivery <strong>{{ $from->format('D, d M') }}{{ $dmax > $dmin ? ' – '.$to->format('d M') : '' }}</strong></span>
        </div>
    @endif

    @if($lowStock)
        <div class="mt-4 flex items-center gap-2 text-sm text-red-600 font-medium">
            <span class="relative flex h-2 w-2"><span class="animate-ping absolute h-2 w-2 rounded-full bg-red-400 opacity-75"></span><span class="relative rounded-full h-2 w-2 bg-red-500"></span></span>
            Hurry — only {{ $product->stock_quantity }} left in stock!
        </div>
    @endif

    {{-- Variant picker (one selector per attribute, e.g. Size, Colour) --}}
    @if($product->has_variants)
        <div class="mt-6 space-y-4">
            @foreach($product->options ?? [] as $attr)
                <div>
                    <span class="label">{{ $attr['name'] }}: <span class="text-ink-700/60 font-normal" x-text="selected[@js($attr['name'])] || 'Choose'"></span></span>
                    <div class="flex flex-wrap gap-2">
                        @foreach($attr['values'] as $val)
                            <button type="button" @click="selectAttr(@js($attr['name']), @js($val))"
                                class="rounded-md border px-4 py-2 text-sm transition"
                                :disabled="!valueInStock(@js($attr['name']), @js($val))"
                                :class="[
                                    isSelected(@js($attr['name']), @js($val)) ? 'border-gold-500 bg-gold-100 text-gold-800' : 'border-ink-100 hover:border-gold-300',
                                    !valueInStock(@js($attr['name']), @js($val)) ? 'opacity-40 line-through cursor-not-allowed' : ''
                                ]">
                                {{ $val }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <p x-show="!matched" class="text-xs text-ink-700/50">Select options to see price &amp; availability.</p>
            <p x-show="matched && variantStock <= 0" x-cloak class="text-xs text-red-600 font-medium">This combination is sold out.</p>
        </div>
    @endif

    {{-- Quantity --}}
    <div class="mt-6 flex items-center gap-4">
        <div class="inline-flex items-center rounded-md border border-ink-100">
            <button type="button" @click="dec()" class="px-3 py-2.5">−</button>
            <span class="w-10 text-center" x-text="qty"></span>
            <button type="button" @click="inc()" class="px-3 py-2.5">+</button>
        </div>
        <span class="text-sm text-ink-700/50">Cash on delivery available</span>
    </div>

    {{-- Pre-order banner --}}
    @if($product->isPreorder())
        @php
            // Estimated delivery for pre-bookings: 2 weeks from today, unless a manual
            // "Expected availability date" has been set on the product (manual wins).
            $preorderEta = $product->preorder_release_date ?: now()->addDays(14);
        @endphp
        <div class="mt-5 rounded-xl border border-violet-200 bg-violet-50 p-4">
            <p class="text-sm font-semibold text-violet-800 flex items-center gap-1.5">📅 Pre-order item</p>
            <p class="text-sm text-violet-700/80 mt-1">
                {{ $product->preorder_note ?: 'Reserve yours now — booked items ship within about 2 weeks.' }}
                <br>Estimated delivery: <strong>{{ $preorderEta->format('d M Y') }}</strong>.
            </p>
        </div>
    @endif

    {{-- Actions --}}
    @php($preorder = $product->isPreorder())
    @if($product->isAvailable() || $preorder)
        @php($bookLabel = $preorder ? 'Book now (Pre-order)' : 'Buy now')
        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <form action="{{ route('cart.add', $product) }}" method="POST" @submit.prevent="fireAddToCart(); $store.cart.add($event.target)">
                @csrf
                <input type="hidden" name="variant_id" :value="variant==='none' ? '' : variant">
                <input type="hidden" name="qty" :value="qty">
                <button type="submit" class="btn-outline w-full" :disabled="!canBuy">{{ $preorder ? 'Add pre-order to cart' : 'Add to cart' }}</button>
            </form>
            <form action="{{ route('cart.buynow', $product) }}" method="POST">
                @csrf
                <input type="hidden" name="variant_id" :value="variant==='none' ? '' : variant">
                <input type="hidden" name="qty" :value="qty">
                <button type="submit" class="w-full {{ $preorder ? 'inline-flex items-center justify-center rounded-md bg-violet-600 px-4 py-2.5 font-medium text-white hover:bg-violet-700 transition' : 'btn-primary' }}" :disabled="!canBuy">{{ $bookLabel }}</button>
            </form>
        </div>
        <p x-show="!canBuy" x-cloak class="mt-2 text-xs text-red-600">Please choose an option above.</p>
    @else
        <button disabled class="btn-dark w-full mt-5 opacity-60">Sold out</button>
    @endif

    {{-- Order / ask via WhatsApp --}}
    @if(theme('show_pdp_whatsapp') && ($waNum = theme('whatsapp_number')))
        @php($waMsg = rawurlencode('Hi! I\'m interested in "'.$product->name.'" — '.route('product.show', $product)))
        <a href="https://wa.me/{{ preg_replace('/\D/', '', $waNum) }}?text={{ $waMsg }}" target="_blank" rel="noopener"
           class="mt-3 flex items-center justify-center gap-2 rounded-md border border-[#25D366]/40 bg-[#25D366]/10 px-4 py-2.5 text-sm font-medium text-[#128C7E] hover:bg-[#25D366]/20 transition">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z"/></svg>
            Order or ask on WhatsApp
        </a>
    @endif

    {{-- Trust badges (editable in Admin → Appearance → Trust badges) --}}
    <x-trust-strip class="mt-6" />
</div>

@push('meta-events')
<script>track('ViewContent', {content_ids:['{{ $product->id }}'], content_name:@json($product->name), content_type:'product', value:{{ (float) $product->price }}, currency:'BDT'});</script>
@endpush
