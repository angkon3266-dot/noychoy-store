@php
    $chg = function ($v) {
        if ($v === null) return ['—', 'text-ink-700/40'];
        return [($v > 0 ? '▲ ' : ($v < 0 ? '▼ ' : '')).abs($v).'%', $v > 0 ? 'text-green-700' : ($v < 0 ? 'text-red-600' : 'text-ink-700/50')];
    };
@endphp

{{-- ── Revenue & profit (selected window vs the one before it) ─────────── --}}
@if($deep['profit'])
    @php $p = $deep['profit']; [$revTxt, $revCls] = $chg($p['revenue_change']); [$proTxt, $proCls] = $chg($p['profit_change']); @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-2 sm:mb-4">
            <h2 class="font-semibold text-sm sm:text-base">Revenue &amp; profit</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}{{ $range->isAllTime() ? '' : ' vs previous period' }}</span>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Revenue</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($p['current']['revenue']) }}</div>
                {{-- "Maximum" has no earlier period to compare against. --}}
                <div class="text-[11px] sm:text-xs leading-snug {{ $revCls }}">{{ $revTxt }}
                    @if($p['previous'])<span class="text-ink-700/40">vs {{ money($p['previous']['revenue']) }}</span>@endif
                </div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Profit</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere] text-green-700">{{ money($p['current']['profit']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug {{ $proCls }}">{{ $proTxt }}
                    @if($p['previous'])<span class="text-ink-700/40">vs {{ money($p['previous']['profit']) }}</span>@endif
                </div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Margin</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $p['current']['margin'] === null ? '—' : $p['current']['margin'].'%' }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">cost {{ money($p['current']['cost']) }}</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Items per order</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $p['items_per_order'] }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">AOV {{ money($p['aov']) }} · {{ money($p['discount_given']) }} discounts</div>
            </div>
        </div>
        @if($p['current']['cost'] <= 0 && $p['current']['revenue'] > 0)
            <p class="text-[11px] sm:text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2.5 sm:px-3 py-1.5 sm:py-2 mt-3 sm:mt-4">
                Profit assumes zero cost — add <strong>cost price</strong> (and transport) on your products so margin is real.
            </p>
        @endif
    </div>
@endif
