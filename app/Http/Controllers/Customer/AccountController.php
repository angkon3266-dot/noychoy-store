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

        $memberPercent = member_pricing()->basePercent();
        $memberSaved = (float) $customer->orders()->sum('member_discount');
        $memberUsage = member_pricing()->enabled() ? member_pricing()->usageStatus($customer) : null;
        $points = (int) $customer->points;
        $tier = $loyalty->enabled() ? $loyalty->tierFor($customer) : null;

        return \Inertia\Inertia::render('Account/Dashboard', [
            'pageTitle' => 'My Account',
            'customer' => [
                'name' => $customer->name,
                'totalOrders' => $customer->total_orders,
                'totalSpentText' => money($customer->total_spent),
            ],
            'stats' => [
                'loved' => $customer->loves()->count(),
                'reviews' => $customer->reviews()->count(),
            ],
            'member' => ($memberPercent > 0 || $memberSaved > 0) ? [
                'percent' => rtrim(rtrim(number_format($memberPercent, 1), '0'), '.'),
                'savedText' => money($memberSaved),
            ] : null,
            'liveOffers' => $customer->liveOffers()->get()->map(fn ($o) => [
                'title' => $o->title,
                'reward' => $o->rewardText(),
                'scope' => $o->applies_to !== 'all' ? $o->scopeLabel() : null,
                'message' => $o->message,
                'until' => $o->expires_at?->format('d M'),
                'code' => $o->code,
            ])->values(),
            'loyalty' => $loyalty->enabled() ? [
                'points' => $points,
                'pointsValueText' => money($loyalty->pointsValue($points)),
                'value100Text' => money($loyalty->pointsValue(100)),
                'per1000' => (int) round($loyalty->earnPerTaka() * 1000),
                'reviewPoints' => $loyalty->reviewPoints(),
                'reviewPhotoBonus' => $loyalty->reviewPhotoBonus(),
                'tier' => [
                    'emoji' => ['silver' => '🥈', 'gold' => '🥇', 'platinum' => '💎'][$tier['current']['key']] ?? '⭐',
                    'label' => $tier['current']['label'],
                    'perk' => $tier['current']['perk'],
                    'lifetime' => number_format($tier['lifetime']),
                    'next' => $tier['next'] ? [
                        'label' => $tier['next']['label'],
                        'perk' => $tier['next']['perk'],
                        'toNext' => number_format($tier['to_next']),
                        'progress' => $tier['progress'],
                    ] : null,
                ],
                'memberUsage' => ($memberUsage && $memberUsage['percent'] > 0) ? [
                    'percent' => rtrim(rtrim(number_format($memberUsage['percent'], 2), '0'), '.'),
                    'capped' => (bool) $memberUsage['capped'],
                    'remaining' => $memberUsage['remaining'] ?? null,
                    'max' => $memberUsage['max'] ?? null,
                    'resets' => $memberUsage['resets_at']?->format('d M'),
                ] : null,
                'milestones' => collect($loyalty->weeklyMilestones($customer))->map(fn ($m) => [
                    'done' => (bool) $m['done'],
                    'icon' => $m['icon'],
                    'label' => $m['label'],
                    'points' => $m['points'],
                ])->values(),
            ] : null,
            'defaultAddress' => $customer->defaultAddress ? [
                'name' => $customer->defaultAddress->name,
                'phone' => $customer->defaultAddress->phone,
                'line' => collect([$customer->defaultAddress->address, $customer->defaultAddress->area, $customer->defaultAddress->district])->filter()->implode(', '),
            ] : null,
            'orders' => $orders->map(fn ($o) => [
                'number' => $o->order_number,
                'url' => route('account.order', $o->order_number),
                'reorderUrl' => route('account.reorder', $o->order_number),
                'date' => store_time($o->created_at)->format('d M Y'),
                'totalText' => money($o->total),
                'status' => $o->status,
            ])->values(),
        ])->withViewData(['pageTitle' => 'My Account']);
    }

    public function orders()
    {
        $orders = $this->customer()->orders()->with('shipment')->latest()->paginate(15);

        return \Inertia\Inertia::render('Account/Orders', [
            'pageTitle' => 'My Orders',
            'orders' => [
                'data' => collect($orders->items())->map(fn ($o) => $this->orderRow($o))->values(),
                'links' => $orders->linkCollection(),
            ],
        ])->withViewData(['pageTitle' => 'My Orders']);
    }

    /** One row of the orders table (list page + dashboard share the shape). */
    protected function orderRow($o): array
    {
        return [
            'number' => $o->order_number,
            'url' => route('account.order', $o->order_number),
            'date' => store_time($o->created_at)->format('d M Y'),
            'totalText' => money($o->total),
            'status' => $o->status,
        ];
    }

    public function notifications(\App\Services\NotificationService $notifications)
    {
        $customer = $this->customer();
        $items = $notifications->visibleFor($customer)->orderByDesc('sent_at')->paginate(20);
        $notifications->markRead($customer);

        return \Inertia\Inertia::render('Account/Notifications', [
            'pageTitle' => 'Notifications',
            'items' => [
                'data' => collect($items->items())->map(fn ($n) => [
                    'icon' => $n->iconOrDefault(),
                    'title' => $n->title,
                    'body' => $n->body,
                    'time' => $n->sent_at?->diffForHumans(),
                    'url' => $n->url ? route('account.notifications.go', $n) : null,
                    'cta' => $n->cta_label,
                ])->values(),
                'links' => $items->linkCollection(),
            ],
        ])->withViewData(['pageTitle' => 'Notifications']);
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
        // Only count a click from someone the notification was actually sent to.
        // Without this any signed-in customer could inflate any campaign's click
        // count by walking the ids, which quietly corrupts campaign analytics.
        $visible = app(\App\Services\NotificationService::class)
            ->visibleFor($this->customer())
            ->whereKey($notification->getKey())
            ->exists();

        abort_unless($visible, 404);

        $notification->increment('clicks');

        $to = $notification->url ?: route('account.notifications');

        // Admin-authored URLs, but this endpoint sits on our domain, so an
        // off-site hop would let the link borrow our credibility. Keep external
        // redirects to hosts we actually control.
        if (str_starts_with($to, 'http')) {
            return $this->isOwnHost($to)
                ? redirect()->away($to)
                : redirect()->route('account.notifications')
                    ->with('error', 'That link points off-site and was not followed.');
        }

        return redirect()->to($to);
    }

    /** Is this absolute URL on the storefront's own host? */
    protected function isOwnHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host !== '' && $own !== '' && $host === $own;
    }

    public function order(string $orderNumber, \App\Services\SteadfastService $steadfast)
    {
        $order = $this->customer()->orders()
            ->where('order_number', $orderNumber)
            ->with(['items', 'shipment'])   // no history: this page renders neither notes nor the status trail
            ->firstOrFail();

        $tracking = $this->trackingFor($order, $steadfast);

        return \Inertia\Inertia::render('Account/OrderDetail', [
            'pageTitle' => $order->order_number,
            'order' => [
                'number' => $order->order_number,
                'status' => $order->status,
                'date' => store_time($order->created_at)->format('d M Y, g:i a'),
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->name,
                    'qty' => $i->quantity,
                    'subtotalText' => money($i->subtotal),
                ])->values(),
                'subtotalText' => money($order->subtotal),
                'discountText' => $order->discount > 0 ? money($order->discount) : null,
                'shippingText' => money($order->shipping_cost),
                'totalText' => money($order->total),
                'address' => [
                    'name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                    'line' => $order->shipping_address
                        .($order->area ? ', '.$order->area : '')
                        .($order->district ? ', '.$order->district : ''),
                ],
            ],
            'tracking' => $tracking,
            'reorderUrl' => route('account.reorder', $order->order_number),
        ])->withViewData(['pageTitle' => $order->order_number]);
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
        $customer = $this->customer();

        return \Inertia\Inertia::render('Account/Profile', [
            'pageTitle' => 'Profile & security',
            'profile' => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'gender' => $customer->gender,
                'hasPassword' => (bool) $customer->password,
            ],
            'genders' => \App\Models\Customer::GENDERS,
        ])->withViewData(['pageTitle' => 'Profile & security']);
    }

    public function updateProfile(Request $request)
    {
        $customer = $this->customer();
        // Canonicalise first so "unique" compares like with like (see register()).
        $request->merge(['phone' => bd_phone($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['required', 'string', new \App\Rules\BdPhone, Rule::unique('customers', 'phone')->ignore($customer->id)],
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
        $customer = $this->customer();

        return \Inertia\Inertia::render('Account/Addresses', [
            'pageTitle' => 'My addresses',
            'addresses' => $customer->addresses()->latest()->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'name' => $a->name,
                'phone' => $a->phone,
                'address' => $a->address,
                'area' => $a->area,
                'city' => $a->city,
                'district' => $a->district,
                'is_inside_dhaka' => (bool) $a->is_inside_dhaka,
                'is_default' => (bool) $a->is_default,
                'line' => collect([$a->address, $a->area, $a->district])->filter()->implode(', '),
                'updateUrl' => route('account.addresses.update', $a),
                'deleteUrl' => route('account.addresses.delete', $a),
                'defaultUrl' => route('account.addresses.default', $a),
            ])->values(),
            'storeUrl' => route('account.addresses.store'),
            'defaults' => ['name' => $customer->name, 'phone' => $customer->phone],
        ])->withViewData(['pageTitle' => 'My addresses']);
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

        return \Inertia\Inertia::render('Account/Reviews', [
            'pageTitle' => 'My reviews',
            'reviews' => [
                'data' => collect($reviews->items())->map(fn ($r) => [
                    'product' => $r->product ? [
                        'name' => $r->product->name,
                        'url' => route('product.show', $r->product),
                    ] : null,
                    'status' => $r->status ?? 'approved',
                    'rating' => (int) $r->rating,
                    'title' => $r->title,
                    'body' => $r->body,
                    'date' => store_time($r->created_at)->format('d M Y'),
                ])->values(),
                'links' => $reviews->linkCollection(),
            ],
        ])->withViewData(['pageTitle' => 'My reviews']);
    }

    public function loved()
    {
        $products = $this->customer()->lovedProducts()->with('images', 'approvedReviews', 'category')->paginate(12);

        return \Inertia\Inertia::render('Account/Loved', [
            'pageTitle' => 'Loved items',
            'products' => [
                'data' => \App\Support\Storefront\ProductCardData::collection(collect($products->items())),
                'links' => $products->linkCollection(),
            ],
        ])->withViewData(['pageTitle' => 'Loved items']);
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
