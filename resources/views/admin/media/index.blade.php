@extends('layouts.admin')
@section('title', 'Media library')
@section('heading', 'Media library')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="card p-4"><div class="text-xs text-ink-700/50">Files (filtered)</div><div class="text-2xl font-semibold">{{ number_format($count) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Total size (filtered)</div><div class="text-2xl font-semibold">{{ $totalSize >= 1048576 ? round($totalSize/1048576,1).' MB' : round($totalSize/1024).' KB' }}</div></div>
</div>

@if($convertibleAll > 0)
    <div class="card p-4 mb-4 flex flex-wrap items-center gap-3 border-gold-200">
        <div class="text-sm">
            <span class="font-medium">{{ number_format($convertibleAll) }}</span> JPG/PNG file(s) in the library can be converted to <strong>WebP</strong> for smaller, faster-loading images.
            <span class="text-ink-700/50">Originals are replaced and every product/page using them is repointed automatically.</span>
        </div>
        <form action="{{ route('admin.media.convert') }}" method="POST" class="ml-auto" onsubmit="return confirm('Convert all {{ $convertibleAll }} JPG/PNG file(s) in the library to WebP? This replaces the originals and repoints all products and pages. This can take a moment for large libraries.')">
            @csrf
            <input type="hidden" name="all" value="1">
            <button class="btn-primary text-sm py-2">Convert all to WebP</button>
        </form>
    </div>
@endif

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
    <input name="q" value="{{ $q }}" placeholder="Search by product name or filename…" class="input py-2 w-64">
    <input type="hidden" name="view" value="{{ $view }}">
    <select name="type" onchange="this.form.submit()" class="input py-2">
        <option value="all" @selected($type=='all')>All types</option>
        <option value="image" @selected($type=='image')>Images</option>
        <option value="video" @selected($type=='video')>Videos</option>
    </select>
    <select name="folder" onchange="this.form.submit()" class="input py-2">
        <option value="">All folders</option>
        @foreach($folders as $f)<option value="{{ $f }}" @selected($folder==$f)>{{ $f }}</option>@endforeach
    </select>
    <button class="btn-outline">Search</button>
    <div class="ml-auto flex items-center gap-1 text-sm">
        <a href="{{ request()->fullUrlWithQuery(['view'=>'grid']) }}" class="px-2.5 py-1.5 rounded {{ $view=='grid' ? 'bg-gold-100 text-gold-800' : 'text-ink-700/60 hover:bg-ink-100' }}">▦ Thumbnails</a>
        <a href="{{ request()->fullUrlWithQuery(['view'=>'list']) }}" class="px-2.5 py-1.5 rounded {{ $view=='list' ? 'bg-gold-100 text-gold-800' : 'text-ink-700/60 hover:bg-ink-100' }}">☰ List</a>
    </div>
</form>

@if($items->isEmpty())
    <div class="card p-10 text-center text-sm text-ink-700/50">No media files found.</div>
@else
<div x-data="{ sel: [], maxw: 1000, quality: 70, all() { this.sel = @js($items->pluck('path')->all()); }, none() { this.sel = []; } }">
    {{-- Bulk action bar --}}
    <div class="card p-4 mb-4">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium"><span x-text="sel.length"></span> selected</span>
            <button type="button" @click="all()" class="text-xs text-gold-700 hover:underline">Select all ({{ $count }})</button>
            <button type="button" @click="none()" class="text-xs text-ink-700/50 hover:underline">Clear</button>

            <div class="flex items-center gap-4 ml-auto flex-wrap">
                <label class="text-xs text-ink-700/70">Max width <span class="font-semibold" x-text="maxw + 'px'"></span>
                    <input type="range" min="300" max="2000" step="50" x-model.number="maxw" class="align-middle ml-1">
                </label>
                <label class="text-xs text-ink-700/70">Quality <span class="font-semibold" x-text="quality + '%'"></span>
                    <input type="range" min="40" max="90" step="5" x-model.number="quality" class="align-middle ml-1">
                </label>

                <form action="{{ route('admin.media.optimize') }}" method="POST" @submit="if(!sel.length){ $event.preventDefault(); alert('Select files first.'); }">
                    @csrf
                    <template x-for="p in sel" :key="p"><input type="hidden" name="paths[]" :value="p"></template>
                    <input type="hidden" name="max_width" :value="maxw">
                    <input type="hidden" name="quality" :value="quality">
                    <button class="btn-primary text-sm py-2" :disabled="!sel.length">Reduce size</button>
                </form>
                <form action="{{ route('admin.media.convert') }}" method="POST" @submit="if(!sel.length){ $event.preventDefault(); alert('Select files first.'); return; } return confirm('Convert ' + sel.length + ' selected file(s) to WebP? Originals (JPG/PNG) are replaced and products/pages using them are repointed automatically. Already-WebP, SVG and video files are skipped.')">
                    @csrf
                    <template x-for="p in sel" :key="p"><input type="hidden" name="paths[]" :value="p"></template>
                    <button class="btn-outline text-sm py-2" :disabled="!sel.length">Convert to WebP</button>
                </form>
                <form action="{{ route('admin.media.destroy') }}" method="POST" @submit="if(!sel.length){ $event.preventDefault(); alert('Select files first.'); return; } return confirm('Delete ' + sel.length + ' file(s)? Files used by products will be removed from those galleries.')">
                    @csrf @method('DELETE')
                    <template x-for="p in sel" :key="p"><input type="hidden" name="paths[]" :value="p"></template>
                    <button class="btn-outline text-sm py-2 text-red-600" :disabled="!sel.length">Delete</button>
                </form>
            </div>
        </div>
        <p class="text-xs text-ink-700/50 mt-2">Tip: for big savings, drag <strong>Max width</strong> down to ~800px and <strong>Quality</strong> to ~60% for product photos. <strong>Videos</strong> can be selected and deleted here, but not compressed on this server — compress them before uploading (e.g. HandBrake, or export at 720p).</p>
    </div>

    @if($view === 'list')
        {{-- List view --}}
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                    <tr><th class="px-3 py-3 w-8"></th><th class="px-4 py-3">File</th><th class="px-4 py-3">Product</th><th class="px-4 py-3">Folder</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Size</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach($items as $m)
                        <tr class="hover:bg-ink-50">
                            <td class="px-3 py-2"><input type="checkbox" value="{{ $m['path'] }}" x-model="sel"></td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-9 h-9 rounded bg-ink-100 overflow-hidden shrink-0">
                                        @if($m['type']==='image' && $m['ext']!=='svg')<img src="{{ $m['url'] }}" class="w-full h-full object-cover" loading="lazy" alt="">@endif
                                    </span>
                                    <a href="{{ $m['url'] }}" target="_blank" rel="noopener" class="truncate max-w-[220px] inline-block align-middle hover:underline">{{ basename($m['path']) }}</a>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-ink-700/70">{{ $m['product'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-ink-700/60">{{ $m['folder'] }}</td>
                            <td class="px-4 py-2 text-ink-700/60 uppercase text-xs">{{ $m['ext'] }}</td>
                            <td class="px-4 py-2 {{ $m['size'] >= 300000 ? 'text-amber-600 font-medium' : 'text-ink-700/60' }}">{{ $m['size'] >= 1048576 ? round($m['size']/1048576,2).' MB' : round($m['size']/1024).' KB' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        {{-- Thumbnail grid --}}
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
            @foreach($items as $m)
                <div class="card overflow-hidden">
                    <label class="relative block bg-ink-100 aspect-square cursor-pointer">
                        <input type="checkbox" value="{{ $m['path'] }}" x-model="sel" class="absolute top-2 left-2 z-10 w-4 h-4">
                        @if($m['type']==='video')
                            <video src="{{ $m['url'] }}" class="w-full h-full object-cover" muted preload="metadata"></video>
                        @elseif($m['ext']==='svg')
                            <img src="{{ $m['url'] }}" class="w-full h-full object-contain p-4" alt="">
                        @else
                            <img src="{{ $m['url'] }}" class="w-full h-full object-cover" loading="lazy" alt="">
                        @endif
                        @if($m['in_use'])<span class="absolute top-1.5 right-1.5 badge bg-gold-600 text-white text-[10px]">In use</span>@endif
                        @if($m['size'] >= 300000)<span class="absolute bottom-1.5 right-1.5 badge bg-amber-100 text-amber-700 text-[10px]">Large</span>@endif
                    </label>
                    <div class="p-2 text-xs">
                        <div class="truncate" title="{{ $m['path'] }}">{{ $m['product'] ?? basename($m['path']) }}</div>
                        <div class="text-ink-700/50">{{ strtoupper($m['ext']) }} · {{ $m['size'] >= 1048576 ? round($m['size']/1048576,2).' MB' : round($m['size']/1024).' KB' }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endif
@endsection
