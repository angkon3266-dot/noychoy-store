<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCart;
use App\Services\CartService;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(protected CartService $cart) {}

    /**
     * Capture a checkout lead (phone) as soon as it is entered, so the team can
     * follow up if the order is never completed. Upserted per session.
     */
    public function capture(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new \App\Rules\BdPhone],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        if ($this->cart->isEmpty()) {
            return response()->json(['ok' => false], 200);
        }

        // product_id / variant_id are what make the snapshot actionable: the
        // recovery SMS links to a route that rebuilds this cart, and a list of
        // names and prices cannot be turned back into a cart.
        $items = $this->cart->items()->map(fn ($i) => [
            'product_id' => $i['product_id'] ?? null,
            'variant_id' => $i['variant_id'] ?? null,
            'name' => $i['name'], 'qty' => $i['qty'], 'price' => $i['price'],
        ])->values()->all();

        AbandonedCart::updateOrCreate(
            ['session_id' => $request->session()->getId(), 'recovered' => false],
            [
                // Canonical "01XXXXXXXXX" — the form PlaceOrder stores and
                // matches on. A raw "+8801…" lead never gets marked recovered.
                'phone' => bd_phone($data['phone']),
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'items' => $items,
                'subtotal' => $this->cart->subtotal(),
                'item_count' => $this->cart->count(),
                'last_step' => 'checkout',
            ],
        );

        // Now we know who is checking out, so any coupon waiting for this
        // number can apply itself. This is the only moment a cash-on-delivery
        // shop learns the shopper's identity — she never logs in.
        $this->cart->rememberCheckoutPhone($data['phone']);

        return response()->json([
            'ok' => true,
            // The totals *after* the assigned coupon, so the checkout can show
            // the discount the moment she finishes typing rather than springing
            // it on her at the end.
            'summary' => $this->summary(),
        ]);
    }

    /** The subset of checkout totals a recalculation can change. */
    protected function summary(): array
    {
        $discount = $this->cart->discount();

        return [
            'discountLines' => collect($this->cart->discountLines())
                ->map(fn ($l) => ['label' => $l['label'], 'amount_text' => money($l['amount'])])->values(),
            'discountText' => $discount > 0 ? money($discount) : null,
            'discountPct' => ($discount > 0 && $this->cart->subtotal() > 0)
                ? round($discount / $this->cart->subtotal() * 100) : 0,
            'coupon_notice' => $this->cart->couponNotice(),
            'sub' => (float) ($this->cart->subtotal() - $discount),
            'freeShipping' => $this->cart->hasFreeShipping(),
        ];
    }
}
