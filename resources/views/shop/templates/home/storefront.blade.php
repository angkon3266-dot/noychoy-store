@extends('layouts.shop')
@section('title', \App\Models\Setting::get('store_name', config('store.name')).' — Online Store')

@section('content')
@php
    $slides = collect(home_content('hero_slides') ?? [])->filter(fn ($s) => filled($s['image'] ?? null))->values();
    if ($slides->isEmpty() && ($hi = theme_asset(home_content('hero_image')))) {
        $slides = collect([['image' => $hi, 'link' => home_content('hero_cta_link') ?: route('shop')]]);
    }
    $features = collect(home_content('feature_strip') ?? [])->filter(fn ($f) => filled($f['title'] ?? null))->values();
@endphp

{{-- ── Hero slider ───────────────────────────────────────────────────── --}}
@if($slides->isNotEmpty())
<section x-data="{ i: 0, n: {{ $slides->count() }}, t: null,
                   start(){ if(this.n>1){ this.t = setInterval(()=>this.i=(this.i+1)%this.n, 5500) } },
                   go(k){ this.i=(k+this.n)%this.n } }"
         x-init="start()" @mouseenter="clearInterval(t)" @mouseleave="start()"
         class="relative overflow-hidden">
    <div class="flex transition-transform duration-700 ease-out" :style="`transform: translateX(-${i*100}%)`">
        @foreach($slides as $s)
            <a href="{{ $s['link'] ?? route('shop') }}" class="block w-full shrink-0">
                <img src="{{ \Illuminate\Support\Str::startsWith($s['image'],['http','/']) ? $s['image'] : \Illuminate\Support\Facades\Storage::disk('public')->url($s['image']) }}"
                     alt="{{ $s['alt'] ?? '' }}" class="w-full h-[42vw] max-h-[640px] min-h-[220px] object-cover">
            </a>
        @endforeach
    </div>
    @if($slides->count() > 1)
        <button @click="go(i-1)" class="absolute left-3 top-1/2 -translate-y-1/2 bg-white/70 hover:bg-white rounded-full w-10 h-10 grid place-items-center shadow">‹</button>
        <button @click="go(i+1)" class="absolute right-3 top-1/2 -translate-y-1/2 bg-white/70 hover:bg-white rounded-full w-10 h-10 grid place-items-center shadow">›</button>
        <div class="absolute bottom-4 inset-x-0 flex justify-center gap-2">
            @foreach($slides as $k => $s)
                <button @click="i={{ $k }}" class="w-2.5 h-2.5 rounded-full transition" :class="i==={{ $k }} ? 'bg-gold-600' : 'bg-white/70'"></button>
            @endforeach
        </div>
    @endif
</section>
@endif

