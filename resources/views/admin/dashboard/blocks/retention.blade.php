{{-- ── Customers & retention ────────────────────────────────────────────── --}}
@if($deep['retention'])
    @php $r = $deep['retention']; $rev = $r['new_revenue'] + $r['repeat_revenue']; @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-2 sm:mb-4">
            <h2 class="font-semibold text-sm sm:text-base">Customers &amp; retention</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-3 sm:mb-4">
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Repeat revenue share</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $r['repeat_share'] === null ? '—' : $r['repeat_share'].'%' }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ money($r['repeat_revenue']) }} of {{ money($rev) }}</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Lifetime value</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($r['clv']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">avg per buyer</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Days to 2nd order</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $r['avg_days_to_second'] ?? '—' }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ $r['repeat_customers'] }} repeat · {{ $r['one_time'] }} one-time</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">At risk</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums text-amber-600">{{ $r['at_risk'] }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">quiet 60+ days
                    <a href="{{ route('admin.notifications.index') }}" class="text-gold-700 hover:underline">win them back</a>
                </div>
            </div>
        </div>
        @if($rev > 0)
            <div class="flex h-2 sm:h-3 rounded-full overflow-hidden">
                <div class="bg-gold-500" style="width: {{ round($r['new_revenue'] / $rev * 100) }}%" title="New customers"></div>
                <div class="bg-ink-800" style="width: {{ round($r['repeat_revenue'] / $rev * 100) }}%" title="Repeat customers"></div>
            </div>
            <div class="flex justify-between text-[11px] sm:text-xs tabular-nums text-ink-700/50 mt-1 sm:mt-1.5">
                <span>New {{ money($r['new_revenue']) }}</span>
                <span>Repeat {{ money($r['repeat_revenue']) }}</span>
            </div>
        @endif
    </div>
@endif
