@extends('layouts.admin')
@section('title', 'New landing page')
@section('heading', 'Start a landing page')

@section('content')
<div class="max-w-5xl">
    <div class="flex items-center justify-between mb-6">
        <p class="text-sm text-ink-700/70">
            Pick a layout to start from. It drops in a ready-made set of sections with placeholder
            copy — edit, reorder or delete any of them afterwards. Nothing stays linked to the template.
        </p>
        <a href="{{ route('admin.landing.index') }}" class="btn-outline whitespace-nowrap ml-4">← Back</a>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        @foreach($templates as $key => $t)
            <a href="{{ route('admin.landing.create', ['template' => $key]) }}"
               class="card p-5 hover:border-gold-300 hover:shadow-md transition group">
                <div class="flex items-start gap-3">
                    <span class="text-3xl shrink-0">{{ $t['icon'] }}</span>
                    <div class="min-w-0">
                        <h2 class="font-semibold group-hover:text-gold-700 transition">{{ $t['name'] }}</h2>
                        <p class="text-sm text-ink-700/70 mt-1">{{ $t['tagline'] }}</p>
                        <p class="text-xs text-ink-700/50 mt-2"><strong class="font-medium">Best for:</strong> {{ $t['best_for'] }}</p>

                        <div class="mt-3 flex flex-wrap gap-1">
                            @foreach($t['blocks'] as $b)
                                <span class="badge bg-ink-100 text-ink-700 text-[10px] capitalize">{{ str_replace('_', ' ', $b['type']) }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </a>
        @endforeach

        <a href="{{ route('admin.landing.create', ['template' => 'blank']) }}"
           class="card p-5 border-dashed hover:border-gold-300 hover:shadow-md transition group flex items-center gap-3">
            <span class="text-3xl shrink-0">➕</span>
            <div>
                <h2 class="font-semibold group-hover:text-gold-700 transition">Blank page</h2>
                <p class="text-sm text-ink-700/70 mt-1">Start with nothing and add sections yourself.</p>
            </div>
        </a>
    </div>
</div>
@endsection
