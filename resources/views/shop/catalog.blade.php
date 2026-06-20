@extends('layouts.shop')
@section('title', $title)

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-display text-3xl font-semibold">{{ $title }}</h1>
            @isset($category)
                @if($category->description)<p class="text-ink-700/70 mt-1 max-w-2xl">{{ $category->description }}</p>@endif
            @endisset
            <p class="text-sm text-ink-700/60 mt-1">{{ $products->total() }} item(s)</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
            <select name="sort" onchange="this.form.submit()" class="input py-2">
                <option value="">Newest</option>
                <option value="price_asc" @selected(request('sort')=='price_asc')>Price: low to high</option>
                <option value="price_desc" @selected(request('sort')=='price_desc')>Price: high to low</option>
                <option value="name" @selected(request('sort')=='name')>Name A–Z</option>
            </select>
        </form>
    </div>

    @if($products->isEmpty())
        <div class="card p-12 text-center text-ink-700/60">
            <p>No products found.</p>
            <a href="{{ route('shop') }}" class="btn-outline mt-4">Browse all jewelry</a>
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($products as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
        <div class="mt-10">{{ $products->links() }}</div>
    @endif
</div>

@if(request('q'))
    @push('meta-events')<script>track('Search', {search_string: @json(request('q'))});</script>@endpush
@endif
@endsection
