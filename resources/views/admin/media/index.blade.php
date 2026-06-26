@extends('layouts.admin')
@section('title', 'Media library')
@section('heading', 'Media library')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="card p-4"><div class="text-xs text-ink-700/50">Files</div><div class="text-2xl font-semibold">{{ number_format($count) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Total size</div><div class="text-2xl font-semibold">{{ $totalSize >= 1048576 ? round($totalSize/1048576,1).' MB' : round($totalSize/1024).' KB' }}</div></div>
</div>

<form method="GET" class="flex flex-wrap gap-2 mb-4">
    <select name="type" onchange="this.form.submit()" class="input py-2">
        <option value="all" @selected($type=='all')>All types</option>
        <option value="image" @selected($type=='image')>Images</option>
        <option value="video" @selected($type=='video')>Videos</option>
    </select>
    <select name="folder" onchange="this.form.submit()" class="input py-2">
        <option value="">All folders</option>
        @foreach($folders as $f)
            <option value="{{ $f }}" @selected($folder==$f)>{{ $f }}</option>
        @endforeach
    </select>
    <span class="text-xs text-ink-700/50 self-center">Biggest files first — optimize those for faster pages.</span>
</form>

@if($items->isEmpty())
    <div class="card p-10 text-center text-sm text-ink-700/50">No media files found.</div>
@else
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
    @foreach($items as $m)
        <div class="card overflow-hidden flex flex-col" x-data="{ opt: false }">
            <div class="relative bg-ink-100 aspect-square grid place-items-center overflow-hidden">
                @if($m['type'] === 'video')
                    <video src="{{ $m['url'] }}" class="w-full h-full object-cover" muted preload="metadata"></video>
                    <span class="absolute inset-0 grid place-items-center text-white/90 text-3xl pointer-events-none">▶</span>
                @elseif($m['ext'] === 'svg')
                    <img src="{{ $m['url'] }}" alt="" class="max-w-full max-h-full p-4">
                @else
                    <img src="{{ $m['url'] }}" alt="" class="w-full h-full object-cover" loading="lazy">
                @endif
                @if($m['in_use'])<span class="absolute top-1.5 left-1.5 badge bg-gold-600 text-white text-[10px]">In use</span>@endif
                @if($m['size'] >= 300000)<span class="absolute top-1.5 right-1.5 badge bg-amber-100 text-amber-700 text-[10px]">Large</span>@endif
            </div>

            <div class="p-2.5 text-xs flex-1 flex flex-col">
                <div class="truncate text-ink-800" title="{{ $m['path'] }}">{{ basename($m['path']) }}</div>
                <div class="text-ink-700/50 mt-0.5">{{ $m['folder'] }} · {{ strtoupper($m['ext']) }} · {{ $m['size'] >= 1048576 ? round($m['size']/1048576,2).' MB' : round($m['size']/1024).' KB' }}</div>

                <div class="mt-2 flex items-center gap-2">
                    @if($m['optimizable'])
                        <button type="button" @click="opt = !opt" class="text-gold-700 hover:underline">Reduce size</button>
                    @endif
                    <a href="{{ $m['url'] }}" target="_blank" rel="noopener" class="text-ink-700/60 hover:underline">View</a>
                    <form action="{{ route('admin.media.destroy') }}" method="POST" class="ml-auto"
                          onsubmit="return confirm('{{ $m['in_use'] ? 'This file is used by a product gallery. Deleting it will remove that image. Continue?' : 'Delete this file permanently?' }}')">
                        @csrf @method('DELETE')
                        <input type="hidden" name="path" value="{{ $m['path'] }}">
                        <button class="text-red-600 hover:underline">Delete</button>
                    </form>
                </div>

                @if($m['optimizable'])
                    <form x-show="opt" x-cloak action="{{ route('admin.media.optimize') }}" method="POST" class="mt-2 border-t border-ink-100 pt-2 space-y-2">
                        @csrf
                        <input type="hidden" name="path" value="{{ $m['path'] }}">
                        <label class="block text-ink-700/60">Max width
                            <select name="max_width" class="input py-1 text-xs mt-0.5">
                                <option value="800">800px (small)</option>
                                <option value="1200" selected>1200px (recommended)</option>
                                <option value="1600">1600px (large)</option>
                                <option value="2000">2000px (keep big)</option>
                            </select>
                        </label>
                        <label class="block text-ink-700/60">Quality
                            <select name="quality" class="input py-1 text-xs mt-0.5">
                                <option value="65">65% (smallest)</option>
                                <option value="80" selected>80% (balanced)</option>
                                <option value="90">90% (high)</option>
                            </select>
                        </label>
                        <button class="btn-primary w-full text-xs py-1.5">Optimize now</button>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
@endsection
