@extends('layouts.shop')
@section('title', home_content('seo_title') ?: 'Fine Jewelry')

@section('content')
    {{-- Traditional (Tanishq / Kalyan-inspired) --}}
    <section class="bg-gold-100">
        <div class="mx-auto max-w-7xl px-4 py-16 text-center">
            <div class="inline-block border-y-2 border-gold-400 py-1 px-6 text-xs uppercase tracking-[0.3em] text-gold-700 mb-5">{{ home_content('hero_eyebrow') ?: 'Since tradition' }}</div>
            <h1 class="font-display text-4xl sm:text-6xl font-bold text-ink-900">{!! home_content_heading('text-gold-700') !!}</h1>
            <p class="mt-4 text-ink-700/70 max-w-xl mx-auto">{{ home_content('hero_subtitle') }}</p>
            <a href="{{ home_content('hero_cta_link') ?: route('shop') }}" class="btn-primary mt-7">{{ home_content('hero_cta_text') }}</a>
        </div>
    </section>

    @if(home_content('show_categories') && $categories->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-12">
        <h2 class="font-display text-2xl font-semibold text-center mb-8">Our collections</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            @foreach($categories as $cat)
                <a href="{{ route('category.show', $cat) }}" class="group text-center">
                    <div class="aspect-square overflow-hidden rounded-lg border-2 border-gold-300 bg-white">
                        @if($cat->image)<img src="{{ \Storage::disk('public')->url($cat->image) }}" alt="{{ $cat->name }}" class="h-full w-full object-cover group-hover:scale-105 transition">@endif
                    </div>
                    <span class="mt-2 block text-sm font-medium group-hover:text-gold-700">{{ $cat->name }}</span>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @if($featured->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-6">
        <div class="text-center mb-8"><h2 class="font-display text-3xl font-semibold">Featured Treasures</h2><div class="mx-auto mt-2 h-px w-24 bg-gold-400"></div></div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($featured as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif

    @if(home_content('show_new_arrivals') && $newArrivals->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-10">
        <div class="text-center mb-8"><h2 class="font-display text-2xl font-semibold">New Arrivals</h2><div class="mx-auto mt-2 h-px w-24 bg-gold-400"></div></div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($newArrivals as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif
{{-- Custom Section Builder blocks (universal — added below this design) --}}
@include('shop._builder-sections')
@endsection
