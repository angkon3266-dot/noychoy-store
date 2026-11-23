@extends('layouts.admin')
@section('title', 'Shop the look')
@section('heading', 'Shop the look')

@section('content')
<div class="flex flex-wrap items-start justify-between gap-4 mb-5">
    <p class="text-sm text-ink-700/60 max-w-2xl">
        A look is one styled photo with the pieces in it pinned on. Shoppers tap a number to see a piece, or add the whole look in one go.
        Looks show on <a href="{{ route('looks') }}" target="_blank" rel="noopener" class="text-gold-700 underline">/looks</a>
        and — the first three — on the homepage (Appearance → Homepage → Shop the look).
    </p>
    <div class="flex gap-2 shrink-0">
        <a href="{{ route('admin.looks.cutouts') }}" class="btn-outline">Try-on &amp; stacker cut-outs</a>
        <a href="{{ route('admin.looks.create') }}" class="btn-primary">New look</a>
    </div>
</div>

@if($looks->isEmpty())
    <div class="card p-10 text-center text-sm text-ink-700/70">
        No looks yet. A model shot or a flat-lay with three or four pieces in it works best.
        <div class="mt-4"><a href="{{ route('admin.looks.create') }}" class="btn-primary">Make the first look</a></div>
    </div>
@else
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($looks as $look)
            <div class="card overflow-hidden">
                <a href="{{ route('admin.looks.edit', $look) }}" class="block relative aspect-[4/5] bg-ink-50">
                    @if($look->image_url)<img src="{{ $look->image_url }}" alt="" class="absolute inset-0 w-full h-full object-cover">@endif
                    @foreach($look->cleanHotspots() as $i => $h)
                        <span class="absolute -translate-x-1/2 -translate-y-1/2 grid place-items-center w-6 h-6 rounded-full bg-ink-900 text-white text-[11px] font-semibold ring-2 ring-white" style="left: {{ $h['x'] }}%; top: {{ $h['y'] }}%">{{ $i + 1 }}</span>
                    @endforeach
                </a>
                <div class="p-3 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <a href="{{ route('admin.looks.edit', $look) }}" class="block font-medium text-sm truncate hover:underline">{{ $look->title }}</a>
                        <span class="text-xs text-ink-700/60">{{ count($look->cleanHotspots()) }} pieces · {{ $look->is_active ? 'Showing' : 'Hidden' }}</span>
                    </div>
                    <form method="POST" action="{{ route('admin.looks.destroy', $look) }}" onsubmit="return confirm('Delete this look?')">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-600 hover:underline">Delete</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
