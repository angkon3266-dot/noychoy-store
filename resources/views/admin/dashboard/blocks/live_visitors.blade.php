{{-- On the site right now. Polled, because shared hosting has no
     websocket — see DashboardController::live. Stays open on a phone:
     the count is the point, and the list scrolls inside a shorter box. --}}
<div class="card p-3 sm:p-5" x-data="liveVisitors({ url: '{{ route('admin.dashboard.live') }}' })" x-init="start()">
    <div class="flex items-center justify-between mb-1">
        <h2 class="font-semibold text-sm sm:text-base flex items-center gap-2">
            <span class="relative flex h-2.5 w-2.5">
                <span class="absolute inline-flex h-full w-full rounded-full bg-green-500 opacity-60"
                      :class="count > 0 && 'animate-ping'"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full"
                      :class="count > 0 ? 'bg-green-500' : 'bg-ink-300'"></span>
            </span>
            On the site right now
        </h2>
        <span class="text-xl sm:text-2xl font-semibold tabular-nums" x-text="count"></span>
    </div>
    <p class="text-[11px] sm:text-xs text-ink-700/55 mb-2 sm:mb-3">
        Shoppers active in the last <span x-text="window"></span> minutes. Refreshes on its own.
    </p>

    <template x-if="!loaded">
        <p class="text-sm text-ink-700/40">Checking…</p>
    </template>
    <template x-if="loaded && rows.length === 0">
        <p class="text-sm text-ink-700/50">Nobody browsing at the moment.</p>
    </template>

    <div class="divide-y divide-ink-100 max-h-48 sm:max-h-72 overflow-y-auto -mx-1 px-1">
        <template x-for="(r, i) in rows" :key="i">
            <div class="py-1.5 sm:py-2 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[13px] sm:text-sm truncate" x-text="r.where"></div>
                    <div class="text-[11px] text-ink-700/50 truncate">
                        <span x-text="r.channel_label"></span>
                        <template x-if="r.campaign"><span> · <span x-text="r.campaign"></span></span></template>
                        <span> · on site <span x-text="r.minutes_on_site"></span>m</span>
                    </div>
                </div>
                <span class="text-[11px] text-ink-700/40 whitespace-nowrap" x-text="ago(r.seconds_ago)"></span>
            </div>
        </template>
    </div>
</div>
