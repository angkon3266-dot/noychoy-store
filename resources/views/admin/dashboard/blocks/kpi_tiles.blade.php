{{-- KPI cards. Two across on a phone, three on a tablet, five from lg — ten
     cards in fours left a ragged row of two. Values are never truncated (a
     clipped ৳ figure is a wrong figure); a very long one wraps inside its card
     instead of spilling out. The value steps down to text-xl between sm and
     xl because five cards beside the sidebar are only ~135px wide. --}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 sm:gap-3 xl:gap-4">
    @php
        $cards = [
        ['Visitors', number_format($stats['visitors_period']), 'text-ink-800', number_format($stats['visitors_today']).' today'],
        ['Orders', $stats['orders_period'], 'text-gold-700', $per],
        ['To process', $stats['pending'] + $stats['processing'], 'text-amber-600', $stats['shipped'].' shipped · now'],
        ['Sales', money($stats['sales_period']), 'text-ink-800', money($stats['revenue_period']).' delivered'],
        ['Avg. order value', money($stats['aov']), 'text-ink-800', $per],
        ['COD success', $stats['cod_success'] === null ? '—' : $stats['cod_success'].'%', 'text-green-700', 'delivered / resolved · all time'],
        ['Customers', $stats['customers'], 'text-ink-800', $stats['repeat_rate'].'% repeat'],
        ['New customers', $stats['new_customers_period'], 'text-ink-800', $per],
        ['Low stock', $stats['low_stock'], 'text-red-600', '≤ '.($stats['low_stock_at'] ?? 3).' left · now'],
        ['Stock on hand', number_format($stats['stock_units']).' pcs', 'text-ink-800', money($stats['stock_cost_value']).' at cost'],
    ]; @endphp
    @foreach($cards as [$label, $value, $color, $sub])
        <div class="card min-w-0 p-3 sm:p-4 xl:p-5">
            <div class="truncate text-[11px] sm:text-sm text-ink-700/60" title="{{ $label }}">{{ $label }}</div>
            <div class="mt-0.5 sm:mt-1 text-lg sm:text-xl xl:text-2xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere] {{ $color }}">{{ $value }}</div>
            <div class="mt-0.5 text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ $sub }}</div>
        </div>
    @endforeach
</div>
