@if($deep['discountLeakage'] !== null)
    @php
        // Where the discounts go (owner, 2026-09-18: "add other analytical
        // info on the dashboard"). Revenue & profit prints one "discounts"
        // figure; this takes it apart by who gave it away — ladder, member
        // pricing, points, coupons and offers, free delivery — as a share of
        // revenue, and says whether ladder orders are the bigger baskets the
        // ladder was meant to buy. The two detail lists (orders per rung, and
        // each coupon code) fold shut on a phone and stay open on a desk.
        $d = $deep['discountLeakage'];
        $given = $d['given_away'];
        $pay = $d['ladder_payoff'];
        $rungMax = max(1, max(array_column($d['ladder_rungs'], 'count') ?: [0]));
        $pct = fn (?float $v) => $v === null ? '—' : $v.'%';
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1 sm:mb-3">
            <h2 class="font-semibold text-sm sm:text-base">Where the discounts go</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        @if($given['amount'] <= 0)
            <p class="text-sm text-ink-700/50">No discounts given in this window.</p>
        @else
            <div class="flex items-baseline gap-2 flex-wrap">
                <div class="text-2xl sm:text-3xl font-semibold tabular-nums [overflow-wrap:anywhere] text-amber-700">{{ money($given['amount']) }}</div>
                <p class="text-[11px] sm:text-xs text-ink-700/50">given away · {{ $pct($given['pct']) }} of {{ money($d['revenue']) }} revenue</p>
            </div>

            <div class="mt-2 sm:mt-3 divide-y divide-ink-100 text-[13px] sm:text-sm">
                @foreach($d['rows'] as $key => $row)
                    <div class="flex items-center justify-between gap-2 py-1 sm:py-1.5 min-w-0">
                        <span class="min-w-0 truncate {{ $row['amount'] > 0 ? '' : 'text-ink-700/40' }}">{{ $row['label'] }}@if($key === 'coupons_offers') <span class="text-[11px] text-ink-700/40">incl. stacked offers</span>@endif</span>
                        <span class="shrink-0 tabular-nums whitespace-nowrap {{ $row['amount'] > 0 ? '' : 'text-ink-700/40' }}">
                            {{ money($row['amount']) }}
                            <span class="text-ink-700/40 text-[11px] sm:text-xs">· {{ $pct($row['pct_of_revenue']) }} · {{ $row['orders'] }} order{{ $row['orders'] === 1 ? '' : 's' }}</span>
                        </span>
                    </div>
                @endforeach
            </div>

            <p class="mt-2 text-[11px] sm:text-xs text-ink-700/55 tabular-nums">
                Points issued: {{ number_format($d['points_issued']['points']) }} ({{ money($d['points_issued']['value']) }}) on delivered orders — a liability, not in the total above.
            </p>

            {{-- Did the ladder pay? Web checkouts only, both n printed, so a
                 seven-order baseline is visibly thin. --}}
            <p class="mt-1.5 text-[13px] sm:text-sm">
                <span class="font-medium">Did the ladder pay?</span>
                @if($pay['with']['n'] > 0 && $pay['without']['n'] > 0)
                    <span class="tabular-nums">Ladder orders {{ money($pay['with']['aov']) }} avg · {{ $pay['with']['items_per_order'] }} items ({{ $pay['with']['n'] }}) — without {{ money($pay['without']['aov']) }} · {{ $pay['without']['items_per_order'] }} items ({{ $pay['without']['n'] }}).</span>
                @else
                    <span class="text-ink-700/50 tabular-nums">Not enough web orders on both sides to compare yet ({{ $pay['with']['n'] }} with the ladder · {{ $pay['without']['n'] }} without).</span>
                @endif
            </p>

            {{-- Orders per rung. --}}
            <div class="mt-2 sm:mt-3 pt-2 border-t border-ink-100 group" x-data="{ expanded: false }" :data-open="expanded">
                <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
                    <h3 class="min-w-0 flex-1 text-xs sm:text-sm font-semibold">Orders per rung</h3>
                    <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide orders per rung">
                        <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                </div>
                <div class="hidden md:block group-data-[open]:block mt-1 space-y-1">
                    @foreach($d['ladder_rungs'] as $rung)
                        <div class="flex items-center gap-2 text-[11px] sm:text-xs tabular-nums min-w-0">
                            <span class="w-24 shrink-0 truncate {{ $rung['count'] > 0 ? 'text-ink-700/70' : 'text-ink-700/40' }}">{{ $rung['n'] === 0 ? 'No ladder' : $rung['label'] }}</span>
                            <span class="flex-1 min-w-0 h-1.5 rounded-full bg-ink-100 overflow-hidden"><span class="block h-full {{ $rung['n'] === 0 ? 'bg-ink-300' : 'bg-gold-500' }}" style="width: {{ round($rung['count'] / $rungMax * 100) }}%"></span></span>
                            <span class="w-6 shrink-0 text-right {{ $rung['count'] > 0 ? '' : 'text-ink-700/40' }}">{{ $rung['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Each coupon code. "Discount on these orders" is the whole
                 discount on the orders that used the code, not the code's own
                 share — only the capped total is stored. --}}
            <div class="mt-2 pt-2 border-t border-ink-100 group" x-data="{ expanded: false }" :data-open="expanded">
                <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
                    <h3 class="min-w-0 flex-1 text-xs sm:text-sm font-semibold">Coupons</h3>
                    <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide coupons">
                        <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                </div>
                <div class="hidden md:block group-data-[open]:block mt-1">
                    @forelse($d['coupons']['codes'] as $code)
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm min-w-0">
                            <span class="min-w-0 truncate"><code class="text-xs">{{ $code['code'] }}</code> <span class="text-ink-700/50 text-[11px] sm:text-xs">· {{ $code['uses'] }} use{{ $code['uses'] === 1 ? '' : 's' }}</span></span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap">{{ money($code['revenue']) }} <span class="text-ink-700/40 text-[11px] sm:text-xs">· {{ money($code['discount_on_orders']) }} discount on these orders</span></span>
                        </div>
                    @empty
                        <p class="text-[13px] sm:text-sm text-ink-700/50">No coupon used in this window.</p>
                    @endforelse
                    @if($d['coupons']['active_unused_count'] > 0)
                        <p class="mt-1 text-[11px] sm:text-xs text-ink-700/45">{{ $d['coupons']['active_unused_count'] }} active code{{ $d['coupons']['active_unused_count'] === 1 ? '' : 's' }} unused</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endif
