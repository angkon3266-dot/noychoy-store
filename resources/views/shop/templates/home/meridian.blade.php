@extends('layouts.shop')
@section('title', home_content('seo_title') ?: 'Fine Jewelry')

@section('content')
@php
    $cats = $categories ?? collect();
    $features = collect(home_content('feature_strip') ?? [])->filter(fn ($f) => filled($f['title'] ?? null))->values();

    // Occasion tiles need a picture to work — a label on an empty box reads as
    // a broken image, so unfinished rows drop out and the section hides with
    // them. See config/home.php for why these particular occasions.
    $occasions = collect(home_content('occasions') ?? [])
        ->map(fn ($o) => [
            'label' => $o['label'] ?? '',
            'tagline' => $o['tagline'] ?? '',
            'link' => filled($o['link'] ?? null) ? $o['link'] : route('shop'),
            'image' => theme_asset($o['image'] ?? null),
        ])
        ->filter(fn ($o) => filled($o['label']) && filled($o['image']))
        ->values();

    // Budget bands → the shop's existing price filter.
    $budgets = collect(home_content('gift_budgets') ?? [])->map(function ($b) {
        $min = $b['min'] ?? null;
        $max = $b['max'] ?? null;
        return [
            'label' => $max === null ? money($min).'+' : ($min === null ? 'Under '.money($max) : money($min).' – '.money($max)),
            'url' => route('shop', array_filter(['price_min' => $min, 'price_max' => $max])),
        ];
    })->values();

    // Hero: admin slides win outright, else the hero image leads and featured
    // products follow. Same contract as the Couture template.
    $heroSlides = collect(home_content('hero_slides') ?? [])
        ->take(8)
        ->map(function ($s) {
            $video = filled($s['video'] ?? null) ? video_meta($s['video']) : null;
            $image = $video ? null : theme_asset($s['image'] ?? null);
            if (! $video && ! $image) {
                return null;
            }

            return [
                'type' => $video ? 'video' : 'image',
                'video' => $video,
                'image' => $image ?: ($video['thumb'] ?? null),
                'link' => filled($s['link'] ?? null) ? $s['link'] : null,
                'alt' => $s['alt'] ?? '',
            ];
        })->filter()->values();

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
<section class="relative bg-gold-50/70">
    <div class="mx-auto max-w-7xl grid lg:grid-cols-2 items-stretch">
        <div class="flex items-center px-6 sm:px-10 py-10 sm:py-16 lg:py-28 order-2 lg:order-1">
            <div class="max-w-lg">
                <p class="uppercase tracking-[0.35em] text-[11px] text-gold-700 mb-5">{{ home_content('hero_eyebrow') ?: 'Crafted to captivate' }}</p>
                <h1 class="font-display text-4xl sm:text-5xl lg:text-6xl leading-[1.05] text-ink-900">{!! home_content_heading('text-gold-700') !!}</h1>
                <p class="mt-6 text-ink-700/70 text-lg leading-relaxed">{{ home_content('hero_subtitle') }}</p>
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

        <div class="order-1 lg:order-2 relative min-h-[34vh] sm:min-h-[42vh] lg:min-h-[80vh] bg-gold-100 overflow-hidden group"
             @if($heroSlides->count() > 1)
                 x-data="heroSlider({{ $heroSlides->count() }})" x-init="start()"
                 @mouseenter="stop()" @mouseleave="start()"
                 @touchstart.passive="swipeStart($event)" @touchend.passive="swipeEnd($event)"
             @endif>
            @foreach($heroSlides as $k => $s)
                @php
                    $tag = $s['link'] ? 'a' : 'div';
                    $attrs = $s['link'] ? 'href="'.e($s['link']).'"' : '';
                    $v900 = $s['type'] === 'image' ? image_variant($s['image'], 900) : null;
                @endphp
                <{{ $tag }} {!! $attrs !!} aria-label="{{ $s['alt'] ?: 'View the collection' }}"
                   class="absolute inset-0 block transition-opacity duration-1000 ease-out"
                   @if($heroSlides->count() > 1)
                       x-bind:class="i === {{ $k }} ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                       x-bind:aria-hidden="i !== {{ $k }}"
                   @endif>
                    @if($s['type'] === 'video' && $s['video']['type'] === 'file')
                        <video src="{{ $s['video']['src'] }}" autoplay muted loop playsinline class="w-full h-full object-cover"></video>
                    @elseif($s['type'] === 'video')
                        @php
                            $embed = $s['video']['embed'];
                            $embed .= $s['video']['type'] === 'youtube'
                                ? (str_contains($embed, '?') ? '&' : '?').'autoplay=1&mute=1&loop=1&playlist='.\Illuminate\Support\Str::afterLast($embed, '/').'&controls=0&modestbranding=1&rel=0'
                                : (str_contains($embed, '?') ? '&' : '?').'autoplay=1&muted=1&loop=1&background=1';
                        @endphp
                        <iframe src="{{ $embed }}" title="{{ $s['alt'] }}" class="w-full h-full pointer-events-none" loading="lazy" allow="autoplay" tabindex="-1"></iframe>
                    @else
                        <img src="{{ $s['image'] }}" alt="{{ $s['alt'] }}"
                             @if($k > 0) loading="lazy" @else fetchpriority="high" @endif
                             @if($v900) srcset="{{ $v900 }} 900w, {{ $s['image'] }} 1600w" sizes="(min-width: 1024px) 50vw, 100vw" @endif
                             class="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-105">
                    @endif
                </{{ $tag }}>
            @endforeach

            <div class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent pointer-events-none"></div>

            @if($heroSlides->count() > 1)
                <button type="button" @click="go(i - 1)" aria-label="Previous"
                        class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/75 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">‹</button>
                <button type="button" @click="go(i + 1)" aria-label="Next"
                        class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/75 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">›</button>
                <div class="absolute bottom-1 inset-x-0 flex justify-center">
                    @foreach($heroSlides as $k => $s)
                        <button type="button" @click="go({{ $k }})" aria-label="Go to slide {{ $k + 1 }}" class="p-2.5 grid place-items-center">
                            <span class="h-1.5 rounded-full transition-all duration-300"
                                  x-bind:class="i === {{ $k }} ? 'bg-white w-5' : 'bg-white/55 w-1.5'"></span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

