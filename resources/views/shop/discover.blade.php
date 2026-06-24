@extends('layouts.shop')
@section('title', 'Discover')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-6 md:py-10">
    <h1 class="font-display text-2xl md:text-3xl font-semibold mb-6">Discover</h1>

    @if(count($tiles))
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4">
            @foreach($tiles as $tile)
                <a href="{{ $tile['link'] ?: '#' }}" class="group block rounded-2xl border border-ink-100 overflow-hidden bg-white hover:shadow-md transition">
                    <div class="aspect-square bg-gold-50 overflow-hidden">
                        <img src="{{ theme_asset($tile['image']) }}" alt="{{ $tile['name'] ?? '' }}" loading="lazy" decoding="async"
                             class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                    </div>
                    @if($tile['name'] ?? null)
                        <div class="px-3 py-3 text-center font-medium text-sm text-ink-800 group-hover:text-gold-700">{{ $tile['name'] }}</div>
                    @endif
                </a>
            @endforeach
        </div>
    @else
        {{-- Fallback: category tiles --}}
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4">
            @foreach($categories as $cat)
                <a href="{{ route('category.show', $cat) }}" class="group block rounded-2xl border border-ink-100 overflow-hidden bg-white hover:shadow-md transition">
                    <div class="aspect-square bg-gold-50 flex items-center justify-center overflow-hidden">
                        @if($cat->image ?? null)
                            <img src="{{ theme_asset($cat->image) }}" alt="{{ $cat->name }}" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                        @else
                            <span class="font-display text-lg text-gold-400">{{ \Illuminate\Support\Str::limit($cat->name, 14) }}</span>
                        @endif
                    </div>
                    <div class="px-3 py-3 text-center font-medium text-sm text-ink-800 group-hover:text-gold-700">{{ $cat->name }}</div>
                </a>
            @endforeach
        </div>
        @if($categories->isEmpty())
            <p class="text-center text-sm text-ink-700/60 py-10">Discover tiles haven't been set up yet.</p>
        @endif
    @endif
</div>
@endsection
