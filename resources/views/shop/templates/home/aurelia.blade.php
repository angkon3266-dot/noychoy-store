@extends('layouts.shop')
@section('title', 'Fine Jewelry')

@section('content')
    <section class="relative bg-gradient-to-br from-ink-900 to-ink-800 text-white overflow-hidden">
        @if($heroImg = theme_asset(home_content('hero_image')))
            <img src="{{ $heroImg }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-30">
        @endif
        <div class="relative z-10 mx-auto max-w-7xl px-4 py-20 sm:py-28 text-center">
            <p class="text-gold-300 uppercase tracking-[0.3em] text-xs mb-4">{{ home_content('hero_eyebrow') ?: config('store.name') }}</p>
            <h1 class="font-display text-4xl sm:text-6xl font-bold leading-tight">{!! home_content_heading('text-gold-300') !!}</h1>
            <p class="mt-5 max-w-xl mx-auto text-white/70">{{ home_content('hero_subtitle') }}</p>
            <div class="mt-8 flex justify-center gap-3">
                <a href="{{ home_content('hero_cta_link') ?: route('shop') }}" class="btn-primary">{{ home_content('hero_cta_text') }}</a>
                @if(home_content('hero_secondary_text'))
                    <a href="{{ home_content('hero_secondary_link') ?: route('track') }}" class="btn-outline border-white/30 text-white hover:bg-white/10">{{ home_content('hero_secondary_text') }}</a>
                @endif
            </div>
        </div>
    </section>

    @if($categories->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-14">
        <h2 class="font-display text-2xl font-semibold text-center mb-8">{{ home_content('categories_title') }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            @foreach($categories as $cat)
                <a href="{{ route('category.show', $cat) }}" class="group text-center">
                    <div class="aspect-square overflow-hidden rounded-full bg-gold-100 mx-auto">
                        @if($cat->image)<img src="{{ \Storage::disk('public')->url($cat->image) }}" alt="{{ $cat->name }}" class="h-full w-full object-cover group-hover:scale-105 transition">@endif
                    </div>
                    <span class="mt-2 block text-sm font-medium group-hover:text-gold-700">{{ $cat->name }}</span>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @foreach([home_content('featured_title') => $featured, home_content('new_arrivals_title') => $newArrivals] as $label => $list)
        @if($list->isNotEmpty())
        <section class="mx-auto max-w-7xl px-4 py-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="font-display text-2xl font-semibold">{{ $label }}</h2>
                <a href="{{ route('shop') }}" class="text-sm text-gold-700 hover:underline">View all →</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
                @foreach($list as $product)<x-product-card :product="$product" />@endforeach
            </div>
        </section>
        @endif
    @endforeach

    <section class="border-t border-gold-200 mt-10">
        <div class="mx-auto max-w-7xl px-4 py-10 grid grid-cols-1 sm:grid-cols-3 gap-6 text-center">
            <div><div class="font-display text-lg font-semibold text-gold-700">{{ home_content('badge1_title') }}</div><p class="text-sm text-ink-700/70 mt-1">{{ home_content('badge1_text') }}</p></div>
            <div><div class="font-display text-lg font-semibold text-gold-700">{{ home_content('badge2_title') }}</div><p class="text-sm text-ink-700/70 mt-1">{{ home_content('badge2_text') }}</p></div>
            <div><div class="font-display text-lg font-semibold text-gold-700">{{ home_content('badge3_title') }}</div><p class="text-sm text-ink-700/70 mt-1">{{ home_content('badge3_text') }}</p></div>
        </div>
    </section>
@endsection