{{-- ── Reassurance strip ─────────────────────────────────────────────── --}}
@if(home_content('show_feature_strip') && $features->isNotEmpty())
<section class="border-y border-ink-100 bg-white">
    <div class="mx-auto max-w-7xl px-4 grid grid-cols-2 lg:grid-cols-4 divide-x divide-ink-100">
        @foreach($features as $f)
            <div class="flex flex-col items-center text-center gap-1.5 py-7 px-3">
                <span class="mx-auto flex w-fit">{!! \App\Support\StorefrontIcons::svg($f['icon'] ?? null, 'w-6 h-6') !!}</span>
                <span class="text-[11px] sm:text-xs tracking-wide uppercase text-ink-800 font-medium">{{ $f['title'] }}</span>
                @if(filled($f['text'] ?? null))<span class="text-[11px] text-ink-700/50">{{ $f['text'] }}</span>@endif
            </div>
        @endforeach
    </div>
</section>
@endif

{{-- ── Shop by occasion ──────────────────────────────────────────────── --}}
@if(home_content('show_occasions') && $occasions->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 py-16 lg:py-20">
    <div class="text-center max-w-2xl mx-auto mb-10">
        <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">For every moment</p>
        <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('occasions_title') }}</h2>
        @if(home_content('occasions_subtitle'))
            <p class="mt-3 text-ink-700/60">{{ home_content('occasions_subtitle') }}</p>
        @endif
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 lg:gap-5">
        @foreach($occasions as $o)
            @php $oImg = image_variant($o['image'], 450); @endphp
            <a href="{{ $o['link'] }}" class="group block">
                <div class="relative aspect-[4/5] overflow-hidden rounded-2xl bg-gold-100">
                    <img src="{{ $o['image'] }}" alt="{{ $o['label'] }}" loading="lazy" width="450" height="562"
                         @if($oImg) srcset="{{ $oImg }} 450w, {{ $o['image'] }} 1200w" sizes="(min-width: 768px) 25vw, 50vw" @endif
                         class="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/65 via-black/15 to-transparent"></div>
                    <div class="absolute inset-x-0 bottom-0 p-4">
                        <h3 class="font-display text-lg text-white leading-tight">{{ $o['label'] }}</h3>
                        @if(filled($o['tagline']))<p class="text-[11px] text-white/75 mt-0.5 leading-snug">{{ $o['tagline'] }}</p>@endif
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</section>
@endif

