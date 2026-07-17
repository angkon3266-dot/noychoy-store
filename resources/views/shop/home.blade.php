@extends('layouts.shop')
@section('title', 'Fine Jewelry')

@section('content')
    <!-- Hero -->
    <section class="relative bg-gradient-to-br from-ink-900 to-ink-800 text-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:py-28 text-center">
            <p class="text-gold-300 uppercase tracking-[0.3em] text-xs mb-4">{{ store_name() }}</p>
            <h1 class="font-display text-4xl sm:text-6xl font-bold leading-tight">Jewelry that tells <span class="text-gold-300">your story</span></h1>
            <p class="mt-5 max-w-xl mx-auto text-white/70">Handpicked pieces, delivered across Bangladesh with cash on delivery.</p>
            <div class="mt-8 flex justify-center gap-3">
                <a href="{{ route('shop') }}" class="btn-primary">Shop the collection</a>
                <a href="{{ route('track') }}" class="btn-outline border-white/30 text-white hover:bg-white/10">Track order</a>
            </div>
        </div>
    </section>

    <!-- Categories -->
    @if($categories->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-14">
        <h2 class="font-display text-2xl font-semibold text-center mb-8">Shop by category</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            @foreach($categories as $cat)
                <a href="{{ route('category.show', $cat) }}" class="group text-center">
                    <div class="aspect-square overflow-hidden rounded-full bg-gold-100 mx-auto">
                        @if($cat->image)
                            <img src="{{ \Storage::disk('public')->url($cat->image) }}" alt="{{ $cat->name }}" class="h-full w-full object-cover group-hover:scale-105 transition">
                        @endif
                    </div>
                    <span class="mt-2 block text-sm font-medium group-hover:text-gold-700">{{ $cat->name }}</span>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    <!-- Featured -->
    @if($featured->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-2xl font-semibold">Featured</h2>
            <a href="{{ route('shop') }}" class="text-sm text-gold-700 hover:underline">View all →</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($featured as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </section>
    @endif

    <!-- New arrivals -->
    @if($newArrivals->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-2xl font-semibold">New arrivals</h2>
            <a href="{{ route('shop') }}?sort=latest" class="text-sm text-gold-700 hover:underline">View all →</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($newArrivals as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </section>
    @endif

    <!-- Trust bar -->
    <section class="border-t border-gold-200 mt-10">
        <div class="mx-auto max-w-7xl px-4 py-10 grid grid-cols-1 sm:grid-cols-3 gap-6 text-center">
            <div><div class="font-display text-lg font-semibold text-gold-700">Cash on Delivery</div><p class="text-sm text-ink-700/70 mt-1">Pay when you receive</p></div>
            <div><div class="font-display text-lg font-semibold text-gold-700">Nationwide Shipping</div><p class="text-sm text-ink-700/70 mt-1">Delivered via Steadfast</p></div>
            <div><div class="font-display text-lg font-semibold text-gold-700">Quality Assured</div><p class="text-sm text-ink-700/70 mt-1">Handpicked pieces</p></div>
        </div>
    </section>
@endsection
