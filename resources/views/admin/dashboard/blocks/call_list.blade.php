@if($deep['callList'] !== null)
    @php
        // Who to call today (owner, 2026-09-18: "add other analytical info on
        // the dashboard"). Five lists of names and numbers in order of
        // urgency — the only card on the page that is work rather than a
        // figure — so it is the first full row under the chart and never
        // folds shut.
        // Each section shows its count (everything waiting) and its top three
        // rows; on a phone a section shows one row until "Show all", the same
        // pattern the lists below the fold use. Every phone is a tel: link,
        // because she reads this on the phone she will ring from.
        //
        // The risk tag comes with the row (DashboardInsights::callList reads
        // the stored courier check through the owner's own thresholds); this
        // view never asks the courier anything.
        $list = $deep['callList'];

        $risk = [
            'blacklisted' => ['bg-red-100 text-red-700', 'Blacklisted'],
            'risky' => ['bg-red-100 text-red-700', 'Risky'],
            'warning' => ['bg-amber-100 text-amber-700', 'Warning'],
            'unchecked' => ['bg-ink-100 text-ink-700', 'Unchecked'],
            'first_timer' => ['bg-blue-100 text-blue-700', 'First-timer'],
            'ok' => ['bg-green-100 text-green-700', 'OK'],
        ];

        // A staff login can open orders and reminders but not customers,
        // carts or occasions (User::sectionsFor); a link it cannot follow is
        // rendered as plain text rather than a 403 (2026-09-18 review).
        $canOpen = fn (string $route) => (bool) auth()->user()?->canAccess(explode('.', $route)[1] ?? '');
        $link = fn (array $section, ?array $params = null) => $canOpen($section['link']['route'])
            ? route($section['link']['route'], $params ?? $section['link']['params'])
            : null;

        // The confirm list is the pre-booking queue, so the orders page opens
        // on both statuses that can still be unbooked, not just processing.
        $confirmUrl = $link($list['confirm'], ['status' => 'pending,processing']);

        $occasionIcon = ['birthday' => '🎂', 'anniversary' => '💍'];
        $noOccasions = ($list['occasions']['extra']['coverage']['birthday'] ?? 0) + ($list['occasions']['extra']['coverage']['anniversary'] ?? 0) === 0;
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-0.5 sm:mb-1">
            <h2 class="font-semibold text-sm sm:text-base flex items-center gap-2">Who to call today
                <span class="min-w-[20px] h-5 px-1.5 rounded-full {{ $list['total'] > 0 ? 'bg-red-600 text-white' : 'bg-ink-100 text-ink-700' }} text-xs font-semibold inline-flex items-center justify-center tabular-nums">{{ $list['total'] }}</span>
            </h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">right now · tap a number to dial</span>
        </div>
        <p class="text-[11px] sm:text-xs text-ink-700/55 mb-2 sm:mb-3">Riskiest first. Each count is everything waiting; the rows are the top three, and the heading opens the full list.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2">
            {{-- Confirm before booking: unbooked orders older than two hours,
                 with the courier's verdict on the number. --}}
            @php $sec = $list['confirm']; @endphp
            <section class="min-w-0 group" x-data="{ all: false }" :data-all="all">
                <h3 class="flex items-baseline justify-between gap-2 text-xs sm:text-sm font-semibold mb-1">
                    <a href="{{ $confirmUrl }}" class="min-w-0 truncate hover:text-gold-700">Confirm before booking</a>
                    <span class="badge bg-ink-100 text-ink-700 tabular-nums shrink-0">{{ $sec['count'] }}</span>
                </h3>
                @forelse($sec['rows'] as $r)
                    <div class="{{ $loop->index >= 1 ? 'hidden md:flex group-data-[all]:flex flex-col' : '' }} py-1.5 border-b border-ink-100 last:border-0 text-[13px] sm:text-sm">
                        <div class="flex items-center justify-between gap-2 min-w-0">
                            <span class="min-w-0 truncate">
                                <a href="{{ route('admin.orders.show', $r['order_id']) }}" class="font-medium text-gold-700 hover:underline">#{{ $r['order_number'] }}</a>
                                {{ $r['name'] }}
                            </span>
                            <span class="shrink-0 font-medium tabular-nums whitespace-nowrap">{{ money($r['total']) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 min-w-0 mt-0.5 text-[11px] sm:text-xs text-ink-700/60">
                            <span class="min-w-0 truncate">
                                @if(tel_link($r['phone']))<a href="{{ tel_link($r['phone']) }}" class="text-gold-700 hover:underline">{{ $r['phone'] }}</a>@else{{ $r['phone'] }}@endif
                                @if($r['area']) · {{ $r['area'] }}@endif
                                · {{ $r['hours_waiting'] }} h
                            </span>
                            <span class="badge {{ $risk[$r['risk']][0] ?? 'bg-ink-100 text-ink-700' }} text-[10px] shrink-0">{{ $risk[$r['risk']][1] ?? ucfirst($r['risk']) }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-[13px] sm:text-sm text-ink-700/50">Nothing to confirm — every order is booked or under two hours old.</p>
                @endforelse
                @if($sec['extra']['cod_at_risk'] > 0)
                    <p class="mt-1 text-[11px] sm:text-xs text-red-600 tabular-nums">COD at risk {{ money($sec['extra']['cod_at_risk']) }}</p>
                @endif
                @if(count($sec['rows']) > 1)
                    <button type="button" @click="all = !all" class="md:hidden mt-1 text-xs font-medium text-gold-700"
                            x-text="all ? 'Show fewer' : 'Show all {{ count($sec['rows']) }}'">Show all {{ count($sec['rows']) }}</button>
                @endif
            </section>

            {{-- Reminders due by the end of today. --}}
            @php $sec = $list['reminders']; @endphp
            <section class="min-w-0 group" x-data="{ all: false }" :data-all="all">
                <h3 class="flex items-baseline justify-between gap-2 text-xs sm:text-sm font-semibold mb-1">
                    @if($link($sec))<a href="{{ $link($sec) }}" class="min-w-0 truncate hover:text-gold-700">Reminders due</a>@else<span class="min-w-0 truncate">Reminders due</span>@endif
                    <span class="badge bg-ink-100 text-ink-700 tabular-nums shrink-0">{{ $sec['count'] }}</span>
                </h3>
                @forelse($sec['rows'] as $r)
                    <div class="{{ $loop->index >= 1 ? 'hidden md:flex group-data-[all]:flex flex-col' : '' }} py-1.5 border-b border-ink-100 last:border-0 text-[13px] sm:text-sm">
                        <div class="flex items-center justify-between gap-2 min-w-0">
                            <span class="min-w-0 truncate">{{ $r['name'] !== '' ? $r['name'] : 'No name' }}</span>
                            @if($r['overdue'])<span class="badge bg-red-100 text-red-700 text-[10px] shrink-0">overdue</span>@endif
                        </div>
                        <div class="min-w-0 truncate mt-0.5 text-[11px] sm:text-xs text-ink-700/60">
                            @if(tel_link($r['phone']))<a href="{{ tel_link($r['phone']) }}" class="text-gold-700 hover:underline">{{ $r['phone'] }}</a>@else{{ $r['phone'] }}@endif
                            @if($r['items']) · {{ implode(', ', $r['items']) }}@endif
                        </div>
                    </div>
                @empty
                    <p class="text-[13px] sm:text-sm text-ink-700/50">No calls scheduled for today.</p>
                @endforelse
                <p class="mt-1 text-[11px] sm:text-xs text-ink-700/45 tabular-nums">{{ $sec['extra']['done_week'] }} done this week · {{ $sec['extra']['orders_from_calls'] }} order{{ $sec['extra']['orders_from_calls'] === 1 ? '' : 's' }} from calls</p>
                @if(count($sec['rows']) > 1)
                    <button type="button" @click="all = !all" class="md:hidden mt-1 text-xs font-medium text-gold-700"
                            x-text="all ? 'Show fewer' : 'Show all {{ count($sec['rows']) }}'">Show all {{ count($sec['rows']) }}</button>
                @endif
            </section>

            {{-- Open carts from the last week, biggest first. --}}
            @php $sec = $list['carts']; $open = $sec['extra']['open_now']; @endphp
            <section class="min-w-0 group" x-data="{ all: false }" :data-all="all">
                <h3 class="flex items-baseline justify-between gap-2 text-xs sm:text-sm font-semibold mb-1">
                    @if($link($sec))<a href="{{ $link($sec) }}" class="min-w-0 truncate hover:text-gold-700">Carts worth a call</a>@else<span class="min-w-0 truncate">Carts worth a call</span>@endif
                    <span class="badge bg-ink-100 text-ink-700 tabular-nums shrink-0">{{ $sec['count'] }}</span>
                </h3>
                @forelse($sec['rows'] as $r)
                    <div class="{{ $loop->index >= 1 ? 'hidden md:flex group-data-[all]:flex flex-col' : '' }} py-1.5 border-b border-ink-100 last:border-0 text-[13px] sm:text-sm">
                        <div class="flex items-center justify-between gap-2 min-w-0">
                            <span class="min-w-0 truncate">{{ filled($r['name']) ? $r['name'] : 'No name' }}</span>
                            <span class="shrink-0 font-medium tabular-nums whitespace-nowrap">{{ money($r['subtotal']) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 min-w-0 mt-0.5 text-[11px] sm:text-xs text-ink-700/60">
                            <span class="min-w-0 truncate">
                                @if(tel_link($r['phone']))<a href="{{ tel_link($r['phone']) }}" class="text-gold-700 hover:underline">{{ $r['phone'] }}</a>@else{{ $r['phone'] }}@endif
                                · {{ $r['item_count'] }} item{{ $r['item_count'] === 1 ? '' : 's' }} · {{ $r['hours_ago'] }} h ago
                            </span>
                            @if($r['sms_sent'])<span class="badge bg-ink-100 text-ink-700 text-[10px] shrink-0">SMS sent</span>@endif
                        </div>
                    </div>
                @empty
                    <p class="text-[13px] sm:text-sm text-ink-700/50">No open carts from the last week.</p>
                @endforelse
                <p class="mt-1 text-[11px] sm:text-xs text-ink-700/45 tabular-nums">
                    @if($link($sec))<a href="{{ $link($sec) }}" class="hover:text-gold-700">Still open: {{ $open['count'] }} · {{ money($open['amount']) }}{{ $open['oldest_days'] !== null ? ' · oldest '.$open['oldest_days'].' d' : '' }}</a>@else<span class="">Still open: {{ $open['count'] }} · {{ money($open['amount']) }}{{ $open['oldest_days'] !== null ? ' · oldest '.$open['oldest_days'].' d' : '' }}</span>@endif
                </p>
                @if(count($sec['rows']) > 1)
                    <button type="button" @click="all = !all" class="md:hidden mt-1 text-xs font-medium text-gold-700"
                            x-text="all ? 'Show fewer' : 'Show all {{ count($sec['rows']) }}'">Show all {{ count($sec['rows']) }}</button>
                @endif
            </section>

            {{-- Birthdays and anniversaries in the next seven days, not yet wished. --}}
            @php $sec = $list['occasions']; @endphp
            <section class="min-w-0 group" x-data="{ all: false }" :data-all="all">
                <h3 class="flex items-baseline justify-between gap-2 text-xs sm:text-sm font-semibold mb-1">
                    @if($link($sec))<a href="{{ $link($sec) }}" class="min-w-0 truncate hover:text-gold-700">Occasions this week</a>@else<span class="min-w-0 truncate">Occasions this week</span>@endif
                    <span class="badge bg-ink-100 text-ink-700 tabular-nums shrink-0">{{ $sec['count'] }}</span>
                </h3>
                @forelse($sec['rows'] as $r)
                    <div class="{{ $loop->index >= 1 ? 'hidden md:flex group-data-[all]:flex flex-col' : '' }} py-1.5 border-b border-ink-100 last:border-0 text-[13px] sm:text-sm">
                        <div class="flex items-center justify-between gap-2 min-w-0">
                            <span class="min-w-0 truncate">{{ $occasionIcon[$r['type']] ?? '🎉' }} {{ $r['name'] }}</span>
                            <span class="shrink-0 text-[11px] sm:text-xs text-ink-700/60 whitespace-nowrap">{{ $r['days_until'] === 0 ? 'today' : ($r['days_until'] === 1 ? 'tomorrow' : 'in '.$r['days_until'].' days') }}</span>
                        </div>
                        <div class="min-w-0 truncate mt-0.5 text-[11px] sm:text-xs text-ink-700/60">
                            @if(tel_link($r['phone']))<a href="{{ tel_link($r['phone']) }}" class="text-gold-700 hover:underline">{{ $r['phone'] }}</a>@else{{ $r['phone'] ?: 'no phone' }}@endif
                        </div>
                    </div>
                @empty
                    @if($noOccasions)
                        <p class="text-[13px] sm:text-sm text-ink-700/50">No birthdays or anniversaries recorded yet — @if($link($sec))<a href="{{ $link($sec) }}" class="text-gold-700 hover:underline">add them on the occasions page</a>@else<span class="text-gold-700">add them on the occasions page</span>@endif.</p>
                    @else
                        <p class="text-[13px] sm:text-sm text-ink-700/50">None in the next seven days.</p>
                    @endif
                @endforelse
                @if(count($sec['rows']) > 1)
                    <button type="button" @click="all = !all" class="md:hidden mt-1 text-xs font-medium text-gold-700"
                            x-text="all ? 'Show fewer' : 'Show all {{ count($sec['rows']) }}'">Show all {{ count($sec['rows']) }}</button>
                @endif
            </section>

            {{-- Buyers going quiet, biggest spender first. --}}
            @php $sec = $list['slipping']; @endphp
            <section class="min-w-0 group" x-data="{ all: false }" :data-all="all">
                <h3 class="flex items-baseline justify-between gap-2 text-xs sm:text-sm font-semibold mb-1">
                    @if($link($sec))<a href="{{ $link($sec) }}" class="min-w-0 truncate hover:text-gold-700">Slipping buyers</a>@else<span class="min-w-0 truncate">Slipping buyers</span>@endif
                    <span class="badge bg-ink-100 text-ink-700 tabular-nums shrink-0">{{ $sec['count'] }}</span>
                </h3>
                @forelse($sec['rows'] as $r)
                    <div class="{{ $loop->index >= 1 ? 'hidden md:flex group-data-[all]:flex flex-col' : '' }} py-1.5 border-b border-ink-100 last:border-0 text-[13px] sm:text-sm">
                        <div class="flex items-center justify-between gap-2 min-w-0">
                            @if($canOpen('admin.customers.show'))<a href="{{ route('admin.customers.show', $r['id']) }}" class="min-w-0 truncate hover:text-gold-700">{{ $r['name'] }}</a>@else<span class="min-w-0 truncate">{{ $r['name'] }}</span>@endif
                            <span class="shrink-0 font-medium tabular-nums whitespace-nowrap">{{ money($r['total_spent']) }} <span class="font-normal text-ink-700/40">lifetime</span></span>
                        </div>
                        <div class="min-w-0 truncate mt-0.5 text-[11px] sm:text-xs text-ink-700/60">
                            @if(tel_link($r['phone']))<a href="{{ tel_link($r['phone']) }}" class="text-gold-700 hover:underline">{{ $r['phone'] }}</a>@else{{ $r['phone'] ?: 'no phone' }}@endif
                            @if($r['days_since'] !== null) · {{ $r['days_since'] }} days since @endif
                        </div>
                    </div>
                @empty
                    <p class="text-[13px] sm:text-sm text-ink-700/50">Nobody is slipping away just now.</p>
                @endforelse
                @if(count($sec['rows']) > 1)
                    <button type="button" @click="all = !all" class="md:hidden mt-1 text-xs font-medium text-gold-700"
                            x-text="all ? 'Show fewer' : 'Show all {{ count($sec['rows']) }}'">Show all {{ count($sec['rows']) }}</button>
                @endif
            </section>
        </div>
    </div>
@endif