{{-- ── Shop by category ──────────────────────────────────────────────── --}}
@if(home_content('show_categories') && $cats->isNotEmpty())
<section class="bg-gold-50/60 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4">
        <div class="flex items-end justify-between mb-10">
            <div>
                <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Shop by category</p>
                <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('categories_title') ?: 'Find your piece' }}</h2>
            </div>
            <a href="{{ route('shop') }}" class="hidden sm:inline-block text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">View all</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 lg:gap-6">
            @foreach($cats as $cat)
                @php $catImg = theme_asset($cat->image); @endphp
                <a href="{{ route('category.show', $cat) }}" class="group relative block overflow-hidden rounded-2xl bg-gold-100 aspect-[4/3]">
                    @if($catImg)
                        <img src="{{ $catImg }}" alt="{{ $cat->name }}" loading="lazy"
                             class="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105">
                    @endif
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent"></div>
                    <div class="absolute bottom-0 left-0 p-5">
                        <h3 class="font-display text-xl lg:text-2xl text-white">{{ $cat->name }}</h3>
                        @if(filled($cat->description))
                            <p class="text-white/75 text-xs mt-1 line-clamp-1">{{ \Illuminate\Support\Str::limit(strip_tags($cat->description), 42) }}</p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ── Best sellers ──────────────────────────────────────────────────── --}}
@if(home_content('show_best_selling') && $bestSellers->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 py-16 lg:py-20">
    <div class="flex items-end justify-between mb-10">
        <div>
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Most loved</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('best_selling_title') ?: 'Best sellers' }}</h2>
        </div>
        <a href="{{ route('best-sellers') }}" class="hidden sm:inline-block text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">View all</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-10">
        @foreach($bestSellers->take(8) as $product)<x-product-card :product="$product" />@endforeach
    </div>
</section>
@endif

{{-- ── Deals of the Day (live offers) ────────────────────────────────── --}}
<x-deals-carousel />

{{-- ── Gift finder: budget bands ─────────────────────────────────────── --}}
@if(home_content('show_gift_finder') && $budgets->isNotEmpty())
<section class="bg-ink-900 text-white py-14">
    <div class="mx-auto max-w-4xl px-4 text-center">
        <p class="uppercase tracking-[0.3em] text-[11px] text-gold-300 mb-3">Gift finder</p>
        <h2 class="font-display text-3xl sm:text-4xl">{{ home_content('gift_finder_title') }}</h2>
        <p class="mt-3 text-white/60">Pick a budget — we will show you everything that fits it.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            @foreach($budgets as $b)
                <a href="{{ $b['url'] }}" class="rounded-full border border-white/25 px-6 py-3 text-sm tracking-wide hover:bg-white hover:text-ink-900 transition">{{ $b['label'] }}</a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ── Why us ────────────────────────────────────────────────────────── --}}
@if(home_content('show_promise'))
@php $promiseImg = theme_asset(home_content('promise_image')) ?: $newArrivals->first()?->thumbnail; @endphp
<section class="mx-auto max-w-7xl px-4 py-16 lg:py-20">
    <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
        <div class="relative aspect-[5/4] rounded-2xl overflow-hidden bg-gold-100">
            @if($promiseImg)<img src="{{ $promiseImg }}" alt="" loading="lazy" class="w-full h-full object-cover">@endif
        </div>
        <div class="max-w-md">
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-3">{{ home_content('promise_eyebrow') }}</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900 leading-tight">{{ home_content('promise_title') }}</h2>
            <p class="mt-5 text-ink-700/70 leading-relaxed">{{ home_content('promise_text') }}</p>
            <div class="mt-8 grid grid-cols-3 gap-4 text-center">
                @for($b = 1; $b <= 3; $b++)
                    <div>
                        <div class="font-display text-lg text-gold-700">{{ home_content('badge'.$b.'_title') }}</div>
                        <p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge'.$b.'_text') }}</p>
                    </div>
                @endfor
            </div>
        </div>
    </div>
</section>
@endif

{{-- ── New arrivals ──────────────────────────────────────────────────── --}}
@if(home_content('show_new_arrivals') && $newArrivals->isNotEmpty())
<section class="bg-gold-50/60 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4">
        <div class="flex items-end justify-between mb-10">
            <div>
                <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Just in</p>
                <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('new_arrivals_title') }}</h2>
            </div>
            <a href="{{ route('shop') }}?sort=new" class="hidden sm:inline-block text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">See what's new</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-10">
            @foreach($newArrivals->take(8) as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </div>
</section>
@endif

{{-- ── Anything else the admin built (banners, FAQ, video, reviews…) ──── --}}
@foreach($sections as $block)
    <x-home-block :block="$block" />
@endforeach
@endsection
