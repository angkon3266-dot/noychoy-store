@extends('layouts.shop')
@section('title', $product->meta_title ?: $product->name)
@section('meta')<meta name="description" content="{{ $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description), 150) }}">@endsection

@section('content')
{{-- Conversion-focused: gallery scrolls, buy box sticks on desktop --}}
<div class="mx-auto max-w-7xl px-4 py-8" x-data="productPage(@js($pp))">
    <div class="grid lg:grid-cols-2 gap-10 items-start">
        @include('shop.templates.product._gallery')
        <div class="lg:sticky lg:top-24">
            @include('shop.templates.product._purchase')
        </div>
    </div>
    @include('shop.templates.product._extras')
    @include('shop.templates.product._sticky')
</div>
@endsection
