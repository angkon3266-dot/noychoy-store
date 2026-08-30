<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\ImageOptimizer;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ReviewController extends Controller
{
    /**
     * The page the post-delivery review request links to.
     *
     * A bare #reviews deep link would land on a collapsed form and could not
     * prefill the phone, so a review arriving from our own request would show
     * as unverified. This page lists just the pieces in the order and posts to
     * the same endpoint, untouched.
     */
    /**
     * The short door onto the review page, for SMS.
     *
     * A Laravel signed URL carries a 64-character signature, which with the
     * path costs about 119 characters — most of a 160-character message, and
     * the reason the review SMS ran to two paid segments. This is the same
     * guarantee in a fifth of the space: a 64-bit HMAC of the order number
     * under the app key, checked in constant time, then handed straight to
     * the signed URL the review page already expects.
     *
     * Deliberately a redirect and not a second entry point: `invite()` keeps
     * its own three-way allow list, and nothing about who may read or write a
     * review changes here.
     */
    public function shortInvite(string $orderNumber, string $token)
    {
        if (! hash_equals(static::shortToken($orderNumber), strtolower($token))) {
            return redirect()->route('track')
                ->with('error', 'That review link is not valid. Please look your order up below.');
        }

        return redirect()->to(URL::signedRoute('order.review', ['orderNumber' => $orderNumber]));
    }

    /** The opaque half of a short review link. */
    public static function shortToken(string $orderNumber): string
    {
        return substr(
            hash_hmac('sha256', 'review-link:'.$orderNumber, (string) config('app.key')),
            0,
            16,
        );
    }

    /** The full short link that goes into a message. */
    public static function shortLink(string $orderNumber): string
    {
        return route('order.review.short', [
            'orderNumber' => $orderNumber,
            'token' => static::shortToken($orderNumber),
        ]);
    }

    public function invite(Request $request, string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->with('items.product.images')
            ->firstOrFail();

        // Same three-way allow list as the confirmation page.
        $allowed = $request->hasValidSignature()
            || in_array($orderNumber, (array) session('placed_orders', []), true)
            || (auth('customer')->check() && (int) $order->customer_id === (int) auth('customer')->id());

        if (! $allowed) {
            return redirect()->route('track')
                ->with('error', 'Please verify with your order number and phone to leave a review.');
        }

        // Phones are canonical on both sides, so an exact match is safe.
        $done = Review::where('phone', $order->customer_phone)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $loyalty = app(LoyaltyService::class);

        return \Inertia\Inertia::render('ReviewInvite', [
            'pageTitle' => 'Rate your order',
            'order' => [
                'number' => $order->order_number,
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
            ],
            'items' => $order->items
                ->filter(fn ($i) => $i->product && $i->product->status === 'published')
                ->unique('product_id')
                ->map(fn ($i) => [
                    'productId' => (int) $i->product_id,
                    'name' => $i->product->name,
                    'image' => image_variant($i->product->thumbnail),
                    'url' => route('product.show', $i->product),
                    'reviewUrl' => route('review.store', $i->product),
                    'done' => in_array((int) $i->product_id, $done, true),
                ])->values(),
            'perk' => $loyalty->enabled() ? $loyalty->reviewPoints() : 0,
        ])->withViewData(['pageTitle' => 'Rate your order']);
    }

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
        // Orders store phones canonically — exact match (indexed, no false hits).
        $verified = false;
        if (! empty($data['phone'])) {
            $verified = Order::where('customer_phone', bd_phone($data['phone']))
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
