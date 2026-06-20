@extends('layouts.shop')
@section('title', 'Fine Jewelry')

@section('content')
    {{-- Editorial split hero (Mejuri-inspired) --}}
    <section class="mx-auto max-w-7xl px-4 pt-6">
        <div class="grid lg:grid-cols-2 gap-6 items-stretch">
            <div class="rounded-3xl bg-gold-100 p-10 sm:p-14 flex flex-col justify-center">
                <p class="text-xs uppercase tracking-[0.3em] text-gold-700">{{ home_content('hero_eyebrow') ?: 'New season' }}</p>
                <h1 class="font-display text-4xl sm:text-5xl font-semibold mt-3 leading-tight">{!! home_content_heading('text-gold-700') !!}</h1>
                <p class="mt-4 text-ink-700/70 max-w-md">{{ home_content('hero_subtitle') }}</p>
                <div class="mt-6"><a href="{{ home_content('hero_cta_link') ?: route('shop') }}" class="btn-primary">{{ home_content('hero_cta_text') }}</a></div>
            </div>
            <div class="rounded-3xl overflow-hidden bg-ink-900 min-h-[320px]">
                @php($lumHero = theme_asset(home_content('hero_image')))
                @if($lumHero)
                    <img src="{{ $lumHero }}" class="h-full w-full object-cover" alt="">
                @elseif($featured->first()?->thumbnail)
                    <img src="{{ $featured->first()->thumbnail }}" class="h-full w-full object-cover" alt="">
                @endif
            </div>
        </div>
    </section>

    @if($categories->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-12">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($categories as $cat)
                <a href="{{ route('category.show', $cat) }}" class="rounded-xl border border-gold-200 py-5 text-center text-sm font-medium hover:bg-gold-100 hover:border-gold-300 transition">{{ $cat->name }}</a>
            @endforeach
        </div>
    </section>
    @endif

    @if($featured->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-4">
        <h2 class="font-display text-3xl font-semibold mb-8 text-center">Bestsellers</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($featured as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif

    <section class="bg-ink-900 text-gold-50 mt-12">
        <div class="mx-auto max-w-7xl px-4 py-14 grid sm:grid-cols-3 gap-8 text-center">
            <div><div class="font-display text-2xl text-gold-300">৳70</div><p class="text-sm text-gold-100/70 mt-1">Delivery inside Dhaka</p></div>
            <div><div class="font-display text-2xl text-gold-300">COD</div><p class="text-sm text-gold-100/70 mt-1">Pay on delivery</p></div>
            <div><div class="font-display text-2xl text-gold-300">7 days</div><p class="text-sm text-gold-100/70 mt-1">Easy exchange</p></div>
        </div>
    </section>

    @if($newArrivals->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-12">
        <h2 class="font-display text-2xl font-semibold mb-6">Just dropped</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($newArrivals as $product)<x-product-card :product="$product" />@endforeach
        </div>
    </section>
    @endif
@endsection
