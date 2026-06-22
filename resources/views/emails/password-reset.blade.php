<!DOCTYPE html>
<html>
<body style="margin:0;background:#f5f3ee;font-family:Arial,Helvetica,sans-serif;color:#161618;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#fff;border-radius:12px;padding:32px;border:1px solid #eee;">
            <h1 style="font-size:20px;margin:0 0 8px;color:#9a6c2e;">{{ \App\Models\Setting::get('store_name', config('store.name')) }}</h1>
            <p style="font-size:15px;line-height:1.6;margin:16px 0;">Hi {{ $customer->name }},</p>
            <p style="font-size:15px;line-height:1.6;margin:16px 0;">We received a request to reset your password. Click the button below to choose a new one. This link is valid for 60 minutes.</p>
            <p style="text-align:center;margin:28px 0;">
                <a href="{{ $url }}" style="background:#9a6c2e;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:15px;display:inline-block;">Reset password</a>
            </p>
            <p style="font-size:13px;line-height:1.6;color:#666;margin:16px 0;">If you didn't request this, you can safely ignore this email — your password won't change.</p>
            <p style="font-size:12px;color:#999;word-break:break-all;">Or paste this link into your browser:<br>{{ $url }}</p>
        </div>
        <p style="text-align:center;font-size:12px;color:#999;margin-top:16px;">© {{ date('Y') }} {{ \App\Models\Setting::get('store_name', config('store.name')) }}</p>
    </div>
</body>
</html>
