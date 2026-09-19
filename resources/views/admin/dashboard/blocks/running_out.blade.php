@if($deep['operations'])
    @php $o = $deep['operations']; @endphp
    {{-- Urgent, so it never folds — the three nearest to running out show
         on a phone and the rest are one tap away. --}}
    <div class="card p-3 sm:p-5 group" x-data="{ all: false }" :data-all="all">
        <h2 class="font-semibold text-sm sm:text-base mb-0.5 sm:mb-1">Running out soon</h2>
        <p class="text-[11px] sm:text-xs text-ink-700/55 mb-1.5 sm:mb-3">Days of stock left at the current sales rate.</p>
        @forelse($o['stock_cover'] as $c)
            <div class="{{ $loop->index >= 3 ? 'hidden md:flex group-data-[all]:flex' : 'flex' }} justify-between items-center text-[13px] sm:text-sm py-1.5 border-b border-ink-100 last:border-0">
                <a href="{{ route('admin.products.edit', $c['slug'] ?? $c['id']) }}" class="min-w-0 flex items-center gap-2 hover:text-gold-700">
                    <x-admin.thumb :src="$thumbs[$c['id']] ?? null" />
                    <span class="truncate">{{ $c['name'] }}</span>
                </a>
                <span class="whitespace-nowrap tabular-nums ml-2 {{ $c['days_left'] <= 7 ? 'text-red-600 font-medium' : 'text-ink-700/60' }}">
                    {{ $c['days_left'] }}d · {{ $c['stock_quantity'] }} left
                </span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">Not enough sales history yet.</p>
        @endforelse
        @if(count($o['stock_cover']) > 3)
            <button type="button" @click="all = !all" class="md:hidden mt-1.5 text-xs font-medium text-gold-700"
                    x-text="all ? 'Show fewer' : 'Show all {{ count($o['stock_cover']) }}'">Show all {{ count($o['stock_cover']) }}</button>
        @endif
    </div>
@endif
