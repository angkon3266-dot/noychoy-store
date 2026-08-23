<!DOCTYPE html>
<html>
<body style="margin:0;background:#f5f3ee;font-family:Arial,Helvetica,sans-serif;color:#161618;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#fff;border-radius:12px;padding:32px;border:1px solid #eee;">
            <h1 style="font-size:20px;margin:0 0 8px;color:#9a6c2e;">{{ store_name() }}</h1>
            <p style="font-size:15px;line-height:1.6;margin:16px 0;">Hi {{ $order->customer_name }},</p>
            <p style="font-size:15px;line-height:1.6;margin:16px 0;">{{ $intro }}</p>
            <p style="text-align:center;margin:28px 0;">
                <a href="{{ $reviewLink }}" style="background:#9a6c2e;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:15px;display:inline-block;">Rate your order</a>
            </p>
            <p style="font-size:13px;line-height:1.6;color:#666;margin:16px 0;">Order {{ $order->order_number }}. Your rating helps the next shopper decide.</p>
            <p style="font-size:12px;color:#999;word-break:break-all;">Or paste this link into your browser:<br>{{ $reviewLink }}</p>
        </div>
        <p style="text-align:center;font-size:12px;color:#999;margin-top:16px;">© {{ date('Y') }} {{ store_name() }}</p>
    </div>
</body>
</html>
