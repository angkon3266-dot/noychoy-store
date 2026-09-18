@if($deep['stockHealth'] !== null)
    @php
        // Cash in stock (owner, 2026-09-18: "add other analytical info on
        // the dashboard"). The KPI tile gives stock at cost as one number and
        // Dead stock gives names with no money; this adds what the shelf
        // would sell for, how fast it turns, and the ৳ locked in pieces that
        // did not move in the window — the purchase-order-or-clearance call
        // with numbers instead of a feeling.
        $st = $deep['stockHealth'];
        $still = $st['sitting_still'];
        $cats = collect($st['sitting_still_by_category'])->filter(fn ($c) => $c['amount'] > 0)->take(3);
        $weeks = $st['weeks_of_cover'];
    @endphp
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-2 sm:mb-3">Cash in stock</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">At cost</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($st['stock_at_cost']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ number_format($st['units_on_hand']) }} pcs on hand</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">At sell price</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($st['stock_at_sell']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">product price; variant prices ignored</div>
            </div>
        </div>

        <div class="mt-2 sm:mt-3 space-y-1 text-[13px] sm:text-sm">
            <div class="flex justify-between gap-2">
                <span class="min-w-0 truncate text-ink-700/70">Sell-through · {{ $per }}</span>
                <span class="shrink-0 tabular-nums whitespace-nowrap">{{ $st['sell_through_pct'] === null ? '—' : $st['sell_through_pct'].'%' }} <span class="text-ink-700/40">({{ number_format($st['units_sold']) }} sold)</span></span>
            </div>
            <div class="flex justify-between gap-2">
                <span class="text-ink-700/70">Weeks of cover</span>
                <span class="tabular-nums whitespace-nowrap">{{ $weeks === null ? '—' : rtrim(rtrim(number_format($weeks, 1), '0'), '.') }}</span>
            </div>
            <div class="flex justify-between gap-2">
                <span class="text-ink-700/70">Sitting still</span>
                <span class="tabular-nums whitespace-nowrap {{ $still['amount'] > 0 ? 'text-amber-600' : '' }}">{{ money($still['amount']) }}</span>
            </div>
            <p class="text-[11px] text-ink-700/45 tabular-nums -mt-0.5">{{ $still['pct_of_cost'] === null ? '—' : $still['pct_of_cost'].'%' }} of stock at cost · {{ number_format($still['units']) }} unit{{ $still['units'] === 1 ? '' : 's' }} with no sale {{ $per }}</p>
        </div>

        @if($cats->isNotEmpty())
            <div class="mt-2 sm:mt-3 pt-2 border-t border-ink-100">
                <div class="text-[10px] uppercase tracking-wide text-ink-700/45 mb-0.5">Sitting still by category</div>
                @foreach($cats as $cat)
                    <div class="flex justify-between gap-2 text-[13px] sm:text-sm py-0.5">
                        <span class="min-w-0 truncate text-ink-700/70">{{ $cat['name'] }}</span>
                        <span class="shrink-0 tabular-nums whitespace-nowrap">{{ money($cat['amount']) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if($st['cost_gap_count'] > 0)
            <p class="text-[11px] sm:text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2.5 sm:px-3 py-1.5 sm:py-2 mt-2 sm:mt-3">
                Cost missing on {{ $st['cost_gap_count'] }} product{{ $st['cost_gap_count'] === 1 ? '' : 's' }} — every ৳ above is an underestimate until it is entered.
            </p>
        @endif
    </div>
@endif
