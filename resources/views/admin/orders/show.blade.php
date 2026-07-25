@extends('layouts.admin')
@section('title', $order->order_number)
@section('heading', 'Order '.$order->order_number)

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('admin.orders.index') }}" class="text-sm text-gold-700 hover:underline">← Back to orders</a>
    @php
        $waTemplate = \App\Models\Setting::get('whatsapp_order_template')
            ?: 'Hello {name}, this is {store} regarding your order {order_number}.';
        $waHref = wa_link($order->customer_phone, strtr($waTemplate, [
            '{name}' => trim((string) $order->customer_name),
            '{store}' => store_name(),
            '{order_number}' => (string) $order->order_number,
            '{total}' => money($order->total),
        ]));
    @endphp
    @if($waHref)
        <a href="{{ $waHref }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 transition">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/>
            </svg>
            WhatsApp {{ \Illuminate\Support\Str::before($order->customer_name, ' ') ?: 'customer' }}
        </a>
    @endif
</div>

{{-- Where this customer came from — first touch found them, last touch closed. --}}
@php
    $srcClass = \App\Support\TrafficSource::class;
    $last = $order->source_channel;
    $first = $order->first_touch_channel;
@endphp
<div class="card p-4 mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
    <span class="text-ink-700/60">Came from</span>
    @if($last)
        <span class="badge {{ $srcClass::badgeClass($last) }}">{{ $srcClass::label($last) }}</span>
        @if($order->source_campaign)
            <span class="text-ink-700/70">Campaign: <strong>{{ $order->source_campaign }}</strong></span>
        @endif
        @if($first && $first !== $last)
            <span class="text-ink-700/60">First found you via
                <span class="badge {{ $srcClass::badgeClass($first) }}">{{ $srcClass::label($first) }}</span>
            </span>
        @endif
        @if($order->source_referrer)
            <span class="text-xs text-ink-700/45">via {{ $order->source_referrer }}</span>
        @endif
        @if($order->landing_path)
            <span class="text-xs text-ink-700/45 truncate">landed on {{ \Illuminate\Support\Str::start(ltrim($order->landing_path, '/'), '/') }}</span>
        @endif
    @else
        <span class="text-ink-700/50">
            Not recorded — this order predates traffic tracking, or was created in the admin.
        </span>
    @endif
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-4">
    <!-- main -->
    <div class="lg:col-span-2 space-y-6">
        <div class="card overflow-hidden"
             x-data="orderAmend({
                items: {{ Illuminate\Support\Js::from($order->items->map(fn($i) => ['id'=>$i->id,'name'=>$i->name,'price'=>(float)$i->price,'quantity'=>(int)$i->quantity])) }},
                shipping: {{ (float) $order->shipping_cost }},
                discount: {{ (float) $order->discount }},
                adjustments: {{ Illuminate\Support\Js::from(collect($order->adjustments ?? [])->map(fn($a) => ['label'=>$a['label'],'amount'=>(float)$a['amount']])->values()) }},
             })">
            <div class="px-5 py-4 border-b border-ink-100 flex items-center justify-between">
                <h2 class="font-semibold">Items &amp; amounts</h2>
                <div class="flex items-center gap-2">
                    <span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span>
                    <button type="button" @click="editing = !editing" class="btn-outline text-xs py-1" x-text="editing ? 'Cancel' : '✎ Amend amounts'"></button>
                </div>
            </div>

            {{-- Read-only view --}}
            <div x-show="!editing">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-ink-100">
                        @foreach($order->items as $item)
                            <tr>
                                <td class="px-5 py-3">{{ $item->name }}
                                    @if($item->attributes)<span class="text-xs text-ink-700/50">({{ collect($item->attributes)->implode(', ') }})</span>@endif
                                    <div class="text-xs text-ink-700/40">
                                        @if($item->product)Product ID #{{ $item->product->serial }}@endif
                                        @if($item->sku) · SKU {{ $item->sku }}@endif
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-ink-700/70">{{ money($item->price) }} × {{ $item->quantity }}</td>
                                <td class="px-5 py-3 text-right font-medium">{{ money($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-ink-100 text-sm">
                        <tr><td colspan="2" class="px-5 py-1.5 text-right text-ink-700/70">Subtotal</td><td class="px-5 py-1.5 text-right">{{ money($order->subtotal) }}</td></tr>
                        @if($order->discount > 0)<tr><td colspan="2" class="px-5 py-1.5 text-right text-green-700">Discount {{ $order->coupon_code ? '('.$order->coupon_code.')' : '' }}</td><td class="px-5 py-1.5 text-right text-green-700">−{{ money($order->discount) }}</td></tr>@endif
                        @foreach($order->adjustments ?? [] as $adj)
                            <tr><td colspan="2" class="px-5 py-1.5 text-right {{ $adj['amount'] < 0 ? 'text-green-700' : 'text-ink-700/70' }}">{{ $adj['label'] }}</td><td class="px-5 py-1.5 text-right {{ $adj['amount'] < 0 ? 'text-green-700' : '' }}">{{ $adj['amount'] < 0 ? '−'.money(abs($adj['amount'])) : money($adj['amount']) }}</td></tr>
                        @endforeach
                        <tr><td colspan="2" class="px-5 py-1.5 text-right text-ink-700/70">Shipping</td><td class="px-5 py-1.5 text-right">{{ money($order->shipping_cost) }}</td></tr>
                        <tr class="font-semibold"><td colspan="2" class="px-5 py-2 text-right">Total (COD)</td><td class="px-5 py-2 text-right">{{ money($order->total) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            {{-- Edit form --}}
            <form x-show="editing" x-cloak action="{{ route('admin.orders.amend', $order) }}" method="POST" class="p-5 space-y-3 text-sm">
                @csrf
                <template x-for="(it, i) in items" :key="it.id">
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-6">
                            <span x-text="it.name" class="font-medium"></span>
                            <input type="hidden" :name="`items[${i}][id]`" :value="it.id">
                        </div>
                        <div class="col-span-3">
                            <label class="label text-[10px]">Unit price ৳</label>
                            <input type="number" step="0.01" min="0" :name="`items[${i}][price]`" x-model.number="it.price" class="input py-1 text-sm">
                        </div>
                        <div class="col-span-3">
                            <label class="label text-[10px]">Qty</label>
                            <input type="number" min="1" :name="`items[${i}][quantity]`" x-model.number="it.quantity" class="input py-1 text-sm">
                        </div>
                    </div>
                </template>

                <div class="border-t border-ink-100 pt-3 space-y-2">
                    <p class="font-medium text-xs text-ink-700/60">Custom adjustments (positive = extra charge, negative = discount)</p>
                    <template x-for="(adj, i) in adjustments" :key="i">
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <input :name="`adjustments[${i}][label]`" x-model="adj.label" placeholder="Label (e.g. Gift wrap)" class="input py-1 text-sm col-span-7">
                            <input type="number" step="0.01" :name="`adjustments[${i}][amount]`" x-model.number="adj.amount" placeholder="Amount ৳" class="input py-1 text-sm col-span-4">
                            <button type="button" @click="adjustments.splice(i,1)" class="col-span-1 text-red-600">✕</button>
                        </div>
                    </template>
                    <button type="button" @click="adjustments.push({label:'',amount:0})" class="text-xs text-gold-700 hover:underline">+ Add adjustment</button>
                </div>

                <div class="grid grid-cols-2 gap-3 border-t border-ink-100 pt-3">
                    <div><label class="label text-xs">Overall discount ৳</label><input type="number" step="0.01" min="0" name="discount" x-model.number="discount" class="input py-1 text-sm"></div>
                    <div><label class="label text-xs">Shipping ৳</label><input type="number" step="0.01" min="0" name="shipping_cost" x-model.number="shipping" class="input py-1 text-sm"></div>
                </div>
                <div><label class="label text-xs">Reason / note (optional)</label><input name="reason" class="input py-1 text-sm" placeholder="Why this amendment?"></div>

                <div class="rounded-lg bg-ink-50 p-3 space-y-1 text-xs">
                    <div class="flex justify-between"><span>Items subtotal</span><span x-text="money(subtotal())"></span></div>
                    <div class="flex justify-between text-green-700" x-show="discount>0"><span>Discount</span><span x-text="'−'+money(discount)"></span></div>
                    <div class="flex justify-between"><span>Adjustments</span><span x-text="money(adjTotal())"></span></div>
                    <div class="flex justify-between"><span>Shipping</span><span x-text="money(shipping)"></span></div>
                    <div class="flex justify-between font-semibold text-sm border-t border-ink-200 pt-1"><span>New total</span><span x-text="money(total())"></span></div>
                </div>
                <button class="btn-primary w-full">Save amended amounts</button>
            </form>
        </div>

        <!-- Timeline -->
        <div class="card p-5">
            <h2 class="font-semibold mb-4">History</h2>
            <ol class="space-y-3 border-l-2 border-gold-200 pl-4">
                @foreach($order->history as $h)
                    <li>
                        <div class="font-medium text-sm">{{ $h->label }} @if($h->created_by)<span class="text-xs text-ink-700/50 font-normal">by {{ $h->created_by }}</span>@endif</div>
                        @if($h->note)<div class="text-sm text-ink-700/60">{{ $h->note }}</div>@endif
                        <div class="text-xs text-ink-700/40">{{ $h->created_at->format('d M Y, g:i a') }}</div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    <!-- sidebar -->
    <div class="space-y-6">
        <!-- Customer -->
        <div class="card p-5 text-sm">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold">Customer</h2>
                @if($insight['is_repeat'])
                    <span class="badge bg-violet-100 text-violet-700">🔁 Repeat ({{ $insight['total'] + 1 }} orders)</span>
                @else
                    <span class="badge bg-ink-100 text-ink-700">New customer</span>
                @endif
            </div>
            <p class="font-medium">{{ $order->customer_name }}</p>
            <p class="text-ink-700/70">{{ $order->customer_phone }}</p>
            @if($order->customer_email)<p class="text-ink-700/70">{{ $order->customer_email }}</p>@endif
            <p class="mt-2 text-ink-700/70">{{ $order->shipping_address }}{{ $order->area ? ', '.$order->area : '' }}{{ $order->district ? ', '.$order->district : '' }}</p>
            <p class="mt-1 text-xs text-ink-700/50">{{ $order->is_inside_dhaka ? 'Inside Dhaka' : 'Outside Dhaka' }}</p>
            @if($order->notes)<p class="mt-2 rounded bg-gold-100/60 p-2 text-xs">Note: {{ $order->notes }}</p>@endif
        </div>

        <!-- Delivery reliability (from this store's own order history) -->
        @php
            $riskStyles = [
                'high'   => ['border-red-300 bg-red-50',     'bg-red-100 text-red-700',     '⚠️ High delivery risk'],
                'medium' => ['border-amber-300 bg-amber-50', 'bg-amber-100 text-amber-700', '⚠️ Caution'],
                'low'    => ['border-green-300 bg-green-50', 'bg-green-100 text-green-700', '✓ Reliable'],
                'none'   => ['border-ink-100',               'bg-ink-100 text-ink-700',     'No history yet'],
            ];
            [$cardCls, $badgeCls, $riskLabel] = $riskStyles[$insight['risk']];
        @endphp
        <div class="card p-5 text-sm border {{ $cardCls }}">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">Delivery reliability</h2>
                <span class="badge {{ $badgeCls }}">{{ $riskLabel }}</span>
            </div>
            @if($insight['total'] === 0)
                <p class="text-ink-700/60">First order from this number — no past delivery history.</p>
            @else
                <p class="text-xs text-ink-700/60 mb-2">Across {{ $insight['total'] }} previous order(s) on this phone:</p>
                <div class="flex flex-wrap gap-1.5">
                    <span class="badge bg-green-100 text-green-700">{{ $insight['delivered'] }} delivered</span>
                    <span class="badge bg-amber-100 text-amber-700">{{ $insight['pending'] }} pending</span>
                    <span class="badge bg-red-100 text-red-700">{{ $insight['cancelled'] }} cancelled</span>
                    <span class="badge bg-red-100 text-red-700">{{ $insight['returned'] }} returned</span>
                </div>
                @if($insight['success_rate'] !== null)
                    <div class="mt-3">
                        <div class="flex justify-between text-xs mb-1"><span>Success rate</span><span class="font-semibold">{{ $insight['success_rate'] }}%</span></div>
                        <div class="h-2 rounded-full bg-ink-100 overflow-hidden">
                            <div class="h-full {{ $insight['success_rate'] >= 70 ? 'bg-green-500' : ($insight['success_rate'] >= 50 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ $insight['success_rate'] }}%"></div>
                        </div>
                    </div>
                @endif
                @if($insight['risk'] === 'high')
                    <p class="mt-3 text-xs text-red-700">High cancel/return rate on COD. Consider confirming by call or taking advance payment.</p>
                @endif
            @endif
        </div>

        <!-- Profitability (internal) -->
        <div class="card p-5 text-sm">
            <h2 class="font-semibold mb-3">Profitability <span class="text-xs font-normal text-ink-700/50">(internal)</span></h2>
            @php $cogs = $order->cost_of_goods; @endphp
            <dl class="space-y-1.5">
                <div class="flex justify-between"><dt class="text-ink-700/70">Revenue (after discount)</dt><dd>{{ money($order->subtotal - $order->discount) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-700/70">Cost of goods + transport</dt><dd>−{{ money($cogs) }}</dd></div>
                <div class="flex justify-between font-semibold border-t border-ink-100 pt-1.5">
                    <dt>Gross profit</dt>
                    <dd class="{{ $order->gross_profit >= 0 ? 'text-green-700' : 'text-red-700' }}">
                        {{ money($order->gross_profit) }}@if($order->margin_percent !== null) <span class="text-xs font-normal text-ink-700/50">({{ $order->margin_percent }}%)</span>@endif
                    </dd>
                </div>
            </dl>
            @unless($order->has_full_cost_data)
                <p class="mt-2 text-xs text-amber-700">Some items have no cost recorded — profit is an over-estimate. Add cost/transport on the product to improve accuracy.</p>
            @endunless
            <p class="mt-2 text-[11px] text-ink-700/40">Shipping ({{ money($order->shipping_cost) }}) excluded — courier charge is separate.</p>
        </div>

        <!-- Update status -->
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Update status</h2>
            <form action="{{ route('admin.orders.status', $order) }}" method="POST" class="space-y-3">
                @csrf
                <select name="status" class="input">
                    @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($order->status==$key)>{{ $label }}</option>@endforeach
                </select>
                <input name="note" placeholder="Note (optional)" class="input">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1"> Send SMS to customer</label>
                <button class="btn-dark w-full">Update</button>
            </form>
        </div>

        <!-- Steadfast -->
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">Courier (Steadfast)</h2>
                @if($balance !== null)<span class="text-xs text-ink-700/50">Balance: <strong>৳{{ number_format((float) $balance, 0) }}</strong></span>@endif
            </div>
            @if($order->shipment?->consignment_id)
                @php
                    $cs = strtolower((string) $order->shipment->status);
                    $deliveryBadge = str_contains($cs, 'partial') ? 'bg-amber-100 text-amber-700'
                        : (str_contains($cs, 'deliver') ? 'bg-green-100 text-green-700'
                        : (str_contains($cs, 'cancel') || str_contains($cs, 'return') ? 'bg-red-100 text-red-700'
                        : 'bg-gold-100 text-gold-800'));
                    $resp = (array) ($order->shipment->response ?? []);
                @endphp
                <div class="text-sm space-y-1.5">
                    <p>Delivery status: <span class="badge {{ $deliveryBadge }} capitalize">{{ str_replace('_', ' ', $order->shipment->status) }}</span></p>
                    <p class="text-ink-700/70">Consignment: <strong>{{ $order->shipment->consignment_id }}</strong></p>
                    <p class="text-ink-700/70">Tracking: <strong>{{ $order->shipment->tracking_code }}</strong></p>
                    @if(!empty($resp['note']) || !empty($resp['delivery_note']))<p class="text-xs text-ink-700/60">Courier note: {{ $resp['note'] ?? $resp['delivery_note'] }}</p>@endif
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <form action="{{ route('admin.orders.steadfast.refresh', $order) }}" method="POST">@csrf<button class="btn-outline w-full">Refresh status</button></form>
                    <a href="{{ route('admin.orders.labels', ['ids' => $order->id]) }}" target="_blank" class="btn-outline w-full text-center">🖨 Print label</a>
                    <a href="{{ route('admin.orders.cards', ['ids' => $order->id]) }}" target="_blank" class="btn-outline w-full text-center">💌 Print thank-you card</a>
                </div>
            @else
                <p class="text-sm text-ink-700/60 mb-3">Create a courier consignment for COD ৳{{ number_format($order->total,0) }}.</p>
                <form action="{{ route('admin.orders.steadfast', $order) }}" method="POST">@csrf<button class="btn-primary w-full">Send to Steadfast</button></form>
            @endif

            {{-- Courier-confirmed track record for this customer --}}
            @if(($courier['total'] ?? 0) > 0)
                <div class="mt-4 pt-3 border-t border-ink-100">
                    <p class="text-xs text-ink-700/60 mb-1.5">Courier outcomes across {{ $courier['total'] }} shipment(s):</p>
                    <div class="flex flex-wrap gap-1.5 text-[11px]">
                        <span class="badge bg-green-100 text-green-700">{{ $courier['delivered'] }} delivered</span>
                        @if($courier['partial'])<span class="badge bg-amber-100 text-amber-700">{{ $courier['partial'] }} partial</span>@endif
                        @if($courier['cancelled'])<span class="badge bg-red-100 text-red-700">{{ $courier['cancelled'] }} cancelled</span>@endif
                        @if($courier['returned'])<span class="badge bg-red-100 text-red-700">{{ $courier['returned'] }} returned</span>@endif
                        @if($courier['pending'])<span class="badge bg-ink-100 text-ink-600">{{ $courier['pending'] }} in transit</span>@endif
                    </div>
                    @if($courier['success_rate'] !== null)<p class="mt-1.5 text-xs text-ink-700/60">Courier success rate: <strong>{{ $courier['success_rate'] }}%</strong></p>@endif
                </div>
            @endif
        </div>

        <!-- Thank-you card message for this customer -->
        <div class="card p-5">
            <h2 class="font-semibold mb-1">💌 Thank-you card</h2>
            <p class="text-xs text-ink-700/60 mb-3">
                Printed message for this customer. Leave it empty to use the
                {{ (int) ($order->customer?->total_orders ?? 0) > 1 ? 'repeat-customer' : 'new-customer' }} default.
                <a href="{{ route('admin.orders.card-templates') }}" class="text-gold-700 underline">Edit defaults</a>
            </p>
            <form action="{{ route('admin.orders.cards.messages') }}" method="POST" class="space-y-2">
                @csrf
                <textarea name="messages[{{ $order->id }}]" rows="4" class="input text-sm"
                          placeholder="Dear {{ $order->customer_name }}, thank you for…">{{ $order->card_message }}</textarea>
                @if(blank($order->card_message))
                    <p class="text-[11px] text-ink-700/50 whitespace-pre-line border-l-2 border-ink-100 pl-2">{{ \App\Http\Controllers\Admin\OrderController::cardMessageFor($order) }}</p>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    <button class="btn-outline w-full">Save message</button>
                    @if($order->status === 'processing')
                        <a href="{{ route('admin.orders.cards', ['ids' => $order->id]) }}" target="_blank"
                           class="btn-outline w-full text-center">🖨 Print card</a>
                    @else
                        <span class="btn-outline w-full text-center opacity-50 cursor-not-allowed"
                              title="Cards print once the order moves to Processing">🖨 Print card</span>
                    @endif
                </div>
                @unless($order->status === 'processing')
                    <p class="text-[11px] text-ink-700/50">Cards print once this order is moved to <strong>Processing</strong>.</p>
                @endunless
            </form>
        </div>

        <!-- Custom SMS -->
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Send SMS</h2>
            <form action="{{ route('admin.orders.sms', $order) }}" method="POST" class="space-y-2">
                @csrf
                <textarea name="message" rows="3" class="input" placeholder="Message to {{ $order->customer_phone }}">Dear {{ $order->customer_name }}, </textarea>
                <button class="btn-outline w-full">Send SMS</button>
            </form>
        </div>
    </div>
</div>
@endsection
