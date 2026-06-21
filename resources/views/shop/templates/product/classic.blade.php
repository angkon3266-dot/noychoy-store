@extends('layouts.shop')
@section('title', $product->meta_title ?: $product->name)
@section('meta')<meta name="description" content="{{ $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description), 150) }}">@endsection

@section('content')
{{-- Traditional centered layout with framed gallery --}}
<div class="mx-auto max-w-5xl px-4 py-10" x-data="productPage(@js($pp))">
    <nav class="text-sm text-ink-700/60 mb-6 text-center">
        <a href="{{ route('home') }}" class="hover:text-gold-700">Home</a> /
        @if($product->category)<a href="{{ route('category.show', $product->category) }}" class="hover:text-gold-700">{{ $product->category->name }}</a>@endif
    </nav>
    <div class="grid md:grid-cols-2 gap-10 items-center rounded-2xl border border-gold-200 p-6 bg-white">
        @include('shop.templates.product._gallery')
        @include('shop.templates.product._purchase')
    </div>
    @include('shop.templates.product._extras')
    @include('shop.templates.product._sticky')
</div>
@endsection
