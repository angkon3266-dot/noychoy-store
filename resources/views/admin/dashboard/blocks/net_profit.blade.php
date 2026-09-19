{{-- Expenses & net profit (owner, 2026-09-19) — App\Support\ExpenseReport for
     the chosen window. Null for anyone who may not open Admin → Expenses, so
     the card stays empty for them. --}}
@if($deep['netProfit'])
    @php
        $n = $deep['netProfit'];
        $signed = fn ($v) => ($v < 0 ? '−' : '').money(abs($v));
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-2 sm:mb-4">
            <h2 class="font-semibold text-sm sm:text-base">Expenses &amp; net profit</h2>
            <a href="{{ route('admin.expenses.index', $range->queryParams()) }}" class="text-[11px] sm:text-xs text-gold-700 hover:underline">{{ $n['count'] ? 'See expenses' : 'Log an expense' }} →</a>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Sales</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($n['sales']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">after discounts, with delivery</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Product cost</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($n['product_cost']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">gross profit {{ $signed($n['gross']) }}</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Expenses</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($n['expenses']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">
                    {{ $n['count'] }} logged{{ $n['stock_bought'] > 0 ? ' · '.money($n['stock_bought']).' stock not included' : '' }}
                </div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Net profit</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere] {{ $n['net'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $signed($n['net']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ $n['sales'] > 0 ? round($n['net'] / $n['sales'] * 100, 1).'% of sales' : '—' }}</div>
            </div>
        </div>
        @if($n['count'] === 0)
            <p class="text-[11px] sm:text-xs text-ink-700/60 bg-ink-50 rounded px-2.5 sm:px-3 py-1.5 sm:py-2 mt-3 sm:mt-4">
                Nothing logged for {{ $per }} yet, so net profit is still just gross profit —
                <a href="{{ route('admin.expenses.index', $range->queryParams()) }}" class="text-gold-700 underline">log ads, courier bills, packaging and the rest</a>.
            </p>
        @endif
    </div>
@endif
