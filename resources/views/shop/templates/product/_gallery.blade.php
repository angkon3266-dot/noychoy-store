{{-- Product image gallery. Expects Alpine `productPage` scope. --}}
<div x-data="{ zoom: false }">
    <div class="aspect-square overflow-hidden rounded-2xl bg-gold-100 group relative">
        @if($product->images->isNotEmpty())
            <img :src="img" alt="{{ $product->name }}" class="h-full w-full object-cover cursor-zoom-in transition duration-500 group-hover:scale-105" @click="zoom = true">
            <span class="absolute bottom-3 right-3 rounded-full bg-white/80 p-2 text-ink-700 opacity-0 group-hover:opacity-100 transition pointer-events-none">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6"/></svg>
            </span>
        @else
            <div class="flex h-full items-center justify-center text-gold-300">No image</div>
        @endif
    </div>

    {{-- Lightbox --}}
    <div x-show="zoom" x-cloak @click="zoom=false" @keydown.escape.window="zoom=false"
         class="fixed inset-0 z-[80] bg-black/80 flex items-center justify-center p-4" x-transition.opacity style="display:none">
        <img :src="img" alt="{{ $product->name }}" class="max-h-[90vh] max-w-[90vw] rounded-lg object-contain">
        <button @click="zoom=false" class="absolute top-4 right-4 text-white text-3xl leading-none">&times;</button>
    </div>
    @if($product->images->count() > 1)
        <div class="mt-4 grid grid-cols-5 gap-3">
            @foreach($product->images as $image)
                <button @click="img='{{ $image->url }}'" class="aspect-square overflow-hidden rounded-lg bg-gold-100 ring-2 ring-transparent" :class="img==='{{ $image->url }}' && 'ring-gold-500'">
                    <img src="{{ $image->url }}" alt="" class="h-full w-full object-cover">
                </button>
            @endforeach
        </div>
    @endif
</div>
