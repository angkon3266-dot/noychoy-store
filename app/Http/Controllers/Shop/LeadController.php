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
            'phone' => ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        if ($this->cart->isEmpty()) {
            return response()->json(['ok' => false], 200);
        }

        $items = $this->cart->items()->map(fn ($i) => [
            'name' => $i['name'], 'qty' => $i['qty'], 'price' => $i['price'],
        ])->values()->all();

        AbandonedCart::updateOrCreate(
            ['session_id' => $request->session()->getId(), 'recovered' => false],
            [
                'phone' => $data['phone'],
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'items' => $items,
                'subtotal' => $this->cart->subtotal(),
                'item_count' => $this->cart->count(),
                'last_step' => 'checkout',
            ],
        );

        return response()->json(['ok' => true]);
    }
}
