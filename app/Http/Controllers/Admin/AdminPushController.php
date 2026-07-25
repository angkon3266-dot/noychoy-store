<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

/**
 * Staff device registration for operational alerts (new orders, low stock).
 * These subscriptions carry audience = 'admin', which keeps them out of every
 * customer marketing send.
 */
class AdminPushController extends Controller
{
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'audience' => 'admin',
                'user_id' => $request->user()?->id,
                'customer_id' => null,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'ua' => substr((string) $request->userAgent(), 0, 255),
                'label' => $this->deviceLabel((string) $request->userAgent()),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        if ($endpoint !== '') {
            PushSubscription::admins()
                ->where('endpoint_hash', PushSubscription::hashFor($endpoint))->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Master switch — pauses new-order alerts for every staff device at once. */
    public function toggle(Request $request)
    {
        \App\Models\Setting::put('admin_order_alerts', $request->boolean('on'));

        return back()->with('success', $request->boolean('on')
            ? 'New-order alerts are on.'
            : 'New-order alerts paused.');
    }

    /** Send a test alert to every staff device so the admin can prove it works. */
    public function test(\App\Services\WebPushService $push)
    {
        if (! $push->ready()) {
            return back()->with('error', 'Web push isn’t ready — enable it and generate VAPID keys in Notifications first.');
        }

        $ids = PushSubscription::admins()->pluck('id');
        if ($ids->isEmpty()) {
            return back()->with('error', 'No staff device is subscribed yet. Turn on “New order alerts” on the device you want alerts on.');
        }

        \App\Jobs\SendWebPush::dispatch($ids->all(), [
            'title' => '🔔 Test — new order alert',
            'body' => 'Order alerts are working on this device.',
            'url' => route('admin.orders.index'),
            'icon' => theme_asset(theme('logo')) ?: asset('favicon.ico'),
            'tag' => 'admin-test',
        ]);

        return back()->with('success', 'Test alert sent to '.$ids->count().' staff device(s).');
    }

    /** A human name for the device row, e.g. "iPhone · Safari". */
    protected function deviceLabel(string $ua): string
    {
        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            default => 'Device',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return $device.' · '.$browser;
    }
}
