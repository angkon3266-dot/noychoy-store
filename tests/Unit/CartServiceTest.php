<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function cart(): CartService
    {
        return app(CartService::class);
    }

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n,
            'slug' => 'ring-'.$n,
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
        ], $attrs));
    }

    public function test_add_sets_count_and_subtotal(): void
    {
        $cart = $this->cart();
        $cart->add($this->product(['price' => 500]), null, 2);

        $this->assertSame(2, $cart->count());
        $this->assertSame(1000.0, $cart->subtotal());
    }

    public function test_adding_same_product_merges_quantity(): void
    {
        $cart = $this->cart();
        $p = $this->product(['price' => 300]);
        $cart->add($p, null, 1);
        $cart->add($p, null, 2);

        $this->assertSame(3, $cart->count());
        $this->assertSame(900.0, $cart->subtotal());
    }

    public function test_clear_empties_the_cart(): void
    {
        $cart = $this->cart();
        $cart->add($this->product(), null, 1);
        $cart->clear();

        $this->assertTrue($cart->isEmpty());
        $this->assertSame(0.0, $cart->subtotal());
    }

    public function test_percentage_coupon_discounts_the_subtotal(): void
    {
        $cart = $this->cart();
        $cart->add($this->product(['price' => 1000]), null, 1);

        $coupon = Coupon::create([
            'code' => 'SAVE10', 'type' => 'percent', 'value' => 10,
            'applies_to' => 'all', 'is_active' => true,
        ]);
        $cart->applyCoupon($coupon);

        $this->assertSame(100.0, $cart->couponDiscount());
        $this->assertSame(100.0, $cart->discount());
    }

    public function test_expired_coupon_is_ignored(): void
    {
        $cart = $this->cart();
        $cart->add($this->product(['price' => 1000]), null, 1);

        $coupon = Coupon::create([
            'code' => 'OLD', 'type' => 'percent', 'value' => 10,
            'applies_to' => 'all', 'is_active' => true, 'expires_at' => now()->subDay(),
        ]);
        $cart->applyCoupon($coupon);

        $this->assertNull($cart->coupon());
        $this->assertSame(0.0, $cart->couponDiscount());
        $this->assertSame(0.0, $cart->discount());
    }

    public function test_inactive_coupon_is_ignored(): void
    {
        $cart = $this->cart();
        $cart->add($this->product(['price' => 1000]), null, 1);

        $coupon = Coupon::create([
            'code' => 'OFF', 'type' => 'percent', 'value' => 20,
            'applies_to' => 'all', 'is_active' => false,
        ]);
        $cart->applyCoupon($coupon);

        $this->assertSame(0.0, $cart->couponDiscount());
    }
}
