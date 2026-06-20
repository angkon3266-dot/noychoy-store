@extends('layouts.shop')
@section('title', $product->meta_title ?: $product->name)
@section('meta')<meta name="description" content="{{ $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description), 150) }}">@endsection

@section('content')
@php
$pp = [
    'id' => $product->id, 'name' => $product->name, 'price' => (float) $product->price,
    'hasVariants' => (bool) $product->has_variants, 'image' => $product->images->first()?->url ?? '',
    'variants' => $product->variants->mapWithKeys(fn($v) => [$v->id => ['label' => $v->attributes['Option'] ?? $v->label, 'price' => $v->effective_price, 'stock' => $v->stock_quantity]]),
    'offers' => $product->offerTiers(),
];
@endphp
<div class="mx-auto max-w-7xl px-4 py-8" x-data="productPage(@js($pp))">
    <nav class="text-sm text-ink-700/60 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold-700">Home</a> /
        @if($product->category)<a href="{{ route('category.show', $product->category) }}" class="hover:text-gold-700">{{ $product->category->name }}</a> /@endif
        <span class="text-ink-800">{{ $product->name }}</span>
    </nav>

    <div class="grid lg:grid-cols-2 gap-10">
        @include('shop.templates.product._gallery')
        @include('shop.templates.product._purchase')
    </div>

    @include('shop.templates.product._extras')
    @include('shop.templates.product._sticky')
</div>
@endsection
