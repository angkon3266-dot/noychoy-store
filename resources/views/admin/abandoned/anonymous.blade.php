@extends('layouts.admin')
@section('title', 'Carts with no contact details')
@section('heading', 'Carts with no contact details')

@section('content')
<div class="flex flex-wrap items-start justify-between gap-3 mb-4">
    <p class="text-sm text-ink-700/70 max-w-2xl">
        Shoppers who filled a basket and left without typing a phone number. There is nobody to ring here —
        what this screen is for is the pattern: which pieces get picked up, and where the buying stops.
    </p>
    <a href="{{ route('admin.abandoned.index') }}" class="btn-outline py-2 text-sm whitespace-nowrap">← Leads you can call</a>
</div>

{{-- The dashboard's own period vocabulary, so a link between the two screens
     keeps the window the owner had chosen. --}}
<div class="flex flex-wrap gap-1.5 mb-5">
    @foreach(\App\Support\DateRange::PRESETS as $key => $label)
        <a href="{{ route('admin.abandoned.anonymous', ['period' => $key]) }}"
           class="px-3 py-1.5 rounded-full text-sm {{ request('period', \App\Support\DateRange::DEFAULT) === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Carts, no contact</p>
        <p class="text-2xl font-semibold mt-1">{{ number_format($summary['sessions']) }}</p>
        <p class="text-[11px] text-ink-700/45 mt-0.5">{{ number_format($summary['leads']) }} did leave a number</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Reached checkout</p>
        <p class="text-2xl font-semibold mt-1 text-amber-700">{{ number_format($summary['reached_checkout']) }}</p>
        <p class="text-[11px] text-ink-700/45 mt-0.5">saw the total, did not finish</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Cart only</p>
        <p class="text-2xl font-semibold mt-1">{{ number_format($summary['cart_only']) }}</p>
        <p class="text-[11px] text-ink-700/45 mt-0.5">never opened the checkout</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Value picked up</p>
        <p class="text-2xl font-semibold mt-1">{{ money($summary['value']) }}</p>
        @if($summary['unmeasured'])
            <p class="text-[11px] text-ink-700/45 mt-0.5">{{ number_format($summary['unmeasured']) }} add(s) predate value tracking</p>
        @else
            <p class="text-[11px] text-ink-700/45 mt-0.5">summed over every add</p>
        @endif
    </div>
</div>

