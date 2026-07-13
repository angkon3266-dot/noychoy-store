@extends('layouts.shop')
@section('title', 'Fine Jewelry')

@section('content')
    {{-- Luxe dark (Cartier / Bvlgari-inspired) --}}
    <section class="relative min-h-[70vh] flex items-center justify-center text-center bg-ink-900 text-white overflow-hidden">
        @php $maisonHero = theme_asset(home_content('hero_image')); @endphp
        @if($maisonHero)
            <img src="{{ $maisonHero }}" class="absolute inset-0 h-full w-full object-cover opacity-30" alt="">
        @elseif($featured->first()?->thumbnail)
            <img src="{{ $featured->first()->thumbnail }}" class="absolute inset-0 h-full w-full object-cover opacity-30" alt="">
        @endif
        <div class="relative z-10 px-4">
            <p class="text-gold-300 uppercase tracking-[0.4em] text-xs mb-5">{{ home_content('hero_eyebrow') ?: 'Maison '.config('store.name') }}</p>
            <h1 class="font-display text-5xl sm:text-7xl font-bold leading-none">{!! home_content_heading('text-gold-300') !!}</h1>
            <p class="mt-6 max-w-lg mx-auto text-white/70">{{ home_content('hero_subtitle') }}</p>
            <a href="{{ home_content('hero_cta_link') ?: route('shop') }}" class="btn-primary mt-8">{{ home_content('hero_cta_text') }}</a>
        </div>
    </section>

    @if(home_content('show_categories') && $categories->isNotEmpty())
    <section class="bg-ink-800">
        <div class="mx-auto max-w-7xl px-4 py-3 flex flex-wrap justify-center gap-x-8 gap-y-2 text-sm text-gold-100/80">
            @foreach($categories as $cat)<a href="{{ route('category.show', $cat) }}" class="hover:text-gold-300 uppercase tracking-wide">{{ $cat->name }}</a>@endforeach
        </div>
    </section>
    @endif

    @if($featured->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-16">
        <h2 class="font-display text-3xl font-semibold mb-10 text-center">Signature pieces</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-10">
            @foreach($featured as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif

    @if(home_content('show_new_arrivals') && $newArrivals->isNotEmpty())
    <section class="bg-gold-100/40">
        <div class="mx-auto max-w-7xl px-4 py-16">
            <h2 class="font-display text-2xl font-semibold mb-8 text-center">New creations</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-10">
                @foreach($newArrivals as $product)<x-product-card :product="$product" />@endforeach
            </div>
        </div>
    </section>
    @endif
{{-- Custom Section Builder blocks (universal — added below this design) --}}
@include('shop._builder-sections')
@endsection
