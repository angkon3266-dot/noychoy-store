@props(['product'])

<a href="{{ route('product.show', $product) }}" class="group block">
    <div class="aspect-square overflow-hidden rounded-xl bg-gold-100 relative">
        @if($product->thumbnail)
            <img src="{{ $product->thumbnail }}" alt="{{ $product->name }}" loading="lazy"
                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
        @else
            <div class="flex h-full items-center justify-center text-gold-300">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M4.5 19.5h15a.75.75 0 00.75-.75V5.25a.75.75 0 00-.75-.75h-15a.75.75 0 00-.75.75v13.5c0 .414.336.75.75.75z"/></svg>
            </div>
        @endif
        @if($product->isPreorder())
            <span class="absolute top-2 left-2 badge bg-violet-600 text-white">Pre-order</span>
        @elseif($product->is_on_sale)
            <span class="absolute top-2 left-2 badge bg-red-600 text-white">-{{ $product->discount_percent }}%</span>
        @endif
        @if(! $product->isAvailable() && ! $product->isPreorder())
            <span class="absolute top-2 right-2 badge bg-ink-900/80 text-white">Sold out</span>
        @endif
    </div>
    <h3 class="mt-3 text-sm font-medium text-ink-800 line-clamp-2 group-hover:text-gold-700">{{ $product->name }}</h3>
    @php($cardRating = $product->average_rating)
    @if($cardRating)
        <div class="mt-1 flex items-center gap-1 text-xs">
            <span class="text-gold-500">{{ str_repeat('★', (int) round($cardRating)) }}<span class="text-ink-200">{{ str_repeat('★', 5 - (int) round($cardRating)) }}</span></span>
            <span class="text-ink-700/50">({{ $product->review_count }})</span>
        </div>
    @endif
    <div class="mt-1 flex items-center gap-2">
        <span class="font-semibold text-gold-700">{{ money($product->price) }}</span>
        @if($product->is_on_sale)
            <span class="text-xs text-ink-400 line-through">{{ money($product->compare_at_price) }}</span>
        @endif
    </div>
</a>
