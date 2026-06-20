<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(protected CartService $cart) {}

    public function index()
    {
        return view('shop.cart', ['cart' => $this->cart]);
    }

    public function add(Request $request, Product $product)
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('id', $data['variant_id'])->firstOrFail();
        } elseif ($product->has_variants) {
            return back()->with('error', 'Please choose the available options first.');
        }

        $this->cart->add($product, $variant, $data['qty'] ?? 1);

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

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('id', $data['variant_id'])->firstOrFail();
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

        return back();
    }

    public function remove(Request $request)
    {
        $this->cart->remove($request->string('key'));

        return back()->with('success', 'Item removed.');
    }

    public function applyCoupon(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $coupon = Coupon::where('code', strtoupper(trim($request->string('code'))))->first();

        if (! $coupon || ! $coupon->isValidFor($this->cart->subtotal(), $this->cart)) {
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
}
