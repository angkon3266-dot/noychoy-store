{{-- Meta catalog status for a single product. Expects $product (existing). --}}
@php
    $status = $product->metaStatus();
    $lastSync = $product->metaLastSyncedAt();
    $badge = match ($status) {
        'synced' => ['Synced', 'bg-green-100 text-green-700'],
        'pending' => ['Pending', 'bg-amber-100 text-amber-700'],
        'failed' => ['Failed', 'bg-red-100 text-red-700'],
        'removed' => ['Removed', 'bg-ink-100 text-ink-700'],
        default => ['Never synced', 'bg-ink-100 text-ink-700'],
    };
    $failedState = $product->metaSyncStates->firstWhere('status', 'failed');
@endphp
<div class="card p-5">
    <div class="flex items-center justify-between">
        <h2 class="font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 8.5c1.5-3 4-3 5.5 0s4 3 5.5 0M3 12a9 9 0 1018 0 9 9 0 00-18 0z"/></svg>
            Meta catalog
        </h2>
        <span class="badge {{ $badge[1] }}">{{ $badge[0] }}</span>
    </div>
    <p class="text-xs text-ink-700/60 mt-2">
        @if($lastSync)Last synced {{ $lastSync->diffForHumans() }}.@else Not yet sent to the Meta catalog.@endif
    </p>
    @if($failedState && $failedState->last_error)
        <p class="text-xs text-red-600 mt-1">{{ \Illuminate\Support\Str::limit($failedState->last_error, 160) }}</p>
    @endif
    <p class="text-xs text-ink-700/40 mt-2">Sync is managed from <a href="{{ route('admin.meta.index') }}" class="text-gold-700 hover:underline">Meta Integration</a>.</p>
</div>
