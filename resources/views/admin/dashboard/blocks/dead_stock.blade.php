@if($deep['operations'])
    @php $o = $deep['operations']; @endphp
    {{-- Folds on a phone. --}}
    <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
        <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
            <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Dead stock</h2>
            <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide dead stock">
                <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
        <div class="hidden md:block group-data-[open]:block">
            <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">In stock, zero sales in this period — cash sitting still.</p>
            @forelse($o['dead_stock'] as $p)
                <div class="flex justify-between items-center text-[13px] sm:text-sm py-1.5 border-b border-ink-100 last:border-0">
                    <a href="{{ route('admin.products.edit', $p['slug'] ?? $p['id']) }}" class="truncate hover:text-gold-700">{{ $p['name'] }}</a>
                    <span class="text-ink-700/60 text-xs tabular-nums whitespace-nowrap ml-2">{{ $p['stock_quantity'] }} pcs</span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">Everything in stock is selling.</p>
            @endforelse
        </div>
    </div>
@endif
