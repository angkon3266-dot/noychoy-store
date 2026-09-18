@if($deep['orderClock'] !== null)
    @php
        // When customers buy (owner, 2026-09-18: "add other analytical info
        // on the dashboard"). Orders and checkout starts by hour of the day
        // and orders by weekday, in the shop's own clock — when to boost an
        // ad, send a text, and be at the phone. Two 24-cell strips drawn as
        // plain divs whose height and opacity follow the count: no chart
        // library, nothing to load, and it reads at 375px. Checkouts are
        // shown too because a few dozen orders are too few to see a shape in,
        // where hundreds of checkout starts are not.
        $k = $deep['orderClock'];
        $hourLabel = fn (int $h) => \Illuminate\Support\Carbon::createFromTime($h, 0, 0, $k['timezone'])->format('g a');
        $strip = function (array $cells, string $what) use ($hourLabel) {
            $max = max(1, max($cells));
            $out = '';
            foreach ($cells as $h => $n) {
                $ratio = $n / $max;
                $out .= sprintf(
                    '<div class="flex-1 min-w-0 rounded %s" style="height: %d%%; opacity: %s" data-hour="%d" data-n="%d" title="%s · %d %s"></div>',
                    $n > 0 ? 'bg-gold-500' : 'bg-ink-100',
                    $n > 0 ? max(12, round($ratio * 100)) : 8,
                    $n > 0 ? number_format(0.3 + 0.7 * $ratio, 2) : '1',
                    $h, $n, e($hourLabel($h)), $n, $what
                );
            }

            return $out;
        };
        // Saturday first: the working week here starts on Saturday.
        $weekOrder = [6, 0, 1, 2, 3, 4, 5];
        $weekLabels = [6 => 'Sat', 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $weekMax = max(1, max($k['weekdays']));
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1 sm:mb-3">
            <h2 class="font-semibold text-sm sm:text-base">When customers buy</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        @if($k['n']['orders'] === 0)
            <p class="text-sm text-ink-700/50">No orders in this window.</p>
        @else
            <div class="text-[10px] uppercase tracking-wide text-ink-700/45">Orders by hour</div>
            <div class="flex items-end gap-px h-8 mt-0.5" data-strip="orders">{!! $strip($k['hours'], 'orders') !!}</div>
            <div class="grid grid-cols-4 text-[10px] text-ink-700/45 tabular-nums mt-0.5">
                <span>12a</span><span>6a</span><span>12p</span><span>6p</span>
            </div>

            <div class="text-[10px] uppercase tracking-wide text-ink-700/45 mt-2 sm:mt-3">Checkouts started by hour</div>
            @if($k['tracking'])
                <div class="flex items-end gap-px h-8 mt-0.5" data-strip="checkouts">{!! $strip($k['checkouts'], 'checkouts') !!}</div>
                <div class="grid grid-cols-4 text-[10px] text-ink-700/45 tabular-nums mt-0.5">
                    <span>12a</span><span>6a</span><span>12p</span><span>6p</span>
                </div>
            @else
                <p class="text-[11px] text-ink-700/45">no visit tracking yet</p>
            @endif

            @if($k['best_hours'])
                <p class="mt-2 text-[13px] sm:text-sm"><span class="font-medium">Best hours:</span> {{ implode(', ', $k['best_hours']) }}</p>
            @endif

            <div class="text-[10px] uppercase tracking-wide text-ink-700/45 mt-2 sm:mt-3">Orders by weekday</div>
            <div class="flex gap-1 mt-0.5 text-center">
                @foreach($weekOrder as $day)
                    @php $n = $k['weekdays'][$day] ?? 0; @endphp
                    <div class="flex-1 rounded-md py-1 min-w-0" style="background-color: rgba(182, 134, 58, {{ $n > 0 ? number_format(0.15 + 0.85 * $n / $weekMax, 2) : '0.06' }})" data-weekday="{{ $day }}" data-n="{{ $n }}" title="{{ $weekLabels[$day] }} · {{ $n }} orders">
                        <div class="text-[10px] text-ink-700/60">{{ $weekLabels[$day] }}</div>
                        <div class="text-[11px] sm:text-xs font-semibold tabular-nums {{ $n > 0 ? '' : 'text-ink-700/30' }}">{{ $n }}</div>
                    </div>
                @endforeach
            </div>

            <p class="text-[11px] text-ink-700/45 mt-2 tabular-nums">n = {{ number_format($k['n']['orders']) }} orders · {{ number_format($k['n']['checkouts']) }} checkouts · Dhaka time</p>
        @endif
    </div>
@endif
