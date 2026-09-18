@if($deep['viewedNotSold'] !== null)
    {{-- Folds on a phone. --}}
    <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
        <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
            <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Viewed but never bought</h2>
            <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide viewed but never bought">
                <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
        <div class="hidden md:block group-data-[open]:block">
            <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">Interest is there — the photos, price or copy are the blocker.</p>
            @forelse($deep['viewedNotSold'] as $r)
                <div class="flex justify-between items-center text-[13px] sm:text-sm py-1.5 border-b border-ink-100 last:border-0">
                    {{-- Linked by slug: Product::getRouteKeyName() is 'slug', so
                         an admin link built from the id 404s. --}}
                    <a href="{{ route('admin.products.edit', $r['slug'] ?: $r['id']) }}" class="truncate hover:text-gold-700">{{ $r['name'] }}</a>
                    <span class="text-ink-700/60 text-xs tabular-nums whitespace-nowrap ml-2">{{ $r['views'] }} views · 0 sold</span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">Nothing flagged — every viewed product has sold.</p>
            @endforelse
        </div>
    </div>
@endif
