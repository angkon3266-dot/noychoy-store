<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Discounts apply in order and each is capped to what is left of the subtotal.
 *
 * Before this, every stage computed against the raw subtotal independently: a
 * 20% quantity offer plus a 20% coupon took 20% of the original price twice,
 * and a large enough stack pushed `discount` past `subtotal` — the checkout page
 * rendered a negative total and the stored order was free, shipping included.
 */
class DiscountCapTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 1000, 'manage_stock' => false, 'in_stock' => true,
        ], $attrs));
    }

    protected function cartWith(float $offerPercent, float $couponPercent, array $couponAttrs = []): CartService
    {
        $p = $this->product([
            'price' => 1000,
            'quantity_offers' => [['min_qty' => 1, 'type' => 'percent', 'value' => $offerPercent]],
        ]);
        Coupon::create(array_merge([
            'code' => 'C'.(int) $couponPercent, 'type' => 'percent', 'value' => $couponPercent,
            'applies_to' => 'all', 'is_active' => true,
        ], $couponAttrs));

        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);
        $this->post('/cart/coupon', ['code' => 'C'.(int) $couponPercent]);

        return app(CartService::class);
    }

    public function test_coupon_applies_to_the_remaining_base_not_the_raw_subtotal(): void
    {
        $cart = $this->cartWith(20, 20);

        $this->assertSame(200.0, $cart->offerDiscount());
        // 20% of the remaining 800, not 20% of the original 1000.
        $this->assertSame(160.0, $cart->couponDiscount());
        $this->assertSame(360.0, $cart->discount());
    }

    public function test_discount_never_exceeds_the_subtotal(): void
    {
        $cart = $this->cartWith(90, 50);

        // 90% takes 900, leaving 100; the coupon then takes 50% of that 100 —
        // not 50% of the original 1000. Total 950, never the old 1400.
        $this->assertSame(900.0, $cart->offerDiscount());
        $this->assertSame(50.0, $cart->couponDiscount());
        $this->assertSame(950.0, $cart->discount());
        $this->assertLessThanOrEqual($cart->subtotal(), $cart->discount());
        $this->assertSame(50.0, $cart->subtotal() - $cart->discount());
    }

    public function test_a_stacked_order_is_never_stored_free(): void
    {
        $this->cartWith(90, 50);

        $this->post('/checkout', [
            'name' => 'B', 'phone' => '01712345678', 'address' => '1 Rd', 'is_inside_dhaka' => 1,
        ]);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertLessThanOrEqual((float) $order->subtotal, (float) $order->discount);
        $this->assertGreaterThan(0, (float) $order->total);   // shipping is still owed
    }

    public function test_discount_lines_add_up_to_the_discount_total(): void
    {
        $cart = $this->cartWith(90, 50);

        $sum = round(collect($cart->discountLines())->sum('amount'), 2);
        $this->assertSame($cart->discount(), $sum);
    }

    public function test_a_coupon_is_rejected_when_its_minimum_beats_the_real_base(): void
    {
        // Min spend 800 clears the raw subtotal (1000) but not the post-offer base (500).
        $cart = $this->cartWith(50, 10, ['min_order' => 800]);

        // It must not be reported as applied while discounting nothing.
        $this->assertNull($cart->coupon());
        $this->assertSame(0.0, $cart->couponDiscount());
        $this->assertNotNull(session('error'));
    }

    public function test_the_discount_cascade_is_computed_once_per_request(): void
    {
        $cart = $this->cartWith(20, 20);

        DB::enableQueryLog();
        $cart->discount();
        $cart->discount();
        $cart->discountLines();
        $cart->total(false);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Was 11 queries for a single discount() call, six of them identical.
        $this->assertLessThan(5, $count, "cascade recomputed: {$count} queries");
    }
}
