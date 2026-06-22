@php
    $badges = collect(theme('trust_badges') ?: config('theme.defaults.trust_badges', []))
        ->filter(fn ($b) => filled($b['title'] ?? null))->values();
    $cols = min(4, max(1, $badges->count()));
@endphp
@if($badges->isNotEmpty())
<div {{ $attributes->merge(['class' => 'grid gap-2 text-center text-xs text-ink-700/70']) }}
     style="grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr));">
    @foreach($badges as $b)
        <div class="rounded-lg bg-gold-100/60 p-3">
            <span class="text-base">{{ $b['icon'] ?? '✓' }}</span><br>
            <span class="font-medium">{{ $b['title'] }}</span>
            @if(!empty($b['text']))<br><span class="text-[10px] text-ink-700/50">{{ $b['text'] }}</span>@endif
        </div>
    @endforeach
</div>
@endif
