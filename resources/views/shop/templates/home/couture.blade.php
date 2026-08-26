@extends('layouts.shop')
@section('title', home_content('seo_title'))

@section('content')
@php
    $cats = $categories ?? collect();

    // Hero slideshow. Admin → Appearance → "Hero slider" gives full control —
    // any mix of images and video, in whatever order they were added — and as
    // soon as one slide exists there, it replaces the auto-built list entirely.
    // Nothing added yet: the uploaded hero image leads (editorial first
    // impression, links to the shop), then up to 5 featured products follow,
    // each linking to its own page, so the hero is shoppable rather than
    // decorative. Capped because later slides are almost never reached and
    // every extra one is another asset competing for the first paint.
    $heroSlides = collect(home_content('hero_slides') ?? [])
        ->take(8)
        ->map(function ($s) {
            $video = filled($s['video'] ?? null) ? video_meta($s['video']) : null;
            $image = $video ? null : theme_asset($s['image'] ?? null);
            if (! $video && ! $image) {
                return null; // malformed row — nothing to show
            }

            return [
                'type' => $video ? 'video' : 'image',
                'video' => $video,
                'image' => $image ?: ($video['thumb'] ?? null),
                'link' => filled($s['link'] ?? null) ? $s['link'] : null,
                'alt' => $s['alt'] ?? '',
            ];
        })
        ->filter()->values();

    if ($heroSlides->isEmpty()) {
        if ($heroImg = theme_asset(home_content('hero_image'))) {
            $heroSlides->push(['type' => 'image', 'video' => null, 'image' => $heroImg, 'link' => home_content('hero_cta_link') ?: route('shop'), 'alt' => '']);
        }
        foreach ($featured->take(5) as $p) {
            if ($p->thumbnail) {
                $heroSlides->push(['type' => 'image', 'video' => null, 'image' => $p->thumbnail, 'link' => route('product.show', $p), 'alt' => $p->name]);
            }
        }
    }
@endphp

