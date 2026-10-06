@if($deep['devices'] !== null)
    @php
        // Devices & browsers (owner, 2026-10-06: "add option to see in which
        // device the site is being browsed"). Phone / tablet / computer with
        // how each one buys, then the browsers and apps — on a shop fed by
        // Facebook ads most visits open inside Facebook's own browser — and
        // the systems. Each visitor counts once per device. Tracking began on
        // 6 Oct 2026; older visits are counted apart as "before tracking".
        $d = $deep['devices'];
        $pct = fn (?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
        $icon = ['mobile' => '📱', 'tablet' => '📲', 'desktop' => '💻'];
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1 sm:mb-3">
            <h2 class="font-semibold text-sm sm:text-base">Devices &amp; browsers</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        @if($d['tracked'] === 0)
            <p class="text-sm text-ink-700/50">No visits recorded with a device yet — devices are counted from 6 Oct 2026.</p>
        @else
            <div class="space-y-2.5">
                @foreach($d['devices'] as $row)
                    <div>
                        <div class="flex justify-between gap-2 text-[13px] sm:text-sm">
                            <span>{{ $icon[$row['key']] ?? '' }} {{ $row['label'] }}</span>
                            <span class="tabular-nums whitespace-nowrap"><span class="font-medium">{{ number_format($row['visitors']) }}</span> <span class="text-ink-700/50">· {{ $pct($row['share']) }}</span></span>
                        </div>
                        <div class="mt-1 h-1.5 rounded-full bg-ink-100 overflow-hidden">
                            <div class="h-full rounded-full bg-gold-500" style="width: {{ max(2, (int) round($row['share'] ?? 0)) }}%"></div>
                        </div>
                        <p class="mt-0.5 text-[11px] text-ink-700/55 tabular-nums">
                            {{ $row['carted'] }} carted · {{ $row['checkout'] }} at checkout · {{ $row['orders'] }} order{{ $row['orders'] === 1 ? '' : 's' }}{{ $row['rate'] !== null ? ' ('.$pct($row['rate']).')' : '' }}@if($row['revenue'] > 0) · {{ money($row['revenue']) }}@endif
                        </p>
                    </div>
                @endforeach
            </div>

            @if($d['browsers'])
                <p class="mt-3 mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-700/45">Browsers &amp; apps</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($d['browsers'] as $b)
                        <span class="badge bg-ink-100 text-ink-700 text-[11px] tabular-nums">{{ $b['name'] }} · {{ $pct($b['share']) }}</span>
                    @endforeach
                </div>
            @endif

            @if($d['systems'])
                <p class="mt-2.5 mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-700/45">Systems</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($d['systems'] as $s)
                        <span class="badge bg-ink-100 text-ink-700 text-[11px] tabular-nums">{{ $s['name'] }} · {{ $pct($s['share']) }}</span>
                    @endforeach
                </div>
            @endif

            @if($d['untracked'] > 0)
                <p class="mt-2.5 text-[11px] text-ink-700/45">{{ number_format($d['untracked']) }} visitor{{ $d['untracked'] === 1 ? '' : 's' }} in this window came before device tracking began (6 Oct 2026).</p>
            @endif
        @endif
    </div>
@endif
