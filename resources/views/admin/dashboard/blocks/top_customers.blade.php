{{-- Most valuable customers. Folds on a phone; the points liability sits in
     the heading so it stays visible while the list is shut. --}}
<div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
    <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
        <div class="min-w-0 flex-1 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
            <h2 class="font-semibold text-sm sm:text-base">Top customers</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">Points liability: {{ money($pointsLiability) }} ({{ number_format($pointsOutstanding) }} pts)</span>
        </div>
        <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide top customers">
            <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </div>
    <div class="hidden md:block group-data-[open]:block mt-1 sm:mt-3">
        @forelse($topCustomers as $c)
            <div class="flex items-center justify-between py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                <a href="{{ route('admin.customers.show', $c) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $c->name }} <span class="text-ink-700/40">· {{ $c->total_orders }} orders</span></a>
                <span class="shrink-0 tabular-nums text-ink-700/60">{{ money($c->total_spent) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No customer sales yet.</p>
        @endforelse
    </div>
</div>
