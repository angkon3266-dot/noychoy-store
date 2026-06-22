<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductLove;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoveController extends Controller
{
    /** Toggle the current visitor's love on a product. Returns JSON {loved, count}. */
    public function toggle(Request $request, Product $product)
    {
        // Stable per-browser token in a long-lived cookie (1 year).
        $token = $request->cookie('visitor_token') ?: Str::random(40);

        $existing = ProductLove::where('product_id', $product->id)
            ->where('visitor_token', $token)->first();

        if ($existing) {
            $existing->delete();
            $loved = false;
        } else {
            // Guard against the unique constraint under double-clicks.
            ProductLove::firstOrCreate(['product_id' => $product->id, 'visitor_token' => $token]);
            $loved = true;
        }

        // Recompute the cached counter from the source of truth.
        $count = ProductLove::where('product_id', $product->id)->count();
        $product->forceFill(['loves_count' => $count])->saveQuietly();

        return response()->json(['loved' => $loved, 'count' => $count])
            ->cookie('visitor_token', $token, 60 * 24 * 365);
    }
}
