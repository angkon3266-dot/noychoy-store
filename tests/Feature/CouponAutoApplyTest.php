<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRecipient;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coupons that apply themselves to an audience.
 *
 * Keyed on the phone typed at checkout, not a login: 632 of this shop's 636
 * customers have no password, so anything gated on `auth('customer')` reaches
 * four people. The phone is the only identity a cash-on-delivery order has.
 */
class CouponAutoApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 50, 'in_stock' => true,
        ], $extra));
    }

    protected function coupon(array $extra = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'AUTO10', 'type' => 'percent', 'value' => 10,
            'applies_to' => 'all', 'is_active' => true, 'auto_apply' => true, 'audience' => 'all',
        ], $extra));
    }

    /** Put one product in the cart and return the cart service. */
    protected function cartWith(Product $product, int $qty = 1): CartService
    {
        $this->post('/cart/add/'.$product->slug, ['qty' => $qty]);

        return app(CartService::class);
    }

    protected function checkoutPayload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Test Buyer', 'phone' => '01712345678',
            'address' => '123 Road, Dhaka', 'is_inside_dhaka' => 1,
        ], $extra);
    }

    // ── Audience: everyone ──────────────────────────────────────────────────

    public function test_an_everyone_coupon_applies_without_being_typed(): void
    {
        $this->coupon();
        $product = $this->product(['price' => 2000]);

        $cart = $this->cartWith($product);

        $this->assertSame(200.0, $cart->couponDiscount());
        $this->assertSame('AUTO10', $cart->coupon()?->code);
    }

    public function test_a_coupon_that_does_not_auto_apply_stays_dormant(): void
    {
        $this->coupon(['auto_apply' => false]);
        $product = $this->product(['price' => 2000]);

        $this->assertSame(0.0, $this->cartWith($product)->couponDiscount());
    }

    // ── Audience: a list of phone numbers ───────────────────────────────────

    public function test_an_assigned_coupon_waits_for_its_phone(): void
    {
        $coupon = $this->coupon(['audience' => 'phones']);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01712345678']);

        $product = $this->product(['price' => 2000]);
        $cart = $this->cartWith($product);

        // Nobody has said who they are yet.
        $this->assertSame(0.0, $cart->couponDiscount());

        // The phone arrives from the checkout form.
        $cart->rememberCheckoutPhone('01712345678');
        $this->assertSame(200.0, $cart->couponDiscount());
    }

    public function test_someone_elses_assigned_coupon_does_not_apply(): void
    {
        $coupon = $this->coupon(['audience' => 'phones']);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01712345678']);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01999999999');

        $this->assertSame(0.0, $cart->couponDiscount());
    }

    public function test_a_number_written_differently_is_still_the_same_person(): void
    {
        $coupon = $this->coupon(['audience' => 'phones']);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01712345678']);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('+8801712345678');

        $this->assertSame(200.0, $cart->couponDiscount());
    }

    public function test_it_reaches_someone_who_has_never_ordered(): void
    {
        // The whole point of keying on a phone rather than a customer row: a
        // number from a leaflet belongs to nobody in the database yet.
        $coupon = $this->coupon(['audience' => 'phones']);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01888888888']);

        $this->assertSame(0, Customer::where('phone', '01888888888')->count());

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01888888888');

        $this->assertSame(200.0, $cart->couponDiscount());
    }

    // ── Audience: a standing rule ───────────────────────────────────────────

    public function test_a_first_order_rule_matches_a_brand_new_shopper(): void
    {
        $this->coupon(['audience' => 'rule', 'audience_rules' => ['first_order_only' => true]]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01755555555');

        $this->assertSame(200.0, $cart->couponDiscount());
    }

    public function test_a_first_order_rule_skips_someone_who_has_bought_before(): void
    {
        Customer::create(['name' => 'Repeat', 'phone' => '01712345678', 'total_orders' => 3, 'total_spent' => 9000]);
        $this->coupon(['audience' => 'rule', 'audience_rules' => ['first_order_only' => true]]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01712345678');

        $this->assertSame(0.0, $cart->couponDiscount());
    }

    public function test_a_loyal_customer_rule_matches_on_past_orders(): void
    {
        Customer::create(['name' => 'Loyal', 'phone' => '01712345678', 'total_orders' => 5, 'total_spent' => 20000]);
        $this->coupon(['audience' => 'rule', 'audience_rules' => ['min_orders' => 3]]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01712345678');

        $this->assertSame(200.0, $cart->couponDiscount());
    }

    public function test_a_lapsed_rule_ignores_someone_who_never_ordered(): void
    {
        // Otherwise every brand-new shopper counts as "lapsed", and a win-back
        // discount goes to people who were never here.
        $this->coupon(['audience' => 'rule', 'audience_rules' => ['lapsed_days' => 60]]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01755555555');

        $this->assertSame(0.0, $cart->couponDiscount());
    }

    public function test_a_lapsed_rule_matches_someone_who_has_gone_quiet(): void
    {
        Customer::create([
            'name' => 'Gone quiet', 'phone' => '01712345678', 'total_orders' => 2,
            'total_spent' => 5000, 'last_order_at' => now()->subDays(120),
        ]);
        $this->coupon(['audience' => 'rule', 'audience_rules' => ['lapsed_days' => 60]]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01712345678');

        $this->assertSame(200.0, $cart->couponDiscount());
    }

    // ── Competing with a typed code ─────────────────────────────────────────

    public function test_the_better_of_the_typed_and_the_assigned_coupon_wins(): void
    {
        $this->coupon(['code' => 'AUTO25', 'value' => 25]);
        Coupon::create([
            'code' => 'TYPED5', 'type' => 'percent', 'value' => 5,
            'applies_to' => 'all', 'is_active' => true,
        ]);

        $product = $this->product(['price' => 2000]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->post('/cart/coupon', ['code' => 'TYPED5']);

        $cart = app(CartService::class);

        $this->assertSame('AUTO25', $cart->coupon()?->code);
        $this->assertSame(500.0, $cart->couponDiscount());
    }

    public function test_a_better_typed_code_beats_the_assigned_one(): void
    {
        $this->coupon(['code' => 'AUTO5', 'value' => 5]);
        Coupon::create([
            'code' => 'TYPED30', 'type' => 'percent', 'value' => 30,
            'applies_to' => 'all', 'is_active' => true,
        ]);

        $product = $this->product(['price' => 2000]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->post('/cart/coupon', ['code' => 'TYPED30']);

        $this->assertSame('TYPED30', app(CartService::class)->coupon()?->code);
    }

    public function test_only_one_coupon_ever_applies(): void
    {
        $this->coupon(['code' => 'AUTO10', 'value' => 10]);
        $this->coupon(['code' => 'AUTO20', 'value' => 20]);

        $cart = $this->cartWith($this->product(['price' => 2000]));

        // 20% of 2000, not 30%.
        $this->assertSame(400.0, $cart->couponDiscount());
        $this->assertSame('AUTO20', $cart->coupon()?->code);
    }

    // ── Limits ──────────────────────────────────────────────────────────────

    public function test_a_spent_per_customer_limit_stops_it_reapplying(): void
    {
        $this->coupon(['code' => 'ONCE', 'per_customer_limit' => 1]);

        Order::create([
            'order_number' => '10001', 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 2000, 'total' => 2000,
            'status' => 'delivered', 'payment_method' => 'cod', 'coupon_code' => 'ONCE',
        ]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01712345678');

        $this->assertSame(0.0, $cart->couponDiscount());
    }

    public function test_the_owner_sets_how_many_uses_a_campaign_allows(): void
    {
        $this->coupon(['code' => 'TWICE', 'per_customer_limit' => 2]);

        Order::create([
            'order_number' => '10001', 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 2000, 'total' => 2000,
            'status' => 'delivered', 'payment_method' => 'cod', 'coupon_code' => 'TWICE',
        ]);

        $cart = $this->cartWith($this->product(['price' => 2000]));
        $cart->rememberCheckoutPhone('01712345678');

        // One used, one left.
        $this->assertSame(200.0, $cart->couponDiscount());
    }

    // ── Through a real checkout ─────────────────────────────────────────────

    public function test_a_real_order_records_and_spends_the_assigned_coupon(): void
    {
        $coupon = $this->coupon(['audience' => 'phones', 'value' => 10]);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01712345678']);

        $product = $this->product(['price' => 2000]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->post('/checkout', $this->checkoutPayload())->assertRedirect();

        $order = Order::sole();

        $this->assertSame('AUTO10', $order->coupon_code);
        $this->assertSame('200.00', (string) $order->discount);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_the_phone_submitted_with_the_order_is_the_one_that_decides(): void
    {
        // She typed one number into the form earlier and corrected it before
        // pressing Place order. The coupon must follow the correction.
        $coupon = $this->coupon(['audience' => 'phones']);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01999999999']);

        $product = $this->product(['price' => 2000]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        app(CartService::class)->rememberCheckoutPhone('01712345678');

        $this->post('/checkout', $this->checkoutPayload(['phone' => '01999999999']))->assertRedirect();

        $this->assertSame('AUTO10', Order::sole()->coupon_code);
    }

    public function test_the_lead_capture_returns_the_discount_it_just_unlocked(): void
    {
        $coupon = $this->coupon(['audience' => 'phones']);
        CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01712345678']);

        $product = $this->product(['price' => 2000]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);

        $this->postJson('/checkout/lead', ['phone' => '01712345678', 'name' => 'Buyer'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.discountText', '৳200');
    }

    // ── Stacking, per the owner's rule ──────────────────────────────────────

    public function test_it_stacks_with_a_store_offer(): void
    {
        // The owner's rule: a coupon is a different thing from an offer or the
        // member discount, so they stack. Only coupons compete with each other.
        \App\Models\Offer::create([
            'title' => '10% off everything', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 10, 'is_active' => true,
        ]);
        $this->coupon(['code' => 'AUTO10', 'value' => 10]);

        $cart = $this->cartWith($this->product(['price' => 2000]));

        // 10% offer = 200, then 10% coupon on the remaining 1800 = 180.
        $this->assertSame(200.0, $cart->promoDiscount());
        $this->assertSame(180.0, $cart->couponDiscount());
        $this->assertSame(380.0, $cart->discount());
    }

    public function test_an_exclusive_assigned_coupon_still_competes_with_everything(): void
    {
        \App\Models\Offer::create([
            'title' => '30% off everything', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 30, 'is_active' => true,
        ]);
        $this->coupon(['code' => 'EXCL5', 'value' => 5, 'is_exclusive' => true]);

        $cart = $this->cartWith($this->product(['price' => 2000]));

        // The offer saves more, so the exclusive coupon stands down entirely.
        $this->assertSame(600.0, $cart->discount());
        $this->assertSame(0.0, $cart->couponDiscount());
    }
}
