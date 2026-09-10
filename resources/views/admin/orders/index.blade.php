@extends('layouts.admin')
@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')
@php
    // Opening line for the WhatsApp button on each row. Editable under
    // Settings → "WhatsApp message"; placeholders match the SMS templates.
    $waTemplate = \App\Models\Setting::get('whatsapp_order_template')
        ?: 'Hello {name}, this is {store} regarding your order {order_number}.';
    $waMessage = fn ($order) => strtr($waTemplate, [
        '{name}' => trim((string) $order->customer_name),
        '{store}' => store_name(),
        '{order_number}' => (string) $order->order_number,
        '{total}' => money($order->total),
    ]);
@endphp
<div x-data="{ sel: [], pageIds: [{{ $orders->pluck('id')->implode(',') }}],
               get allChecked(){ return this.pageIds.length && this.sel.length === this.pageIds.length },
               toggleAll(e){ this.sel = e.target.checked ? [...this.pageIds] : [] } }">

    @if($courierBalance !== null)
        {{-- Courier wallet. This is the screen orders are booked from, so a
             balance running dry belongs here rather than one order deep. --}}
        @php $lowBalance = $courierBalance < 500; @endphp
        <div class="mb-4 flex items-center gap-2 text-sm">
            <span class="inline-flex items-center gap-2 rounded-lg px-3 py-2 font-medium
                         {{ $lowBalance ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-ink-50 text-ink-700 border border-ink-100' }}">
                🚚 Courier balance: <strong>৳{{ number_format($courierBalance, 0) }}</strong>
                @if($lowBalance)
                    <span class="text-xs font-normal">· low — top up Steadfast before booking more parcels</span>
                @endif
            </span>
        </div>
    @endif

    {{-- A large share of this shop's orders arrive by phone or DM. Taking one
         through the public storefront fired the Pixel from the owner's own
         browser and attributed the sale to her session. --}}
    <div class="mb-4">
        <a href="{{ route('admin.orders.create') }}" class="btn-primary py-2 text-sm inline-block">+ New order (phone / Messenger)</a>
    </div>

    {{-- Status slicer.

         The dropdown can only say one thing at a time, and the owner's day is
         spent bouncing between the same two or three queues — so those get a
         pill each, with a live count, one click away. "Multi" changes what a
         click means: off, a pill REPLACES the filter (the dropdown's
         behaviour); on, it ADDS to it, which is the only way to watch Pending
         and Processing side by side. Which statuses get a pill is the owner's
         call, editable right here. --}}
    @php
        // Carried through every pill so a filter click never silently drops
        // the search term or bounces you out of Trash.
        $base = array_filter([
            'q' => $search,
            'trashed' => $trashed ? 1 : null,
        ], fn ($v) => filled($v));

        // Anything currently filtered on gets a pill whether it is pinned or
        // not. Without this, landing on the default Processing queue with
        // Processing unpinned lit up no pill at all — the row said "All" was
        // off and named nothing that was on.
        $pillKeys = $quickFilters;
        foreach ($selectedStatuses as $active) {
            if (! in_array($active, $pillKeys, true)) {
                $pillKeys[] = $active;
            }
        }

        // Both destinations are worked out server-side so the pills stay plain
        // links: the href is the single-select one, and Alpine swaps in the
        // additive one only while "Multi" is on. Nothing here needs JS to work.
        $pillHref = function (string $key) use ($base, $selectedStatuses) {
            $toggled = in_array($key, $selectedStatuses, true)
                ? array_values(array_diff($selectedStatuses, [$key]))
                : [...$selectedStatuses, $key];

            return [
                'single' => route('admin.orders.index', $base + ['status' => $key]),
                // Untoggling the last pill has to say "all" out loud — an empty
                // status means "use the default", which would snap back to
                // Processing instead of clearing.
                'multi' => route('admin.orders.index', $base + ['status' => $toggled ? implode(',', $toggled) : 'all']),
            ];
        };
        $pillOn = 'border-gold-600 bg-gold-600 text-white shadow-sm';
        $pillOff = 'border-ink-200 bg-white text-ink-700 hover:border-gold-300 hover:bg-gold-50';
        $multiSelected = count($selectedStatuses) > 1;
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2"
         {{-- Read in init(), inside a try: localStorage THROWS outright when site
              data is blocked, and an x-data that throws leaves the component
              dead — which is the one state where the pills must still work. --}}
         x-data="{ multi: false, init() { try { this.multi = localStorage.getItem('orderStatusMulti') === '1' } catch (e) {} } }">

        @php $allOn = $selectedStatuses === []; @endphp
        <a href="{{ route('admin.orders.index', $base + ['status' => 'all']) }}"
           class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition {{ $allOn ? $pillOn : $pillOff }}">
            All <span class="{{ $allOn ? 'text-white/80' : 'text-ink-700/60' }}">{{ $statusCounts->sum() }}</span>
        </a>

        @foreach($pillKeys as $key)
            @php $on = in_array($key, $selectedStatuses, true); $href = $pillHref($key); @endphp
            <a href="{{ $href['single'] }}" :href="multi ? @js($href['multi']) : @js($href['single'])"
               class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition {{ $on ? $pillOn : $pillOff }}">
                {{ $statuses[$key] }}
                <span class="{{ $on ? 'text-white/80' : 'text-ink-700/60' }}">{{ $statusCounts[$key] ?? 0 }}</span>
            </a>
        @endforeach

        <label class="ml-1 inline-flex cursor-pointer select-none items-center gap-1.5 text-xs text-ink-700/60"
               title="Off: a pill replaces the filter. On: pills add up, so you can watch Pending and Processing together.">
            <input type="checkbox" x-model="multi"
                   @change="try { localStorage.setItem('orderStatusMulti', multi ? '1' : '0') } catch (e) {}"
                   class="rounded border-ink-300">
            Multi
        </label>

        <div class="relative" x-data="{ open: false, max: {{ $maxQuickFilters }}, picked: @js($quickFilters) }">
            <button type="button" @click="open = ! open"
                    class="text-xs text-ink-700/45 hover:text-gold-700 hover:underline"
                    title="Choose which statuses appear as pills">✎ Edit pills</button>
            <div x-show="open" x-cloak @click.outside="open = false"
                 class="absolute left-0 top-full z-30 mt-2 w-60 rounded-lg border border-ink-200 bg-white p-3 shadow-lg">
                <p class="mb-2 text-xs text-ink-700/60">Pin up to {{ $maxQuickFilters }} statuses as pills.</p>
                <form action="{{ route('admin.orders.quick-filters') }}" method="POST">
                    @csrf
                    {{-- Held at the cap in the browser as well as on the server:
                         the server rejects a fourth, but bouncing the owner off
                         to a validation error to say so is a worse answer than
                         greying the box out. --}}
                    @foreach($statuses as $key => $label)
                        <label class="flex items-center gap-2 py-1 text-sm"
                               :class="picked.length >= max && ! picked.includes('{{ $key }}') ? 'opacity-40' : ''">
                            <input type="checkbox" name="statuses[]" value="{{ $key }}" x-model="picked"
                                   :disabled="picked.length >= max && ! picked.includes('{{ $key }}')"
                                   class="rounded border-ink-300">
                            {{ $label }}
                        </label>
                    @endforeach
                    <button class="btn-primary mt-3 w-full py-1.5 text-xs">Save pills</button>
                </form>
            </div>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-4">
        {{-- Without this, searching or changing the status from inside Trash
             dropped ?trashed and dumped you back into the live list. --}}
        @if($trashed)<input type="hidden" name="trashed" value="1">@endif
        <input name="q" value="{{ $search }}" placeholder="Order #, name or phone…" class="input py-2 w-64">
        <select name="status" onchange="submitForm(this.form)" class="input py-2">
            @if($multiSelected)
                {{-- The pills can express a set this dropdown cannot. Say so,
                     rather than letting it read "All statuses" while the table
                     is showing two. --}}
                <option value="{{ implode(',', $selectedStatuses) }}" selected>{{ count($selectedStatuses) }} statuses selected</option>
            @endif
            {{-- "all" is spelled out because an empty value means "use the
                 default", which is Processing — the day's packing queue. --}}
            <option value="all" @selected(! $multiSelected && $status==='all')>All statuses</option>
            @foreach($statuses as $key => $label)
                <option value="{{ $key }}" @selected(! $multiSelected && $status===$key)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn-outline">Search</button>
        @if($trashed)
            <a href="{{ route('admin.orders.index') }}" class="btn-outline whitespace-nowrap ml-auto">← Back to active orders</a>
        @else
            <a href="{{ route('admin.orders.labels') }}" target="_blank" class="btn-outline whitespace-nowrap ml-auto"
               title="Labels for every order booked with the courier and still waiting to be handed over">🖨 Print all labels</a>
            <a href="{{ route('admin.orders.cards') }}" target="_blank" class="btn-outline whitespace-nowrap"
               title="Cards for every order currently being packed">💌 Thank-you cards</a>
            <a href="{{ route('admin.orders.index', ['trashed' => 1]) }}" class="btn-outline whitespace-nowrap">🗑 Trash{{ $trashCount ? ' ('.$trashCount.')' : '' }}</a>
        @endif
    </form>

    {{-- Bulk action bar (active orders only) --}}
    @unless($trashed)
    <div x-show="sel.length" x-cloak
         class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3">
        <span class="text-sm font-medium"><span x-text="sel.length"></span> selected</span>

        <form action="{{ route('admin.orders.bulk-steadfast') }}" method="POST" class="inline"
              onsubmit="return confirm('Send the selected orders to Steadfast? Orders already booked are skipped.')">
            @csrf
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-primary py-2 text-sm">🚚 Send to Steadfast</button>
        </form>

        @if($bdCourierOn)
            <form action="{{ route('admin.orders.bulk-courier-check') }}" method="POST" class="inline"
                  onsubmit="return confirm('Look up courier history for the selected customers? Numbers already checked in the last 48 hours are skipped, and repeats count once — so this usually costs fewer credits than orders selected.')">
                @csrf
                <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                <button class="btn-outline py-2 text-sm">🔍 Check courier history</button>
            </form>
        @endif

        <button type="button" class="btn-outline py-2 text-sm"
                @click="window.open('{{ route('admin.orders.labels') }}?ids=' + sel.join(','), '_blank')">
            🖨 Print labels
        </button>

        <button type="button" class="btn-outline py-2 text-sm"
                @click="window.open('{{ route('admin.orders.cards') }}?ids=' + sel.join(','), '_blank')">
            💌 Print thank-you cards
        </button>

        <form action="{{ route('admin.orders.merge') }}" method="POST" class="inline"
              onsubmit="return confirm('Merge the selected orders into one? This combines their items under the earliest order number and removes the others.')">
            @csrf
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-outline py-2 text-sm" x-show="sel.length >= 2">🔗 Merge orders</button>
        </form>

        <form action="{{ route('admin.orders.bulk-delete') }}" method="POST" class="inline"
              onsubmit="return confirm('Move the selected order(s) to Trash? Stock from non-cancelled orders is returned to inventory. You can restore them from Trash.')">
            @csrf
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-outline py-2 text-sm !text-red-700 !border-red-200 hover:!bg-red-50">🗑 Delete</button>
        </form>

        <button type="button" class="text-sm text-ink-700/60 hover:underline ml-auto" @click="sel = []">Clear</button>
    </div>
    @endunless

    <div class="grid xl:grid-cols-[1fr_320px] gap-6 items-start">
    <div class="min-w-0">
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-3 py-3 w-8"><input type="checkbox" :checked="allChecked" @change="toggleAll($event)"></th>
                    <th class="px-4 py-3">Order</th><th class="px-4 py-3">Customer</th>
                    @if($bdCourierOn)
                        <th class="px-4 py-3" title="This customer's delivery record across every courier (BDCourier)">Courier History</th>
                    @endif
                    <th class="px-4 py-3">Source</th><th class="px-4 py-3">Items</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3" title="This order's Steadfast delivery status">Courier</th>
                    <th class="px-4 py-3">Status</th><th class="px-4 py-3">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($orders as $order)
                    @php
                        $repeat = ($orderCounts[$order->customer_phone] ?? 1) > 1;
                        $booked = $order->shipment && $order->shipment->consignment_id;
                        // Read off the stored shipment row (already eager-loaded) —
                        // never the live API, or a page of 20 orders would fire 20
                        // courier calls. The webhook and Refresh keep it current.
                        $courierRaw = $order->courierStatus();
                        [$courierLabel, , $courierTone] = \App\Services\SteadfastService::describeStatus($courierRaw);
                        $locked = $order->isStatusLocked();
                        $courierTones = [
                            'green' => 'bg-green-100 text-green-700',
                            'amber' => 'bg-amber-100 text-amber-700',
                            'red' => 'bg-red-100 text-red-700',
                            'gold' => 'bg-gold-100 text-gold-700',
                            'ink' => 'bg-ink-100 text-ink-700',
                        ];
                        // BDCourier reputation, from cache only — never fetched on a page view.
                        $bdRow = $bdHistory[$order->customer_phone] ?? null;
                    @endphp
                    <tr class="{{ $trashed ? 'opacity-70' : 'cursor-pointer' }} {{ $repeat ? 'bg-violet-50 hover:bg-violet-100' : 'hover:bg-ink-50' }}" @unless($trashed) onclick="window.location='{{ route('admin.orders.show', $order) }}'" @endunless>
                        <td class="px-3 py-3" onclick="event.stopPropagation()">
                            <input type="checkbox" value="{{ $order->id }}" x-model.number="sel">
                        </td>
                        <td class="px-4 py-3 font-medium text-gold-700">
                            {{ $order->order_number }}
                            @if($repeat)<span class="ml-1 align-middle badge bg-violet-100 text-violet-700 text-[10px]" title="Returning customer — {{ $orderCounts[$order->customer_phone] }} orders total">🔁 Repeat</span>@endif
                            @if($booked)<div class="text-[10px] text-emerald-700 mt-0.5">📦 {{ $order->shipment->consignment_id }}</div>@endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="min-w-0">
                                    <div class="truncate">{{ $order->customer_name }}</div>
                                    <div class="text-xs text-ink-700/50">{{ $order->customer_phone }}</div>
                                </div>
                                @if($tel = tel_link($order->customer_phone))
                                    {{-- stopPropagation: the row itself opens the order. --}}
                                    <a href="{{ $tel }}" onclick="event.stopPropagation()"
                                       class="shrink-0 grid h-7 w-7 place-items-center rounded-full bg-blue-100 text-blue-700 hover:bg-blue-200 transition"
                                       title="Call {{ $order->customer_name }}">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2Z"/>
                                        </svg>
                                    </a>
                                @endif
                                @if($wa = wa_link($order->customer_phone, $waMessage($order)))
                                    <a href="{{ $wa }}" target="_blank" rel="noopener" onclick="event.stopPropagation()"
                                       class="shrink-0 grid h-7 w-7 place-items-center rounded-full bg-green-100 text-green-700 hover:bg-green-200 transition"
                                       title="WhatsApp {{ $order->customer_name }}">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/>
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        </td>
                        @if($bdCourierOn)
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($bdRow)
                                    <span class="badge {{ $courierTones[$bdRow['risk']['tone']] ?? 'bg-ink-100 text-ink-700' }} text-[10px]"
                                          title="{{ $bdRow['risk']['note'] }}">{{ $bdRow['risk']['label'] }}</span>
                                    @if(($bdRow['summary']['total_parcel'] ?? 0) > 0)
                                        <div class="text-[11px] text-ink-700/60 mt-0.5">
                                            {{ $bdRow['summary']['success_ratio'] }}%
                                            <span class="text-ink-700/35">· {{ $bdRow['summary']['success_parcel'] }}/{{ $bdRow['summary']['total_parcel'] }}</span>
                                        </div>
                                    @endif
                                    @if(! empty($bdRow['reports']))
                                        <div class="text-[10px] text-red-700 mt-0.5" title="{{ count($bdRow['reports']) }} fraud report(s)">⚠️ {{ count($bdRow['reports']) }} report(s)</div>
                                    @endif
                                @else
                                    <span class="text-xs text-ink-700/35" title="Select orders and press “Check courier history”">Not checked</span>
                                @endif
                            </td>
                        @endif
                        <td class="px-4 py-3">
                            {{-- An order the AI assistant took, called out so it can be
                                 checked before it is booked with the courier. --}}
                            @if($order->source === 'chat')
                                <span class="badge bg-gold-600 text-white text-[10px] mr-1" title="Taken by the AI assistant in chat">AI chat</span>
                            @endif
                            @if($order->source_channel)
                                <span class="badge {{ \App\Support\TrafficSource::badgeClass($order->source_channel) }} text-[10px]"
                                      title="{{ $order->source_campaign ? 'Campaign: '.$order->source_campaign : ($order->source_referrer ?: '') }}">
                                    {{ \App\Support\TrafficSource::label($order->source_channel) }}
                                </span>
                            @else
                                <span class="text-xs text-ink-700/35">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $order->items_count }}</td>
                        <td class="px-4 py-3">{{ money($order->total) }}</td>
                        <td class="px-4 py-3">
                            @if(filled($courierRaw))
                                <span class="badge {{ $courierTones[$courierTone] ?? 'bg-ink-100 text-ink-700' }} text-[10px] whitespace-nowrap"
                                      title="Steadfast: {{ $courierRaw }}">{{ $courierLabel }}</span>
                            @elseif($booked)
                                <span class="text-xs text-ink-700/35" title="Booked, no status yet">Booked</span>
                            @else
                                <span class="text-xs text-ink-700/35">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" onclick="event.stopPropagation()">
                            @if($trashed)
                                <div class="flex items-center gap-3">
                                    <form action="{{ route('admin.orders.restore', $order) }}" method="POST">@csrf<button class="text-xs font-medium text-emerald-700 hover:underline">↩ Restore</button></form>
                                    <form action="{{ route('admin.orders.force-delete', $order) }}" method="POST" onsubmit="return confirm('Permanently delete order {{ $order->order_number }}? This cannot be undone.')">@csrf @method('DELETE')<button class="text-xs text-red-700 hover:underline">Delete forever</button></form>
                                </div>
                            @else
                                @if($locked)
                                    {{-- Courier confirmed delivery: goods gone, COD collected, status final. --}}
                                    <select disabled
                                            class="rounded-md border border-ink-200 bg-ink-100 px-2 py-1 text-xs capitalize text-ink-700/60 cursor-not-allowed"
                                            title="Locked — the courier confirmed delivery.">
                                        <option>{{ $statuses[$order->status] ?? $order->status }}</option>
                                    </select>
                                    <div class="text-[10px] text-ink-700/40 mt-0.5">🔒 confirmed by courier</div>
                                @else
                                    <form action="{{ route('admin.orders.status', $order) }}" method="POST">
                                        @csrf
                                        <select name="status" onchange="submitForm(this.form)"
                                                class="rounded-md border border-ink-200 bg-white px-2 py-1 text-xs capitalize">
                                            @foreach($statuses as $key => $label)
                                                <option value="{{ $key }}" @selected($order->status==$key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $order->created_at->format('d M, g:i a') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $bdCourierOn ? 10 : 9 }}" class="px-4 py-10 text-center text-ink-700/50">No orders found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $orders->links() }}</div>
    </div>

    {{-- Processing fulfilment queue (products to prepare) --}}
    <aside class="card p-4 xl:sticky xl:top-4">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">To prepare · Processing</h2>
            <span class="badge bg-amber-100 text-amber-700 text-[10px]">{{ $processingItems->sum('qty') }} units</span>
        </div>
        <p class="text-xs text-ink-700/50 mb-3">Items across all orders currently in <strong>Processing</strong>.</p>
        @forelse($processingItems as $it)
            <div class="flex items-start gap-2.5 py-2 border-b border-ink-50 last:border-0 text-sm">
                <span class="w-10 h-10 rounded-md bg-ink-100 overflow-hidden shrink-0">
                    @if($it->product_id && ($processingImages[$it->product_id] ?? null))
                        <img src="{{ $processingImages[$it->product_id] }}" alt="" class="w-full h-full object-cover" loading="lazy">
                    @endif
                </span>
                <div class="min-w-0 flex-1">
                    <div class="truncate">{{ $it->name }}</div>
                    <div class="text-[11px] text-ink-700/45">
                        @if($it->product_id)ID #{{ $processingSerials[$it->product_id] ?? $it->product_id }}@else (deleted) @endif
                        · {{ $it->orders }} order{{ $it->orders > 1 ? 's' : '' }}
                    </div>
                </div>
                <span class="shrink-0 font-semibold text-gold-700">×{{ $it->qty }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-700/50">Nothing in processing right now. 🎉</p>
        @endforelse
    </aside>
    </div>
</div>
@endsection
