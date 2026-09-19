{{-- Most loved products. Folds on a phone with the total left in the heading.
     The name gets the width there and the bar a fixed stub — the old fixed
     12rem name column left the bar about 40px on a phone. --}}
@php $lovedMax = max(1, $mostLoved->max('loves_count')); @endphp
<div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
    <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
        <div class="min-w-0 flex-1 flex items-center justify-between gap-3">
            <h2 class="font-semibold text-sm sm:text-base flex items-center gap-2">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                Most loved products
            </h2>
            <span class="shrink-0 text-xs sm:text-sm tabular-nums text-ink-700/60">{{ number_format($totalLoves) }} total ❤️</span>
        </div>
        <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide most loved products">
            <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </div>
    <div class="hidden md:block group-data-[open]:block mt-1 sm:mt-3">
        @forelse($mostLoved as $p)
            <div class="flex items-center gap-2 sm:gap-3 py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                <x-admin.thumb :src="$thumbs[$p->id] ?? null" />
                <a href="{{ route('admin.products.edit', $p) }}" class="min-w-0 flex-1 sm:flex-none sm:w-48 sm:shrink-0 truncate text-gold-700 hover:underline">{{ $p->name }}</a>
                <div class="w-16 shrink-0 sm:w-auto sm:shrink sm:flex-1 h-1.5 sm:h-2 rounded-full bg-ink-50 overflow-hidden">
                    <div class="h-full rounded-full bg-red-400" style="width: {{ round($p->loves_count / $lovedMax * 100) }}%"></div>
                </div>
                <span class="shrink-0 w-14 sm:w-16 text-right font-medium tabular-nums text-red-600">❤️ {{ number_format($p->loves_count) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No love reactions yet. They'll appear here as customers tap the ❤️ on products.</p>
        @endforelse
    </div>
</div>
