<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    protected function customer()
    {
        return auth('customer')->user();
    }

    public function index(\App\Services\LoyaltyService $loyalty)
    {
        $customer = $this->customer();
        $orders = $customer->orders()->with('shipment')->latest()->take(5)->get();

        return view('customer.account', [
            'customer' => $customer,
            'orders' => $orders,
            'defaultAddress' => $customer->defaultAddress,
            'lovedCount' => $customer->loves()->count(),
            'reviewCount' => $customer->reviews()->count(),
            'addressCount' => $customer->addresses()->count(),
            // Member pricing
            'memberSaved' => (float) $customer->orders()->sum('member_discount'),
            'memberPercent' => member_pricing()->basePercent(),
            // Loyalty
            'loyaltyEnabled' => $loyalty->enabled(),
            'points' => (int) $customer->points,
            'pointsValue' => $loyalty->pointsValue((int) $customer->points),
            'liveOffers' => $customer->liveOffers()->get(),
            'milestones' => $loyalty->weeklyMilestones($customer),
            'tier' => $loyalty->tierFor($customer),
            'referralCode' => $customer->ensureReferralCode(),
            'referralCount' => $customer->referrals()->count(),
            'referralPoints' => $loyalty->referralPoints(),
        ]);
    }

    /** Award one-per-week social-share points (called from the storefront share buttons). */
    public function share(Request $request, \App\Services\LoyaltyService $loyalty)
    {
        $customer = $this->customer();

        if (! $loyalty->enabled()) {
            return response()->json(['ok' => false, 'message' => 'Rewards are currently off.']);
        }

        // Once per ISO week.
        $already = $customer->pointTransactions()
            ->where('type', 'earn_share')
            ->where('created_at', '>=', now()->startOfWeek())
            ->exists();

        if ($already) {
            return response()->json(['ok' => false, 'message' => 'You already earned share points this week.']);
        }

        $points = $loyalty->sharePoints();
        $loyalty->award($customer, $points, 'earn_share', 'Shared on '.$request->string('platform', 'social'));

        return response()->json([
            'ok' => true,
            'message' => '+'.$points.' points for sharing — thank you!',
            'points' => (int) $customer->fresh()->points,
        ]);
    }

    public function orders()
    {
        $orders = $this->customer()->orders()->with('shipment')->latest()->paginate(15);

        return view('customer.orders', compact('orders'));
    }

    public function notifications(\App\Services\NotificationService $notifications)
    {
        $customer = $this->customer();
        $items = $notifications->visibleFor($customer)->orderByDesc('sent_at')->paginate(20);
        $notifications->markRead($customer);

        return view('customer.notifications', compact('items'));
    }

    public function markNotificationsRead(\App\Services\NotificationService $notifications)
    {
        $notifications->markRead($this->customer());

        return response()->json(['ok' => true]);
    }

    /** Save (or refresh) a browser web-push subscription for this member. */
    public function subscribePush(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        \App\Models\PushSubscription::updateOrCreate(
            ['endpoint_hash' => \App\Models\PushSubscription::hashFor($data['endpoint'])],
            [
                'customer_id' => $this->customer()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'ua' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** Remove a web-push subscription (member turned notifications off). */
    public function unsubscribePush(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        if ($endpoint !== '') {
            \App\Models\PushSubscription::where('endpoint_hash', \App\Models\PushSubscription::hashFor($endpoint))->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Count a notification click, then forward to its destination (campaign analytics). */
    public function trackNotification(\App\Models\CustomerNotification $notification)
    {
        $notification->increment('clicks');

        $to = $notification->url ?: route('account.notifications');

        return str_starts_with($to, 'http') ? redirect()->away($to) : redirect()->to($to);
    }

    public function order(string $orderNumber, \App\Services\SteadfastService $steadfast)
    {
        $order = $this->customer()->orders()
            ->where('order_number', $orderNumber)
            ->with(['items', 'shipment', 'history'])
            ->firstOrFail();

        $tracking = $this->trackingFor($order, $steadfast);

        return view('customer.order', compact('order', 'tracking'));
    }

    /** Build the live courier-tracking view-model for an order (or null). */
    public static function trackingFor($order, \App\Services\SteadfastService $steadfast): ?array
    {
        $cid = $order->shipment?->consignment_id;
        if (! $cid) {
            return null;
        }
        $raw = $steadfast->deliveryStatus($cid);
        [$label, $step, $tone] = \App\Services\SteadfastService::describeStatus($raw);
        $toneClass = [
            'green' => 'bg-green-100 text-green-700',
            'amber' => 'bg-amber-100 text-amber-700',
            'red' => 'bg-red-100 text-red-700',
            'gold' => 'bg-gold-100 text-gold-800',
        ][$tone] ?? 'bg-gold-100 text-gold-800';

        return [
            'label' => $label,
            'step' => $step,
            'tone_class' => $toneClass,
            'tracking_code' => $order->shipment->tracking_code,
        ];
    }

    // ── Profile & security ───────────────────────────────────────────────────
    public function profile()
    {
        return view('customer.profile', ['customer' => $this->customer()]);
    }

    public function updateProfile(Request $request)
    {
        $customer = $this->customer();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['required', 'string', 'max:20', Rule::unique('customers', 'phone')->ignore($customer->id)],
            'gender' => ['nullable', 'in:male,female,other'],
        ]);
        $data['gender'] = $data['gender'] ?: null;

        $customer->update($data);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $customer = $this->customer();
        $request->validate([
            'current_password' => [$customer->password ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($customer->password && ! Hash::check($request->input('current_password'), $customer->password)) {
            return back()->withErrors(['current_password' => 'Your current password is incorrect.']);
        }

        $customer->update(['password' => $request->input('password')]);

        return back()->with('success', 'Password changed.');
    }

    // ── Addresses ────────────────────────────────────────────────────────────
    public function addresses()
    {
        return view('customer.addresses', ['addresses' => $this->customer()->addresses()->latest()->get()]);
    }

    public function storeAddress(Request $request)
    {
        $data = $this->validateAddress($request);
        $customer = $this->customer();

        // First address becomes the default automatically.
        $data['is_default'] = $request->boolean('is_default') || $customer->addresses()->count() === 0;
        if ($data['is_default']) {
            $customer->addresses()->update(['is_default' => false]);
        }

        $customer->addresses()->create($data);

        return back()->with('success', 'Address added.');
    }

    public function updateAddress(Request $request, Address $address)
    {
        $this->authorizeAddress($address);
        $data = $this->validateAddress($request);

        $data['is_default'] = $request->boolean('is_default');
        if ($data['is_default']) {
            $this->customer()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        }

        $address->update($data);

        return back()->with('success', 'Address updated.');
    }

    public function deleteAddress(Address $address)
    {
        $this->authorizeAddress($address);
        $wasDefault = $address->is_default;
        $address->delete();

        // Promote another address to default if we removed the default.
        if ($wasDefault) {
            $this->customer()->addresses()->latest()->first()?->update(['is_default' => true]);
        }

        return back()->with('success', 'Address removed.');
    }

    public function setDefaultAddress(Address $address)
    {
        $this->authorizeAddress($address);
        $this->customer()->addresses()->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return back()->with('success', 'Default address updated.');
    }

    protected function validateAddress(Request $request): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:400'],
            'area' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'is_inside_dhaka' => ['nullable', 'boolean'],
        ]);
    }

    protected function authorizeAddress(Address $address): void
    {
        abort_unless((int) $address->customer_id === (int) $this->customer()->id, 403);
    }

    // ── Reviews & loved ──────────────────────────────────────────────────────
    public function reviews()
    {
        $reviews = $this->customer()->reviews()->with('product')->latest()->paginate(15);

        return view('customer.reviews', compact('reviews'));
    }

    public function loved()
    {
        $products = $this->customer()->lovedProducts()->paginate(12);

        return view('customer.loved', compact('products'));
    }

    // ── Reorder ──────────────────────────────────────────────────────────────
    public function reorder(string $orderNumber, CartService $cart)
    {
        $order = $this->customer()->orders()->where('order_number', $orderNumber)->with('items')->firstOrFail();

        $added = 0;
        $skipped = 0;
        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product || ! $product->isAvailable()) {
                $skipped++;
                continue;
            }
            $variant = $item->variant_id ? ProductVariant::find($item->variant_id) : null;
            $cart->add($product, $variant, max(1, (int) $item->quantity));
            $added++;
        }

        if ($added === 0) {
            return redirect()->route('account.order', $orderNumber)
                ->with('error', 'None of these items are available to reorder right now.');
        }

        $msg = "Added {$added} item(s) to your cart.".($skipped ? " {$skipped} item(s) are no longer available." : '');

        return redirect()->route('cart')->with('success', $msg);
    }
}
