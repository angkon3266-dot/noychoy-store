@extends('layouts.shop')
@section('title', $title)

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8" x-data="{ showFilters: false }">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-display text-3xl font-semibold">{{ $title }}</h1>
            @isset($category)
                @if($category->description)<p class="text-ink-700/70 mt-1 max-w-2xl">{{ $category->description }}</p>@endif
            @endisset
            <p class="text-sm text-ink-700/60 mt-1">{{ $products->total() }} item(s)</p>
        </div>
    </div>

    <form method="GET" class="lg:grid lg:grid-cols-[260px_1fr] lg:gap-8">
        @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif

        {{-- Sidebar filters --}}
        <aside class="mb-6 lg:mb-0">
            <div class="flex items-center justify-between lg:hidden mb-3">
                <button type="button" @click="showFilters = !showFilters" class="btn-outline py-2">⚙ Filters</button>
            </div>
            <div :class="showFilters ? 'block' : 'hidden lg:block'">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-display text-lg tracking-wide">FILTER BY</h2>
                    @if(request()->hasAny(['attr','cf','tags','price_range','price_min','price_max','in_stock','on_sale']))
                        <a href="{{ url()->current() }}{{ request('q') ? '?q='.urlencode(request('q')) : '' }}" class="text-xs text-ink-700/60 hover:text-gold-700">Clear all</a>
                    @endif
                </div>

                @forelse($filters as $group)
                    <div class="border-t border-ink-100 py-3">
                        <h3 class="text-xs uppercase tracking-wide text-ink-700/60 mb-2">{{ $group['label'] }}
                            <span class="block h-0.5 w-8 bg-gold-400 mt-1"></span>
                        </h3>
                        <div class="space-y-1 max-h-64 overflow-y-auto pr-1">
                            @foreach($group['options'] as $opt)
                                <label class="flex items-center gap-2 text-sm py-0.5 cursor-pointer">
                                    <input type="checkbox" name="{{ $group['param'] }}" value="{{ $opt['value'] }}" @checked($opt['checked']) onchange="this.form.submit()">
                                    @if(($group['is_color'] ?? false))
                                        @if($opt['hex'] === 'multi')
                                            <span class="w-4 h-4 rounded-full border border-ink-200" style="background:conic-gradient(red,orange,yellow,green,blue,violet,red)"></span>
                                        @elseif($opt['hex'])
                                            <span class="w-4 h-4 rounded-full border border-ink-200" style="background:{{ $opt['hex'] }}"></span>
                                        @endif
                                    @endif
                                    <span>{{ $opt['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-700/50">No filters configured.</p>
                @endforelse

                @if(($filters && count($filters)))
                    <noscript><button class="btn-outline w-full mt-3">Apply</button></noscript>
                @endif
            </div>
        </aside>

        {{-- Products --}}
        <div>
            <div class="flex flex-wrap items-center justify-end gap-2 mb-4">
                @php $curSort = $shopSort ?? 'new'; @endphp
                <select name="sort" onchange="this.form.submit()" class="input py-2 w-auto">
                    <option value="new" @selected($curSort=='new')>Newest</option>
                    <option value="popular" @selected($curSort=='popular')>Most popular</option>
                    <option value="price_asc" @selected($curSort=='price_asc')>Price: low to high</option>
                    <option value="price_desc" @selected($curSort=='price_desc')>Price: high to low</option>
                    <option value="name" @selected($curSort=='name')>Name A–Z</option>
                </select>
            </div>

            @if($products->isEmpty())
                <div class="card p-12 text-center text-ink-700/60">
                    <p>No products match these filters.</p>
                    <a href="{{ route('shop') }}" class="btn-outline mt-4">Browse all jewelry</a>
                </div>
            @else
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-8">
                    @foreach($products as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-10">{{ $products->links() }}</div>
            @endif
        </div>
    </form>
</div>

@if(request('q'))
    @push('meta-events')<script>track('Search', {search_string: @json(request('q'))});</script>@endpush
@endif
@endsection
