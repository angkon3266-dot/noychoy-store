{{-- Best-selling categories --}}
<div class="card p-3 sm:p-5">
    <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Best-selling categories · {{ $range->label }}</h2>
    @forelse($topCategories as $cat)
        <div class="py-1 sm:py-1.5">
            <div class="flex items-center justify-between text-[13px] sm:text-sm mb-1">
                <span class="truncate pr-3">{{ $cat->name }}</span>
                <span class="shrink-0 tabular-nums text-ink-700/60">{{ $cat->qty }} sold · {{ money($cat->revenue) }}</span>
            </div>
            <div class="h-1 sm:h-1.5 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ round($cat->qty / $catMax * 100) }}%"></div></div>
        </div>
    @empty
        <p class="text-sm text-ink-700/50">No category sales in this period yet.</p>
    @endforelse
</div>
