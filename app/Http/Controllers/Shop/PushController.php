<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

/**
 * Public web-push subscription endpoints. Works for guests (customer_id null)
 * and members alike; a guest subscription is linked to the account the moment
 * that browser logs in (see AuthController).
 */
class PushController extends Controller
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
                'customer_id' => auth('customer')->id(),      // null for guests
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'ua' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** Register a "notify me when back in stock" watcher for a product. */
    public function watchStock(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        $sub = PushSubscription::where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))->first();
        if (! $sub) {
            return response()->json(['ok' => false, 'message' => 'Not subscribed'], 422);
        }

        \App\Models\StockWatcher::firstOrCreate([
            'product_id' => $data['product_id'],
            'push_subscription_id' => $sub->id,
        ]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        if ($endpoint !== '') {
            // ->customers() matters: a browser has ONE push endpoint, so when
            // the owner used her own store this row was the same row her staff
            // device was registered as. Turning off shop notifications here
            // deleted it, and new-order alerts stopped with no sign of why.
            // Staff devices are turned off from the admin, not from here.
            PushSubscription::customers()
                ->where('endpoint_hash', PushSubscription::hashFor($endpoint))->delete();
        }

        return response()->json(['ok' => true]);
    }
}
