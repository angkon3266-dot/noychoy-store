@if($deep['series'] !== null)
    {{-- The funnel over time. Visitors alone only say whether traffic moved;
         plotted against the steps under it, the same chart says where it
         stopped. funnelChart measures the plot box (x-ref="plot") and draws
         at its real width, shorter on a phone — see app.js. --}}
    <div class="card p-3 sm:p-5"
         x-data="funnelChart({ rows: @js(collect($deep['series'])->values()) })">
        <div class="flex flex-wrap items-center justify-between gap-2 sm:gap-3 mb-1">
            <h2 class="font-semibold text-sm sm:text-base">Traffic &amp; conversion over time</h2>
            <div class="flex flex-wrap gap-1 sm:gap-1.5">
                <template x-for="s in series" :key="s.key">
                    <button type="button" @click="toggle(s.key)"
                            class="inline-flex items-center gap-1 sm:gap-1.5 rounded-full border px-2 sm:px-2.5 py-0.5 sm:py-1 text-[11px] sm:text-xs transition"
                            :class="off[s.key] ? 'border-ink-100 text-ink-700/40' : 'border-ink-200 text-ink-700'">
                        <span class="h-2 w-2 rounded-full" :style="`background:${off[s.key] ? '#cbd5e1' : s.color}`"></span>
                        <span x-text="s.label"></span>
                        <span class="tabular-nums text-ink-700/40" x-text="total(s.key)"></span>
                    </button>
                </template>
            </div>
        </div>
        <p class="text-[11px] sm:text-xs text-ink-700/55 mb-2 sm:mb-3">{{ $range->label }} · switch a line off to rescale the rest.</p>

        <div class="relative" x-ref="plot" @mousemove="track($event)" @mouseleave="hover = null"
             @touchstart.passive="track($event)" @touchmove.passive="track($event)">
            <div x-html="svg()"></div>
            <div x-show="hover !== null" x-cloak
                 class="pointer-events-none absolute z-10 rounded-lg border border-ink-100 bg-white/95 px-2.5 sm:px-3 py-1.5 sm:py-2 text-[11px] sm:text-xs shadow-lg backdrop-blur"
                 :style="tooltipStyle()">
                <div class="font-semibold mb-1" x-text="hover !== null ? rows[hover].label : ''"></div>
                <template x-for="s in series" :key="s.key">
                    <div class="flex items-center gap-2" x-show="!off[s.key]">
                        <span class="h-2 w-2 rounded-full shrink-0" :style="`background:${s.color}`"></span>
                        <span class="text-ink-700/60" x-text="s.label"></span>
                        <span class="ml-auto font-medium tabular-nums" x-text="hover !== null ? rows[hover][s.key] : ''"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
@endif