{{-- ── What was true when they left ── --}}
<div class="card p-5 mb-6">
    <h2 class="font-semibold mb-1">Where the buying stops</h2>
    <p class="text-xs text-ink-700/55 mb-3">
        Nobody was asked why they left, so these are not reasons — they are the measurable things that were
        true at the time. Read them as places to look.
    </p>
    <div class="space-y-2">
        @foreach($signals as $signal)
            <div class="flex items-start gap-3 border-t border-ink-100 pt-2 first:border-0 first:pt-0">
                <span class="text-lg font-semibold w-14 shrink-0 tabular-nums">{{ number_format($signal['count']) }}</span>
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ $signal['label'] }}</p>
                    <p class="text-xs text-ink-700/60">{{ $signal['note'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
    @unless($threshold)
        <p class="mt-3 text-[11px] text-ink-700/45">
            Free delivery is switched off, so this cannot tell you whether postage was the sticking point.
            Set a threshold in <a href="{{ route('admin.settings') }}" class="underline">Settings</a> and it will.
        </p>
    @endunless
</div>

{{-- ── Most picked up ── --}}
<div class="card p-5 mb-6 overflow-x-auto">
    <h2 class="font-semibold mb-1">Picked up and put back down</h2>
    <p class="text-xs text-ink-700/55 mb-3">
        Ranked by how many different people reached for it — ten adds from one undecided shopper is still one person.
    </p>
    <table class="w-full text-sm min-w-[40rem]">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr>
                <th class="px-3 py-2">Piece</th>
                <th class="px-3 py-2 text-right">People</th>
                <th class="px-3 py-2 text-right">Adds</th>
                <th class="px-3 py-2 text-right">Reached checkout</th>
                <th class="px-3 py-2 text-right">Value</th>
                <th class="px-3 py-2">Buyable now</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($products as $p)
                <tr class="hover:bg-ink-50">
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <div class="h-9 w-9 shrink-0 overflow-hidden rounded bg-ink-50">
                                @if($p['thumb'])<img src="{{ $p['thumb'] }}" alt="" class="h-full w-full object-cover">@endif
                            </div>
                            @if($p['url'])
                                <a href="{{ $p['url'] }}" target="_blank" rel="noopener" class="font-medium hover:underline truncate">{{ $p['name'] }}</a>
                            @else
                                <span class="font-medium truncate">{{ $p['name'] }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($p['sessions']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-ink-700/70">{{ number_format($p['adds']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums {{ $p['reached_checkout'] ? '' : 'text-ink-700/35' }}">
                        {{ number_format($p['reached_checkout']) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($p['value']) }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        @if($p['gone'])
                            <span class="badge bg-red-100 text-red-700 text-[10px]">Withdrawn</span>
                        @elseif($p['in_stock'] === false)
                            <span class="badge bg-red-100 text-red-700 text-[10px]">Out of stock</span>
                        @else
                            <span class="badge bg-green-100 text-green-700 text-[10px]">In stock</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-10 text-center text-ink-700/50">
                    Nothing was added to a cart by an unidentified visitor in this period.
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ── The sessions themselves ── --}}
<div class="card p-5 overflow-x-auto">
    <h2 class="font-semibold mb-1">The sessions</h2>
    <p class="text-xs text-ink-700/55 mb-3">
        Newest first, up to {{ \App\Services\AnonymousCartInsight::PAGE }}. The reference is a browser cookie, not a person —
        there is no name behind it.
    </p>
    <table class="w-full text-sm min-w-[48rem]">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr>
                <th class="px-3 py-2">Session</th>
                <th class="px-3 py-2">Picked up</th>
                <th class="px-3 py-2 text-right">Basket</th>
                <th class="px-3 py-2">Got as far as</th>
                <th class="px-3 py-2">Came from</th>
                <th class="px-3 py-2">When</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($sessions as $s)
                <tr class="hover:bg-ink-50 align-top">
                    <td class="px-3 py-2 font-mono text-xs text-ink-700/60 whitespace-nowrap">{{ $s['short'] }}</td>
                    <td class="px-3 py-2">
                        @forelse($s['items'] as $item)
                            <div class="text-xs truncate max-w-[18rem]">
                                {{ $item['name'] }}@if($item['adds'] > 1) <span class="text-ink-700/45">×{{ $item['adds'] }}</span>@endif
                                @if($item['sellable'] === false)<span class="text-red-600">· out of stock</span>@endif
                            </div>
                        @empty
                            <span class="text-xs text-ink-700/40">—</span>
                        @endforelse
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ money($s['value']) }}</td>
                    <td class="px-3 py-2">
                        <span class="badge {{ $s['signal']['tone'] }} text-[10px]" title="{{ $s['signal']['note'] }}">{{ $s['signal']['label'] }}</span>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="badge {{ \App\Support\TrafficSource::badgeClass($s['channel']) }} text-[10px]">{{ $s['channel_label'] }}</span>
                        @if($s['campaign'])<div class="text-[11px] text-ink-700/45 truncate max-w-[10rem]">🏷 {{ $s['campaign'] }}</div>@endif
                    </td>
                    <td class="px-3 py-2 text-xs text-ink-700/60 whitespace-nowrap">
                        {{ store_time(\Illuminate\Support\Carbon::parse($s['last_at']))?->diffForHumans() }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-10 text-center text-ink-700/50">
                    No anonymous carts in this period.
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4 space-y-1 text-[11px] text-ink-700/45">
    <p>
        A shopper counts as “no contact” when no lead was captured against her browser. Leads captured before
        9 September 2026 were stored without that reference, so a small number of early callers may appear here too.
    </p>
    @if($summary['automated'])
        <p>
            {{ number_format($summary['automated']) }} session(s) were left out of every figure above: they added to the
            cart more than {{ \App\Services\AnonymousCartInsight::ADD_CEILING }} times, or added without ever loading a
            page. Those are scripts, not shoppers, and counting them put pieces at the top of the list that no person
            had reached for.
        </p>
    @endif
</div>
@endsection
