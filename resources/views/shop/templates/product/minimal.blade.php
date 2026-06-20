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
{{-- Editorial / minimal: large centered gallery, calm whitespace --}}
<div class="mx-auto max-w-3xl px-4 py-10 text-center" x-data="productPage(@js($pp))">
    @if($product->category)<a href="{{ route('category.show', $product->category) }}" class="text-xs uppercase tracking-[0.3em] text-gold-700">{{ $product->category->name }}</a>@endif

    <div class="mt-6 mx-auto max-w-md">@include('shop.templates.product._gallery')</div>

    <div class="mt-8 mx-auto max-w-md text-left">
        @include('shop.templates.product._purchase')
    </div>

    <div class="text-left">@include('shop.templates.product._extras')</div>
    @include('shop.templates.product._sticky')
</div>
@endsection
