<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Support\SpinWheel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The checkout spin wheel (owner, 2026-10-31): the owner sets the slices, the
 * customer spins once, and the prize comes off that order by itself.
 */
class SpinWheelTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 2000,
            'manage_stock' => true, 'stock_quantity' => 50, 'in_stock' => true,
        ], $extra));
    }

    /** A live wheel whose every spin lands on the slice with all the weight. */
    protected function wheel(array $segments = null, array $settings = []): void
    {
        Setting::put('spin_wheel_enabled', true);
        Setting::put('spin_wheel_segments', $segments ?? [
            ['label' => 'Try again', 'type' => 'none', 'weight' => 0],
            ['label' => '10% OFF', 'type' => 'percent', 'value' => 10, 'weight' => 1],
        ]);
        foreach ($settings as $k => $v) {
            Setting::put('spin_wheel_'.$k, $v);
        }
    }

    protected function inCart(int $price = 2000): void
    {
        $this->post('/cart/add/'.$this->product(['price' => $price])->slug, ['qty' => 1]);
    }

    protected function checkoutData(array $extra = []): array
    {
        return array_merge([
            'name' => 'Test Buyer', 'phone' => '01712345678',
            'address' => '123 Road, Dhaka', 'is_inside_dhaka' => 1,
        ], $extra);
    }

    public function test_the_wheel_is_absent_until_the_owner_switches_it_on(): void
    {
        $this->inCart();

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page->where('spin', null));
        $this->postJson('/checkout/spin')->assertOk()->assertJson(['ok' => false]);
        $this->assertSame(0, Coupon::count());
    }

    public function test_checkout_shows_the_slices_but_never_their_odds(): void
    {
        $this->wheel();
        $this->inCart();

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('spin.segments', 2)
            ->where('spin.segments.1.label', '10% OFF')
            ->missing('spin.segments.1.weight')
            ->where('spin.result', null)
            ->where('spin.blocked', null));
    }

    public function test_a_prize_is_minted_and_applied_to_the_order(): void
    {
        $this->wheel();
        $this->inCart(2000);

        $res = $this->postJson('/checkout/spin')->assertOk()
            ->assertJson(['ok' => true, 'index' => 1, 'result' => ['won' => true, 'applied' => true, 'label' => '10% OFF']]);

        $coupon = Coupon::firstOrFail();
        $this->assertSame(SpinWheel::SOURCE, $coupon->source);
        $this->assertSame($coupon->code, $res->json('result.code'));
        $this->assertStringStartsWith('SPIN', $coupon->code);
        $this->assertSame(1, $coupon->usage_limit);
        $this->assertTrue($coupon->expires_at->isFuture());

        $this->app->forgetInstance(CartService::class);
        $cart = app(CartService::class);
        $this->assertSame(200.0, $cart->couponDiscount());

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('coupon.code', $coupon->code)
            ->where('spin.result.applied', true));
    }

    public function test_one_spin_per_shopper(): void
    {
        $this->wheel();
        $this->inCart();

        $this->postJson('/checkout/spin')->assertJson(['ok' => true]);
        $this->postJson('/checkout/spin')->assertJson(['ok' => false, 'result' => ['won' => true]]);

        $this->assertSame(1, Coupon::count());
    }

    public function test_a_no_prize_slice_gives_nothing(): void
    {
        $this->wheel([
            ['label' => 'Try again', 'type' => 'none', 'weight' => 1],
            ['label' => '10% OFF', 'type' => 'percent', 'value' => 10, 'weight' => 0],
        ]);
        $this->inCart();

        $this->postJson('/checkout/spin')->assertJson(['ok' => true, 'index' => 0, 'result' => ['won' => false, 'code' => null]]);
        $this->assertSame(0, Coupon::count());
    }

    public function test_a_free_delivery_slice_makes_delivery_free(): void
    {
        $this->wheel([
            ['label' => 'Free delivery', 'type' => 'free_delivery', 'weight' => 1],
            ['label' => 'Try again', 'type' => 'none', 'weight' => 0],
        ]);
        $this->inCart(500);

        $this->postJson('/checkout/spin')->assertJson(['ok' => true]);

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page->where('freeShipping', true));
    }

    public function test_the_prize_is_spent_by_the_order(): void
    {
        $this->wheel();
        $this->inCart(2000);
        $code = $this->postJson('/checkout/spin')->json('result.code');

        $this->post('/checkout', $this->checkoutData())->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame($code, $order->coupon_code);
        $this->assertSame(1, Coupon::where('code', $code)->value('used_count'));
    }

    public function test_one_prize_per_phone_number(): void
    {
        $this->wheel();
        $this->inCart(2000);
        $this->postJson('/checkout/spin');
        $this->post('/checkout', $this->checkoutData())->assertRedirect();

        // A fresh browser the next day (no session, no cookie, and past the
        // duplicate-order window): a second spin, and a second prize.
        $this->travel(1)->days();
        $this->flushSession();
        $this->app->forgetInstance(CartService::class);
        $this->post('/cart/add/ring', ['qty' => 1]);
        $second = $this->postJson('/checkout/spin')->assertJson(['ok' => true])->json('result.code');

        $this->post('/checkout', $this->checkoutData())
            ->assertRedirect(route('cart'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, $second));

        $this->assertSame(1, Order::count());
    }

    public function test_a_number_known_to_have_used_a_prize_is_told_before_spinning(): void
    {
        $this->wheel();
        $prize = Coupon::create(['code' => 'SPINOLD', 'source' => SpinWheel::SOURCE, 'type' => 'percent', 'value' => 5, 'applies_to' => 'all', 'is_active' => true]);
        Order::forceCreate([
            'order_number' => 'NC-1', 'customer_name' => 'A', 'customer_phone' => '01712345678',
            'shipping_address' => 'x', 'subtotal' => 100, 'total' => 100, 'coupon_code' => $prize->code,
        ]);
        $this->inCart();
        app(CartService::class)->rememberCheckoutPhone('01712345678');

        $this->postJson('/checkout/spin')->assertJson(['ok' => false, 'message' => 'This number has already used its wheel prize.']);
    }

    public function test_a_code_she_typed_keeps_the_wheel_away(): void
    {
        $this->wheel();
        Coupon::create(['code' => 'MINE', 'type' => 'percent', 'value' => 15, 'applies_to' => 'all', 'is_active' => true]);
        $this->inCart();
        $this->post('/cart/coupon', ['code' => 'MINE']);

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page->where('spin', null));
        $this->postJson('/checkout/spin')->assertJson(['ok' => false]);
        $this->assertSame(1, Coupon::count());
    }

    public function test_a_prize_waits_for_the_minimum_order(): void
    {
        $this->wheel(null, ['min_cart' => 1500]);
        $this->inCart(1000);

        $this->postJson('/checkout/spin')->assertJson(['ok' => true, 'result' => ['won' => true, 'applied' => false]]);

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('coupon', null)
            ->where('spin.result.note', 'Spend ৳1,500 to use it.'));
    }

    // ── While browsing ──────────────────────────────────────────────────────

    public function test_every_storefront_page_carries_the_wheel(): void
    {
        $this->wheel(null, ['popup_delay' => 30, 'popup_pages' => 3]);
        $this->product();

        $this->get('/shop')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('chrome.spin.segments', 2)
            ->where('chrome.spin.popup', ['delay' => 30, 'pages' => 3])
            ->where('chrome.spin.url', route('checkout.spin')));
    }

    public function test_the_popup_can_be_switched_off(): void
    {
        $this->wheel(null, ['popup' => false]);
        $this->product();

        $this->get('/shop')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('chrome.spin.popup', null)
            ->has('chrome.spin.segments'));
    }

    public function test_a_shopper_can_spin_before_adding_anything(): void
    {
        $this->wheel();

        $this->postJson('/checkout/spin')->assertJson(['ok' => true, 'result' => [
            'won' => true, 'applied' => false, 'note' => 'Saved for you — it comes off automatically at checkout.',
        ]]);

        // The prize was waiting in her session; it applies once there is a bag.
        $this->inCart(2000);
        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('coupon.code', Coupon::firstOrFail()->code)
            ->where('spin.result.applied', true));
    }

    // ── Free gift ───────────────────────────────────────────────────────────

    protected function giftWheel(Product $gift): void
    {
        $this->wheel([
            ['label' => 'Free gift', 'type' => 'free_gift', 'product_id' => $gift->id, 'weight' => 1],
            ['label' => 'Try again', 'type' => 'none', 'weight' => 0],
        ]);
    }

    public function test_a_gift_goes_into_the_bag_and_comes_off_with_an_order(): void
    {
        $gift = $this->product(['name' => 'Pouch', 'slug' => 'pouch', 'price' => 300]);
        $this->giftWheel($gift);
        $this->inCart(2000);

        $this->postJson('/checkout/spin')->assertJson(['ok' => true, 'result' => [
            'won' => true, 'applied' => true, 'gift' => ['name' => 'Pouch'],
        ]]);

        $this->app->forgetInstance(CartService::class);
        $cart = app(CartService::class);
        $this->assertTrue($cart->items()->contains(fn ($i) => $i['product_id'] === $gift->id && $i['qty'] === 1));
        $this->assertSame(300.0, $cart->couponDiscount());

        // The order carries the gift line, and the gift's stock comes off.
        $this->post('/checkout', $this->checkoutData())->assertRedirect();
        $this->assertSame(1, Order::firstOrFail()->items()->where('product_id', $gift->id)->count());
        $this->assertSame(49, $gift->fresh()->stock_quantity);
    }

    public function test_a_gift_on_its_own_is_not_free(): void
    {
        $gift = $this->product(['name' => 'Pouch', 'slug' => 'pouch', 'price' => 300]);
        $this->giftWheel($gift);

        $this->postJson('/checkout/spin')->assertJson(['ok' => true]);

        $this->get('/checkout')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('coupon', null)
            ->where('spin.result.note', 'Add any piece you love — the gift comes free with it.'));
    }

    public function test_the_gift_can_be_the_piece_she_is_buying(): void
    {
        $gift = $this->product(['name' => 'Pouch', 'slug' => 'pouch', 'price' => 300]);
        $this->giftWheel($gift);
        $this->post('/cart/add/pouch', ['qty' => 1]);

        // One paid, one won: the second pouch is the free one.
        $this->postJson('/checkout/spin')->assertJson(['ok' => true, 'result' => ['applied' => true]]);

        $this->app->forgetInstance(CartService::class);
        $cart = app(CartService::class);
        $this->assertSame(2, (int) $cart->items()->sum('qty'));
        $this->assertSame(300.0, $cart->couponDiscount());
    }

    public function test_a_sold_out_gift_is_never_won(): void
    {
        $gift = $this->product(['name' => 'Pouch', 'slug' => 'pouch', 'price' => 300, 'stock_quantity' => 0]);
        $this->wheel([
            ['label' => 'Free gift', 'type' => 'free_gift', 'product_id' => $gift->id, 'weight' => 1000],
            ['label' => 'Try again', 'type' => 'none', 'weight' => 1],
        ]);
        $this->inCart(2000);

        $this->postJson('/checkout/spin')->assertJson(['ok' => true, 'index' => 1, 'result' => ['won' => false]]);
        $this->assertSame(0, Coupon::count());
    }

    public function test_the_odds_follow_the_weights(): void
    {
        $this->wheel([
            ['label' => 'A', 'type' => 'percent', 'value' => 5, 'weight' => 3],
            ['label' => 'B', 'type' => 'none', 'weight' => 1],
            ['label' => 'Never', 'type' => 'flat', 'value' => 999, 'weight' => 0],
        ]);

        $wheel = app(SpinWheel::class);
        $pick = (new \ReflectionMethod($wheel, 'pick'));
        $counts = [0, 0, 0];
        for ($i = 0; $i < 4000; $i++) {
            $counts[$pick->invoke($wheel, $wheel->segments())]++;
        }

        $this->assertSame(0, $counts[2]);
        $this->assertEqualsWithDelta(0.75, $counts[0] / 4000, 0.05);
    }

    // ── Admin ───────────────────────────────────────────────────────────────

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'wheel@admin.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    public function test_the_owner_sets_the_slices_in_offers(): void
    {
        $this->actingAs($this->admin())->get(route('admin.offers.index'))
            ->assertOk()->assertSee('Spin & win wheel');

        $this->actingAs($this->admin())->post(route('admin.offers.spin-wheel'), [
            'enabled' => 1, 'once_per_phone' => 1, 'title' => 'Ghurao, jeto!', 'valid_hours' => 48, 'min_cart' => 0,
            'segments' => [
                ['label' => '', 'type' => 'percent', 'value' => 15, 'weight' => 5, 'color' => '#112233'],
                ['label' => 'Oops', 'type' => 'none', 'value' => '', 'weight' => 5, 'color' => '#abcdef'],
                ['label' => 'Broken', 'type' => 'flat', 'value' => '', 'weight' => 5],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $wheel = app(SpinWheel::class);
        $this->assertTrue($wheel->enabled());
        $this->assertSame('Ghurao, jeto!', $wheel->title());
        $this->assertSame(48, $wheel->validHours());
        // A ৳ slice with no amount is dropped; a blank label is written for her.
        $this->assertCount(2, $wheel->segments());
        $this->assertSame('15% OFF', $wheel->segments()[0]['label']);
        $this->assertSame('#ABCDEF', $wheel->segments()[1]['color']);
    }

    public function test_a_gift_slice_needs_its_piece(): void
    {
        $gift = $this->product(['name' => 'Pouch', 'slug' => 'pouch', 'price' => 300]);

        $this->actingAs($this->admin())->post(route('admin.offers.spin-wheel'), [
            'enabled' => 1, 'valid_hours' => 24, 'popup' => 1, 'popup_delay' => 15, 'popup_pages' => 2,
            'segments' => [
                ['label' => 'Gift', 'type' => 'free_gift', 'weight' => 1],
                ['label' => 'No', 'type' => 'none', 'weight' => 1],
            ],
        ])->assertSessionHasErrors('segments.0.product_id');

        $this->actingAs($this->admin())->post(route('admin.offers.spin-wheel'), [
            'enabled' => 1, 'valid_hours' => 24, 'popup' => 1, 'popup_delay' => 15, 'popup_pages' => 2,
            'segments' => [
                ['label' => '', 'type' => 'free_gift', 'product_id' => $gift->id, 'weight' => 1],
                ['label' => 'No', 'type' => 'none', 'weight' => 1],
            ],
        ])->assertSessionHas('success');

        $wheel = app(SpinWheel::class);
        $this->assertSame($gift->id, $wheel->segments()[0]['product_id']);
        $this->assertSame('Free gift', $wheel->segments()[0]['label']);
        $this->assertSame(['delay' => 15, 'pages' => 2], $wheel->popup());
    }

    public function test_a_wheel_with_no_prize_cannot_go_live(): void
    {
        $this->actingAs($this->admin())->post(route('admin.offers.spin-wheel'), [
            'enabled' => 1, 'valid_hours' => 24,
            'segments' => [
                ['label' => 'No', 'type' => 'none', 'weight' => 1],
                ['label' => 'Nope', 'type' => 'none', 'weight' => 1],
            ],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertFalse(app(SpinWheel::class)->enabled());
    }
}
