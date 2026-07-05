@extends('layouts.admin')
@section('title', $channel['name'])
@section('heading', $channel['name'])

@section('content')
<div class="max-w-xl">
    <a href="{{ route('admin.marketing.index') }}" class="text-sm text-gold-700 hover:underline">← Marketing Center</a>

    <div class="card p-8 text-center mt-4">
        <div class="w-14 h-14 rounded-full bg-gold-100 text-gold-700 grid place-items-center mx-auto mb-4">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h2 class="font-display text-xl font-semibold">{{ $channel['name'] }}</h2>
        <p class="badge bg-ink-100 text-ink-700 mt-2">Coming soon</p>
        <p class="text-sm text-ink-700/60 mt-4">{{ $channel['desc'] }}</p>
        <p class="text-xs text-ink-700/40 mt-4">This channel is on the roadmap. Meta (Facebook &amp; Instagram) is available today — connect it from the Marketing Center.</p>
        <a href="{{ route('admin.meta.index') }}" class="btn-outline mt-5 inline-block">Set up Meta instead</a>
    </div>
</div>
@endsection
