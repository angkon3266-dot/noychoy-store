<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCart;
use Illuminate\Http\Request;

class AbandonedCartController extends Controller
{
    public function index(Request $request)
    {
        $carts = AbandonedCart::query()
            ->when($request->query('filter') === 'open', fn ($q) => $q->where('recovered', false))
            ->when($request->query('filter') === 'recovered', fn ($q) => $q->where('recovered', true))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.abandoned.index', [
            'carts' => $carts,
            'openCount' => AbandonedCart::where('recovered', false)->where('contacted', false)->count(),
        ]);
    }

    public function markContacted(AbandonedCart $cart)
    {
        $cart->update(['contacted' => true]);

        return back()->with('success', 'Marked as contacted.');
    }

    public function destroy(AbandonedCart $cart)
    {
        $cart->delete();

        return back()->with('success', 'Lead removed.');
    }
}
