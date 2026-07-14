@extends('layouts.shop')
@section('title', 'Notifications')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10">
    <h1 class="font-display text-3xl font-semibold mb-6">Notifications</h1>

    <div class="space-y-3">
        @forelse($items as $n)
            <a href="{{ $n->url ? route('account.notifications.go', $n) : '#' }}" class="card p-4 flex items-start gap-3 hover:border-gold-300 transition {{ $n->url ? '' : 'pointer-events-none' }}">
                <span class="text-2xl shrink-0">{{ $n->iconOrDefault() }}</span>
                <div class="min-w-0">
                    <p class="font-medium">{{ $n->title }}</p>
                    @if($n->body)<p class="text-sm text-ink-700/70 mt-0.5">{{ $n->body }}</p>@endif
                    <p class="text-xs text-ink-700/40 mt-1">{{ $n->sent_at?->diffForHumans() }}</p>
                    @if($n->url && $n->cta_label)
                        <span class="inline-block mt-2 text-sm text-gold-700 font-medium">{{ $n->cta_label }} →</span>
                    @endif
                </div>
            </a>
        @empty
            <div class="card p-10 text-center text-ink-700/50">No notifications yet.</div>
        @endforelse
    </div>

    <div class="mt-6">{{ $items->links() }}</div>
</div>
@endsection
