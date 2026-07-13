@props(['product'])

@php
    $available = $product->isAvailable();
    $preorder = $product->isPreorder();
    $variable = $product->has_variants;
    // Member pricing: logged-in customers see the discounted price.
    $memberPct = (is_member() && member_pricing()->enabled()) ? member_pricing()->percentForProduct($product) : 0;
    $memberPrice = $memberPct > 0 ? member_pricing()->memberPrice($product) : null;
@endphp

<div class="group relative block">
    <a href="{{ route('product.show', $product) }}" class="block">
        <div class="aspect-square overflow-hidden rounded-xl bg-gold-100 relative">
            @if($product->thumbnail)
                <img src="{{ $product->thumbnail }}" alt="{{ $product->name }}" loading="lazy"
                     class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
            @else
                <div class="flex h-full items-center justify-center text-gold-300">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M4.5 19.5h15a.75.75 0 00.75-.75V5.25a.75.75 0 00-.75-.75h-15a.75.75 0 00-.75.75v13.5c0 .414.336.75.75.75z"/></svg>
                </div>
            @endif
            @if($preorder)
                <span class="absolute top-2 left-2 badge bg-violet-600 text-white">Pre-order</span>
            @elseif($product->is_on_sale)
                <span class="absolute top-2 left-2 badge bg-red-600 text-white">-{{ $product->discount_percent }}%</span>
            @endif
            @if(! $available && ! $preorder)
                <span class="absolute top-2 right-2 badge bg-ink-900/80 text-white">Sold out</span>
            @endif
        </div>
        <h3 class="mt-3 text-sm font-medium text-ink-800 line-clamp-2 group-hover:text-gold-700">{{ $product->name }}</h3>
        @php $cardRating = $product->average_rating; @endphp
        @if($cardRating)
            <div class="mt-1 flex items-center gap-1 text-xs">
                <span class="text-gold-500">{{ str_repeat('★', (int) round($cardRating)) }}<span class="text-ink-200">{{ str_repeat('★', 5 - (int) round($cardRating)) }}</span></span>
                <span class="text-ink-700/50">({{ $product->review_count }})</span>
            </div>
        @endif
        <div class="mt-1 flex items-center gap-2 flex-wrap">
            @if($memberPrice !== null)
                <span class="font-semibold text-gold-700">{{ $variable ? 'From ' : '' }}{{ money($memberPrice) }}</span>
                <span class="text-xs text-ink-400 line-through">{{ money($product->price) }}</span>
                <span class="badge bg-gold-600 text-white text-[10px]">Member −{{ rtrim(rtrim(number_format($memberPct,1),'0'),'.') }}%</span>
            @else
                <span class="font-semibold text-gold-700">{{ $variable ? 'From ' : '' }}{{ money($product->price) }}</span>
                @if($product->is_on_sale)
                    <span class="text-xs text-ink-400 line-through">{{ money($product->compare_at_price) }}</span>
                @endif
            @endif
        </div>
    </a>

    {{-- Quick actions: always visible on touch, reveal on hover on desktop. --}}
    <div class="mt-2 transition duration-200 ease-out
                md:opacity-0 md:translate-y-1 md:pointer-events-none
                md:group-hover:opacity-100 md:group-hover:translate-y-0 md:group-hover:pointer-events-auto">
        @if(! $available && ! $preorder)
            <button type="button" disabled class="w-full rounded-full border border-ink-100 bg-ink-50 px-3 py-2 text-xs font-medium text-ink-400 cursor-not-allowed">Sold out</button>
        @elseif($variable)
            {{-- Variations require choosing options → go to the product page. --}}
            <a href="{{ route('product.show', $product) }}"
               class="flex w-full items-center justify-center gap-1.5 rounded-full border border-ink-200 px-3 py-2 text-xs font-medium text-ink-800 hover:border-gold-400 hover:text-gold-700 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 12h9.75m-9.75 6h9.75M3.75 6h.007v.008H3.75V6zm.375 6a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 6h.007v.008H3.75V18z"/></svg>
                Select options
            </a>
        @else
            <div class="flex gap-1.5" x-data>
                <form action="{{ route('cart.add', $product) }}" method="POST" class="flex-1"
                      @submit.prevent="$store.cart.add($event.target)">
                    @csrf
                    <input type="hidden" name="variant_id" value="">
                    <input type="hidden" name="qty" value="1">
                    <button type="submit" class="flex w-full items-center justify-center gap-1.5 rounded-full border border-ink-200 px-3 py-2 text-xs font-medium text-ink-800 hover:border-gold-400 hover:text-gold-700 transition" title="Add to cart">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/></svg>
                        <span class="hidden sm:inline">Add</span>
                    </button>
                </form>
                <form action="{{ route('cart.buynow', $product) }}" method="POST" class="flex-1">
                    @csrf
                    <input type="hidden" name="variant_id" value="">
                    <input type="hidden" name="qty" value="1">
                    <button type="submit" class="w-full rounded-full bg-gold-700 px-3 py-2 text-xs font-medium text-white hover:bg-gold-800 transition">
                        {{ $preorder ? 'Book now' : 'Buy now' }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
