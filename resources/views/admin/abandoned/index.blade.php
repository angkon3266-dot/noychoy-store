@extends('layouts.admin')
@section('title', 'Abandoned carts')
@section('heading', 'Abandoned carts')

@section('content')
{{-- Flash messages are rendered once by layouts/admin.blade.php — do not repeat them here. --}}

<p class="text-sm text-ink-700/70 mb-4">
    Checkouts that were started and never finished. Everything the shopper typed is kept, so you can
    call, WhatsApp or text her the cart she left behind.
</p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Waiting for follow-up</p>
        <p class="text-2xl font-semibold mt-1">{{ number_format($openCount) }}</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Value still on the table</p>
        <p class="text-2xl font-semibold mt-1">{{ money($atRisk) }}</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Recovered · last 30 days</p>
        <p class="text-2xl font-semibold mt-1 text-green-700">{{ money($recoveredValue) }}</p>
    </div>
</div>

@unless($smsReady)
    <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2.5 text-sm">
        SMS is off or missing credentials, so the “Send SMS” buttons will refuse. Calling and WhatsApp still work.
        <a href="{{ route('admin.system-config.integrations') }}" class="underline">Set it up →</a>
    </div>
@endunless

<div class="flex flex-wrap items-center gap-2 mb-4">
    <form method="GET" class="flex flex-wrap gap-2">
        @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
        <input name="q" value="{{ $q }}" placeholder="Name or phone…" class="input py-2 w-56">
        <button class="btn-outline py-2 text-sm">Search</button>
        @if($q)<a href="{{ route('admin.abandoned.index', ['filter' => $filter]) }}" class="btn-outline py-2 text-sm">Clear</a>@endif
    </form>
    <div class="ml-auto flex flex-wrap gap-2">
        @foreach(['' => 'All', 'open' => 'Not contacted', 'contacted' => 'Contacted', 'recovered' => 'Recovered'] as $key => $label)
            <a href="{{ route('admin.abandoned.index', array_filter(['filter' => $key ?: null, 'q' => $q ?: null])) }}"
               class="px-3 py-1.5 rounded-full text-sm {{ (string) $filter === (string) $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

<div x-data="{ sel: [], pageIds: [{{ $carts->pluck('id')->implode(',') }}],
               get allChecked(){ return this.pageIds.length && this.sel.length === this.pageIds.length },
               toggleAll(e){ this.sel = e.target.checked ? [...this.pageIds] : [] } }">

    <div x-show="sel.length" x-cloak
         class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3">
        <span class="text-sm font-medium"><span x-text="sel.length"></span> selected</span>

        <form action="{{ route('admin.abandoned.bulk') }}" method="POST" class="inline"
              onsubmit="return confirm('Text the selected shoppers their cart link? This spends SMS credit. Leads already texted in the last 24 hours are skipped.')">
            @csrf
            {{-- Never name a form field "action": it shadows form.action and the
                 ajax layer posts to "[object HTMLInputElement]". --}}
            <input type="hidden" name="bulk_action" value="sms">
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-primary py-2 text-sm">💬 Send SMS</button>
        </form>

        <form action="{{ route('admin.abandoned.bulk') }}" method="POST" class="inline">
            @csrf
            {{-- Never name a form field "action": it shadows form.action and the
                 ajax layer posts to "[object HTMLInputElement]". --}}
            <input type="hidden" name="bulk_action" value="contacted">
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-outline py-2 text-sm">✓ Mark contacted</button>
        </form>

        <form action="{{ route('admin.abandoned.bulk') }}" method="POST" class="inline"
              onsubmit="return confirm('Remove the selected lead(s)? This cannot be undone.')">
            @csrf
            {{-- Never name a form field "action": it shadows form.action and the
                 ajax layer posts to "[object HTMLInputElement]". --}}
            <input type="hidden" name="bulk_action" value="delete">
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <button class="btn-outline py-2 text-sm !text-red-700 !border-red-200 hover:!bg-red-50">🗑 Delete</button>
        </form>

        <button type="button" class="text-sm text-ink-700/60 hover:underline ml-auto" @click="sel = []">Clear</button>
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-3 py-3 w-8"><input type="checkbox" :checked="allChecked" @change="toggleAll($event)"></th>
                    <th class="px-4 py-3">Contact</th>
                    <th class="px-4 py-3">Left behind</th>
                    <th class="px-4 py-3">Value</th>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Reach her</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($carts as $cart)
                    @php $wa = \App\Support\AbandonedCartOutreach::whatsappLink($cart); @endphp
                    <tr class="cursor-pointer {{ $cart->recovered ? 'opacity-60' : '' }} hover:bg-ink-50"
                        onclick="window.location='{{ route('admin.abandoned.show', $cart) }}'">
                        <td class="px-3 py-3" onclick="event.stopPropagation()">
                            <input type="checkbox" value="{{ $cart->id }}" x-model.number="sel">
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $cart->name ?: 'Unnamed shopper' }}</div>
                            <div class="text-xs text-ink-700/60">{{ $cart->phone }}</div>
                            @if($cart->area || $cart->address)
                                <div class="text-[11px] text-ink-700/45 truncate max-w-[16rem]">
                                    {{ $cart->area ?: \Illuminate\Support\Str::limit($cart->address, 40) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-700/70 text-xs max-w-xs">{{ $cart->itemSummary() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ money($cart->subtotal) }}</td>
                        <td class="px-4 py-3 text-ink-700/60 text-xs whitespace-nowrap">{{ $cart->updated_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if($cart->recovered)
                                <span class="badge bg-green-100 text-green-700">Recovered</span>
                            @elseif($cart->contacted)
                                <span class="badge bg-blue-100 text-blue-700">Contacted</span>
                            @else
                                <span class="badge bg-amber-100 text-amber-700">Waiting</span>
                            @endif
                            {{-- Only a lead converted by hand knows its order;
                                 one that recovered on its own is matched by
                                 phone alone and has nothing to point at. --}}
                            @if($cart->recoveredOrder)
                                <div class="text-[11px] mt-0.5" onclick="event.stopPropagation()">
                                    <a href="{{ route('admin.orders.show', $cart->recoveredOrder) }}"
                                       class="text-gold-700 hover:underline">Order {{ $cart->recoveredOrder->order_number }}</a>
                                </div>
                            @endif
                            @if($cart->contacts_count)
                                <div class="text-[11px] text-ink-700/45 mt-0.5">{{ $cart->contacts_count }} follow-up(s)</div>
                            @elseif($cart->sms_reminded_at)
                                <div class="text-[11px] text-ink-700/45 mt-0.5">Texted {{ $cart->sms_reminded_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-end gap-1.5">
                                @if($tel = tel_link($cart->phone))
                                    <a href="{{ $tel }}"
                                       class="shrink-0 grid h-7 w-7 place-items-center rounded-full bg-blue-100 text-blue-700 hover:bg-blue-200 transition"
                                       title="Call {{ $cart->phone }}">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2Z"/>
                                        </svg>
                                    </a>
                                @endif
                                @if($wa)
                                    <a href="{{ $wa }}" target="_blank" rel="noopener"
                                       class="shrink-0 grid h-7 w-7 place-items-center rounded-full bg-green-100 text-green-700 hover:bg-green-200 transition"
                                       title="WhatsApp her cart link">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/>
                                        </svg>
                                    </a>
                                @endif
                                <a href="{{ route('admin.abandoned.show', $cart) }}" class="text-xs text-gold-700 hover:underline ml-1">Open</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">
                        {{ $q ? 'No leads match that search.' : 'No abandoned carts captured yet.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6">{{ $carts->links() }}</div>
@endsection
