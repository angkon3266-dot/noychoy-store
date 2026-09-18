@if($deep['leadRecovery'] !== null)
    @php
        // Leads & recovery (owner, 2026-09-18: "add other analytical info on
        // the dashboard"). The funnel's "left at checkout" is anonymous
        // money; this is the named-lead desk — who left a number, what was
        // done about it, and what came back. Windowed on when the cart was
        // captured. Shares are withheld under five carts, where a percentage
        // would be a coin toss.
        $l = $deep['leadRecovery'];
        $pct = fn (?float $v) => $v === null ? '—' : $v.'%';
        $attempts = collect($l['attempts'])->filter(fn ($c) => $c['count'] > 0);
        $outcomes = collect($l['outcomes'])->filter(fn ($c) => $c['count'] > 0);
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1 sm:mb-3">
            <h2 class="font-semibold text-sm sm:text-base">Leads &amp; recovery</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        @if($l['captured']['count'] === 0)
            <p class="text-sm text-ink-700/50">No carts captured in this window.</p>
        @else
            <div class="space-y-1 text-[13px] sm:text-sm">
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">Captured</span>
                    <span class="tabular-nums whitespace-nowrap">{{ $l['captured']['count'] }} · {{ money($l['captured']['amount']) }}</span>
                </div>
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">Contacted</span>
                    <span class="tabular-nums whitespace-nowrap">{{ $l['contacted']['count'] }} · {{ $pct($l['contacted']['pct']) }}</span>
                </div>
                @if($attempts->isNotEmpty())
                    <div class="flex flex-wrap gap-1 pl-2">
                        @foreach($attempts as $chip)
                            <span class="badge bg-ink-100 text-ink-700 text-[10px] tabular-nums">{{ $chip['label'] }} · {{ $chip['count'] }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">Reminders</span>
                    <span class="tabular-nums whitespace-nowrap">{{ $l['reminders']['sms'] }} SMS · {{ $l['reminders']['push'] }} push</span>
                </div>
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">Recovered</span>
                    <span class="tabular-nums whitespace-nowrap {{ $l['recovered']['count'] > 0 ? 'text-green-700 font-medium' : '' }}">{{ $l['recovered']['count'] }} · {{ $pct($l['recovered']['pct']) }}</span>
                </div>
                <p class="text-[11px] text-ink-700/45 tabular-nums -mt-0.5 pl-2">by hand {{ $l['recovered']['by_hand'] }} · on their own {{ $l['recovered']['on_their_own'] }}</p>
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">Recovered value</span>
                    <span class="tabular-nums whitespace-nowrap font-medium">{{ money($l['recovered_value']['amount']) }}</span>
                </div>
                @if($l['recovered_value']['includes_cart_values'])
                    <p class="text-[11px] text-ink-700/45 -mt-0.5 pl-2">includes cart values where no order was linked</p>
                @endif
            </div>
            @if($outcomes->isNotEmpty())
                <div class="mt-2 sm:mt-3 pt-2 border-t border-ink-100">
                    <div class="text-[10px] uppercase tracking-wide text-ink-700/45 mb-1">Outcomes of attempts</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach($outcomes as $chip)
                            <span class="badge bg-ink-50 text-ink-700 text-[10px] tabular-nums">{{ $chip['label'] }} · {{ $chip['count'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
@endif
