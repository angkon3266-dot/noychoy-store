<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product, ImageOptimizer $optimizer)
    {
        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['image', 'max:5120'], // 5 MB each
        ]);

        // Verified buyer = this phone has an order containing this product.
        $verified = false;
        if (! empty($data['phone'])) {
            $digits = preg_replace('/\D/', '', $data['phone']);
            $verified = Order::where('customer_phone', 'like', '%'.substr($digits, -9).'%')
                ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
                ->exists();
        }

        $photos = [];
        foreach ($request->file('photos', []) as $file) {
            $photos[] = $optimizer->storeWebp($file, 'reviews', 1200, 80);
        }

        $product->reviews()->create([
            'customer_id' => auth('customer')->id(),
            'author_name' => $data['author_name'],
            'phone' => $data['phone'] ?? null,
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'photos' => $photos ?: null,
            'is_verified_buyer' => $verified,
            'status' => 'pending', // moderated before going public
        ]);

        return back()->with('success', 'Thank you! Your review has been submitted and will appear once approved.');
    }
}
