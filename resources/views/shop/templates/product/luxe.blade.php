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
<div x-data="productPage(@js($pp))">
    {{-- Dark immersive hero --}}
    <div class="bg-ink-900 text-gold-50">
        <div class="mx-auto max-w-7xl px-4 py-10 grid lg:grid-cols-2 gap-10 items-center">
            @include('shop.templates.product._gallery')
            <div class="[&_h1]:text-white [&_.text-ink-800]:text-white [&_.text-ink-700\/80]:text-gold-100/80">
                @include('shop.templates.product._purchase')
            </div>
        </div>
    </div>
    <div class="mx-auto max-w-7xl px-4 py-8">
        @include('shop.templates.product._extras')
    </div>
    @include('shop.templates.product._sticky')
</div>
@endsection
