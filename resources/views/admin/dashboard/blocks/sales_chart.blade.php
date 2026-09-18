{{-- Revenue over the selected window (days grouped once it gets long).

     Thirty bars on a phone are ~10px each, and a ৳ label over every one of
     them was unreadable soup. The per-bar labels now show only while they
     fit (up to 7 bars on a phone, 14 above), the date axis thins to match,
     and hovering or tapping the chart reads that bar's exact figure out in
     the heading — so no bar's number is lost, it is one tap away. --}}
@php
    $bars = $daily->count();
    $barFigures = $daily->map(fn ($d) => $d['label'].' · '.money($d['total']))->values()->all();
    $axisEveryXs = max(1, (int) ceil($bars / 6));
    $axisEverySm = max(1, (int) ceil($bars / 14));
@endphp
<div class="card p-3 sm:p-5"
     x-data="{
        pick: null,
        figures: @js($barFigures),
        at(e) {
            const box = e.currentTarget.getBoundingClientRect();
            const i = Math.floor((e.clientX - box.left) / box.width * this.figures.length);
            this.pick = Math.min(this.figures.length - 1, Math.max(0, i));
        },
     }">
    <div class="flex items-baseline justify-between gap-2 mb-2 sm:mb-4">
        <h2 class="min-w-0 truncate font-semibold text-sm sm:text-base">Sales · {{ $range->label }}</h2>
        <span class="shrink-0 text-xs font-medium tabular-nums text-gold-700" x-text="pick === null ? '' : figures[pick]"></span>
    </div>
    <div class="flex items-end justify-between h-32 sm:h-44 {{ $bars > 14 ? 'gap-px sm:gap-1' : 'gap-1.5 sm:gap-3' }}"
         @mousemove="at($event)" @click="at($event)" @mouseleave="pick = null">
        @foreach($daily as $d)
            @php
                $i = $loop->index;
                $axisXs = $i % $axisEveryXs === 0;
                $axisSm = $i % $axisEverySm === 0;
                $axisClass = $axisXs ? ($axisSm ? '' : 'sm:invisible') : ($axisSm ? 'invisible sm:visible' : 'invisible');
                // A ~10px phone bar centres a 40px date on itself; the first
                // one would hang over the card's edge, so it starts flush.
                if ($i === 0 && $bars > 7) {
                    $axisClass .= ' self-start sm:self-center';
                }
            @endphp
            <div class="flex-1 min-w-0 flex flex-col items-center justify-end h-full">
                <div class="{{ $bars > 14 ? 'hidden' : ($bars > 7 ? 'hidden sm:block' : '') }} mb-1 whitespace-nowrap text-[9px] sm:text-[10px] tabular-nums text-ink-700/50">{{ $d['total'] > 0 ? money($d['total']) : '' }}</div>
                <div class="w-full rounded-t bg-gold-400/80 transition-opacity" :class="pick !== null && pick !== {{ $i }} && 'opacity-40'"
                     style="height: {{ $d['total'] > 0 ? max(4, round($d['total'] / $dailyMax * 100)) : 1 }}%"></div>
                <div class="{{ $axisClass }} mt-1 sm:mt-2 whitespace-nowrap text-[10px] sm:text-xs text-ink-700/50">{{ $d['label'] }}</div>
            </div>
        @endforeach
    </div>
</div>
