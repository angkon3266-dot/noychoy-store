<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use App\Support\SpinWheel;

/**
 * The checkout spin wheel's one write: take the spin.
 *
 * Always answers 200 with `ok`, so the page can say why a spin was refused
 * (fetchJson throws on anything else and the reason would be lost).
 */
class SpinWheelController extends Controller
{
    public function __invoke(CartService $cart, SpinWheel $wheel)
    {
        return response()->json($wheel->spin($cart));
    }
}
