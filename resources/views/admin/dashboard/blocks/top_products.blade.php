@if($deep['topEarners'] !== null)
    @php
        // Top earners (owner, 2026-09-18: "add other analytical info on the
        // dashboard"). Until then this card was "Top products" — units sold
        // by name — which answered a third of the reorder question; Running
        // out soon (days) and Dead stock (names) answered the other two-thirds
        // on other cards. This ranks by the profit each piece made and puts
        // what is left, how fast it goes, and whether to reorder or stop on
        // the same row. The key stays `top_products` so a saved layout keeps
        // the card where the admin put it.
        //
        // Products by default; "By category" is the same ranking by category
        // with the cash on the shelf beside what it earned. Six rows, then
        // "Show all" up to twelve. On a phone a row shows the name, profit and
        // days left; the rest opens on a tap and is always open from md.
        $e = $deep['topEarners'];
        $rows = $e['rows'];
        $cats = $deep['earnersByCategory'];
        $pct = fn (?float $v) => $v === null ? '—' : $v.'%';
    @endphp
    <div class="card p-3 sm:p-5 group" x-data="{ tab: 'products', all: false }" :data-all="all">
        <div class="flex items-center justify-between gap-2 mb-1 sm:mb-3">
            <h2 class="min-w-0 truncate font-semibold text-sm sm:text-base">Top earners · {{ $range->label }}</h2>
            <div class="shrink-0 inline-flex rounded-lg border border-ink-100 p-1 text-[11px] sm:text-xs" role="tablist" aria-label="Top earners view">
                <button type="button" role="tab" @click="tab = 'products'" :aria-selected="tab === 'products'"
                        :class="tab === 'products' ? 'bg-ink-900 text-white' : 'text-ink-700/70'"
                        class="rounded-md px-2 py-0.5 font-medium">Products</button>
                <button type="button" role="tab" @click="tab = 'category'" :aria-selected="tab === 'category'"
                        :class="tab === 'category' ? 'bg-ink-900 text-white' : 'text-ink-700/70'"
                        class="rounded-md px-2 py-0.5 font-medium">By category</button>
            </div>
        </div>

        @if(empty($rows))
            <p class="text-sm text-ink-700/50">No sales in this window — try a longer period.</p>
        @else
            <div x-show="tab === 'products'" role="tabpanel">
                @foreach($rows as $r)
                    <div class="{{ $loop->index >= 6 ? 'hidden group-data-[all]:flex flex-col' : '' }} py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm group"
                         x-data="{ open: false }" :data-open="open">
                        <div class="flex items-center justify-between gap-2 min-w-0 cursor-pointer md:cursor-auto" @click="open = !open">
                            <span class="min-w-0 flex items-center gap-1.5">
                                @if(empty($r['deleted']) && $r['slug'])<a href="{{ route('admin.products.edit', $r['slug']) }}" class="min-w-0 truncate hover:text-gold-700" @click.stop>{{ $r['name'] }}</a>@else<span class="min-w-0 truncate text-ink-700/70">{{ $r['name'] }}</span>@endif
                                @if($r['badges']['reorder'])<span class="badge bg-amber-100 text-amber-700 text-[10px] shrink-0">Reorder</span>@endif
                                @if($r['badges']['slow'])<span class="badge bg-ink-100 text-ink-700 text-[10px] shrink-0">Slow</span>@endif
                                @if($r['cost_missing'])<span class="shrink-0 text-[10px] text-amber-700" title="No cost price on this line, so the profit is overstated">cost missing</span>@endif
                            </span>
                            <span class="shrink-0 text-right whitespace-nowrap tabular-nums">
                                <span class="font-semibold text-green-700">{{ money($r['profit']) }}</span>
                                <span class="text-ink-700/50 text-[11px] sm:text-xs">· {{ $pct($r['margin']) }}</span>
                            </span>
                        </div>
                        <div class="flex justify-between gap-2 min-w-0 mt-0.5 text-[11px] sm:text-xs text-ink-700/60 tabular-nums">
                            <span class="min-w-0 truncate">
                                @if($r['stock_left'] === null)
                                    stock not tracked
                                @else
                                    {{ $r['stock_left'] }} left{{ $r['days_left'] !== null ? ' · '.$r['days_left'].' d left' : '' }}
                                @endif
                                @if($r['variants'] !== null && $r['variants']['out'] > 0)
                                    · <span class="text-red-600">{{ $r['variants']['out'] }} of {{ $r['variants']['active'] }} options out</span>
                                @endif
                            </span>
                            <svg class="md:hidden h-3 w-3 shrink-0 text-ink-700/40 transition" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                        <div class="hidden md:block group-data-[open]:block mt-0.5 text-[11px] sm:text-xs text-ink-700/60 tabular-nums">
                            {{ $r['units'] }} sold · {{ money($r['revenue']) }} revenue
                            @if($r['sell_through_pct'] !== null) · sell-through {{ $r['sell_through_pct'] }}% @endif
                            @if($r['listed_days'] !== null) · listed {{ $r['listed_days'] }} d ago @endif
                        </div>
                    </div>
                @endforeach
                @if(count($rows) > 6)
                    <button type="button" @click="all = !all" class="mt-1.5 text-xs font-medium text-gold-700"
                            x-text="all ? 'Show fewer' : 'Show all {{ count($rows) }}'">Show all {{ count($rows) }}</button>
                @endif
                @if($e['overall']['cost_missing_items'] > 0)
                    <p class="mt-1.5 text-[11px] sm:text-xs text-amber-700">cost missing on {{ $e['overall']['cost_missing_items'] }} item{{ $e['overall']['cost_missing_items'] === 1 ? '' : 's' }} — those margins read high</p>
                @endif
            </div>

            <div x-show="tab === 'category'" x-cloak role="tabpanel">
                @if($cats === null)
                    <p class="text-sm text-ink-700/50">Category figures are not available right now.</p>
                @else
                    @forelse($cats as $c)
                        <div class="py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                            <div class="flex items-center justify-between gap-2 min-w-0">
                                <span class="min-w-0 truncate">{{ $c['name'] }}</span>
                                <span class="shrink-0 text-right whitespace-nowrap tabular-nums">
                                    <span class="font-semibold text-green-700">{{ money($c['profit']) }}</span>
                                    <span class="text-ink-700/50 text-[11px] sm:text-xs">· {{ $pct($c['margin']) }}</span>
                                </span>
                            </div>
                            <div class="flex justify-between gap-2 min-w-0 mt-0.5 text-[11px] sm:text-xs text-ink-700/60 tabular-nums">
                                <span class="min-w-0 truncate">{{ $c['units'] }} sold · {{ money($c['revenue']) }} revenue</span>
                                <span class="shrink-0 whitespace-nowrap">{{ money($c['stock_at_cost']) }} on the shelf</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-ink-700/50">No sales in this window — try a longer period.</p>
                    @endforelse
                @endif
            </div>
        @endif
    </div>
@endif
