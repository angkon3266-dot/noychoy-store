@if($deep['cashAtCourier'] !== null)
    @php
        // Cash with the courier (owner, 2026-09-18: "add other analytical
        // info on the dashboard"). The KPI tile counts parcels out; this is
        // the money in them and how long each has been out, because a parcel
        // out for more than a week is a return in the making and the one to
        // ring Steadfast about. Live figures are right now, whatever the
        // window; only "Collected" follows it.
        $c = $deep['cashAtCourier'];
        $live = $c['live'];
        $parcels = fn (int $n) => $n.' parcel'.($n === 1 ? '' : 's');
    @endphp
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-0.5 sm:mb-1">Cash with the courier</h2>
        @if($live['count'] === 0)
            <p class="text-sm text-ink-700/50">Nothing is out with the courier right now.</p>
        @else
            <div class="text-2xl sm:text-3xl font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($live['amount']) }}</div>
            <p class="text-[11px] sm:text-xs text-ink-700/50 mb-2 sm:mb-3">{{ $parcels($live['count']) }} out, COD not yet settled · now</p>

            <div class="space-y-1 text-[13px] sm:text-sm">
                @foreach([
                    ['Booked, waiting for pickup', $live['stages']['booked_waiting']],
                    ['Out for delivery', $live['stages']['out_for_delivery']],
                ] as [$stageLabel, $stage])
                    <div class="flex justify-between gap-2 min-w-0">
                        <span class="min-w-0 truncate text-ink-700/70">{{ $stageLabel }}</span>
                        <span class="shrink-0 tabular-nums whitespace-nowrap">{{ money($stage['amount']) }} <span class="text-ink-700/40">· {{ $stage['count'] }}</span></span>
                    </div>
                @endforeach
            </div>

            {{-- Age since booking. Over a week is red the moment it is non-empty. --}}
            <div class="mt-2 sm:mt-3 grid grid-cols-3 gap-1.5 text-center">
                @foreach([
                    ['≤ 3 d', $live['ages']['le3'], ''],
                    ['4–7 d', $live['ages']['d4_7'], ''],
                    ['Over 7 d', $live['ages']['over7'], $live['ages']['over7']['count'] > 0 ? 'text-red-600' : ''],
                ] as [$ageLabel, $age, $ageClass])
                    <div class="rounded-md bg-ink-50 px-1 py-1 min-w-0">
                        <div class="text-[10px] uppercase tracking-wide text-ink-700/45">{{ $ageLabel }}</div>
                        <div class="text-[13px] sm:text-sm leading-tight font-semibold tabular-nums [overflow-wrap:anywhere] {{ $ageClass }}">{{ money($age['amount']) }}</div>
                        <div class="text-[11px] tabular-nums {{ $ageClass ?: 'text-ink-700/50' }}">{{ $age['count'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($c['stale']['count'] > 0)
            <p class="mt-2 text-[11px] sm:text-xs text-ink-700/55 tabular-nums">
                {{ $c['stale']['count'] }} open consignment{{ $c['stale']['count'] === 1 ? '' : 's' }} on settled orders · {{ money($c['stale']['amount']) }} — cancel them at Steadfast
            </p>
        @endif
        @if($c['rebooked']['count'] > 0)
            <p class="mt-1 text-[11px] sm:text-xs text-ink-700/55 tabular-nums">
                Re-booked: {{ $c['rebooked']['count'] }} ({{ $c['rebooked']['delivered_after_replacement'] }} delivered on the old consignment)
            </p>
        @endif

        <div class="mt-2 sm:mt-3 pt-2 border-t border-ink-100 text-[13px] sm:text-sm">
            <div class="flex justify-between gap-2">
                <span class="text-ink-700/70">Steadfast balance</span>
                <span class="tabular-nums whitespace-nowrap">{{ $c['balance'] === null ? '—' : money($c['balance']) }}</span>
            </div>
            <p class="text-[11px] text-ink-700/45">{{ $c['balance'] !== null ? 'collected, not yet paid out' : (! empty($c['balance_available']) ? 'balance unavailable right now' : 'not connected') }}</p>
            <div class="flex justify-between gap-2 mt-1">
                <span class="min-w-0 truncate text-ink-700/70">Collected · {{ $per }}</span>
                <span class="shrink-0 tabular-nums whitespace-nowrap">{{ money($c['collected']['amount']) }} <span class="text-ink-700/40">({{ $c['collected']['count'] }})</span></span>
            </div>
            <p class="text-[11px] text-ink-700/45">by delivery date, tracked since Aug 2026</p>
        </div>
    </div>
@endif
