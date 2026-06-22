{{-- Product image gallery. Expects Alpine `productPage` scope. --}}
@php $videos = $product->galleryVideos(); @endphp
<div x-data="{ zoom: false, vidEmbed: null, vidSrc: null,
               openVid(embed, src){ this.vidEmbed = embed || null; this.vidSrc = src || null; },
               closeVid(){ this.vidEmbed = null; this.vidSrc = null; } }">
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

    {{-- Lightbox (image) --}}
    <div x-show="zoom" x-cloak @click="zoom=false" @keydown.escape.window="zoom=false"
         class="fixed inset-0 z-[80] bg-black/80 flex items-center justify-center p-4" x-transition.opacity style="display:none">
        <img :src="img" alt="{{ $product->name }}" class="max-h-[90vh] max-w-[90vw] rounded-lg object-contain">
        <button @click="zoom=false" class="absolute top-4 right-4 text-white text-3xl leading-none">&times;</button>
    </div>

    @if($product->images->count() > 1 || !empty($videos))
        <div class="mt-4 grid grid-cols-5 gap-3">
            @foreach($product->images as $image)
                <button @click="img='{{ $image->url }}'" class="aspect-square overflow-hidden rounded-lg bg-gold-100 ring-2 ring-transparent" :class="img==='{{ $image->url }}' && 'ring-gold-500'">
                    <img src="{{ $image->url }}" alt="" class="h-full w-full object-cover">
                </button>
            @endforeach

            {{-- Video thumbnails --}}
            @foreach($videos as $v)
                <button type="button" @click="openVid(@js($v['embed']), @js($v['src']))"
                        class="relative aspect-square overflow-hidden rounded-lg bg-ink-900 ring-2 ring-transparent hover:ring-gold-500">
                    @if($v['thumb'])
                        <img src="{{ $v['thumb'] }}" alt="" class="h-full w-full object-cover opacity-80">
                    @endif
                    <span class="absolute inset-0 grid place-items-center">
                        <span class="bg-white/90 rounded-full w-8 h-8 grid place-items-center">
                            <svg class="w-4 h-4 text-ink-900" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        </span>
                    </span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Lightbox (video) --}}
    <div x-show="vidEmbed || vidSrc" x-cloak @keydown.escape.window="closeVid()"
         class="fixed inset-0 z-[80] bg-black/85 flex items-center justify-center p-4" x-transition.opacity style="display:none">
        <div class="w-full max-w-3xl aspect-video bg-black rounded-lg overflow-hidden" @click.outside="closeVid()">
            <template x-if="vidEmbed">
                <iframe :src="vidEmbed + '?autoplay=1'" class="w-full h-full" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
            </template>
            <template x-if="vidSrc">
                <video :src="vidSrc" controls autoplay class="w-full h-full object-contain bg-black"></video>
            </template>
        </div>
        <button @click="closeVid()" class="absolute top-4 right-4 text-white text-3xl leading-none">&times;</button>
    </div>
</div>
