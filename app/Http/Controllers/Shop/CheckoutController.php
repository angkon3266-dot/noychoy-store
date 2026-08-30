<?php

namespace App\Http\Controllers\Shop;

use App\Actions\PlaceOrder;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CartService;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(protected CartService $cart) {}

    public function show(MetaTrackingService $tracking)
    {
        if ($this->cart->isEmpty()) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }

        $customer = auth('customer')->user();
        $address = $customer?->defaultAddress;

        // Funnel step for the dashboard's conversion report.
        \App\Models\Visit::record('checkout_start');

        // InitiateCheckout — server-side (CAPI) + shared event id for the browser
        // Pixel. content_ids match the catalog retailer_id.
        $icEventId = MetaTrackingService::newEventId('InitiateCheckout');
        $icContentIds = $this->cart->items()->map(fn ($i) => $i['variant_id']
            ? "prod-{$i['product_id']}-var-{$i['variant_id']}"
            : "prod-{$i['product_id']}")->values()->all();
        $icValue = (float) ($this->cart->subtotal() - $this->cart->discount());
        $user = $tracking->customerMatchData($customer);
        // After the response, never before it — see CatalogController::show().
        // The cart is read NOW: the closure must not re-read it, because a
        // terminate-time read would be a different snapshot.
        $icCount = (int) $this->cart->count();
        $icContext = MetaTrackingService::captureClientContext();
        app()->terminating(fn () => $tracking->initiateCheckout($icContentIds, $icValue, $icCount, $icEventId, $user, $icContext));

        $loyalty = app(\App\Services\LoyaltyService::class);
        $custPoints = (int) ($customer->points ?? 0);
        $appliedPoints = $this->cart->redeemablePoints();
        $regPct = (float) \App\Models\Setting::get('register_offer_percent', config('loyalty.register_discount_percent', 3));
        $discount = $this->cart->discount();

        // What signing up is actually worth on THIS cart, in taka. "Get an extra
        // 2% off" is an abstraction; "Save ৳130 on this order" is the same fact
        // in the unit the customer is deciding in. Mirrors CartService's
        // per-line loop so per-product and per-category member overrides apply
        // — the figure cannot be derived client-side from one percentage.
        $regSaving = 0.0;
        if (! $customer) {
            $pricing = app(\App\Services\MemberPricingService::class);
            if ($pricing->enabled()) {
                foreach ($this->cart->items() as $line) {
                    $pct = $pricing->percentForLine((int) $line['product_id'], $line['category_id'] ?? null);
                    if ($pct > 0) {
                        $regSaving += $line['price'] * $line['qty'] * $pct / 100;
                    }
                }
            }
        }

        return \Inertia\Inertia::render('Checkout', [
            'pageTitle' => 'Checkout',
            'items' => $this->cart->items()->map(fn ($i) => [
                'name' => $i['name'],
                'qty' => $i['qty'],
                'lineText' => money($i['price'] * $i['qty']),
            ])->values(),
            'summary' => [
                'subtotalText' => money($this->cart->subtotal()),
                'discountLines' => collect($this->cart->discountLines())
                    ->map(fn ($l) => ['label' => $l['label'], 'amount_text' => money($l['amount'])])->values(),
                'discountText' => $discount > 0 ? money($discount) : null,
                'discountPct' => ($discount > 0 && $this->cart->subtotal() > 0)
                    ? round($discount / $this->cart->subtotal() * 100) : 0,
                'hints' => $this->cart->offerHints(),
                'coupon_notice' => $this->cart->couponNotice(),
                // Client-side shipping math inputs (same rules the server applies).
                'sub' => (float) ($this->cart->subtotal() - $discount),
                'rawSubtotal' => (float) $this->cart->subtotal(),
                'shipInside' => (int) \App\Models\Setting::get('shipping_inside', config('store.shipping.inside_dhaka')),
                'shipOutside' => (int) \App\Models\Setting::get('shipping_outside', config('store.shipping.outside_dhaka')),
                'freeThreshold' => free_shipping_threshold(),
            ],
            'prefill' => [
                'name' => old('name', $customer->name ?? ''),
                'phone' => old('phone', $customer->phone ?? ''),
                'address' => old('address', $address->address ?? ''),
                'area' => old('area', $address->area ?? ''),
                'inside' => (bool) old('is_inside_dhaka', $address->is_inside_dhaka ?? false),
            ],
            'isMember' => (bool) $customer,
            'loyalty' => ($customer && $loyalty->enabled() && ($custPoints > 0 || $appliedPoints > 0)) ? [
                'points' => $custPoints,
                'pointsValueText' => money($loyalty->pointsValue($custPoints)),
                'applied' => $appliedPoints,
                'appliedDiscountText' => money($this->cart->pointsDiscount()),
                'minRedeem' => $loyalty->minRedeem(),
                'step' => $loyalty->redeemStep(),
                'defaultRedeem' => (int) (floor($custPoints / max(1, $loyalty->redeemStep())) * $loyalty->redeemStep()),
                'pointsUrl' => route('cart.points'),
            ] : null,
            'registerPct' => (! $customer && $regPct > 0) ? [
                'pct' => rtrim(rtrim(number_format($regPct, 2), '0'), '.'),
                'saving' => round($regSaving, 2),
                'savingText' => money(round($regSaving, 2)),
            ] : null,
            'trustBadges' => collect(theme('trust_badges') ?: config('theme.defaults.trust_badges', []))
                ->filter(fn ($b) => filled($b['title'] ?? null))->take(4)->values(),
            'ic' => [
                'eventId' => $icEventId,
                'contentIds' => $icContentIds,
                'value' => $icValue,
                'numItems' => $icCount,
            ],
            'coupon' => ($c = $this->cart->coupon()) ? ['code' => $c->code] : null,
            // Free delivery already won: the zone picker is noise at that
            // point, so the page shows a badge instead (the zone still
            // travels with the order, inferred from the address).
            'freeShipping' => $this->cart->hasFreeShipping(),
            'gift' => theme('gift_enabled', true) ? [
                'title' => theme('gift_title'),
                'note' => theme('gift_note'),
                'messageLabel' => theme('gift_message_label'),
                'messagePlaceholder' => theme('gift_message_placeholder'),
                'messageHelp' => theme('gift_message_help'),
                'max' => max(20, min(240, (int) theme('gift_message_max', 100))),
            ] : null,
            'urls' => [
                'store' => route('checkout.store'),
                'lead' => route('checkout.lead'),
                'couponApply' => route('cart.coupon'),
                'couponRemove' => route('cart.coupon.remove'),
            ],
        ])->withViewData(['pageTitle' => 'Checkout']);
    }

    public function store(Request $request, PlaceOrder $placeOrder)
    {
        if ($this->cart->isEmpty()) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', new \App\Rules\BdPhone],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'is_inside_dhaka' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_gift' => ['nullable', 'boolean'],
            'card_message' => ['nullable', 'string', 'max:'.max(20, min(240, (int) theme('gift_message_max', 100)))],
        ], [
            'phone.regex' => 'Please enter a valid Bangladeshi mobile number (e.g. 01XXXXXXXXX).',
        ]);

        $data['is_inside_dhaka'] = $request->boolean('is_inside_dhaka');
        $data['is_gift'] = $request->boolean('is_gift');

        try {
            $order = $placeOrder->handle($data);
        } catch (\App\Exceptions\CheckoutException $e) {
            // Stock ran out / a price changed / a product went away — the cart
            // has been corrected; send the customer back to review it.
            return redirect()->route('cart')->with('error', $e->getMessage());
        }

        // Authorize this browser to view the confirmation page (kept to the
        // last few orders so the session doesn't grow unbounded).
        $placed = array_slice(array_unique(array_merge(
            (array) session('placed_orders', []),
            [$order->order_number],
        )), -5);
        session()->put('placed_orders', $placed);

        return redirect()->route('order.confirmation', $order->order_number)
            ->with('prompt_push', 'order');   // ask about notifications now they have bought
    }

    public function confirmation(Request $request, string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)->with('items')->firstOrFail();

        // Order numbers are sequential and guessable — only the buyer may view
        // this page: the session that just placed it, the logged-in owner, or a
        // signed link. Everyone else goes to the phone-verified tracking page.
        $allowed = in_array($orderNumber, (array) session('placed_orders', []), true)
            || (auth('customer')->check() && (int) $order->customer_id === (int) auth('customer')->id())
            || $request->hasValidSignature();

        if (! $allowed) {
            return redirect()->route('track')
                ->with('error', 'Please verify with your order number and phone to view this order.');
        }

        return \Inertia\Inertia::render('Confirmation', [
            'pageTitle' => 'Order Confirmed',
            'order' => [
                'number' => $order->order_number,
                'name' => $order->customer_name,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->name,
                    'qty' => $i->quantity,
                    'subtotalText' => money($i->subtotal),
                ])->values(),
                'subtotalText' => money($order->subtotal),
                'discountText' => $order->discount > 0 ? money($order->discount) : null,
                'shippingText' => money($order->shipping_cost),
                'totalText' => money($order->total),
                'isGift' => (bool) $order->is_gift,
                'cardMessage' => $order->card_message,
                'insideDhaka' => (bool) $order->is_inside_dhaka,
            ],
            // Purchase Pixel event — eventID is the order number, so Meta dedups
            // against the server CAPI Purchase and against page refreshes alike.
            'purchase' => [
                'value' => (float) $order->total,
                'contentIds' => $order->items->map(fn ($i) => $i->variant_id
                    ? "prod-{$i->product_id}-var-{$i->variant_id}"
                    : "prod-{$i->product_id}")->values(),
                'numItems' => (int) $order->items->sum('quantity'),
                'eventId' => $order->order_number,
            ],
            'trackUrl' => route('track').'?order_number='.$order->order_number,
            // The window the courier will actually hit: Fridays skipped, and
            // inside Dhaka quoted faster than outside.
            // Dropped once the window has passed: this page can be revisited
            // days later, and "the courier delivers Sun 23" on the 30th reads
            // as broken rather than reassuring. The tracking link is the
            // honest answer at that point.
            'estimate' => ($est = \App\Support\DeliveryEstimate::for((bool) $order->is_inside_dhaka, $order->created_at))
                && ($est->to ?? $est->from)->endOfDay()->isFuture() ? [
                    'label' => $est->label(),
                    'zoneText' => $order->is_inside_dhaka ? 'inside Dhaka' : 'outside Dhaka',
                ] : null,
            // A human rings before the parcel moves — say so, and on what number.
            'storePhone' => \App\Models\Setting::get('store_phone', config('store.phone')),
            // Right after buying is the best moment this shop ever gets to ask
            // for an account — and it was the one moment it did not. PlaceOrder
            // already made a customer row for them; this offers to turn it into
            // a real login. Null for anyone already signed in or registered.
            'claimAccount' => $this->claimOffer($order),
        ])->withViewData(['pageTitle' => 'Order Confirmed']);
    }

    /**
     * The "set a password" offer, or null when it does not apply.
     */
    protected function claimOffer(Order $order): ?array
    {
        if (auth('customer')->check()) {
            return null;
        }

        $customer = $order->customer;

        // Only a guest row — one PlaceOrder created by phone, with no password.
        if (! $customer || filled($customer->password)) {
            return null;
        }

        $pct = (float) \App\Models\Setting::get('register_offer_percent', config('loyalty.register_discount_percent', 3));

        return [
            'url' => route('order.claim', $order->order_number),
            'name' => $customer->name,
            'phone' => $customer->phone,
            'pct' => $pct > 0 ? rtrim(rtrim(number_format($pct, 2), '0'), '.') : null,
        ];
    }

    /**
     * Turn the guest customer row PlaceOrder created into a real member.
     *
     * Registration proper rejects a phone that already exists — and after a
     * guest checkout it always does, because PlaceOrder matches or creates the
     * customer by phone. Rather than relaxing that unique rule, which would let
     * anyone claim any guest account from a phone number alone, this is gated
     * by proof that the order is yours: the session that placed it, or a signed
     * link. Same test the confirmation page itself uses.
     */
    public function claimAccount(Request $request, string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)->with('customer')->firstOrFail();

        $allowed = in_array($orderNumber, (array) session('placed_orders', []), true)
            || $request->hasValidSignature();

        abort_unless($allowed, 403);

        $customer = $order->customer;

        if (! $customer || filled($customer->password)) {
            return redirect()->route('customer.login')
                ->with('error', 'That account already has a password — please sign in.');
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $customer->update(['password' => $data['password']]);

        $loyalty = app(\App\Services\LoyaltyService::class);
        if ($loyalty->enabled() && $loyalty->signupPoints() > 0) {
            $loyalty->award($customer, $loyalty->signupPoints(), 'signup', 'Welcome bonus');
        }

        auth('customer')->login($customer, remember: true);
        $request->session()->regenerate();

        return redirect()->route('account')
            ->with('success', 'Your account is ready — welcome to '.store_name().'.');
    }

    public function track(Request $request, \App\Services\SteadfastService $steadfast)
    {
        $order = null;
        $tracking = null;
        if ($request->filled('order_number') && $request->filled('phone')) {
            // Orders store phones canonically (bd_phone in PlaceOrder) — match
            // exactly so a partial input can't unlock someone else's order.
            $order = Order::where('order_number', $request->string('order_number'))
                ->where('customer_phone', bd_phone($request->string('phone')))
                ->with(['items', 'shipment', 'history'])
                ->first();

            if ($order) {
                $tracking = \App\Http\Controllers\Customer\AccountController::trackingFor($order, $steadfast);
            }
        }

        return \Inertia\Inertia::render('Track', [
            'pageTitle' => 'Track Order',
            'query' => [
                'order_number' => (string) $request->query('order_number', ''),
                'phone' => (string) $request->query('phone', ''),
            ],
            'notFound' => $request->filled('order_number') && ! $order,
            'order' => $order ? [
                'number' => $order->order_number,
                'status' => $order->status,
                // Deliberately no `note`: history notes are written by staff
                // for staff ("customer unreachable, retry Sunday") and this
                // page is reachable with an order number and a phone number.
                'history' => $order->history->map(fn ($h) => [
                    'status' => $h->status,
                    'date' => store_time($h->created_at)->format('d M Y, g:i a'),
                ])->values(),
            ] : null,
            'tracking' => $tracking ? [
                'label' => $tracking['label'],
                'tone_class' => $tracking['tone_class'],
                'step' => $tracking['step'],
                'tracking_code' => $tracking['tracking_code'],
            ] : null,
        ])->withViewData(['pageTitle' => 'Track Order']);
    }
}
