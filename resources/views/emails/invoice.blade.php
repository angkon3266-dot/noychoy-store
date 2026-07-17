<!DOCTYPE html>
<html>
@php $store = store_name(); @endphp
<body style="margin:0;background:#f5f3ee;font-family:Arial,Helvetica,sans-serif;color:#161618;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#fff;border-radius:12px;padding:32px;border:1px solid #eee;">
            <h1 style="font-size:20px;margin:0;color:#9a6c2e;">{{ $store }}</h1>
            <p style="font-size:15px;line-height:1.6;margin:16px 0 4px;">Hi {{ $order->customer_name }},</p>
            <p style="font-size:15px;line-height:1.6;margin:0 0 16px;">Thank you for your order! Here's your invoice. We'll call you shortly to confirm.</p>

            <table style="width:100%;border-collapse:collapse;margin:8px 0 16px;font-size:14px;">
                <tr>
                    <td style="padding:6px 0;color:#666;">Order number</td>
                    <td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $order->order_number }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#666;">Date</td>
                    <td style="padding:6px 0;text-align:right;">{{ $order->created_at->format('d M Y') }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#666;">Payment</td>
                    <td style="padding:6px 0;text-align:right;">Cash on Delivery</td>
                </tr>
            </table>

            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="border-bottom:2px solid #161618;text-align:left;">
                        <th style="padding:8px 0;">Item</th>
                        <th style="padding:8px 0;text-align:center;">Qty</th>
                        <th style="padding:8px 0;text-align:right;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                        @php $var = collect($item->attributes ?? [])->filter()->implode(', '); @endphp
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 0;">{{ $item->name }}@if($var)<br><span style="color:#888;font-size:12px;">{{ $var }}</span>@endif</td>
                            <td style="padding:8px 0;text-align:center;">{{ $item->quantity }}</td>
                            <td style="padding:8px 0;text-align:right;">{{ money($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:14px;">
                <tr><td style="padding:4px 0;color:#666;">Subtotal</td><td style="padding:4px 0;text-align:right;">{{ money($order->subtotal) }}</td></tr>
                @if((float) $order->discount > 0)
                    <tr><td style="padding:4px 0;color:#0a7d40;">Discount</td><td style="padding:4px 0;text-align:right;color:#0a7d40;">−{{ money($order->discount) }}</td></tr>
                @endif
                <tr><td style="padding:4px 0;color:#666;">Shipping</td><td style="padding:4px 0;text-align:right;">{{ money($order->shipping_cost) }}</td></tr>
                <tr style="border-top:2px solid #161618;"><td style="padding:8px 0;font-weight:bold;">Total (COD)</td><td style="padding:8px 0;text-align:right;font-weight:bold;">{{ money($order->total) }}</td></tr>
            </table>

            <div style="margin-top:20px;font-size:13px;line-height:1.6;color:#444;">
                <strong>Delivery to:</strong><br>
                {{ $order->customer_name }} · {{ $order->customer_phone }}<br>
                {{ $order->shipping_address }}{{ $order->area ? ', '.$order->area : '' }}{{ $order->district ? ', '.$order->district : '' }}
            </div>

            <p style="text-align:center;margin:28px 0 8px;">
                @if(!empty($viewLink))
                    <a href="{{ $viewLink }}" style="background:#9a6c2e;color:#fff;text-decoration:none;padding:10px 24px;border-radius:8px;font-size:14px;display:inline-block;">View your order</a>
                    &nbsp;
                    <a href="{{ route('track') }}" style="background:#fff;color:#9a6c2e;border:1px solid #9a6c2e;text-decoration:none;padding:9px 24px;border-radius:8px;font-size:14px;display:inline-block;">Track delivery</a>
                @else
                    <a href="{{ route('track') }}" style="background:#9a6c2e;color:#fff;text-decoration:none;padding:10px 24px;border-radius:8px;font-size:14px;display:inline-block;">Track your order</a>
                @endif
            </p>
        </div>
        <p style="text-align:center;font-size:12px;color:#999;margin-top:16px;">© {{ date('Y') }} {{ $store }} · No advance payment needed</p>
    </div>
</body>
</html>
