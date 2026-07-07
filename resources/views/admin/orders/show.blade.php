@extends('layouts.admin')
@section('title', $order->order_number)
@section('heading', 'Order '.$order->order_number)

@section('content')
<a href="{{ route('admin.orders.index') }}" class="text-sm text-gold-700 hover:underline">← Back to orders</a>

<div class="grid lg:grid-cols-3 gap-6 mt-4">
    <!-- main -->
    <div class="lg:col-span-2 space-y-6">
        <div class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-ink-100 flex items-center justify-between">
                <h2 class="font-semibold">Items</h2>
                <span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span>
            </div>
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
                    <tr><td colspan="2" class="px-5 py-1.5 text-right text-ink-700/70">Shipping</td><td class="px-5 py-1.5 text-right">{{ money($order->shipping_cost) }}</td></tr>
                    <tr class="font-semibold"><td colspan="2" class="px-5 py-2 text-right">Total (COD)</td><td class="px-5 py-2 text-right">{{ money($order->total) }}</td></tr>
                </tfoot>
            </table>
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

        <!-- Delivery reliability / fraud signal -->
        @php
            $riskStyles = [
                'high'   => ['border-red-300 bg-red-50',     'bg-red-100 text-red-700',     '⚠️ High fraud risk'],
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
