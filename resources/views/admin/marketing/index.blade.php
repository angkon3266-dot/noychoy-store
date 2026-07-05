@extends('layouts.admin')
@section('title', 'Marketing Center')
@section('heading', 'Marketing Center')

@section('content')
<div class="max-w-5xl">
    <p class="text-sm text-ink-700/60 mb-5">Your centralized Marketing &amp; Commerce hub. Connect Meta once, then enable each module independently.</p>

    {{-- Meta Connection --}}
    <a href="{{ route('admin.meta.connection') }}" class="card p-5 flex items-center justify-between gap-4 mb-4 hover:border-gold-300 transition">
        <div class="flex items-center gap-3">
            <span class="w-10 h-10 rounded-full bg-[#1877F2]/10 text-[#1877F2] grid place-items-center shrink-0">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12c0-6.627-5.373-12-12-12S0 5.373 0 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874V12h3.328l-.532 3.469h-2.796v8.385C19.612 22.954 24 17.99 24 12z"/></svg>
            </span>
            <div>
                <h3 class="font-semibold">Meta Connection</h3>
                <p class="text-sm text-ink-700/60">Connect Facebook &amp; Instagram, manage assets and per-module permissions.</p>
            </div>
        </div>
        @php $hb = ['ok'=>'bg-green-100 text-green-700','expiring'=>'bg-amber-100 text-amber-700','expired'=>'bg-red-100 text-red-700','needs_reconnect'=>'bg-red-100 text-red-700','disconnected'=>'bg-ink-100 text-ink-700'][$connectionHealth] ?? 'bg-ink-100 text-ink-700'; @endphp
        <span class="badge {{ $hb }} shrink-0">{{ ucfirst(str_replace('_',' ', $connectionHealth)) }}</span>
    </a>

    {{-- Modules --}}
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($modules as $m)
            @php $isLink = $m['available'] && $m['url']; @endphp
            <div class="card p-5 flex flex-col {{ $isLink ? 'hover:border-gold-300 transition' : 'opacity-80' }}">
                <div class="flex items-start justify-between gap-2">
                    <span class="w-9 h-9 rounded-lg bg-gold-50 text-gold-700 grid place-items-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $m['icon'] }}"/></svg>
                    </span>
                    @if($m['available'])<span class="badge bg-green-100 text-green-700 text-[10px]">Active</span>
                    @else<span class="badge bg-ink-100 text-ink-700 text-[10px]">Coming soon</span>@endif
                </div>
                <h3 class="font-semibold mt-3">{{ $m['name'] }}</h3>
                <p class="text-sm text-ink-700/60 mt-1 flex-1">{{ $m['description'] }}</p>
                @if($isLink)
                    <a href="{{ $m['url'] }}" class="text-xs text-gold-700 mt-3 hover:underline">Open →</a>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-5 text-sm">
        <a href="{{ route('admin.system-config.index') }}" class="text-gold-700 hover:underline">Settings &amp; credentials (System Config) →</a>
    </div>
</div>
@endsection
