@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
{{-- Reporting window. Every time-based figure below follows this; live counts
     (to process, stock, customer base) deliberately do not. --}}
<div class="flex flex-wrap items-center gap-2 mb-4" x-data="{ custom: @js($range->isCustom()) }">
    @foreach(\App\Support\DateRange::PRESETS as $key => $label)
        <a href="{{ route('admin.dashboard', ['period' => $key]) }}"
           class="rounded-lg px-3 py-1.5 text-xs font-medium border transition
                  {{ $range->key === $key
                        ? 'bg-ink-900 text-white border-ink-900'
                        : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">{{ $label }}</a>
    @endforeach

    <button type="button" @click="custom = !custom"
            class="rounded-lg px-3 py-1.5 text-xs font-medium border transition
                   {{ $range->isCustom()
                        ? 'bg-ink-900 text-white border-ink-900'
                        : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">
        {{ $range->isCustom() ? $range->label : 'Custom…' }}
    </button>

    <form x-show="custom" x-cloak method="GET" action="{{ route('admin.dashboard') }}"
          class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="{{ $range->isCustom() ? $range->start->toDateString() : '' }}"
               class="input py-1 text-xs w-auto" required>
        <span class="text-xs text-ink-700/40">to</span>
        <input type="date" name="to" value="{{ $range->isCustom() ? $range->end->toDateString() : '' }}"
               class="input py-1 text-xs w-auto" required>
        <button class="btn-primary py-1.5 px-3 text-xs">Apply</button>
    </form>
</div>

{{-- Which deep-analysis panels to show --}}
<div class="flex justify-end mb-3" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" @click="open = !open" class="btn-outline py-1.5 text-xs">⚙ Customize dashboard</button>
    <form action="{{ route('admin.dashboard.panels') }}" method="POST" x-show="open" x-cloak x-transition
          class="absolute z-30 mt-9 w-64 rounded-xl border border-ink-100 bg-white shadow-xl p-3 text-sm">
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

{{-- KPI cards --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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
        <div class="card p-5">
            <div class="text-sm text-ink-700/60">{{ $label }}</div>
            <div class="text-2xl font-semibold mt-1 {{ $color }}">{{ $value }}</div>
            <div class="text-xs text-ink-700/40 mt-0.5">{{ $sub }}</div>
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-6">
    {{-- Revenue over the selected window (days grouped once it gets long) --}}
    <div class="card p-5 lg:col-span-2">
        <h2 class="font-semibold mb-4">Sales · {{ $range->label }}</h2>
        <div class="flex items-end justify-between gap-3 h-44">
            @foreach($daily as $d)
                <div class="flex-1 flex flex-col items-center justify-end h-full">
                    <div class="text-[10px] text-ink-700/50 mb-1">{{ $d['total'] > 0 ? money($d['total']) : '' }}</div>
                    <div class="w-full rounded-t bg-gold-400/80" style="height: {{ $d['total'] > 0 ? max(4, round($d['total'] / $dailyMax * 100)) : 1 }}%"></div>
                    <div class="text-xs text-ink-700/50 mt-2">{{ $d['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Order status breakdown --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-4">Orders by status</h2>
        <div class="space-y-2">
            @foreach(\App\Models\Order::STATUSES as $key => $label)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-700/70">{{ $label }}</span>
                    <span class="badge bg-ink-100 text-ink-700">{{ $statusCounts[$key] ?? 0 }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
    {{-- Top products --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Top products · {{ $range->label }}</h2>
        @forelse($topProducts as $p)
            <div class="flex items-center justify-between py-2 border-b border-ink-50 last:border-0 text-sm">
                <span class="truncate pr-3">{{ $p->name }}</span>
                <span class="shrink-0 text-ink-700/60">{{ $p->qty }} sold · {{ money($p->revenue) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No sales in this period yet.</p>
        @endforelse
    </div>

    {{-- Best-selling categories --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Best-selling categories · {{ $range->label }}</h2>
        @forelse($topCategories as $cat)
            <div class="py-1.5">
                <div class="flex items-center justify-between text-sm mb-1">
                    <span class="truncate pr-3">{{ $cat->name }}</span>
                    <span class="shrink-0 text-ink-700/60">{{ $cat->qty }} sold · {{ money($cat->revenue) }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ round($cat->qty / $catMax * 100) }}%"></div></div>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No category sales in this period yet.</p>
        @endforelse
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
    {{-- Low stock --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Low stock alerts</h2>
        @forelse($lowStockProducts as $p)
            <div class="flex items-center justify-between py-2 border-b border-ink-50 last:border-0 text-sm">
                <a href="{{ route('admin.products.edit', $p) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $p->name }}</a>
                <span class="badge {{ $p->stock_quantity == 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ $p->stock_quantity }} left</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">Everything's well stocked. 🎉</p>
        @endforelse
    </div>

    {{-- Most valuable customers --}}
    <div class="card p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Top customers</h2>
            <span class="text-xs text-ink-700/50">Points liability: {{ money($pointsLiability) }} ({{ number_format($pointsOutstanding) }} pts)</span>
        </div>
        @forelse($topCustomers as $c)
            <div class="flex items-center justify-between py-2 border-b border-ink-50 last:border-0 text-sm">
                <a href="{{ route('admin.customers.show', $c) }}" class="text-gold-700 hover:underline truncate pr-3">{{ $c->name }} <span class="text-ink-700/40">· {{ $c->total_orders }} orders</span></a>
                <span class="shrink-0 text-ink-700/60">{{ money($c->total_spent) }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">No customer sales yet.</p>
        @endforelse
    </div>
</div>

{{-- Most loved products --}}
<div class="card p-5 mt-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold flex items-center gap-2">
            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
            Most loved products
        </h2>
        <span class="text-sm text-ink-700/60">{{ number_format($totalLoves) }} total ❤️</span>
    </div>
    @forelse($mostLoved as $p)
        @php $lovedMax = max(1, $mostLoved->max('loves_count')); @endphp
        <div class="flex items-center gap-3 py-2 border-b border-ink-50 last:border-0 text-sm">
            <a href="{{ route('admin.products.edit', $p) }}" class="text-gold-700 hover:underline truncate w-48 shrink-0">{{ $p->name }}</a>
            <div class="flex-1 h-2 rounded-full bg-ink-50 overflow-hidden">
                <div class="h-full rounded-full bg-red-400" style="width: {{ round($p->loves_count / $lovedMax * 100) }}%"></div>
            </div>
            <span class="shrink-0 font-medium text-red-600 w-16 text-right">❤️ {{ number_format($p->loves_count) }}</span>
        </div>
    @empty
        <p class="text-sm text-ink-700/50">No love reactions yet. They'll appear here as customers tap the ❤️ on products.</p>
    @endforelse
</div>

{{-- Contact messages inbox --}}
@if($unreadMessages > 0)
<div class="card mt-6 overflow-hidden border-gold-200">
    <div class="flex items-center justify-between px-5 py-4 border-b border-ink-100 bg-gold-50/60">
        <h2 class="font-semibold flex items-center gap-2">📨 New messages
            <span class="min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold inline-flex items-center justify-center">{{ $unreadMessages }}</span>
        </h2>
        <a href="{{ route('admin.messages') }}" class="text-sm text-gold-700 hover:underline">All messages →</a>
    </div>
    <div class="divide-y divide-ink-100">
        @foreach($recentMessages as $m)
            <div class="px-5 py-3 flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ $m->name }}
                        <span class="text-xs font-normal text-ink-700/50">· {{ $m->phone ?: $m->email }} · {{ $m->created_at->diffForHumans() }}</span>
                    </p>
                    @if($m->subject)<p class="text-xs font-medium text-ink-700/70 mt-0.5">{{ $m->subject }}</p>@endif
                    <p class="text-sm text-ink-700/70 mt-0.5">{{ \Illuminate\Support\Str::limit($m->message, 160) }}</p>
                </div>
                <form action="{{ route('admin.messages.read', $m) }}" method="POST" class="shrink-0">
                    @csrf
                    <button class="text-xs text-gold-700 hover:underline whitespace-nowrap">Mark read</button>
                </form>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- Recent orders --}}
<div class="card mt-6 overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-ink-100">
        <h2 class="font-semibold">Recent orders</h2>
        <a href="{{ route('admin.orders.index') }}" class="text-sm text-gold-700 hover:underline">All orders →</a>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Date</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($recentOrders as $order)
                <tr class="hover:bg-ink-50">
                    <td class="px-5 py-3"><a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-gold-700 hover:underline">{{ $order->order_number }}</a></td>
                    <td class="px-5 py-3">{{ $order->customer_name }}<div class="text-xs text-ink-700/50">{{ $order->customer_phone }}</div></td>
                    <td class="px-5 py-3">{{ money($order->total) }}</td>
                    <td class="px-5 py-3"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></td>
                    <td class="px-5 py-3 text-ink-700/60">{{ $order->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-8 text-center text-ink-700/50">No orders yet.</td></tr>
            @endforelse
        </tbody>
    </table>
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
    <div class="card p-5 mt-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold">Revenue &amp; profit</h2>
            <span class="text-xs text-ink-700/50">{{ $range->label }}{{ $range->isAllTime() ? '' : ' vs previous period' }}</span>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <div class="text-xs text-ink-700/60">Revenue</div>
                <div class="text-xl font-semibold">{{ money($p['current']['revenue']) }}</div>
                {{-- "Maximum" has no earlier period to compare against. --}}
                <div class="text-xs {{ $revCls }}">{{ $revTxt }}
                    @if($p['previous'])<span class="text-ink-700/40">vs {{ money($p['previous']['revenue']) }}</span>@endif
                </div>
            </div>
            <div>
                <div class="text-xs text-ink-700/60">Profit</div>
                <div class="text-xl font-semibold text-green-700">{{ money($p['current']['profit']) }}</div>
                <div class="text-xs {{ $proCls }}">{{ $proTxt }}
                    @if($p['previous'])<span class="text-ink-700/40">vs {{ money($p['previous']['profit']) }}</span>@endif
                </div>
            </div>
            <div>
                <div class="text-xs text-ink-700/60">Margin</div>
                <div class="text-xl font-semibold">{{ $p['current']['margin'] === null ? '—' : $p['current']['margin'].'%' }}</div>
                <div class="text-xs text-ink-700/40">cost {{ money($p['current']['cost']) }}</div>
            </div>
            <div>
                <div class="text-xs text-ink-700/60">Items per order</div>
                <div class="text-xl font-semibold">{{ $p['items_per_order'] }}</div>
                <div class="text-xs text-ink-700/40">AOV {{ money($p['aov']) }} · {{ money($p['discount_given']) }} discounts</div>
            </div>
        </div>
        @if($p['current']['cost'] <= 0 && $p['current']['revenue'] > 0)
            <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mt-4">
                Profit assumes zero cost — add <strong>cost price</strong> (and transport) on your products so margin is real.
            </p>
        @endif
    </div>
@endif

{{-- ── Traffic & conversion funnel ──────────────────────────────────────── --}}
@if($deep['funnel'])
    @php $f = $deep['funnel']; @endphp
    <div class="grid lg:grid-cols-3 gap-6 mt-6">
        <div class="card p-5 lg:col-span-2">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-semibold">Conversion funnel</h2>
                <span class="text-xs text-ink-700/50">{{ $range->label }} · unique visitors</span>
            </div>
            @unless($f['tracking'])
                <p class="text-xs text-ink-700/50 mb-3">No traffic recorded yet — figures fill in as customers visit the storefront.</p>
            @endunless
            <div class="space-y-2.5 mt-3">
                @foreach($f['steps'] as $s)
                    <div>
                        <div class="flex justify-between text-sm">
                            <span>{{ $s['label'] }}</span>
                            <span class="font-medium">{{ number_format($s['value']) }} <span class="text-ink-700/40 text-xs">{{ $s['pct'] === null ? '' : $s['pct'].'%' }}</span></span>
                        </div>
                        <div class="h-2 rounded-full bg-ink-100 overflow-hidden mt-1">
                            <div class="h-full bg-gold-500" style="width: {{ min(100, $s['pct'] ?? 0) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-sm mt-4">Visitor → order conversion:
                <strong>{{ $f['conversion'] === null ? '—' : $f['conversion'].'%' }}</strong></p>
        </div>

        <div class="card p-5">
            <h2 class="font-semibold mb-1">Where visitors come from</h2>
            <p class="text-xs text-ink-700/55 mb-3">Visitors and what each channel earned — {{ $per }}.</p>
            @forelse($deep['sources'] as $s)
                <div class="py-2 border-b border-ink-100 last:border-0">
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="badge {{ \App\Support\TrafficSource::badgeClass($s['channel']) }} shrink-0">{{ $s['label'] }}</span>
                        <span class="font-medium whitespace-nowrap">{{ number_format($s['visitors']) }} visitor{{ $s['visitors'] === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="flex justify-between text-xs text-ink-700/60 mt-1">
                        <span>{{ $s['orders'] }} order{{ $s['orders'] === 1 ? '' : 's' }}{{ $s['rate'] !== null ? ' · '.$s['rate'].'% convert' : '' }}</span>
                        <span class="font-medium text-ink-700/80">{{ money($s['revenue']) }}</span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">No traffic data yet.</p>
            @endforelse
        </div>
    </div>

    @if($deep['campaigns']->isNotEmpty())
        <div class="card p-5 mt-6">
            <h2 class="font-semibold mb-1">Campaigns that sold</h2>
            <p class="text-xs text-ink-700/55 mb-3">
                From <code>utm_campaign</code> on your ad and post links — tag every link and this tells you which
                specific ad paid for itself.
            </p>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-ink-700/50">
                    <tr><th class="py-1">Campaign</th><th class="py-1">Channel</th><th class="py-1 text-right">Orders</th><th class="py-1 text-right">Revenue</th></tr>
                </thead>
                <tbody>
                    @foreach($deep['campaigns'] as $c)
                        <tr class="border-t border-ink-100">
                            <td class="py-1.5 truncate max-w-[220px]">{{ $c['source_campaign'] }}</td>
                            <td class="py-1.5"><span class="badge {{ \App\Support\TrafficSource::badgeClass($c['source_channel']) }}">{{ \App\Support\TrafficSource::label($c['source_channel']) }}</span></td>
                            <td class="py-1.5 text-right">{{ number_format($c['orders']) }}</td>
                            <td class="py-1.5 text-right font-medium">{{ money($c['revenue']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6 mt-6">
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Visitors — {{ $per }}</h2>
            @php $vMax = max(1, collect($deep['visitorsByDay'])->max('value')); @endphp
            <div class="flex items-end gap-1.5 h-32">
                @foreach($deep['visitorsByDay'] as $d)
                    <div class="flex-1 flex flex-col items-center gap-1" title="{{ $d['label'] }}: {{ $d['value'] }}">
                        <div class="w-full rounded-t bg-gold-400" style="height: {{ max(2, round($d['value'] / $vMax * 100)) }}%"></div>
                        <span class="text-[9px] text-ink-700/40 whitespace-nowrap">{{ explode(' ', $d['label'])[0] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card p-5">
            <h2 class="font-semibold mb-1">Viewed but never bought</h2>
            <p class="text-xs text-ink-700/55 mb-3">Interest is there — the photos, price or copy are the blocker.</p>
            @forelse($deep['viewedNotSold'] as $r)
                <div class="flex justify-between items-center text-sm py-1.5 border-b border-ink-100 last:border-0">
                    <a href="{{ route('admin.products.edit', $r['id']) }}" class="truncate hover:text-gold-700">{{ $r['name'] }}</a>
                    <span class="text-ink-700/60 text-xs whitespace-nowrap ml-2">{{ $r['views'] }} views · 0 sold</span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">Nothing flagged — every viewed product has sold.</p>
            @endforelse
        </div>
    </div>
@endif

{{-- ── Customers & retention ────────────────────────────────────────────── --}}
@if($deep['retention'])
    @php $r = $deep['retention']; $rev = $r['new_revenue'] + $r['repeat_revenue']; @endphp
    <div class="card p-5 mt-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold">Customers &amp; retention</h2>
            <span class="text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            <div>
                <div class="text-xs text-ink-700/60">Repeat revenue share</div>
                <div class="text-xl font-semibold">{{ $r['repeat_share'] === null ? '—' : $r['repeat_share'].'%' }}</div>
                <div class="text-xs text-ink-700/40">{{ money($r['repeat_revenue']) }} of {{ money($rev) }}</div>
            </div>
            <div>
                <div class="text-xs text-ink-700/60">Lifetime value</div>
                <div class="text-xl font-semibold">{{ money($r['clv']) }}</div>
                <div class="text-xs text-ink-700/40">avg per buyer</div>
            </div>
            <div>
                <div class="text-xs text-ink-700/60">Days to 2nd order</div>
                <div class="text-xl font-semibold">{{ $r['avg_days_to_second'] ?? '—' }}</div>
                <div class="text-xs text-ink-700/40">{{ $r['repeat_customers'] }} repeat · {{ $r['one_time'] }} one-time</div>
            </div>
            <div>
                <div class="text-xs text-ink-700/60">At risk</div>
                <div class="text-xl font-semibold text-amber-600">{{ $r['at_risk'] }}</div>
                <div class="text-xs text-ink-700/40">quiet 60+ days
                    <a href="{{ route('admin.notifications.index') }}" class="text-gold-700 hover:underline">win them back</a>
                </div>
            </div>
        </div>
        @if($rev > 0)
            <div class="flex h-3 rounded-full overflow-hidden">
                <div class="bg-gold-500" style="width: {{ round($r['new_revenue'] / $rev * 100) }}%" title="New customers"></div>
                <div class="bg-ink-800" style="width: {{ round($r['repeat_revenue'] / $rev * 100) }}%" title="Repeat customers"></div>
            </div>
            <div class="flex justify-between text-xs text-ink-700/50 mt-1.5">
                <span>New {{ money($r['new_revenue']) }}</span>
                <span>Repeat {{ money($r['repeat_revenue']) }}</span>
            </div>
        @endif
    </div>
@endif

{{-- ── Operations & inventory ───────────────────────────────────────────── --}}
@if($deep['operations'])
    @php $o = $deep['operations']; @endphp
    <div class="grid lg:grid-cols-3 gap-6 mt-6">
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Delivery outcomes</h2>
            <div class="text-3xl font-semibold text-green-700">{{ $o['cod_success'] === null ? '—' : $o['cod_success'].'%' }}</div>
            <p class="text-xs text-ink-700/50 mb-3">COD success, {{ $per }}</p>
            <div class="text-sm space-y-1">
                <div class="flex justify-between"><span>Delivered</span><span class="font-medium">{{ $o['delivered'] }}</span></div>
                <div class="flex justify-between"><span>Cancelled</span><span class="font-medium text-amber-600">{{ $o['cancelled'] }}</span></div>
                <div class="flex justify-between"><span>Returned</span><span class="font-medium text-red-600">{{ $o['returned'] }}</span></div>
            </div>
            <h3 class="text-sm font-semibold mt-4 mb-1">Unfulfilled orders</h3>
            <div class="text-sm space-y-1">
                <div class="flex justify-between"><span>Today</span><span>{{ $o['pending_aging']['today'] }}</span></div>
                <div class="flex justify-between"><span>1–3 days old</span><span class="text-amber-600">{{ $o['pending_aging']['1_3'] }}</span></div>
                <div class="flex justify-between"><span>Over 3 days</span><span class="text-red-600 font-medium">{{ $o['pending_aging']['over_3'] }}</span></div>
            </div>
        </div>

        <div class="card p-5">
            <h2 class="font-semibold mb-1">Running out soon</h2>
            <p class="text-xs text-ink-700/55 mb-3">Days of stock left at the current sales rate.</p>
            @forelse($o['stock_cover'] as $c)
                <div class="flex justify-between items-center text-sm py-1.5 border-b border-ink-100 last:border-0">
                    <a href="{{ route('admin.products.edit', $c['id']) }}" class="truncate hover:text-gold-700">{{ $c['name'] }}</a>
                    <span class="whitespace-nowrap ml-2 {{ $c['days_left'] <= 7 ? 'text-red-600 font-medium' : 'text-ink-700/60' }}">
                        {{ $c['days_left'] }}d · {{ $c['stock_quantity'] }} left
                    </span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">Not enough sales history yet.</p>
            @endforelse
        </div>

        <div class="card p-5">
            <h2 class="font-semibold mb-1">Dead stock</h2>
            <p class="text-xs text-ink-700/55 mb-3">In stock, zero sales in this period — cash sitting still.</p>
            @forelse($o['dead_stock'] as $p)
                <div class="flex justify-between items-center text-sm py-1.5 border-b border-ink-100 last:border-0">
                    <a href="{{ route('admin.products.edit', $p['id']) }}" class="truncate hover:text-gold-700">{{ $p['name'] }}</a>
                    <span class="text-ink-700/60 text-xs whitespace-nowrap ml-2">{{ $p['stock_quantity'] }} pcs</span>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">Everything in stock is selling.</p>
            @endforelse
        </div>
    </div>
@endif
@endsection
