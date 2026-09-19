{{-- Low stock --}}
<div class="card p-3 sm:p-5">
    <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Low stock alerts</h2>
    @forelse($lowStockProducts as $p)
        <div class="flex items-center justify-between py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
            <a href="{{ route('admin.products.edit', $p) }}" class="min-w-0 flex items-center gap-2 pr-3 text-gold-700 hover:underline">
                <x-admin.thumb :src="$thumbs[$p->id] ?? null" />
                <span class="truncate">{{ $p->name }}</span>
            </a>
            <span class="badge shrink-0 {{ $p->stock_quantity == 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ $p->stock_quantity }} left</span>
        </div>
    @empty
        <p class="text-sm text-ink-700/50">Everything's well stocked. 🎉</p>
    @endforelse
</div>
