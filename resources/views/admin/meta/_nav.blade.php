{{-- Meta module sub-navigation. --}}
@php
    $tabs = [
        ['admin.meta.index', 'Dashboard'],
        ['admin.meta.logs', 'Sync Logs'],
        ['admin.meta.queue', 'Queue'],
        ['admin.meta.webhook', 'Webhook'],
    ];
@endphp
<div class="flex items-center justify-between gap-3 mb-5 flex-wrap">
    <div>
        <a href="{{ route('admin.marketing.index') }}" class="text-xs text-ink-700/50 hover:text-gold-700">← Marketing Center</a>
        <h2 class="font-display text-xl font-semibold flex items-center gap-2">
            <svg class="w-5 h-5 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 8.5c1.5-3 4-3 5.5 0s4 3 5.5 0M3 12a9 9 0 1018 0 9 9 0 00-18 0z"/></svg>
            Meta
        </h2>
    </div>
    <nav class="flex gap-1 text-sm bg-ink-50 rounded-lg p-1">
        @foreach($tabs as [$route, $label])
            <a href="{{ route($route) }}"
               class="px-3 py-1.5 rounded-md {{ request()->routeIs($route) ? 'bg-white shadow-sm text-gold-800 font-medium' : 'text-ink-700/60 hover:text-ink-700' }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>
