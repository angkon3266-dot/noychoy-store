@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
{{-- Owner's call, 17 Sep 2026: the dashboard is mostly read on a phone, and it
     took a long scroll — or a pinch to zoom out — to get past the first few
     cards. The page is compacted for a ~375px screen rather than cut down:
     every figure is still on it. Tighter padding and type below sm; long lists
     show their first few rows with a "Show all" button; secondary panels fold
     shut below md and open on a tap. From md up everything is open as before.

     Folding and "Show all" are a data attribute on a Tailwind `group`, never
     x-show: x-show writes an inline display:none, which beats `md:block`, so a
     panel left closed on a phone would also stay closed on a desktop. Before
     Alpine starts the attribute is simply absent, so a phone renders folded
     and a desktop renders open with no flash either way. --}}

{{-- Reporting window. Every time-based figure below follows this; live counts
     (to process, stock, customer base) deliberately do not.

     On a phone the presets are one strip that scrolls sideways instead of four
     wrapped rows, scrolled so the chosen one is in view. ⚙ sits in the same row
     but outside the strip: an overflow container clips anything absolutely
     positioned inside it, and the panel picker would open invisible. --}}
<div class="mb-3 sm:mb-4" x-data="{ custom: @js($range->isCustom()) }">
    <div class="flex items-start gap-2">
        <div class="relative min-w-0 flex-1 flex gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:flex-wrap sm:gap-2 sm:overflow-visible"
             x-init="$nextTick(() => { const a = $el.querySelector('[data-current]'); if (a && $el.scrollWidth > $el.clientWidth) $el.scrollLeft = a.offsetLeft - ($el.clientWidth - a.offsetWidth) / 2 })">
            @foreach(\App\Support\DateRange::PRESETS as $key => $label)
                <a href="{{ route('admin.dashboard', ['period' => $key]) }}"
                   @if($range->key === $key) aria-current="page" data-current @endif
                   class="shrink-0 whitespace-nowrap rounded-lg px-2.5 sm:px-3 py-1.5 text-xs font-medium border transition
                          {{ $range->key === $key
                                ? 'bg-ink-900 text-white border-ink-900'
                                : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">{{ $label }}</a>
            @endforeach

            <button type="button" @click="custom = !custom" :aria-expanded="custom"
                    @if($range->isCustom()) data-current @endif
                    class="shrink-0 whitespace-nowrap rounded-lg px-2.5 sm:px-3 py-1.5 text-xs font-medium border transition
                           {{ $range->isCustom()
                                ? 'bg-ink-900 text-white border-ink-900'
                                : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">
                {{ $range->isCustom() ? $range->label : 'Custom…' }}
            </button>
        </div>

        {{-- Which deep-analysis panels to show. Just the ⚙ on a phone; the
             words stay for screen readers and come back from sm. --}}
        <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" @click="open = !open" :aria-expanded="open" title="Customize dashboard"
                    class="btn-outline py-1.5 px-2.5 sm:px-3 text-xs">⚙<span class="sr-only sm:not-sr-only">Customize dashboard</span></button>
            <form action="{{ route('admin.dashboard.panels') }}" method="POST" x-show="open" x-cloak x-transition
                  class="absolute right-0 top-full z-30 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-ink-100 bg-white shadow-xl p-3 text-sm">
                @csrf
                <p class="text-xs text-ink-700/60 mb-2">Show these analysis panels:</p>
                @foreach(\App\Http\Controllers\Admin\DashboardController::PANELS as $key => $label)
                    <label class="flex items-center gap-2 py-1">
                        <input type="checkbox" name="panels[]" value="{{ $key }}" @checked(in_array($key, $panels, true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                <button class="btn-primary w-full py-1.5 text-xs mt-2">Save layout</button>
            </form>
        </div>
    </div>

    <form x-show="custom" x-cloak method="GET" action="{{ route('admin.dashboard') }}"
          class="mt-2 flex flex-wrap items-center gap-2">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="{{ $range->isCustom() ? $range->start->toDateString() : '' }}"
               class="input py-1 text-xs min-w-0 flex-1 sm:flex-none sm:w-auto" required>
        <span class="text-xs text-ink-700/40">to</span>
        <input type="date" name="to" value="{{ $range->isCustom() ? $range->end->toDateString() : '' }}"
               class="input py-1 text-xs min-w-0 flex-1 sm:flex-none sm:w-auto" required>
        <button class="btn-primary py-1.5 px-3 text-xs">Apply</button>
    </form>
</div>

{{-- KPI cards. Two across on a phone, three on a tablet, five from lg — ten
     cards in fours left a ragged row of two. Values are never truncated (a
     clipped ৳ figure is a wrong figure); a very long one wraps inside its card
     instead of spilling out. The value steps down to text-xl between sm and
     xl because five cards beside the sidebar are only ~135px wide. --}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 sm:gap-3 xl:gap-4">
    @php
        // Lowercased to sit as a caption under the number — except a custom
        // range, whose label is a pair of dates ("10 Jul 2026 – 20 Jul 2026")
        // that strtolower would turn into "10 jul 2026".
        $per = $range->isAllTime()
            ? 'all time'
            : ($range->isCustom() ? $range->label : strtolower($range->label));

        $cards = [
        ['Visitors', number_format($stats['visitors_period']), 'text-ink-800', number_format($stats['visitors_today']).' today'],
        ['Orders', $stats['orders_period'], 'text-gold-700', $per],
        ['To process', $stats['pending'] + $stats['processing'], 'text-amber-600', $stats['shipped'].' shipped · now'],
        ['Sales', money($stats['sales_period']), 'text-ink-800', money($stats['revenue_period']).' delivered'],
        ['Avg. order value', money($stats['aov']), 'text-ink-800', $per],
        ['COD success', $stats['cod_success'] === null ? '—' : $stats['cod_success'].'%', 'text-green-700', 'delivered / resolved · all time'],
        ['Customers', $stats['customers'], 'text-ink-800', $stats['repeat_rate'].'% repeat'],
        ['New customers', $stats['new_customers_period'], 'text-ink-800', $per],
        ['Low stock', $stats['low_stock'], 'text-red-600', '≤ 3 left · now'],
        ['Stock on hand', number_format($stats['stock_units']).' pcs', 'text-ink-800', money($stats['stock_cost_value']).' at cost'],
    ]; @endphp
    @foreach($cards as [$label, $value, $color, $sub])
        <div class="card min-w-0 p-3 sm:p-4 xl:p-5">
            <div class="truncate text-[11px] sm:text-sm text-ink-700/60" title="{{ $label }}">{{ $label }}</div>
            <div class="mt-0.5 sm:mt-1 text-lg sm:text-xl xl:text-2xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere] {{ $color }}">{{ $value }}</div>
            <div class="mt-0.5 text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ $sub }}</div>
        </div>
    @endforeach
</div>

{{-- grid-cols-1, not a bare `grid`, on every two/three-panel row: a bare grid's
     one implicit column is sized to its widest card's min-content, and thirty
     no-wrap date labels under the sales bars made that ~900px — the whole page
     scrolled sideways on a phone. grid-cols-1 is minmax(0, 1fr), which can
     shrink to the screen. --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 md:gap-6 mt-3 md:mt-6">
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
    <div class="card p-3 sm:p-5 lg:col-span-2"
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

    {{-- Order status breakdown: two columns on a phone rather than nine rows,
         back to one list beside the chart from lg. --}}
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-2 sm:mb-4">Orders by status</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-1 gap-x-4 gap-y-1.5 lg:gap-y-2">
            @foreach(\App\Models\Order::STATUSES as $key => $label)
                <div class="flex min-w-0 items-center justify-between gap-2 text-xs sm:text-sm">
                    <span class="truncate text-ink-700/70" title="{{ $label }}">{{ $label }}</span>
                    <span class="badge bg-ink-100 text-ink-700 tabular-nums">{{ $statusCounts[$key] ?? 0 }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-6 mt-3 md:mt-6">
    {{-- Top products --}}
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Top products · {{ $range->label }}</h2>
        @forelse($topProducts as $p)
            <div class="flex items-center justify-between py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                <span class="truncate pr-3">{{ $p->name }}</span>
                <span class="shrink-0 tabular-nums text-ink-700/60">{{ $p->qty }} sold · {{ money($p->revenue) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No sales in this period yet.</p>
        @endforelse
    </div>

    {{-- Best-selling categories --}}
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Best-selling categories · {{ $range->label }}</h2>
        @forelse($topCategories as $cat)
            <div class="py-1 sm:py-1.5">
                <div class="flex items-center justify-between text-[13px] sm:text-sm mb-1">
                    <span class="truncate pr-3">{{ $cat->name }}</span>
                    <span class="shrink-0 tabular-nums text-ink-700/60">{{ $cat->qty }} sold · {{ money($cat->revenue) }}</span>
                </div>
                <div class="h-1 sm:h-1.5 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ round($cat->qty / $catMax * 100) }}%"></div></div>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No category sales in this period yet.</p>
        @endforelse
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-6 mt-3 md:mt-6">
    {{-- Low stock --}}
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Low stock alerts</h2>
        @forelse($lowStockProducts as $p)
            <div class="flex items-center justify-between py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                <a href="{{ route('admin.products.edit', $p) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $p->name }}</a>
                <span class="badge shrink-0 {{ $p->stock_quantity == 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ $p->stock_quantity }} left</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">Everything's well stocked. 🎉</p>
        @endforelse
    </div>

    {{-- Most valuable customers. Folds on a phone; the points liability sits in
         the heading so it stays visible while the list is shut. --}}
    <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
        <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
            <div class="min-w-0 flex-1 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <h2 class="font-semibold text-sm sm:text-base">Top customers</h2>
                <span class="text-[11px] sm:text-xs text-ink-700/50">Points liability: {{ money($pointsLiability) }} ({{ number_format($pointsOutstanding) }} pts)</span>
            </div>
            <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide top customers">
                <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
        <div class="hidden md:block group-data-[open]:block mt-1 sm:mt-3">
            @forelse($topCustomers as $c)
                <div class="flex items-center justify-between py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                    <a href="{{ route('admin.customers.show', $c) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $c->name }} <span class="text-ink-700/40">· {{ $c->total_orders }} orders</span></a>
                    <span class="shrink-0 tabular-nums text-ink-700/60">{{ money($c->total_spent) }}</span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">No customer sales yet.</p>
            @endforelse
        </div>
    </div>
</div>

{{-- Most loved products. Folds on a phone with the total left in the heading.
     The name gets the width there and the bar a fixed stub — the old fixed
     12rem name column left the bar about 40px on a phone. --}}
@php $lovedMax = max(1, $mostLoved->max('loves_count')); @endphp
<div class="card p-3 sm:p-5 mt-3 md:mt-6 group" x-data="{ expanded: false }" :data-open="expanded">
    <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
        <div class="min-w-0 flex-1 flex items-center justify-between gap-3">
            <h2 class="font-semibold text-sm sm:text-base flex items-center gap-2">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                Most loved products
            </h2>
            <span class="shrink-0 text-xs sm:text-sm tabular-nums text-ink-700/60">{{ number_format($totalLoves) }} total ❤️</span>
        </div>
        <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide most loved products">
            <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </div>
    <div class="hidden md:block group-data-[open]:block mt-1 sm:mt-3">
        @forelse($mostLoved as $p)
            <div class="flex items-center gap-2 sm:gap-3 py-1.5 sm:py-2 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm">
                <a href="{{ route('admin.products.edit', $p) }}" class="min-w-0 flex-1 sm:flex-none sm:w-48 sm:shrink-0 truncate text-gold-700 hover:underline">{{ $p->name }}</a>
                <div class="w-16 shrink-0 sm:w-auto sm:shrink sm:flex-1 h-1.5 sm:h-2 rounded-full bg-ink-50 overflow-hidden">
                    <div class="h-full rounded-full bg-red-400" style="width: {{ round($p->loves_count / $lovedMax * 100) }}%"></div>
                </div>
                <span class="shrink-0 w-14 sm:w-16 text-right font-medium tabular-nums text-red-600">❤️ {{ number_format($p->loves_count) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No love reactions yet. They'll appear here as customers tap the ❤️ on products.</p>
        @endforelse
    </div>
</div>

{{-- Contact messages inbox. Two on a phone, each clipped to two lines, with the
     rest a tap away — they are waiting on a reply, so they never fold shut. --}}
@if($unreadMessages > 0)
<div class="card mt-3 md:mt-6 overflow-hidden border-gold-200 group" x-data="{ all: false }" :data-all="all">
    <div class="flex items-center justify-between gap-3 px-3 sm:px-5 py-2.5 sm:py-4 border-b border-ink-100 bg-gold-50/60">
        <h2 class="font-semibold text-sm sm:text-base flex items-center gap-2">📨 New messages
            <span class="min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold inline-flex items-center justify-center">{{ $unreadMessages }}</span>
        </h2>
        <a href="{{ route('admin.messages') }}" class="shrink-0 text-xs sm:text-sm text-gold-700 hover:underline">All messages →</a>
    </div>
    <div class="divide-y divide-ink-100">
        @foreach($recentMessages as $m)
            <div class="{{ $loop->index >= 2 ? 'hidden md:flex group-data-[all]:flex' : 'flex' }} px-3 sm:px-5 py-2.5 sm:py-3 items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ $m->name }}
                        <span class="text-xs font-normal text-ink-700/50">· {{ $m->phone ?: $m->email }} · {{ $m->created_at->diffForHumans() }}</span>
                    </p>
                    @if($m->subject)<p class="text-xs font-medium text-ink-700/70 mt-0.5">{{ $m->subject }}</p>@endif
                    <p class="text-[13px] sm:text-sm text-ink-700/70 mt-0.5 line-clamp-2 md:line-clamp-none">{{ \Illuminate\Support\Str::limit($m->message, 160) }}</p>
                </div>
                <form action="{{ route('admin.messages.read', $m) }}" method="POST" class="shrink-0">
                    @csrf
                    <button class="text-xs text-gold-700 hover:underline whitespace-nowrap">Mark read</button>
                </form>
            </div>
        @endforeach
    </div>
    @if($recentMessages->count() > 2)
        <button type="button" @click="all = !all" class="md:hidden w-full border-t border-ink-100 py-2 text-xs font-medium text-gold-700"
                x-text="all ? 'Show fewer' : 'Show all {{ $recentMessages->count() }}'">Show all {{ $recentMessages->count() }}</button>
    @endif
</div>
@endif

{{-- Recent orders. On a phone the five-column table did not fit 343px, so
     below sm the date tucks under the order number and the status under the
     total — two dense lines a row, the same cells, no second copy of the
     list — and only the latest five show until "Show all". --}}
<div class="card mt-3 md:mt-6 overflow-hidden group" x-data="{ all: false }" :data-all="all">
    <div class="flex items-center justify-between px-3 sm:px-5 py-2.5 sm:py-4 border-b border-ink-100">
        <h2 class="font-semibold text-sm sm:text-base">Recent orders</h2>
        <a href="{{ route('admin.orders.index') }}" class="text-xs sm:text-sm text-gold-700 hover:underline">All orders →</a>
    </div>
    <table class="w-full text-[13px] sm:text-sm">
        <thead class="hidden sm:table-header-group bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Date</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($recentOrders as $order)
                <tr class="{{ $loop->index >= 5 ? 'hidden md:table-row group-data-[all]:table-row' : '' }} hover:bg-ink-50">
                    <td class="pl-3 pr-2 py-2 sm:px-5 sm:py-3 align-top sm:align-middle">
                        <a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-gold-700 hover:underline">{{ $order->order_number }}</a>
                        <div class="sm:hidden text-[11px] whitespace-nowrap text-ink-700/50">{{ $order->created_at->diffForHumans(null, null, true) }}</div>
                    </td>
                    <td class="px-2 py-2 sm:px-5 sm:py-3 align-top sm:align-middle">{{ $order->customer_name }}<div class="text-[11px] sm:text-xs text-ink-700/50">{{ $order->customer_phone }}</div></td>
                    <td class="pl-2 pr-3 py-2 sm:px-5 sm:py-3 align-top sm:align-middle text-right sm:text-left whitespace-nowrap tabular-nums">{{ money($order->total) }}
                        <div class="sm:hidden mt-0.5"><span class="badge px-2 text-[10px] bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></div>
                    </td>
                    <td class="hidden sm:table-cell px-5 py-3"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></td>
                    <td class="hidden sm:table-cell px-5 py-3 text-ink-700/60">{{ $order->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-8 text-center text-ink-700/50">No orders yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($recentOrders->count() > 5)
        <button type="button" @click="all = !all" class="md:hidden w-full border-t border-ink-100 py-2 text-xs font-medium text-gold-700"
                x-text="all ? 'Show fewer' : 'Show all {{ $recentOrders->count() }}'">Show all {{ $recentOrders->count() }}</button>
    @endif
</div>

@php
    $chg = function ($v) {
        if ($v === null) return ['—', 'text-ink-700/40'];
        return [($v > 0 ? '▲ ' : ($v < 0 ? '▼ ' : '')).abs($v).'%', $v > 0 ? 'text-green-700' : ($v < 0 ? 'text-red-600' : 'text-ink-700/50')];
    };
@endphp

{{-- ── Revenue & profit (selected window vs the one before it) ─────────── --}}
@if($deep['profit'])
    @php $p = $deep['profit']; [$revTxt, $revCls] = $chg($p['revenue_change']); [$proTxt, $proCls] = $chg($p['profit_change']); @endphp
    <div class="card p-3 sm:p-5 mt-3 md:mt-6">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-2 sm:mb-4">
            <h2 class="font-semibold text-sm sm:text-base">Revenue &amp; profit</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}{{ $range->isAllTime() ? '' : ' vs previous period' }}</span>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Revenue</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($p['current']['revenue']) }}</div>
                {{-- "Maximum" has no earlier period to compare against. --}}
                <div class="text-[11px] sm:text-xs leading-snug {{ $revCls }}">{{ $revTxt }}
                    @if($p['previous'])<span class="text-ink-700/40">vs {{ money($p['previous']['revenue']) }}</span>@endif
                </div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Profit</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere] text-green-700">{{ money($p['current']['profit']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug {{ $proCls }}">{{ $proTxt }}
                    @if($p['previous'])<span class="text-ink-700/40">vs {{ money($p['previous']['profit']) }}</span>@endif
                </div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Margin</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $p['current']['margin'] === null ? '—' : $p['current']['margin'].'%' }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">cost {{ money($p['current']['cost']) }}</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Items per order</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $p['items_per_order'] }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">AOV {{ money($p['aov']) }} · {{ money($p['discount_given']) }} discounts</div>
            </div>
        </div>
        @if($p['current']['cost'] <= 0 && $p['current']['revenue'] > 0)
            <p class="text-[11px] sm:text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2.5 sm:px-3 py-1.5 sm:py-2 mt-3 sm:mt-4">
                Profit assumes zero cost — add <strong>cost price</strong> (and transport) on your products so margin is real.
            </p>
        @endif
    </div>
@endif

{{-- ── Traffic & conversion funnel ──────────────────────────────────────── --}}
@if($deep['funnel'])
    @php $f = $deep['funnel']; @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 md:gap-6 mt-3 md:mt-6">
        <div class="card p-3 sm:p-5 lg:col-span-2">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1">
                <h2 class="font-semibold text-sm sm:text-base">Conversion funnel</h2>
                <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }} · unique visitors</span>
            </div>
            @unless($f['tracking'])
                <p class="text-[11px] sm:text-xs text-ink-700/50 mb-2 sm:mb-3">No traffic recorded yet — figures fill in as customers visit the storefront.</p>
            @endunless
            <div class="space-y-2 sm:space-y-2.5 mt-2 sm:mt-3">
                @foreach($f['steps'] as $s)
                    <div>
                        <div class="flex justify-between items-baseline text-[13px] sm:text-sm gap-3">
                            <span class="flex items-baseline gap-2 min-w-0">
                                <span class="truncate">{{ $s['label'] }}</span>
                                {{-- The money each step carried. A count is people;
                                     a value is money, summed over every event —
                                     one shopper can carry three items' worth. --}}
                                @if(($s['money'] ?? null) !== null)
                                    <span class="text-[11px] sm:text-xs font-medium tabular-nums text-gold-700 whitespace-nowrap">{{ money($s['money']) }}</span>
                                @elseif(($s['unmeasured'] ?? 0) > 0)
                                    <span class="text-[11px] sm:text-xs text-ink-700/40 whitespace-nowrap" title="These events were recorded before the funnel started storing values.">value not recorded</span>
                                @endif
                            </span>
                            <span class="font-medium tabular-nums whitespace-nowrap">{{ number_format($s['count']) }} <span class="text-ink-700/40 text-[11px] sm:text-xs">{{ $s['pct'] === null ? '' : $s['pct'].'%' }}</span></span>
                        </div>
                        <div class="h-1.5 sm:h-2 rounded-full bg-ink-100 overflow-hidden mt-0.5 sm:mt-1">
                            <div class="h-full bg-gold-500" style="width: {{ min(100, $s['pct'] ?? 0) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 sm:mt-4 flex flex-wrap items-baseline gap-x-4 sm:gap-x-6 gap-y-0.5 text-[13px] sm:text-sm">
                <span>Visitor → order conversion:
                    <strong>{{ $f['conversion'] === null ? '—' : $f['conversion'].'%' }}</strong></span>
                <span>Order value: <strong>{{ money($f['revenue'] ?? 0) }}</strong></span>
                @if(($f['abandoned'] ?? null) > 0)
                    <span class="text-amber-700">Left at checkout: <strong>{{ money($f['abandoned']) }}</strong></span>
                @endif
            </div>
            @if(($f['unmeasured'] ?? 0) > 0)
                <p class="text-[11px] text-ink-700/45 mt-1.5">
                    {{ $f['unmeasured'] }} cart/checkout event{{ $f['unmeasured'] === 1 ? '' : 's' }} in this window predate value
                    tracking, so the totals above cover only the ones we measured.
                </p>
            @endif
        </div>

        {{-- Where visitors come from. Each row opens to name the sites and
             campaigns underneath it — "Other website" is the channel that means
             "we could not name this", so leaving it closed answers nothing.

             Up to eight channels with a step strip each ran to a phone-screen
             and a half, so the card folds on a phone. Each step cell now puts
             its count and rate on one line, which also tightens it on desktop. --}}
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
                    @php $hasDetail = ! empty($s['sites']) || ! empty($s['campaigns']); @endphp
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
    </div>

    {{-- The funnel over time. Visitors alone only say whether traffic moved;
         plotted against the steps under it, the same chart says where it
         stopped. funnelChart measures the plot box (x-ref="plot") and draws
         at its real width, shorter on a phone — see app.js. --}}
    <div class="card p-3 sm:p-5 mt-3 md:mt-6"
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-6 mt-3 md:mt-6">
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

        {{-- Folds on a phone. --}}
        <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
            <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
                <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Viewed but never bought</h2>
                <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide viewed but never bought">
                    <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>
            <div class="hidden md:block group-data-[open]:block">
                <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">Interest is there — the photos, price or copy are the blocker.</p>
                @forelse($deep['viewedNotSold'] as $r)
                    <div class="flex justify-between items-center text-[13px] sm:text-sm py-1.5 border-b border-ink-100 last:border-0">
                        {{-- Linked by slug: Product::getRouteKeyName() is 'slug', so
                             an admin link built from the id 404s. --}}
                        <a href="{{ route('admin.products.edit', $r['slug'] ?: $r['id']) }}" class="truncate hover:text-gold-700">{{ $r['name'] }}</a>
                        <span class="text-ink-700/60 text-xs tabular-nums whitespace-nowrap ml-2">{{ $r['views'] }} views · 0 sold</span>
                    </div>
                @empty
                    <p class="text-sm text-ink-700/50">Nothing flagged — every viewed product has sold.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Which ad sent the traffic. Starts from visits, not orders, so a
         campaign that spent all week sending people who bought nothing is
         visible — that is the one worth switching off.

         Folds on a phone. When open there, the Ad and Channel columns move
         under the campaign name so the four figures fit without the old
         560px sideways scroll. --}}
    <div class="card p-3 sm:p-5 mt-3 md:mt-6 group" x-data="{ expanded: false }" :data-open="expanded">
        <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
            <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Ads &amp; campaigns</h2>
            <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide ads and campaigns">
                <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
        <div class="hidden md:block group-data-[open]:block">
            <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">
                Read from <code>utm_campaign</code> and <code>utm_content</code> on your ad links — {{ $per }}.
            </p>

            {{-- The table can only ever be as good as the links. Meta fills these
                 macros in for you, so the campaign shows as its name rather than a
                 seventeen-digit id. --}}
            <details class="mb-2 sm:mb-3 text-xs">
                <summary class="cursor-pointer text-gold-700 hover:underline select-none">How to tag your ad links</summary>
                <div class="mt-2 rounded-lg bg-ink-50 border border-ink-100 p-3 space-y-2">
                    <p class="text-ink-700/70">
                        In Meta Ads Manager, put this in <strong>URL parameters</strong> at the ad level. Meta replaces each
                        <code>&#123;&#123;…&#125;&#125;</code> with the real name when someone clicks:
                    </p>
                    <pre class="overflow-x-auto text-[11px] leading-relaxed text-ink-800">utm_source=&#123;&#123;site_source_name&#125;&#125;&amp;utm_medium=paid_social&amp;utm_campaign=&#123;&#123;campaign.name&#125;&#125;&amp;utm_content=&#123;&#123;ad.name&#125;&#125;&amp;ad_id=&#123;&#123;ad.id&#125;&#125;</pre>
                    <p class="text-ink-700/70">
                        For a boosted post or a plain link, add <code>?utm_source=facebook&amp;utm_campaign=eid-sale</code>
                        to the end of the URL — any name you'll recognise later.
                    </p>
                </div>
            </details>
            @if($deep['ads']->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-xs sm:text-sm sm:min-w-[560px]">
                        <thead class="text-left text-[10px] sm:text-xs uppercase tracking-wide text-ink-700/50">
                            <tr>
                                <th class="py-1 pr-2">Campaign</th><th class="hidden sm:table-cell py-1 pr-2">Ad</th><th class="hidden sm:table-cell py-1 pr-2">Channel</th>
                                <th class="py-1 pl-2 text-right">Visitors</th><th class="py-1 pl-2 text-right">Orders</th>
                                <th class="py-1 pl-2 text-right">Convert</th><th class="py-1 pl-2 text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($deep['ads'] as $a)
                                <tr class="border-t border-ink-100 {{ $a['orders'] === 0 && $a['visitors'] >= 20 ? 'bg-red-50/60' : '' }}">
                                    <td class="py-1.5 pr-2 truncate w-full max-w-0 sm:w-auto sm:max-w-[200px]" title="{{ $a['campaign'] }}">
                                        <div class="truncate">{{ $a['campaign'] }}</div>
                                        <div class="sm:hidden truncate text-[11px] text-ink-700/55">{{ $a['ad'] ?? '—' }} · {{ \App\Support\TrafficSource::label($a['channel']) }}</div>
                                    </td>
                                    <td class="hidden sm:table-cell py-1.5 pr-2 truncate max-w-[180px] text-ink-700/70" title="{{ $a['ad'] }}">{{ $a['ad'] ?? '—' }}</td>
                                    <td class="hidden sm:table-cell py-1.5 pr-2"><span class="badge {{ \App\Support\TrafficSource::badgeClass($a['channel']) }}">{{ \App\Support\TrafficSource::label($a['channel']) }}</span></td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums">{{ number_format($a['visitors']) }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums">{{ number_format($a['orders']) }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums {{ $a['orders'] === 0 ? 'text-red-600' : '' }}">{{ $a['rate'] === null ? '—' : $a['rate'].'%' }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums whitespace-nowrap font-medium">{{ money($a['revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-[11px] text-ink-700/45 mt-2">
                    A row shaded red sent real traffic and made no sale. Ads only appear once your links carry
                    <code>utm_content</code> — until then each campaign shows as one row.
                </p>
            @else
                <p class="text-sm text-ink-700/50">
                    No tagged links seen yet. Add <code>utm_campaign</code> to your ad and post links and this fills in.
                </p>
            @endif
        </div>
    </div>
@endif

{{-- ── Customers & retention ────────────────────────────────────────────── --}}
@if($deep['retention'])
    @php $r = $deep['retention']; $rev = $r['new_revenue'] + $r['repeat_revenue']; @endphp
    <div class="card p-3 sm:p-5 mt-3 md:mt-6">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-2 sm:mb-4">
            <h2 class="font-semibold text-sm sm:text-base">Customers &amp; retention</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-3 sm:mb-4">
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Repeat revenue share</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $r['repeat_share'] === null ? '—' : $r['repeat_share'].'%' }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ money($r['repeat_revenue']) }} of {{ money($rev) }}</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Lifetime value</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums [overflow-wrap:anywhere]">{{ money($r['clv']) }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">avg per buyer</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">Days to 2nd order</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums">{{ $r['avg_days_to_second'] ?? '—' }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">{{ $r['repeat_customers'] }} repeat · {{ $r['one_time'] }} one-time</div>
            </div>
            <div class="min-w-0">
                <div class="text-[11px] sm:text-xs text-ink-700/60">At risk</div>
                <div class="text-lg sm:text-xl leading-tight font-semibold tabular-nums text-amber-600">{{ $r['at_risk'] }}</div>
                <div class="text-[11px] sm:text-xs leading-snug text-ink-700/40">quiet 60+ days
                    <a href="{{ route('admin.notifications.index') }}" class="text-gold-700 hover:underline">win them back</a>
                </div>
            </div>
        </div>
        @if($rev > 0)
            <div class="flex h-2 sm:h-3 rounded-full overflow-hidden">
                <div class="bg-gold-500" style="width: {{ round($r['new_revenue'] / $rev * 100) }}%" title="New customers"></div>
                <div class="bg-ink-800" style="width: {{ round($r['repeat_revenue'] / $rev * 100) }}%" title="Repeat customers"></div>
            </div>
            <div class="flex justify-between text-[11px] sm:text-xs tabular-nums text-ink-700/50 mt-1 sm:mt-1.5">
                <span>New {{ money($r['new_revenue']) }}</span>
                <span>Repeat {{ money($r['repeat_revenue']) }}</span>
            </div>
        @endif
    </div>
@endif

{{-- ── Operations & inventory ───────────────────────────────────────────── --}}
@if($deep['operations'])
    @php $o = $deep['operations']; @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 md:gap-6 mt-3 md:mt-6">
        {{-- The two three-line lists become three cells across on a phone and
             go back to label-left, figure-right lists from lg, where the card
             is a third of the page wide. Same markup for both. --}}
        <div class="card p-3 sm:p-5">
            <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Delivery outcomes</h2>
            <div class="flex items-baseline gap-2 lg:block">
                <div class="text-2xl sm:text-3xl font-semibold tabular-nums text-green-700">{{ $o['cod_success'] === null ? '—' : $o['cod_success'].'%' }}</div>
                <p class="text-[11px] sm:text-xs text-ink-700/50 lg:mb-3">COD success, {{ $per }}</p>
            </div>
            <div class="mt-2 lg:mt-0 grid grid-cols-3 lg:grid-cols-1 gap-1.5 lg:gap-1 text-center lg:text-left text-sm">
                @foreach([
                    ['Delivered', $o['delivered'], ''],
                    ['Cancelled', $o['cancelled'], 'text-amber-600'],
                    ['Returned', $o['returned'], 'text-red-600'],
                ] as [$outcome, $outcomeCount, $outcomeClass])
                    <div class="rounded-md bg-ink-50 px-1 py-1 lg:flex lg:justify-between lg:bg-transparent lg:p-0">
                        <span class="block text-[11px] text-ink-700/60 lg:inline lg:text-sm lg:text-current">{{ $outcome }}</span>
                        <span class="font-medium tabular-nums {{ $outcomeClass }}">{{ $outcomeCount }}</span>
                    </div>
                @endforeach
            </div>
            <h3 class="text-xs sm:text-sm font-semibold mt-3 lg:mt-4 mb-1">Unfulfilled orders</h3>
            <div class="grid grid-cols-3 lg:grid-cols-1 gap-1.5 lg:gap-1 text-center lg:text-left text-sm">
                @foreach([
                    ['Today', $o['pending_aging']['today'], ''],
                    ['1–3 days old', $o['pending_aging']['1_3'], 'text-amber-600'],
                    ['Over 3 days', $o['pending_aging']['over_3'], 'text-red-600 font-medium'],
                ] as [$age, $ageCount, $ageClass])
                    <div class="rounded-md bg-ink-50 px-1 py-1 lg:flex lg:justify-between lg:bg-transparent lg:p-0">
                        <span class="block text-[11px] text-ink-700/60 lg:inline lg:text-sm lg:text-current">{{ $age }}</span>
                        <span class="tabular-nums {{ $ageClass }}">{{ $ageCount }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Urgent, so it never folds — the three nearest to running out show
             on a phone and the rest are one tap away. --}}
        <div class="card p-3 sm:p-5 group" x-data="{ all: false }" :data-all="all">
            <h2 class="font-semibold text-sm sm:text-base mb-0.5 sm:mb-1">Running out soon</h2>
            <p class="text-[11px] sm:text-xs text-ink-700/55 mb-1.5 sm:mb-3">Days of stock left at the current sales rate.</p>
            @forelse($o['stock_cover'] as $c)
                <div class="{{ $loop->index >= 3 ? 'hidden md:flex group-data-[all]:flex' : 'flex' }} justify-between items-center text-[13px] sm:text-sm py-1.5 border-b border-ink-100 last:border-0">
                    <a href="{{ route('admin.products.edit', $c['slug'] ?? $c['id']) }}" class="truncate hover:text-gold-700">{{ $c['name'] }}</a>
                    <span class="whitespace-nowrap tabular-nums ml-2 {{ $c['days_left'] <= 7 ? 'text-red-600 font-medium' : 'text-ink-700/60' }}">
                        {{ $c['days_left'] }}d · {{ $c['stock_quantity'] }} left
                    </span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">Not enough sales history yet.</p>
            @endforelse
            @if(count($o['stock_cover']) > 3)
                <button type="button" @click="all = !all" class="md:hidden mt-1.5 text-xs font-medium text-gold-700"
                        x-text="all ? 'Show fewer' : 'Show all {{ count($o['stock_cover']) }}'">Show all {{ count($o['stock_cover']) }}</button>
            @endif
        </div>

        {{-- Folds on a phone. --}}
        <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
            <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
                <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Dead stock</h2>
                <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide dead stock">
                    <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>
            <div class="hidden md:block group-data-[open]:block">
                <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">In stock, zero sales in this period — cash sitting still.</p>
                @forelse($o['dead_stock'] as $p)
                    <div class="flex justify-between items-center text-[13px] sm:text-sm py-1.5 border-b border-ink-100 last:border-0">
                        <a href="{{ route('admin.products.edit', $p['slug'] ?? $p['id']) }}" class="truncate hover:text-gold-700">{{ $p['name'] }}</a>
                        <span class="text-ink-700/60 text-xs tabular-nums whitespace-nowrap ml-2">{{ $p['stock_quantity'] }} pcs</span>
                    </div>
                @empty
                    <p class="text-sm text-ink-700/50">Everything in stock is selling.</p>
                @endforelse
            </div>
        </div>
    </div>
@endif
@endsection
