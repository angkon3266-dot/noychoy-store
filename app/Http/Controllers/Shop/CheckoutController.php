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

        // InitiateCheckout — server-side (CAPI) + shared event id for the browser
        // Pixel. content_ids match the catalog retailer_id.
        $icEventId = MetaTrackingService::newEventId('InitiateCheckout');
        $icContentIds = $this->cart->items()->map(fn ($i) => $i['variant_id']
            ? "prod-{$i['product_id']}-var-{$i['variant_id']}"
            : "prod-{$i['product_id']}")->values()->all();
        $icValue = (float) ($this->cart->subtotal() - $this->cart->discount());
        $user = $customer ? ['em' => $customer->email, 'ph' => $customer->phone, 'fn' => $customer->name] : [];
        $tracking->initiateCheckout($icContentIds, $icValue, (int) $this->cart->count(), $icEventId, $user);

        return view('shop.checkout', [
            'cart' => $this->cart,
            'customer' => $customer,
            'address' => $address,
            'icEventId' => $icEventId,
            'icContentIds' => $icContentIds,
        ]);
    }

    public function store(Request $request, PlaceOrder $placeOrder)
    {
        if ($this->cart->isEmpty()) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'is_inside_dhaka' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'phone.regex' => 'Please enter a valid Bangladeshi mobile number (e.g. 01XXXXXXXXX).',
        ]);

        $data['is_inside_dhaka'] = $request->boolean('is_inside_dhaka');

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

        return redirect()->route('order.confirmation', $order->order_number);
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

        return view('shop.confirmation', compact('order'));
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

        return view('shop.track', compact('order', 'tracking'));
    }
}
