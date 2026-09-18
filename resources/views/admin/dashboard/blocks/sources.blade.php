@if($deep['sources'] !== null)
    {{-- Where visitors come from. Each row opens to name the sites and
         campaigns underneath it — "Other website" is the channel that means
         "we could not name this", so leaving it closed answers nothing.

         Up to eight channels with a step strip each ran to a phone-screen
         and a half, so the card folds on a phone. Each step cell now puts
         its count and rate on one line, which also tightens it on desktop.

         Since 2026-09-18 (owner: "add other analytical info on the
         dashboard") each row also carries what its orders were worth AFTER
         the order — profit per order and how many actually got delivered —
         from DashboardInsights::channelEconomics, keyed by the same channel.
         A channel can win on order count and lose on cash-on-delivery
         refusals and margin, and that is the number the ad budget should
         follow. The two figures show on every width; margin, AOV, first-time
         buyers and cancellations sit inside the row's expand. --}}
    <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
        <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
            <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Where visitors come from</h2>
            <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide where visitors come from">
                <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
        <div class="hidden md:block group-data-[open]:block">
            <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">
                Visitors and what each channel earned — {{ $per }}. Tap a row for the detail.
                Each step below is counted as a share of the step before it.
            </p>
            @forelse($deep['sources'] as $s)
                @php
                    // Null when the economics were not computed or failed —
                    // the row then reads exactly as it did before them.
                    $econ = is_array($deep['channelEconomics'] ?? null) ? ($deep['channelEconomics'][$s['channel']] ?? null) : null;
                    $hasDetail = ! empty($s['sites']) || ! empty($s['campaigns']) || $econ !== null;
                @endphp
                <div class="py-1.5 sm:py-2 border-b border-ink-100 last:border-0" x-data="{ open: false }">
                    <div class="flex justify-between items-center text-[13px] sm:text-sm gap-2 {{ $hasDetail ? 'cursor-pointer' : '' }}"
                         @if($hasDetail) @click="open = !open" @endif>
                        <span class="flex items-center gap-1.5 min-w-0">
                            <span class="badge {{ \App\Support\TrafficSource::badgeClass($s['channel']) }} shrink-0">{{ $s['label'] }}</span>
                            @if($hasDetail)
                                <svg class="w-3 h-3 text-ink-700/40 shrink-0 transition" :class="open && 'rotate-90'"
                                     fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                            @endif
                        </span>
                        <span class="font-medium tabular-nums whitespace-nowrap">{{ number_format($s['visitors']) }} visitor{{ $s['visitors'] === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="flex justify-between text-[11px] sm:text-xs text-ink-700/60 mt-0.5 sm:mt-1">
                        <span>{{ $s['orders'] }} order{{ $s['orders'] === 1 ? '' : 's' }}{{ $s['rate'] !== null ? ' · '.$s['rate'].'% convert' : '' }}</span>
                        <span class="font-medium tabular-nums text-ink-700/80">{{ money($s['revenue']) }}</span>
                    </div>
                    @if($econ !== null)
                        <div class="flex justify-between gap-2 text-[11px] sm:text-xs text-ink-700/60 mt-0.5 tabular-nums min-w-0">
                            <span class="min-w-0 truncate">Profit/order <span class="font-medium {{ $econ['profit_per_order'] !== null && $econ['profit_per_order'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $econ['profit_per_order'] === null ? '—' : money($econ['profit_per_order']) }}</span></span>
                            <span class="shrink-0 whitespace-nowrap">Delivered <span class="font-medium">{{ $econ['delivered_pct'] === null ? '—' : $econ['delivered_pct'].'%' }}</span> <span class="text-ink-700/40">({{ $econ['resolved'] }} resolved)</span></span>
                        </div>
                    @endif

                    {{-- Where this channel's people stopped. Each percentage is
                         of the step above it, not of all visitors: "8 reached
                         checkout, 2 ordered" is the sentence that says whether
                         the traffic is wrong or the checkout is. --}}
                    <div class="mt-1.5 sm:mt-2 grid grid-cols-3 gap-px overflow-hidden rounded-md bg-ink-100 text-center">
                        @foreach([
                            ['Cart', $s['carted'], $s['carted_rate']],
                            ['Checkout', $s['checkout'], $s['checkout_rate']],
                            ['Ordered', $s['orders'], $s['order_rate']],
                        ] as [$stepLabel, $stepCount, $stepRate])
                            <div class="bg-white px-1 py-1">
                                <div class="text-[10px] uppercase tracking-wide text-ink-700/45">{{ $stepLabel }}</div>
                                <div class="text-[13px] sm:text-sm leading-tight font-semibold tabular-nums {{ $stepCount ? '' : 'text-ink-700/30' }}">{{ number_format($stepCount) }}
                                    <span class="text-[10px] font-normal text-ink-700/45">{{ $stepRate !== null ? $stepRate.'%' : '—' }}</span></div>
                            </div>
                        @endforeach
                    </div>

                    @if($s['visitors'] === 0 && $s['orders'] > 0)
                        <p class="mt-1 text-[11px] text-ink-700/45">
                            No visits behind these — orders typed in by hand, so there is no funnel to measure.
                        </p>
                    @endif
                    @if($hasDetail)
                        <div x-show="open" x-cloak x-collapse class="mt-2 pl-1 border-l-2 border-ink-100 space-y-1">
                            @if($econ !== null)
                                <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 pl-2 text-xs text-ink-700/70 tabular-nums">
                                    <span>Margin <span class="font-medium">{{ $econ['margin'] === null ? '—' : $econ['margin'].'%' }}</span></span>
                                    <span>AOV <span class="font-medium">{{ $econ['aov'] === null ? '—' : money($econ['aov']) }}</span></span>
                                    <span>First-time buyers <span class="font-medium">{{ $econ['first_time_pct'] === null ? '—' : $econ['first_time_pct'].'%' }}</span> <span class="text-ink-700/40">({{ $econ['first_time_n'] }})</span></span>
                                    <span>Cancelled <span class="font-medium {{ $econ['cancelled'] > 0 ? 'text-red-600' : '' }}">{{ $econ['cancelled'] }}</span></span>
                                </div>
                            @endif
                            @foreach($s['sites'] as $site)
                                <div class="flex justify-between text-xs text-ink-700/70 pl-2">
                                    <span class="truncate">{{ $site['name'] }}</span>
                                    <span class="whitespace-nowrap ml-2">{{ number_format($site['visitors']) }}</span>
                                </div>
                            @endforeach
                            @foreach($s['campaigns'] as $camp)
                                <div class="flex justify-between text-xs text-ink-700/70 pl-2">
                                    <span class="truncate">🏷 {{ $camp['name'] }}</span>
                                    <span class="whitespace-nowrap ml-2">{{ number_format($camp['visitors']) }}</span>
                                </div>
                            @endforeach
                            @if($s['channel'] === 'referral')
                                <p class="text-[11px] text-ink-700/45 pl-2 pt-1">
                                    Grouped here because the link carried a <code>utm_source</code> this store doesn't
                                    recognise. Rows with no site listed came from an app that strips the referrer.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-ink-700/50">No traffic data yet.</p>
            @endforelse
        </div>
    </div>
@endif