{{-- ── Hero: editorial split ─────────────────────────────────────────── --}}
<section class="relative">
    <div class="mx-auto max-w-7xl grid lg:grid-cols-2 items-stretch">
        {{-- py-10 on phones: with the image stacked above this block, generous
             padding pushed "Shop the collection" off the first screen entirely
             on smaller phones. The CTA must land inside the opening viewport. --}}
        <div class="flex items-center px-6 sm:px-10 py-10 sm:py-16 lg:py-28 order-2 lg:order-1">
            <div class="max-w-lg">
                <p class="uppercase tracking-[0.35em] text-[11px] text-gold-700 mb-5">{{ home_content('hero_eyebrow') ?: 'Handcrafted in Bangladesh' }}</p>
                <h1 class="font-display text-4xl sm:text-5xl lg:text-6xl leading-[1.05] text-ink-900">{!! home_content_heading('text-gold-700') !!}</h1>
                <p class="mt-6 text-ink-700/70 text-lg leading-relaxed">{{ home_content('hero_subtitle') ?: 'Timeless pieces, made to be worn every day and remembered forever.' }}</p>
                <div class="mt-9 flex flex-wrap gap-4">
                    <a href="{{ home_content('hero_cta_link') ?: route('shop') }}" class="inline-flex items-center gap-2 rounded-full bg-ink-900 text-white px-7 py-3.5 text-sm tracking-wide hover:bg-ink-800 transition">
                        {{ home_content('hero_cta_text') ?: 'Shop the collection' }}
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6"/></svg>
                    </a>
                    @if(home_content('hero_secondary_text'))
                        <a href="{{ home_content('hero_secondary_link') ?: route('track') }}" class="inline-flex items-center px-6 py-3.5 text-sm tracking-wide border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition">{{ home_content('hero_secondary_text') }}</a>
                    @endif
                </div>
                <div class="mt-10 flex items-center gap-6 text-xs text-ink-700/50">
                    <span>★★★★★ Loved by customers</span><span>·</span><span>Cash on delivery</span><span>·</span><span>Nationwide</span>
                </div>
            </div>
        </div>
        {{-- Every utility below already exists elsewhere in the bundle, so this
             slideshow adds no CSS weight and no new JS beyond the Alpine that
             ships with the page anyway. --}}
        <div class="order-1 lg:order-2 relative min-h-[34vh] sm:min-h-[42vh] lg:min-h-[80vh] bg-gold-100 overflow-hidden group"
             @if($heroSlides->count() > 1)
                 x-data="heroSlider({{ $heroSlides->count() }})" x-init="start()"
                 @mouseenter="stop()" @mouseleave="start()"
                 @touchstart.passive="swipeStart($event)" @touchend.passive="swipeEnd($event)"
             @endif>
            @foreach($heroSlides as $k => $s)
                @php
                    // A video with nowhere to send you plays inline instead of
                    // being wrapped in a link — nothing to click through to.
                    $tag = $s['link'] ? 'a' : 'div';
                    $attrs = $s['link'] ? 'href="'.e($s['link']).'"' : '';
                @endphp
                {{-- Stacked and cross-faded rather than translated: the panel is a
                     fixed frame here, so a slide-in would show the page behind it. --}}
                <{{ $tag }} {!! $attrs !!} aria-label="{{ $s['alt'] ?: 'View the collection' }}"
                   class="absolute inset-0 block transition-opacity duration-1000 ease-out"
                   @if($heroSlides->count() > 1)
                       x-bind:class="i === {{ $k }} ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                       x-bind:aria-hidden="i !== {{ $k }}"
                   @endif>
                    @if($s['type'] === 'video' && $s['video']['type'] === 'file')
                        {{-- Self-hosted upload: an ambient loop, same as a still image's
                             screen time — no controls, no sound, nothing to wait on. --}}
                        <video src="{{ $s['video']['src'] }}" autoplay muted loop playsinline
                               class="w-full h-full object-cover"></video>
                    @elseif($s['type'] === 'video')
                        @php
                            $embed = $s['video']['embed'];
                            $embed .= $s['video']['type'] === 'youtube'
                                ? (str_contains($embed, '?') ? '&' : '?').'autoplay=1&mute=1&loop=1&playlist='.\Illuminate\Support\Str::afterLast($embed, '/').'&controls=0&modestbranding=1&rel=0'
                                : (str_contains($embed, '?') ? '&' : '?').'autoplay=1&muted=1&loop=1&background=1';
                        @endphp
                        <iframe src="{{ $embed }}" title="{{ $s['alt'] }}" class="w-full h-full pointer-events-none"
                                loading="lazy" allow="autoplay" tabindex="-1"></iframe>
                    @else
                        {{-- First slide is the page's LCP element: fetch it at
                             high priority; later slides stay lazy. The 900w
                             variant serves the ~630px panel; originals can be
                             1600px, which phones should never download. --}}
                        @php $v900 = image_variant($s['image'], 900); @endphp
                        <img src="{{ $s['image'] }}" alt="{{ $s['alt'] }}"
                             @if($k > 0) loading="lazy" @else fetchpriority="high" @endif
                             @if($v900) srcset="{{ $v900 }} 900w, {{ $s['image'] }} 1600w" sizes="(min-width: 1024px) 50vw, 100vw" @endif
                             class="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-105">
                    @endif
                </{{ $tag }}>
            @endforeach

            <div class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent pointer-events-none"></div>

            @if($heroSlides->count() > 1)
                {{-- Arrows only on hover, so the panel still reads as an editorial
                     photograph when nobody is interacting with it. --}}
                <button type="button" @click="go(i - 1)" aria-label="Previous"
                        class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/75 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">‹</button>
                <button type="button" @click="go(i + 1)" aria-label="Next"
                        class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/75 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">›</button>

                {{-- The dot is 6px of paint but the button is a ~44px target —
                     a bare 6px control is untappable on touch, where these are
                     the only visible way to change slides. --}}
                <div class="absolute bottom-1 inset-x-0 flex justify-center">
                    @foreach($heroSlides as $k => $s)
                        <button type="button" @click="go({{ $k }})" aria-label="Go to slide {{ $k + 1 }}"
                                class="p-2.5 grid place-items-center">
                            <span class="h-1.5 rounded-full transition-all duration-300"
                                  x-bind:class="i === {{ $k }} ? 'bg-white w-5' : 'bg-white/55 w-1.5'"></span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

{{-- ── Deals of the Day ──────────────────────────────────────────────── --}}
{{-- Straight after the hero: a deal nobody scrolls to is not a deal. Renders
     nothing at all when no offers are live, so the page simply closes up. --}}
<x-deals-carousel />

