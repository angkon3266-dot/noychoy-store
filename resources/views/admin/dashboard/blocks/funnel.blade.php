{{-- ── Traffic & conversion funnel ──────────────────────────────────────── --}}
@if($deep['funnel'])
    @php $f = $deep['funnel']; @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1">
            <h2 class="font-semibold text-sm sm:text-base">Conversion funnel</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }} · unique visitors</span>
        </div>
        @unless($f['tracking'])
            <p class="text-[11px] sm:text-xs text-ink-700/50 mb-2 sm:mb-3">No traffic recorded yet — figures fill in as customers visit the storefront.</p>
        @endunless
        <div class="space-y-2 sm:space-y-2.5 mt-2 sm:mt-3">
            @foreach($f['steps'] as $s)
                <div>
                    <div class="flex justify-between items-baseline text-[13px] sm:text-sm gap-3">
                        <span class="flex items-baseline gap-2 min-w-0">
                            <span class="truncate">{{ $s['label'] }}</span>
                            {{-- The money each step carried. A count is people;
                                 a value is money, summed over every event —
                                 one shopper can carry three items' worth. --}}
                            @if(($s['money'] ?? null) !== null)
                                <span class="text-[11px] sm:text-xs font-medium tabular-nums text-gold-700 whitespace-nowrap">{{ money($s['money']) }}</span>
                            @elseif(($s['unmeasured'] ?? 0) > 0)
                                <span class="text-[11px] sm:text-xs text-ink-700/40 whitespace-nowrap" title="These events were recorded before the funnel started storing values.">value not recorded</span>
                            @endif
                        </span>
                        <span class="font-medium tabular-nums whitespace-nowrap">{{ number_format($s['count']) }} <span class="text-ink-700/40 text-[11px] sm:text-xs">{{ $s['pct'] === null ? '' : $s['pct'].'%' }}</span></span>
                    </div>
                    <div class="h-1.5 sm:h-2 rounded-full bg-ink-100 overflow-hidden mt-0.5 sm:mt-1">
                        <div class="h-full bg-gold-500" style="width: {{ min(100, $s['pct'] ?? 0) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3 sm:mt-4 flex flex-wrap items-baseline gap-x-4 sm:gap-x-6 gap-y-0.5 text-[13px] sm:text-sm">
            <span>Visitor → order conversion:
                <strong>{{ $f['conversion'] === null ? '—' : $f['conversion'].'%' }}</strong></span>
            <span>Order value: <strong>{{ money($f['revenue'] ?? 0) }}</strong></span>
            @if(($f['abandoned'] ?? null) > 0)
                <span class="text-amber-700">Left at checkout: <strong>{{ money($f['abandoned']) }}</strong></span>
            @endif
        </div>
        @if(($f['unmeasured'] ?? 0) > 0)
            <p class="text-[11px] text-ink-700/45 mt-1.5">
                {{ $f['unmeasured'] }} cart/checkout event{{ $f['unmeasured'] === 1 ? '' : 's' }} in this window predate value
                tracking, so the totals above cover only the ones we measured.
            </p>
        @endif
    </div>
@endif
