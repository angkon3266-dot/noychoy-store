<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\MemberPricingService;
use App\Support\Storefront\ProductCardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The member price on a product card and the member discount in the cart are
 * the same number (2026-11-28).
 *
 * They were worked out two ways: the card looked at every category a piece is
 * filed under and skipped a 0 override, the cart looked only at the primary
 * category it snapshotted and honoured a 0; and the card kept showing the
 * member price after the weekly use cap had switched it off in the cart.
 * Category coupons had the cart's primary-only blind spot too.
 */
class MemberPriceCardCartTest extends TestCase
{
    use RefreshDatabase;

    protected Category $rings;

    protected Category $bridal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rings = Category::create(['name' => 'Rings', 'slug' => 'rings', 'is_active' => true]);
        $this->bridal = Category::create(['name' => 'Bridal', 'slug' => 'bridal', 'is_active' => true]);

        Setting::put('register_offer_percent', 3);
        Setting::put('register_offer_max_uses', 0);
    }

    /** A ৳1,000 ring filed under Rings, and Bridal as a second category. */
    protected function ring(): Product
    {
        $p = Product::create([
            'name' => 'Halo Ring', 'slug' => 'halo-ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => false, 'in_stock' => true, 'category_id' => $this->rings->id,
        ]);
        $p->categories()->attach($this->bridal->id);

        return $p;
    }

    protected function overrides(array $categories = [], array $products = []): void
    {
        Setting::put('member_discount_overrides', ['products' => $products, 'categories' => $categories]);
        $this->app->forgetInstance(MemberPricingService::class);
    }

    protected function member(): Customer
    {
        $c = Customer::create(['name' => 'Member', 'phone' => '01711112222', 'password' => bcrypt('x')]);
        $this->actingAs($c, 'customer');

        return $c;
    }

    protected function cartWith(Product $p): CartService
    {
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);
        $this->app->forgetInstance(CartService::class);

        return app(CartService::class);
    }

    protected function cardPct(Product $p): ?string
    {
        return ProductCardData::make($p->fresh())['member']['pct'] ?? null;
    }

    public function test_an_override_on_a_second_category_prices_the_card_and_the_cart_alike(): void
    {
        $this->overrides([$this->bridal->id => 10]);
        $this->member();
        $ring = $this->ring();

        $this->assertSame('10', $this->cardPct($ring));
        $this->assertSame(100.0, $this->cartWith($ring)->memberSignupDiscount());
    }

    public function test_a_zero_override_means_no_member_price_on_the_card_either(): void
    {
        $this->overrides([$this->rings->id => 0]);
        $this->member();
        $ring = $this->ring();

        $this->assertNull($this->cardPct($ring));
        $this->assertSame(0.0, $this->cartWith($ring)->memberSignupDiscount());
    }

    public function test_the_best_override_among_its_categories_applies_in_both(): void
    {
        $this->overrides([$this->rings->id => 5, $this->bridal->id => 8]);
        $this->member();
        $ring = $this->ring();

        $this->assertSame('8', $this->cardPct($ring));
        $this->assertSame(80.0, $this->cartWith($ring)->memberSignupDiscount());
    }

    public function test_no_override_is_the_base_rate_in_both(): void
    {
        $this->member();
        $ring = $this->ring();

        $this->assertSame('3', $this->cardPct($ring));
        $this->assertSame(30.0, $this->cartWith($ring)->memberSignupDiscount());
    }

    public function test_once_the_weekly_uses_are_spent_the_card_stops_showing_the_member_price(): void
    {
        Setting::put('register_offer_max_uses', 1);
        $c = $this->member();
        $ring = $this->ring();
        Order::create([
            'order_number' => '60001', 'customer_id' => $c->id, 'customer_name' => 'Member', 'customer_phone' => $c->phone,
            'shipping_address' => '1 Rd', 'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0, 'total' => 1000,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);

        $this->assertNull($this->cardPct($ring));
        $this->assertSame(0.0, $this->cartWith($ring)->memberSignupDiscount());
    }

    public function test_a_category_coupon_covers_a_piece_filed_under_that_category_second(): void
    {
        $ring = $this->ring();
        Coupon::create([
            'code' => 'BRIDAL10', 'type' => 'percent', 'value' => 10, 'applies_to' => 'categories',
            'category_ids' => [$this->bridal->id], 'is_active' => true,
        ]);

        $this->post('/cart/add/'.$ring->slug, ['qty' => 1]);
        $this->post('/cart/coupon', ['code' => 'BRIDAL10']);
        $this->app->forgetInstance(CartService::class);

        $this->assertSame(100.0, app(CartService::class)->couponDiscount());
    }
}
