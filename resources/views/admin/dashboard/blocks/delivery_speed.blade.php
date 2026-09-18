@if($deep['deliverySpeed'] !== null)
    @php
        // Delivery speed (owner, 2026-09-18: "add other analytical info on
        // the dashboard"). Nothing else on the page measures time: this says
        // whether a slow delivery was the shop's (late booking) or the
        // courier's (long transit), by zone, and whether the promise on the
        // product page is being kept. A zone with too few deliveries says so
        // rather than showing a median of two.
        $s = $deep['deliverySpeed'];
        $n = fn (int $count) => '<span class="text-ink-700/40">('.$count.')</span>';
        $days = fn (?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').' d';
        $shop = $s['shop_to_courier']['median_hours'];
        $shopText = $shop === null ? '—' : ($shop >= 48 ? rtrim(rtrim(number_format($shop / 24, 1), '0'), '.').' d' : round($shop).' h');
        $dist = $s['distribution'];
        $distMax = max(1, max($dist));
    @endphp
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-0.5 sm:mb-1">Delivery speed</h2>
        @if($s['n'] === 0)
            <p class="text-sm text-ink-700/50">No deliveries completed in this window.</p>
        @else
            <p class="text-[11px] sm:text-xs text-ink-700/50 mb-2 sm:mb-3">{{ $s['n'] }} delivered, {{ $per }}</p>
            <div class="space-y-1 text-[13px] sm:text-sm">
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">You → courier</span>
                    <span class="tabular-nums whitespace-nowrap">median {{ $shopText }} {!! $n($s['shop_to_courier']['n']) !!}</span>
                </div>
                <div class="text-ink-700/70">Courier → door</div>
                @foreach(['inside' => 'inside Dhaka', 'outside' => 'outside'] as $zone => $zoneLabel)
                    @php $z = $s['courier_to_door'][$zone]; @endphp
                    <div class="flex justify-between gap-2 pl-2">
                        <span class="text-ink-700/70">{{ $zoneLabel }}</span>
                        <span class="tabular-nums whitespace-nowrap">
                            @if($z['median_days'] === null)
                                — <span class="text-ink-700/40">too few to say</span> {!! $n($z['n']) !!}
                            @else
                                {{ $days($z['median_days']) }} · p90 {{ $days($z['p90_days']) }} {!! $n($z['n']) !!}
                            @endif
                        </span>
                    </div>
                @endforeach
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">On time</span>
                    <span class="tabular-nums whitespace-nowrap {{ $s['on_time']['pct'] !== null && $s['on_time']['pct'] < 70 ? 'text-amber-600' : '' }}">{{ $s['on_time']['pct'] === null ? '—' : $s['on_time']['pct'].'%' }} {!! $n($s['on_time']['n']) !!}</span>
                </div>
            </div>

            {{-- The two zones side by side: what each sends, what it is
                 worth, and how much of it actually arrives. --}}
            <table class="w-full mt-2 sm:mt-3 text-[11px] sm:text-xs tabular-nums">
                <thead class="text-[10px] uppercase tracking-wide text-ink-700/45">
                    <tr><th class="text-left font-medium py-0.5">Zone</th><th class="text-right font-medium">Orders</th><th class="text-right font-medium">AOV</th><th class="text-right font-medium">Delivered</th></tr>
                </thead>
                <tbody>
                    @foreach(['inside' => 'Inside Dhaka', 'outside' => 'Outside'] as $zone => $zoneLabel)
                        @php $z = $s['zones'][$zone]; @endphp
                        <tr class="border-t border-ink-100">
                            <td class="py-1">{{ $zoneLabel }}</td>
                            <td class="text-right">{{ $z['orders'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ $z['aov'] === null ? '—' : money($z['aov']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ $z['delivered_pct'] === null ? '—' : $z['delivered_pct'].'%' }} <span class="text-ink-700/40">({{ $z['resolved'] }} resolved)</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex justify-between gap-2 mt-1.5 text-[13px] sm:text-sm">
                <span class="text-ink-700/70">RTO</span>
                <span class="tabular-nums whitespace-nowrap {{ $s['rto']['pct'] !== null && $s['rto']['pct'] > 0 ? 'text-red-600' : '' }}">{{ $s['rto']['pct'] === null ? '—' : $s['rto']['pct'].'%' }} <span class="text-ink-700/40">(settled {{ $s['rto']['settled_n'] }})</span></span>
            </div>

            {{-- Booked → delivered, in days. Five small bars; a phone gives
                 this card no room for them. --}}
            <div class="hidden sm:block mt-2 sm:mt-3">
                <div class="flex items-end gap-1 h-8">
                    @foreach(['le1' => '≤1 d', 'd2' => '2 d', 'd3' => '3 d', 'd4_6' => '4–6 d', 'over7' => '7 d+'] as $bucket => $bucketLabel)
                        <div class="flex-1 min-w-0 rounded {{ $dist[$bucket] > 0 ? 'bg-gold-500' : 'bg-ink-100' }}" style="height: {{ max(6, round($dist[$bucket] / $distMax * 100)) }}%" title="{{ $bucketLabel }} · {{ $dist[$bucket] }}"></div>
                    @endforeach
                </div>
                <div class="grid grid-cols-5 gap-1 text-center text-[10px] text-ink-700/45 tabular-nums mt-0.5">
                    @foreach(['≤1 d', '2 d', '3 d', '4–6 d', '7 d+'] as $bucketLabel)
                        <div class="min-w-0 truncate">{{ $bucketLabel }}</div>
                    @endforeach
                </div>
            </div>
        @endif
        <p class="text-[11px] text-ink-700/45 mt-2">history since Aug 2026 · delivered = when Steadfast reported it</p>
    </div>
@endif
