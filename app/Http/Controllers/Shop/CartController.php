<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Visit;
use App\Services\CartService;
use App\Services\LoyaltyService;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(protected CartService $cart) {}

    public function index()
    {
        $cart = $this->cart;

        // Offers: applied + the single nearest "add X more to unlock".
        $allOffers = \App\Models\Offer::active()->get();
        $matched = $cart->matchingOffers();
        $matchedIds = $matched->pluck('id')->all();
        $sub = $cart->subtotal() - $cart->offerDiscount();
        $nearly = $allOffers->reject(fn ($o) => in_array($o->id, $matchedIds))
            ->filter(fn ($o) => $o->min_subtotal && $o->remainingToUnlock($sub) > 0 && (! $o->members_only || auth('customer')->check()))
            ->sortBy(fn ($o) => $o->remainingToUnlock($sub))
            ->first();

        $freeThreshold = (float) config('store.shipping.free_threshold');
        $customer = auth('customer')->user();
        $memberUsage = ($customer && member_pricing()->enabled()) ? member_pricing()->usageStatus($customer) : null;

        $suggestions = collect();
        if (! $cart->isEmpty()) {
            $inCart = $cart->items()->pluck('product_id')->all();
            $suggestions = \App\Models\Product::published()->whereNotIn('id', $inCart)
                ->with('images', 'approvedReviews', 'category')->inRandomOrder()->take(4)->get();
        }

        return \Inertia\Inertia::render('Cart', [
            'pageTitle' => 'Your Cart',
            'items' => $cart->items()->map(function ($item) use ($cart) {
                $offerPct = $cart->lineOfferPercent($item);

                return [
                    'key' => $item['key'],
                    'name' => $item['name'],
                    'url' => route('product.show', $item['slug']),
                    'image' => $item['image'],
                    'attributes' => ! empty($item['attributes'])
                        ? collect($item['attributes'])->map(fn ($v) => "$v")->implode(', ') : null,
                    'price_text' => money($item['price']),
                    'qty' => $item['qty'],
                    'line_total_text' => money($item['price'] * $item['qty']),
                    'offer' => $offerPct > 0 ? [
                        'label' => $cart->lineOfferTier($item)['label'] ?? 'Bundle offer',
                        'saving_text' => money($cart->lineOfferSaving($item)),
                    ] : null,
                ];
            })->values(),
            'summary' => [
                'subtotal_text' => money($cart->subtotal()),
                'discount_lines' => collect($cart->discountLines())
                    ->map(fn ($l) => ['label' => $l['label'], 'amount_text' => money($l['amount'])])->values(),
                'free_shipping_offer' => $cart->hasFreeShippingOffer(),
                'total_text' => money($cart->subtotal() - $cart->discount()),
                'hints' => $cart->offerHints(),
            ],
            'coupon' => ($c = $cart->coupon()) ? ['code' => $c->code] : null,
            'freeBar' => (theme('free_shipping_bar') && $freeThreshold > 0) ? [
                'remaining_text' => money(max(0, $freeThreshold - $cart->subtotal())),
                'unlocked' => $cart->subtotal() >= $freeThreshold,
                'pct' => min(100, (int) ($cart->subtotal() / $freeThreshold * 100)),
            ] : null,
            'offersPanel' => ($matched->isNotEmpty() || $nearly) ? [
                'matched' => $matched->map(fn ($o) => ['title' => $o->title])->values(),
                'nearly' => $nearly ? [
                    'title' => $nearly->title,
                    'remaining_text' => money($nearly->remainingToUnlock($sub)),
                    'pct' => min(100, (int) ($sub / max(1, (float) $nearly->min_subtotal) * 100)),
                ] : null,
                'register_hint' => ! auth('customer')->check()
                    && $allOffers->where('members_only', true)->where('is_active', true)->isNotEmpty(),
            ] : null,
            'memberUsage' => ($memberUsage && $memberUsage['percent'] > 0 && $memberUsage['capped']) ? [
                'remaining' => $memberUsage['remaining'],
                'max' => $memberUsage['max'],
                'resets' => $memberUsage['resets_at']?->format('d M'),
            ] : null,
            'suggestions' => \App\Support\Storefront\ProductCardData::collection($suggestions),
            'cartUrls' => [
                'update' => route('cart.update'),
                'remove' => route('cart.remove'),
                'couponApply' => route('cart.coupon'),
                'couponRemove' => route('cart.coupon.remove'),
            ],
        ])->withViewData(['pageTitle' => 'Your Cart']);
    }

    public function add(Request $request, Product $product, MetaTrackingService $tracking)
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            // Optional event id generated by the browser Pixel for CAPI dedup.
            'event_id' => ['nullable', 'string', 'max:100'],
        ]);

        // Route-model binding resolves by slug without the published scope, so an
        // unpublished product's name and price could be pulled into a cart by
        // anyone who knew (or guessed) its slug. addMany() already scoped this.
        abort_unless($product->status === 'published', 404);

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('id', $data['variant_id'])
                ->where('is_active', true)->firstOrFail();
        } elseif ($product->has_variants) {
            return back()->with('error', 'Please choose the available options first.');
        }

        $this->cart->add($product, $variant, $data['qty'] ?? 1);

        // Funnel step for the dashboard's conversion report.
        Visit::record('cart_add', ['product_id' => $product->id]);

        // AddToCart — server-side (CAPI) with the SAME event id the browser Pixel
        // used, so Meta collapses the two into one deduplicated event.
        if (filled($data['event_id'] ?? null)) {
            $tracking->addToCart(
                $product,
                (int) ($data['qty'] ?? 1),
                $data['event_id'],
                $tracking->customerMatchData(auth('customer')->user()),
                $variant,
            );
        }

        if ($request->wantsJson()) {
            return response()->json($this->miniPayload(['added' => $product->name]));
        }

        return back()->with('success', $product->name.' added to cart.');
    }

    /** Live cart snapshot for the slide-over mini-cart / badge. */
    public function mini()
    {
        return response()->json($this->miniPayload());
    }

    protected function miniPayload(array $extra = []): array
    {
        return array_merge([
            'count' => $this->cart->count(),
            'subtotal' => $this->cart->subtotal(),
            'subtotal_text' => money($this->cart->subtotal()),
            'discount' => $this->cart->discount(),
            'discount_text' => money($this->cart->discount()),
            'discount_lines' => collect($this->cart->discountLines())
                ->map(fn ($l) => ['label' => $l['label'], 'amount_text' => money($l['amount'])])->values(),
            'hints' => $this->cart->offerHints(),
            'free_shipping' => $this->cart->hasFreeShipping(),
            'items' => $this->cart->items()->map(fn ($i) => [
                'key' => $i['key'],
                'name' => $i['name'],
                'qty' => $i['qty'],
                'price_text' => money($i['price'] * $i['qty']),
                'image' => $i['image'],
                'url' => route('product.show', $i['slug']),
            ])->values(),
        ], $extra);
    }

    public function buyNow(Request $request, Product $product)
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        // Route-model binding resolves by slug without the published scope, so an
        // unpublished product's name and price could be pulled into a cart by
        // anyone who knew (or guessed) its slug. addMany() already scoped this.
        abort_unless($product->status === 'published', 404);

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('id', $data['variant_id'])
                ->where('is_active', true)->firstOrFail();
        } elseif ($product->has_variants) {
            return back()->with('error', 'Please choose the available options first.');
        }

        $this->cart->add($product, $variant, $data['qty'] ?? 1);

        return redirect()->route('checkout');
    }

    /** Add several products at once ("frequently bought together" bundle). */
    public function addMany(Request $request)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'redirect' => ['nullable', 'in:checkout'],
        ]);

        $products = Product::published()->whereIn('id', $data['product_ids'])->get();
        $added = 0;
        foreach ($products as $product) {
            if ($product->has_variants) {
                continue; // variant products need explicit option selection
            }
            $this->cart->add($product, null, 1);
            $added++;
        }

        // "Buy now" sends the shopper straight to checkout with the selected items.
        if ($added && ($data['redirect'] ?? null) === 'checkout') {
            return redirect()->route('checkout');
        }

        return back()->with($added ? 'success' : 'error',
            $added ? "$added item(s) added to cart." : 'Nothing could be added (choose options on variant products).');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $this->cart->update($data['key'], $data['qty']);

        if ($request->expectsJson()) {
            return response()->json($this->miniPayload());
        }

        return back();
    }

    public function remove(Request $request)
    {
        $this->cart->remove($request->string('key'));

        if ($request->expectsJson()) {
            return response()->json($this->miniPayload(['removed' => true]));
        }

        return back()->with('success', 'Item removed.');
    }

    public function applyCoupon(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $coupon = Coupon::where('code', strtoupper(trim($request->string('code'))))->first();

        // Validate against the base the cart will actually apply it to (subtotal
        // after offers and member/personalised discounts), not the raw subtotal —
        // otherwise a coupon whose minimum spend only clears the raw figure gets
        // accepted here and then silently dropped, discounting nothing.
        if (! $coupon || ! $coupon->isValidFor($this->cart->couponBase(), $this->cart)) {
            return back()->with('error', 'This coupon can’t be applied to your cart (check the items, minimum spend or quantity).');
        }

        // Per-customer cap (best-effort for logged-in customers; re-checked at checkout).
        $phone = auth('customer')->user()?->phone;
        if ($coupon->customerLimitReached($phone)) {
            return back()->with('error', 'You’ve already used this coupon the maximum number of times.');
        }

        $this->cart->applyCoupon($coupon);

        return back()->with('success', $coupon->free_shipping ? 'Coupon applied — free shipping unlocked!' : 'Coupon applied.');
    }

    public function removeCoupon()
    {
        $this->cart->removeCoupon();

        return back();
    }

    /** Apply loyalty points to the cart (logged-in customers only). */
    public function applyPoints(Request $request)
    {
        $request->validate(['points' => ['required', 'integer', 'min:0']]);

        $customer = auth('customer')->user();
        if (! $customer) {
            return back()->with('error', 'Please log in to redeem points.');
        }

        $this->cart->redeemPoints((int) $request->integer('points'));
        $applied = $this->cart->redeemablePoints();

        if ($applied <= 0) {
            $this->cart->clearPoints();
            $min = app(LoyaltyService::class)->minRedeem();
            $msg = 'Not enough points to redeem (minimum '.$min.').';

            return $request->wantsJson() ? response()->json(['ok' => false, 'message' => $msg]) : back()->with('error', $msg);
        }

        $msg = $applied.' points applied — '.money($this->cart->pointsDiscount()).' off.';

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'message' => $msg, 'applied' => $applied])
            : back()->with('success', $msg);
    }

    public function removePoints(Request $request)
    {
        $this->cart->clearPoints();

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }
}
