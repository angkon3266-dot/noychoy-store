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
            @foreach(['price_min','price_max','in_stock','on_sale','tag'] as $keep)@if(request()->filled($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif @endforeach
            <select name="sort" onchange="this.form.submit()" class="input py-2">
                <option value="">Newest</option>
                <option value="popular" @selected(request('sort')=='popular')>Most popular</option>
                <option value="price_asc" @selected(request('sort')=='price_asc')>Price: low to high</option>
                <option value="price_desc" @selected(request('sort')=='price_desc')>Price: high to low</option>
                <option value="name" @selected(request('sort')=='name')>Name A–Z</option>
            </select>
        </form>
    </div>

    {{-- Filter bar --}}
    <form method="GET" x-data="{ open: false }" class="card p-3 mb-6">
        @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-xs text-ink-700/60">Min price</label>
                <input name="price_min" type="number" value="{{ request('price_min') }}" placeholder="0" class="input py-1.5 w-24">
            </div>
            <div>
                <label class="text-xs text-ink-700/60">Max price</label>
                <input name="price_max" type="number" value="{{ request('price_max') }}" placeholder="any" class="input py-1.5 w-24">
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="in_stock" value="1" @checked(request()->boolean('in_stock'))> In stock</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="on_sale" value="1" @checked(request()->boolean('on_sale'))> On sale</label>
            <button class="btn-outline py-1.5">Apply filters</button>
            @if(request()->hasAny(['price_min','price_max','in_stock','on_sale','tag']))
                <a href="{{ url()->current() }}{{ request('q') ? '?q='.urlencode(request('q')) : '' }}" class="text-sm text-ink-700/60 hover:text-gold-700 self-center">Clear</a>
            @endif
        </div>
    </form>

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
