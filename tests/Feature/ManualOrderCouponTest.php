<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRecipient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Coupons on an order the owner takes by hand.
 *
 * Owner, 2026-09-17: "whenever that phone number is used, the customer gets the
 * coupon applied against that number". The storefront checkout applies those
 * by itself. An order taken on the phone has no cart to do it, so the manual
 * order form asks which coupons are waiting for the number, and the save checks
 * and prices the code the way checkout does — refusing, in plain words, rather
 * than writing an order without the discount she promised on the call.
 */
class ManualOrderCouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The confirmation SMS and Purchase event are not what is under test.
        Queue::fake();
    }

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Opal Ring',
            'slug' => 'opal-ring-'.uniqid(),
            'status' => 'published',
            'price' => 2000,
            'manage_stock' => true,
            'stock_quantity' => 5,
            'in_stock' => true,
        ], $attrs));
    }

    /** A coupon given to a list of numbers, the way the coupon screen builds one. */
    protected function phoneCoupon(array $attrs = [], array $phones = ['01712345678']): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'code' => 'NADIA200', 'type' => 'fixed', 'value' => 200, 'applies_to' => 'all',
            'is_active' => true, 'auto_apply' => true, 'audience' => 'phones',
        ], $attrs));

        foreach ($phones as $phone) {
            CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => $phone]);
        }

        return $coupon;
    }

    protected function save(array $overrides = [], ?array $lines = null): TestResponse
    {
        return $this->actingAs($this->admin())
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store-manual'), array_merge([
                'name' => 'Nadia Islam',
                'phone' => '01712345678',
                'address' => 'Road 4, Banani',
                'is_inside_dhaka' => '1',
                'shipping_cost' => 70,
                'discount' => 0,
                'status' => 'confirmed',
                'lines' => $lines ?? [['product_id' => $this->product()->id, 'qty' => 1, 'price' => null]],
            ], $overrides));
    }

    // ── Asking which coupons are waiting for a number ───────────────────────

    public function test_the_lookup_offers_a_coupon_given_to_this_number(): void
    {
        $this->phoneCoupon(['label' => 'Eid thank-you']);

        $this->actingAs($this->admin())
            // However the number was typed into the form.
            ->getJson(route('admin.orders.coupon-lookup', ['phone' => '+880 1712-345678']))
            ->assertOk()
            ->assertJsonCount(1, 'coupons')
            ->assertJsonPath('coupons.0.code', 'NADIA200')
            ->assertJsonPath('coupons.0.label', 'Eid thank-you')
            ->assertJsonPath('coupons.0.summary', '৳200 off');
    }

    public function test_the_lookup_offers_nothing_to_a_number_that_is_not_on_the_list(): void
    {
        $this->phoneCoupon();

        $this->actingAs($this->admin())
            ->getJson(route('admin.orders.coupon-lookup', ['phone' => '01812345678']))
            ->assertOk()
            ->assertExactJson(['coupons' => []]);
    }

    public function test_the_lookup_also_finds_a_coupon_reserved_for_the_number(): void
    {
        Coupon::create([
            'code' => 'THANKS-X1', 'type' => 'percent', 'value' => 10, 'applies_to' => 'all',
            'is_active' => true, 'reserved_for_phone' => '01712345678',
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.orders.coupon-lookup', ['phone' => '01712345678']))
            ->assertOk()
            ->assertJsonPath('coupons.0.code', 'THANKS-X1')
            ->assertJsonPath('coupons.0.summary', '10% off');
    }

    public function test_the_lookup_describes_a_coupon_in_plain_words(): void
    {
        $this->phoneCoupon([
            'code' => 'VIP10', 'type' => 'percent', 'value' => 10, 'free_shipping' => true,
            'applies_to' => 'products', 'product_ids' => [1], 'min_order' => 1000,
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.orders.coupon-lookup', ['phone' => '01712345678']))
            ->assertOk()
            ->assertJsonPath('coupons.0.summary', '10% off + free delivery · on selected products · min order ৳1,000')
            ->assertJsonPath('coupons.0.applies_to', 'products')
            ->assertJsonPath('coupons.0.free_shipping', true);
    }

    public function test_the_lookup_without_a_number_answers_with_nothing(): void
    {
        $this->phoneCoupon();

        $this->actingAs($this->admin())
            ->getJson(route('admin.orders.coupon-lookup'))
            ->assertOk()
            ->assertExactJson(['coupons' => []]);
    }

    // ── Saving an order with one ─────────────────────────────────────────────

    public function test_saving_with_the_coupon_takes_it_off_records_it_and_counts_the_use(): void
    {
        $coupon = $this->phoneCoupon();

        $this->save(['coupon_code' => 'NADIA200'])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::latest('id')->first();

        $this->assertNotNull($order);
        $this->assertSame('NADIA200', $order->coupon_code);
        $this->assertSame(2000.0, (float) $order->subtotal);
        $this->assertSame(200.0, (float) $order->discount);
        $this->assertSame(70.0, (float) $order->shipping_cost);
        $this->assertSame(1870.0, (float) $order->total);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);

        $this->assertStringContainsString('with coupon NADIA200 (৳200 off)', $order->history()->first()->note);
    }

    public function test_a_code_read_out_over_the_phone_is_found_however_it_was_typed(): void
    {
        $this->phoneCoupon();

        $this->save(['coupon_code' => ' nadia-200 '])->assertSessionHasNoErrors();

        $this->assertSame('NADIA200', Order::latest('id')->first()->coupon_code);
    }

    public function test_a_free_delivery_coupon_zeroes_the_delivery_charge(): void
    {
        $this->phoneCoupon(['code' => 'FREESHIP', 'value' => 0, 'free_shipping' => true]);

        $this->save(['coupon_code' => 'FREESHIP', 'shipping_cost' => 130])->assertSessionHasNoErrors();

        $order = Order::latest('id')->first();

        $this->assertSame(0.0, (float) $order->shipping_cost);
        $this->assertSame(0.0, (float) $order->discount);
        $this->assertSame(2000.0, (float) $order->total);
        $this->assertStringContainsString('with coupon FREESHIP (free delivery)', $order->history()->first()->note);
    }

    public function test_a_percent_coupon_for_certain_products_only_discounts_those_lines(): void
    {
        $covered = $this->product(['name' => 'Covered Ring', 'price' => 2000]);
        $other = $this->product(['name' => 'Other Pendant', 'price' => 1000]);

        $this->phoneCoupon([
            'code' => 'RING10', 'type' => 'percent', 'value' => 10,
            'applies_to' => 'products', 'product_ids' => [$covered->id],
        ]);

        $this->save(['coupon_code' => 'RING10'], [
            ['product_id' => $covered->id, 'qty' => 2, 'price' => null],
            ['product_id' => $other->id, 'qty' => 1, 'price' => null],
        ])->assertSessionHasNoErrors();

        $order = Order::latest('id')->first();

        // 10% of the two rings (4,000), nothing off the pendant.
        $this->assertSame(5000.0, (float) $order->subtotal);
        $this->assertSame(400.0, (float) $order->discount);
        $this->assertSame(4670.0, (float) $order->total);
    }

    public function test_a_category_coupon_that_leaves_out_sale_pieces_prices_only_the_rest(): void
    {
        $rings = Category::create(['name' => 'Rings', 'slug' => 'rings', 'is_active' => true]);
        $onSale = $this->product(['name' => 'Sale Ring', 'price' => 2000, 'compare_at_price' => 2500, 'category_id' => $rings->id]);
        $fullPrice = $this->product(['name' => 'Full Price Ring', 'price' => 1000, 'category_id' => $rings->id]);
        $elsewhere = $this->product(['name' => 'Necklace', 'price' => 3000]);

        $this->phoneCoupon([
            'code' => 'RINGS20', 'type' => 'percent', 'value' => 20,
            'applies_to' => 'categories', 'category_ids' => [$rings->id], 'exclude_sale_items' => true,
        ]);

        $this->save(['coupon_code' => 'RINGS20'], [
            ['product_id' => $onSale->id, 'qty' => 1, 'price' => null],
            ['product_id' => $fullPrice->id, 'qty' => 1, 'price' => null],
            ['product_id' => $elsewhere->id, 'qty' => 1, 'price' => null],
        ])->assertSessionHasNoErrors();

        // 20% of the one full-price ring only.
        $this->assertSame(200.0, (float) Order::latest('id')->first()->discount);
    }

    public function test_her_own_discount_and_the_coupon_together_never_pass_the_items(): void
    {
        $this->phoneCoupon(['value' => 500]);

        $this->save([
            'coupon_code' => 'NADIA200', 'discount' => 1900, 'shipping_cost' => 70,
        ])->assertSessionHasNoErrors();

        $order = Order::latest('id')->first();

        // Only 100 of the coupon's 500 was left to give.
        $this->assertSame(2000.0, (float) $order->discount);
        $this->assertSame(70.0, (float) $order->total);
    }

    public function test_saving_without_a_coupon_is_unchanged(): void
    {
        $this->phoneCoupon();

        $this->save(['discount' => 100])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::latest('id')->first();

        // Offered in the form, never applied behind her back on save.
        $this->assertNull($order->coupon_code);
        $this->assertSame(100.0, (float) $order->discount);
        $this->assertSame(70.0, (float) $order->shipping_cost);
        $this->assertSame(1970.0, (float) $order->total);
        $this->assertSame('Order taken by hand in the admin', $order->history()->first()->note);
        $this->assertSame(0, (int) Coupon::first()->used_count);
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    /** A refusal names the coupon field, saves nothing and spends nothing. */
    protected function assertRefused(TestResponse $response, string $reason, ?Coupon $coupon = null): void
    {
        $response->assertRedirect(route('admin.orders.create'))->assertSessionHasErrors('coupon_code');

        $this->assertStringContainsString($reason, session('errors')->first('coupon_code'));
        $this->assertSame(0, Order::count(), 'no order should be written when the coupon is refused');
        $this->assertSame(5, (int) Product::first()->stock_quantity, 'no stock should come off');

        if ($coupon) {
            $this->assertSame((int) $coupon->used_count, (int) $coupon->fresh()->used_count, 'the use must not be counted');
        }
    }

    public function test_a_coupon_given_to_another_number_is_refused(): void
    {
        $coupon = $this->phoneCoupon();

        $this->assertRefused(
            $this->save(['coupon_code' => 'NADIA200', 'phone' => '01812345678']),
            'This coupon is for a different phone number.',
            $coupon,
        );
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['expires_at' => now()->subDay()]);

        $this->assertRefused($this->save(['coupon_code' => 'NADIA200']), 'expired on', $coupon);
    }

    public function test_a_coupon_that_has_not_started_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['starts_at' => now()->addWeek()]);

        $this->assertRefused($this->save(['coupon_code' => 'NADIA200']), 'does not start until', $coupon);
    }

    public function test_a_switched_off_coupon_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['is_active' => false]);

        $this->assertRefused($this->save(['coupon_code' => 'NADIA200']), 'is switched off', $coupon);
    }

    public function test_a_coupon_that_is_used_up_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['usage_limit' => 3, 'used_count' => 3]);

        $this->assertRefused($this->save(['coupon_code' => 'NADIA200']), 'has been used up', $coupon);
    }

    public function test_a_coupon_this_number_has_already_used_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['per_customer_limit' => 1]);

        // An earlier order on this number that already spent it.
        Order::create([
            'order_number' => 'NOY-100001', 'customer_name' => 'Nadia Islam', 'customer_phone' => '01712345678',
            'shipping_address' => 'Road 4, Banani', 'status' => 'delivered', 'coupon_code' => 'NADIA200',
            'subtotal' => 2000, 'total' => 1870,
        ]);

        $response = $this->save(['coupon_code' => 'NADIA200']);

        $response->assertSessionHasErrors('coupon_code');
        $this->assertStringContainsString('has already used NADIA200', session('errors')->first('coupon_code'));
        $this->assertSame(1, Order::count(), 'no second order should be written');
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
    }

    public function test_a_coupon_below_its_minimum_order_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['min_order' => 5000]);

        $this->assertRefused(
            $this->save(['coupon_code' => 'NADIA200']),
            'needs an order of at least ৳5,000 — the items come to ৳2,000',
            $coupon,
        );
    }

    public function test_a_coupon_that_covers_none_of_the_pieces_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['applies_to' => 'products', 'product_ids' => [999]]);

        $this->assertRefused($this->save(['coupon_code' => 'NADIA200']), 'None of the items on this order are covered', $coupon);
    }

    public function test_a_coupon_short_of_its_minimum_quantity_is_refused(): void
    {
        $coupon = $this->phoneCoupon(['min_qty' => 2]);

        $this->assertRefused($this->save(['coupon_code' => 'NADIA200']), 'needs at least 2 pieces', $coupon);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->product();

        $this->assertRefused(
            $this->save(['coupon_code' => 'NOSUCHCODE'], [['product_id' => Product::first()->id, 'qty' => 1, 'price' => null]]),
            'There is no coupon with the code NOSUCHCODE.',
        );
    }

    public function test_a_refused_coupon_reopens_the_form_with_everything_that_was_typed(): void
    {
        $this->phoneCoupon();
        $ring = $this->product(['name' => 'Covered Ring']);
        $pendant = $this->product(['name' => 'Other Pendant', 'price' => 1000]);

        $response = $this->actingAs($this->admin())
            ->from(route('admin.orders.create'))
            ->followingRedirects()
            ->post(route('admin.orders.store-manual'), [
                'name' => 'Rafi Ahmed', 'phone' => '01812345678', 'address' => 'Sector 7, Uttara',
                'district' => 'Dhaka North', 'shipping_cost' => 130, 'discount' => 50,
                'coupon_code' => 'NADIA200',
                'lines' => [
                    ['product_id' => $ring->id, 'qty' => 2, 'price' => 1800],
                    ['product_id' => $pendant->id, 'qty' => 1, 'price' => null],
                ],
            ])
            ->assertOk()
            ->assertSee('The order was not saved')
            ->assertSee('This coupon is for a different phone number.')
            ->assertSee('value="Rafi Ahmed"', false)
            ->assertSee('value="Dhaka North"', false);

        // The Alpine-owned fields are handed back too, not reset to defaults.
        $restored = $response->viewData('restored');
        $this->assertSame('NADIA200', $restored['coupon']);
        $this->assertSame(130.0, $restored['shipping']);
        $this->assertSame(50.0, $restored['discount']);
        $this->assertFalse($restored['inside']);
        $this->assertCount(2, $restored['lines']);
        $this->assertSame($ring->id, $restored['lines'][0]['product_id']);
        $this->assertSame(2, $restored['lines'][0]['qty']);
        $this->assertSame(1800.0, $restored['lines'][0]['price']);
        $this->assertSame('', $restored['lines'][1]['price']);
    }

    /**
     * An order taken by hand counts towards the customer exactly as a
     * storefront order does. It never used to, so phone and Messenger regulars
     * read as one-time buyers wherever the rollups are used.
     */
    public function test_an_order_taken_by_hand_counts_towards_the_customer(): void
    {
        $this->save()->assertRedirect();

        $order = \App\Models\Order::sole();
        $customer = \App\Models\Customer::where('phone', '01712345678')->sole();

        $this->assertSame(1, (int) $customer->total_orders);
        $this->assertSame((float) $order->total, (float) $customer->total_spent);
        $this->assertNotNull($customer->last_order_at);

        $this->save()->assertRedirect();
        $this->assertSame(2, (int) $customer->fresh()->total_orders);
    }
}
