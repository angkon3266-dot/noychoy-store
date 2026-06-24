@extends('layouts.shop')
@section('title', 'Loved items')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 md:py-10">
    <div class="grid md:grid-cols-[220px_1fr] gap-8">
        <aside class="hidden md:block"><div class="card p-3 sticky top-20">@include('customer._nav')</div></aside>

        <div class="min-w-0">
            @include('customer._flash')
            <h1 class="font-display text-2xl font-semibold mb-6">Loved items</h1>

            @if($products->isEmpty())
                <div class="card p-8 text-center text-sm text-ink-700/60">
                    <svg class="w-10 h-10 mx-auto text-ink-200 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                    Nothing loved yet. Tap the ❤️ on any product to save it here.
                    <div class="mt-3"><a href="{{ route('shop') }}" class="btn-primary inline-block">Browse products</a></div>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    @foreach($products as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-6">{{ $products->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