{{-- ── Feature strip ─────────────────────────────────────────────────── --}}
@if(home_content('show_feature_strip') && $features->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 py-8">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($features as $f)
            <div class="flex flex-col items-center text-center gap-2 rounded-xl border border-ink-100 py-6 px-3">
                <span class="text-2xl">{{ $f['icon'] ?? '✓' }}</span>
                <span class="text-xs sm:text-sm tracking-wide uppercase text-ink-700/70">{{ $f['title'] }}</span>
            </div>
        @endforeach
    </div>
</section>
@endif

{{-- ── Custom section blocks (page builder) take over the middle when present ── --}}
@if(($sections ?? collect())->isNotEmpty())
    @foreach($sections as $block)
        <x-home-block :block="$block" />
    @endforeach
@else

{{-- ── Browse categories (scroller) ──────────────────────────────────── --}}
@if(home_content('show_categories') && $categories->isNotEmpty())
<x-section-heading :title="home_content('categories_title') ?: 'Browse Our Categories'" />
<x-carousel class="mx-auto max-w-7xl px-4 pb-12">
    @foreach($categories as $cat)
        <a href="{{ route('category.show', $cat) }}" class="snap-start shrink-0 w-64 sm:w-72 group">
            <div class="relative aspect-[4/3] overflow-hidden rounded-xl bg-gold-100">
                @if($cat->image)
                    <img src="{{ \Illuminate\Support\Str::startsWith($cat->image,'http') ? $cat->image : \Illuminate\Support\Facades\Storage::disk('public')->url($cat->image) }}" alt="{{ $cat->name }}" class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                @endif
                <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
                <div class="absolute bottom-3 left-1/2 -translate-x-1/2 bg-white/90 rounded-md px-4 py-1.5 text-sm font-medium tracking-wide uppercase whitespace-nowrap">{{ $cat->name }}</div>
            </div>
        </a>
    @endforeach
</x-carousel>
@endif

{{-- ── Best selling ──────────────────────────────────────────────────── --}}
@if(home_content('show_best_selling') && $bestSellers->isNotEmpty())
<x-section-heading :title="home_content('best_selling_title') ?: 'Best Selling Products'" :link="route('shop').'?sort=popular'" linkLabel="View All" />
<x-carousel class="mx-auto max-w-7xl px-4 pb-12">
    @foreach($bestSellers as $product)
        <div class="snap-start shrink-0 w-52 sm:w-60"><x-product-card :product="$product" /></div>
    @endforeach
</x-carousel>
@endif

{{-- ── New arrivals ──────────────────────────────────────────────────── --}}
@if(home_content('show_new_arrivals') && $newArrivals->isNotEmpty())
<x-section-heading :title="home_content('new_arrivals_title') ?: 'New Arrival Products'" :link="route('shop')" linkLabel="See All" />
<x-carousel class="mx-auto max-w-7xl px-4 pb-12">
    @foreach($newArrivals as $product)
        <div class="snap-start shrink-0 w-52 sm:w-60"><x-product-card :product="$product" /></div>
    @endforeach
</x-carousel>
@endif

{{-- ── Highlighted categories (editorial tiles) ──────────────────────── --}}
@if(home_content('show_highlights') && $highlightCategories->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 pb-12 grid gap-4 md:grid-cols-2">
    @foreach($highlightCategories as $cat)
        <a href="{{ route('category.show', $cat) }}" class="group relative block overflow-hidden rounded-2xl bg-gold-100 aspect-[16/7]">
            @if($cat->image)
                <img src="{{ \Illuminate\Support\Str::startsWith($cat->image,'http') ? $cat->image : \Illuminate\Support\Facades\Storage::disk('public')->url($cat->image) }}" alt="{{ $cat->name }}" class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
            @endif
            <div class="absolute inset-0 bg-gradient-to-r from-black/55 to-transparent"></div>
            <div class="absolute inset-0 flex flex-col justify-center p-8 max-w-sm">
                <h3 class="font-display text-2xl sm:text-3xl text-white">{{ $cat->name }}</h3>
                <span class="mt-3 inline-flex items-center gap-2 text-white text-sm tracking-wide uppercase">Explore now
                    <svg class="w-4 h-4 transition group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6"/></svg>
                </span>
            </div>
        </a>
    @endforeach
</section>
@endif

{{-- ── Video sections ────────────────────────────────────────────────── --}}
@if(home_content('show_videos') && $homeVideos->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 pb-16 grid gap-6 md:grid-cols-2">
    @foreach($homeVideos as $v)
        <div>
            @if($v['title'])<h3 class="text-sm font-semibold uppercase tracking-wide text-ink-700/70 mb-3">{{ $v['title'] }}</h3>@endif
            <div class="aspect-video overflow-hidden rounded-xl bg-ink-900">
                @if($v['meta']['type'] === 'file')
                    <video src="{{ $v['meta']['src'] }}" controls preload="metadata" class="w-full h-full object-cover"></video>
                @else
                    <iframe src="{{ $v['meta']['embed'] }}" title="{{ $v['title'] }}" class="w-full h-full" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                @endif
            </div>
        </div>
    @endforeach
</section>
@endif

@endif {{-- end custom-sections fallback --}}

{{-- ── Trust strip ───────────────────────────────────────────────────── --}}
<section class="mx-auto max-w-5xl px-4 pb-16">
    <x-trust-strip />
</section>
@endsection
