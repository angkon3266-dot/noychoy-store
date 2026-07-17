@extends('layouts.shop')
@section('title', home_content('seo_title') ?: 'Fine Jewelry')

@section('content')
    {{-- Playful (Pandora-inspired) --}}
    <section class="mx-auto max-w-7xl px-4 pt-8">
        <div class="rounded-[2rem] bg-gradient-to-r from-gold-200 via-gold-100 to-gold-200 px-6 py-16 sm:py-20 text-center">
            @if(home_content('hero_eyebrow'))<p class="text-xs uppercase tracking-[0.3em] text-gold-700 mb-3">{{ home_content('hero_eyebrow') }}</p>@endif
            <h1 class="font-display text-4xl sm:text-6xl font-bold text-ink-900">{!! home_content_heading('text-gold-700') !!}</h1>
            <p class="mt-4 text-ink-700/70 max-w-lg mx-auto">{{ home_content('hero_subtitle') }}</p>
            <a href="{{ home_content('hero_cta_link') ?: route('shop') }}" class="btn-dark mt-7">{{ home_content('hero_cta_text') }}</a>
        </div>
    </section>

    @if(home_content('show_categories') && $categories->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-10">
        <div class="flex flex-wrap justify-center gap-3">
            @foreach($categories as $cat)
                <a href="{{ route('category.show', $cat) }}" class="rounded-full bg-gold-100 px-5 py-2.5 text-sm font-medium hover:bg-gold-200 transition">{{ $cat->name }}</a>
            @endforeach
        </div>
    </section>
    @endif

    @if($featured->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-6">
        <h2 class="font-display text-2xl font-semibold mb-6 text-center">💛 Loved by everyone</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($featured as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif

    <section class="mx-auto max-w-7xl px-4 py-10">
        <div class="grid sm:grid-cols-3 gap-4 text-center">
            <div class="rounded-2xl bg-gold-100 p-6"><div class="text-3xl">🚚</div><div class="font-medium mt-2">Fast delivery</div></div>
            <div class="rounded-2xl bg-gold-100 p-6"><div class="text-3xl">💵</div><div class="font-medium mt-2">Cash on delivery</div></div>
            <div class="rounded-2xl bg-gold-100 p-6"><div class="text-3xl">🔁</div><div class="font-medium mt-2">Easy exchange</div></div>
        </div>
    </section>

    @if(home_content('show_new_arrivals') && $newArrivals->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-6">
        <h2 class="font-display text-2xl font-semibold mb-6 text-center">Fresh & new</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($newArrivals as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif
{{-- Custom Section Builder blocks (universal — added below this design) --}}
@include('shop._builder-sections')
@endsection
