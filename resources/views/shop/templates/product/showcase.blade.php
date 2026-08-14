@extends('layouts.shop')
@section('title', $product->meta_title ?: $product->name)
@section('meta')<meta name="description" content="{{ $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description), 150) }}">@endsection

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8" x-data="productPage(@js($pp))">
    @include('shop.templates.product._breadcrumb')

    <div class="grid lg:grid-cols-2 gap-10">
        @include('shop.templates.product._gallery')
        @include('shop.templates.product._purchase')
    </div>

    @include('shop.templates.product._extras')
    @include('shop.templates.product._sticky')
</div>
@endsection
