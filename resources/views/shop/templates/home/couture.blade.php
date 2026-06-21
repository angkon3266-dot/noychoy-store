@extends('layouts.shop')
@section('title', config('store.name').' — Fine Jewelry')

@section('content')
@php
    $heroImg = theme_asset(home_content('hero_image'));
    $cats = $categories ?? collect();
@endphp

{{-- ── Hero: editorial split ─────────────────────────────────────────── --}}
<section class="relative">
    <div class="mx-auto max-w-7xl grid lg:grid-cols-2 items-stretch">
        <div class="flex items-center px-6 sm:px-10 py-16 lg:py-28 order-2 lg:order-1">
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
        <div class="order-1 lg:order-2 relative min-h-[42vh] lg:min-h-[80vh] bg-gold-100 overflow-hidden">
            @if($heroImg)
                <img src="{{ $heroImg }}" alt="" class="absolute inset-0 w-full h-full object-cover">
            @elseif($featured->first()?->thumbnail)
                <img src="{{ $featured->first()->thumbnail }}" alt="" class="absolute inset-0 w-full h-full object-cover">
            @endif
            <div class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent"></div>
        </div>
    </div>
</section>

{{-- ── Category lookbook ─────────────────────────────────────────────── --}}
@if($cats->isNotEmpty())
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

{{-- ── Editorial brand band ──────────────────────────────────────────── --}}
<section class="mx-auto max-w-7xl px-4 py-16 lg:py-24">
    <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
        <div class="relative aspect-[5/4] rounded-2xl overflow-hidden bg-gold-100">
            @if($newArrivals->first()?->thumbnail)<img src="{{ $newArrivals->first()->thumbnail }}" alt="" class="w-full h-full object-cover">@endif
        </div>
        <div class="max-w-md">
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-3">Our promise</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900 leading-tight">Crafted to be treasured</h2>
            <p class="mt-5 text-ink-700/70 leading-relaxed">{{ home_content('hero_subtitle') ?: 'Every piece is quality-checked and finished by hand. Pay on delivery, return with ease, and wear with confidence.' }}</p>
            <div class="mt-8 grid grid-cols-3 gap-4 text-center">
                <div><div class="font-display text-lg text-gold-700">{{ home_content('badge1_title') ?: 'COD' }}</div><p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge1_text') ?: 'Pay on delivery' }}</p></div>
                <div><div class="font-display text-lg text-gold-700">{{ home_content('badge2_title') ?: 'Fast' }}</div><p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge2_text') ?: 'Nationwide' }}</p></div>
                <div><div class="font-display text-lg text-gold-700">{{ home_content('badge3_title') ?: 'Quality' }}</div><p class="text-xs text-ink-700/60 mt-1">{{ home_content('badge3_text') ?: 'Hand-finished' }}</p></div>
            </div>
        </div>
    </div>
</section>

{{-- ── New arrivals ──────────────────────────────────────────────────── --}}
@if($newArrivals->isNotEmpty())
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
@endsection