{{-- ── Category lookbook ─────────────────────────────────────────────── --}}
@if(home_content('show_categories') && $cats->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 py-16 lg:py-24">
    <div class="flex items-end justify-between mb-10">
        <div>
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Explore</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('categories_title') ?: 'Shop by category' }}</h2>
        </div>
        <a href="{{ route('shop') }}" class="hidden sm:inline-block text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">View all</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 lg:gap-6">
        @foreach($cats as $i => $cat)
            <a href="{{ route('category.show', $cat) }}" class="group relative block overflow-hidden rounded-2xl bg-gold-100 {{ $i === 0 ? 'md:col-span-2 md:row-span-2 aspect-[4/3] md:aspect-auto' : 'aspect-square' }}">
                @if($cat->image)
                    <img src="{{ \Illuminate\Support\Str::startsWith($cat->image, 'http') ? $cat->image : \Illuminate\Support\Facades\Storage::disk('public')->url($cat->image) }}" alt="{{ $cat->name }}" class="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105">
                @endif
                <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-transparent"></div>
                <div class="absolute bottom-0 left-0 p-5">
                    <h3 class="font-display text-xl lg:text-2xl text-white">{{ $cat->name }}</h3>
                    <span class="text-white/80 text-xs tracking-wide inline-flex items-center gap-1 mt-1">Discover <svg class="w-3.5 h-3.5 transition group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6"/></svg></span>
                </div>
            </a>
        @endforeach
    </div>
</section>
@endif

{{-- ── Featured collection ───────────────────────────────────────────── --}}
@if($featured->isNotEmpty())
<section class="bg-gold-50/60 py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4">
        <div class="text-center max-w-2xl mx-auto mb-12">
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Curated</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('featured_title') ?: 'The Signature Edit' }}</h2>
            <p class="mt-3 text-ink-700/60">Our most-loved pieces, chosen for the season.</p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-10">
            @foreach($featured as $product)<x-product-card :product="$product" />@endforeach
        </div>
        <div class="text-center mt-12">
            <a href="{{ route('shop') }}" class="inline-flex items-center gap-2 rounded-full border border-ink-900/20 px-8 py-3.5 text-sm tracking-wide hover:bg-ink-900 hover:text-white transition">Shop all jewelry</a>
        </div>
    </div>
</section>
@endif

{{-- ── Editorial brand band ("Our promise") ──────────────────────────── --}}
@if(home_content('show_promise'))
@php $promiseImg = theme_asset(home_content('promise_image')); @endphp
<section class="mx-auto max-w-7xl px-4 py-16 lg:py-24">
    <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
        <div class="relative aspect-[5/4] rounded-2xl overflow-hidden bg-gold-100">
            @if($promiseImg)<img src="{{ $promiseImg }}" alt="" class="w-full h-full object-cover">
            @elseif($newArrivals->first()?->thumbnail)<img src="{{ $newArrivals->first()->thumbnail }}" alt="" class="w-full h-full object-cover">@endif
        </div>
        <div class="max-w-md">
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-3">{{ home_content('promise_eyebrow') ?: 'Our promise' }}</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900 leading-tight">{{ home_content('promise_title') ?: 'Crafted to be treasured' }}</h2>
            {{-- home_content() falls back to the config default; the old extra
                 fallback to hero_subtitle made the homepage repeat itself. --}}
            <p class="mt-5 text-ink-700/70 leading-relaxed">{{ home_content('promise_text') }}</p>
            <div class="mt-8 grid grid-cols-3 gap-4 text-center">
                <div><div class="font-display text-lg text-gold-700">{{ home_content('badge1_title') ?: 'COD' }}</div><p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge1_text') ?: 'Pay on delivery' }}</p></div>
                <div><div class="font-display text-lg text-gold-700">{{ home_content('badge2_title') ?: 'Fast' }}</div><p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge2_text') ?: 'Nationwide' }}</p></div>
                <div><div class="font-display text-lg text-gold-700">{{ home_content('badge3_title') ?: 'Quality' }}</div><p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge3_text') ?: 'Hand-finished' }}</p></div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- ── New arrivals ──────────────────────────────────────────────────── --}}
@if(home_content('show_new_arrivals') && $newArrivals->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 pb-20">
    <div class="flex items-end justify-between mb-10">
        <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('new_arrivals_title') ?: 'New Arrivals' }}</h2>
        <a href="{{ route('shop') }}?sort=" class="text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">See what's new</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-10">
        @foreach($newArrivals as $product)<x-product-card :product="$product" />@endforeach
    </div>
</section>
@endif
{{-- Custom Section Builder blocks (universal — added below this design) --}}
@include('shop._builder-sections')
@endsection
