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

        $order = $placeOrder->handle($data);

        return redirect()->route('order.confirmation', $order->order_number);
    }

    public function confirmation(string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)->with('items')->firstOrFail();

        return view('shop.confirmation', compact('order'));
    }

    public function track(Request $request, \App\Services\SteadfastService $steadfast)
    {
        $order = null;
        $tracking = null;
        if ($request->filled('order_number') && $request->filled('phone')) {
            $order = Order::where('order_number', $request->string('order_number'))
                ->where('customer_phone', 'like', '%'.preg_replace('/\D/', '', $request->string('phone')).'%')
                ->with(['items', 'shipment', 'history'])
                ->first();

            if ($order) {
                $tracking = \App\Http\Controllers\Customer\AccountController::trackingFor($order, $steadfast);
            }
        }

        return view('shop.track', compact('order', 'tracking'));
    }
}
