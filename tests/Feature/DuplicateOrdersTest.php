<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Services\CartService;
use App\Support\DuplicateOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "Stop duplicate orders" (owner, 2026-09-19): pressing the order button twice
 * must not make two of anything.
 *
 * Every test runs with the switch at its default — on — unless it says
 * otherwise, because that is what production gets without anyone touching
 * Admin → Settings.
 */
class DuplicateOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Order-placed effects (SMS, email, Meta) have no business here.
        Queue::fake();
    }

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n,
            'slug' => 'dup-ring-'.$n,
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => true,
            'stock_quantity' => 20,
            'in_stock' => true,
        ], $attrs));
    }

    protected function buyNow(Product $p, int $qty = 1)
    {
        return $this->post('/cart/buy-now/'.$p->slug, ['qty' => $qty]);
    }

    protected function checkout(array $override = [])
    {
        return $this->post('/checkout', array_merge([
            'name' => 'Nadia',
            'phone' => '01712345678',
            'address' => 'House 4, Road 2, Dhanmondi, Dhaka',
            'is_inside_dhaka' => 1,
        ], $override));
    }

    protected function cartQty(): int
    {
        return app(CartService::class)->count();
    }

    protected function turnOff(): void
    {
        Setting::put(DuplicateOrders::SETTING, false);
    }

    // ── Buy now ──────────────────────────────────────────────────────────────

    public function test_the_switch_is_on_until_the_owner_turns_it_off(): void
    {
        $this->assertTrue(DuplicateOrders::enabled());

        $this->turnOff();

        $this->assertFalse(DuplicateOrders::enabled());
    }

    public function test_buy_now_pressed_twice_sends_one_piece_to_the_checkout(): void
    {
        $p = $this->product();

        $this->buyNow($p)->assertRedirect('/checkout');
        // A double tap — or Back from the checkout and Buy now again.
        $this->buyNow($p)->assertRedirect('/checkout');

        $this->assertSame(1, $this->cartQty());
    }

    public function test_a_repeat_buy_now_is_not_counted_as_another_add_to_cart(): void
    {
        $p = $this->product(['price' => 2200]);

        $this->buyNow($p);
        $this->buyNow($p);

        // The dashboard funnel sees one add worth one piece, not two.
        $visit = Visit::where('event', 'cart_add')->sole();
        $this->assertSame('2200.00', (string) $visit->value);
    }

    public function test_buy_now_tops_up_to_the_quantity_chosen_but_never_lowers_one(): void
    {
        $p = $this->product();

        $this->buyNow($p, 1);
        $this->buyNow($p, 3);
        $this->assertSame(3, $this->cartQty(), 'three chosen on the page — three go to the checkout');

        $this->buyNow($p, 1);
        $this->assertSame(3, $this->cartQty(), 'a later press of one does not take two away');
    }

    public function test_buy_now_leaves_the_rest_of_the_cart_alone(): void
    {
        $other = $this->product();
        $p = $this->product();

        $this->post('/cart/add/'.$other->slug, ['qty' => 2]);
        $this->buyNow($p);
        $this->buyNow($p);

        $lines = app(CartService::class)->items()->pluck('qty', 'product_id');
        $this->assertSame(2, $lines[$other->id]);
        $this->assertSame(1, $lines[$p->id]);
    }

    public function test_add_to_cart_still_adds_a_piece_on_every_press(): void
    {
        $p = $this->product();

        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        $this->assertSame(2, $this->cartQty());
    }

    public function test_with_the_switch_off_buy_now_adds_on_every_press_as_before(): void
    {
        $this->turnOff();
        $p = $this->product();

        $this->buyNow($p);
        $this->buyNow($p);

        $this->assertSame(2, $this->cartQty());
        $this->assertSame(2, Visit::where('event', 'cart_add')->count());
    }

    public function test_the_bundle_buy_now_pressed_twice_keeps_one_of_each(): void
    {
        $a = $this->product();
        $b = $this->product();

        foreach ([1, 2] as $press) {
            $this->post('/cart/add-many', ['product_ids' => [$a->id, $b->id], 'redirect' => 'checkout'])
                ->assertRedirect('/checkout');
        }

        $this->assertSame(2, $this->cartQty());
        $this->assertSame(1, Visit::where('event', 'cart_add')->count(), 'the repeat added nothing, so it counts nothing');
    }

    public function test_the_bundle_add_selected_still_adds(): void
    {
        $a = $this->product();

        $this->post('/cart/add-many', ['product_ids' => [$a->id]]);
        $this->post('/cart/add-many', ['product_ids' => [$a->id]]);

        $this->assertSame(2, $this->cartQty());
    }

    // ── Place order ──────────────────────────────────────────────────────────

    public function test_place_order_pressed_twice_makes_one_order_and_shows_it(): void
    {
        $p = $this->product();
        $this->buyNow($p);

        $first = $this->checkout();
        $order = Order::sole();
        $first->assertRedirect(route('order.confirmation', $order->order_number));

        // The second press arrives after the first emptied the cart. It used to
        // be told "Your cart is empty", which reads as though the order failed.
        $this->checkout()
            ->assertRedirect(route('order.confirmation', $order->order_number))
            ->assertSessionMissing('error');

        $this->assertSame(1, Order::count());
        $this->assertSame(19, $p->fresh()->stock_quantity, 'one piece taken from stock, not two');
    }

    public function test_both_checkout_posts_hold_the_session_for_one_request_at_a_time(): void
    {
        // What makes the second press see the cart the first one emptied: two
        // requests at once each read the full cart and wrote their own back.
        foreach (['checkout.store', 'checkout.lead'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route->locksFor(), "$name must block the session");
            $this->assertGreaterThan($route->locksFor(), $route->waitsFor(), "$name must wait longer than a stuck lock can live");
        }
    }

    public function test_the_same_pieces_again_on_the_same_number_are_not_ordered_twice(): void
    {
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();
        $order = Order::sole();

        // Unsure it went through, she builds the same basket and orders again.
        $this->buyNow($p);
        $this->post('/checkout/lead', ['phone' => '01712345678'])->assertOk();
        $res = $this->checkout(['phone' => '+880 1712-345678']);   // however she types it

        $res->assertRedirect(route('order.confirmation', $order->order_number));
        $res->assertSessionHas('success', fn ($m) => str_contains($m, 'not taken it twice'));
        $this->assertSame(1, Order::count());
        $this->assertTrue(app(CartService::class)->isEmpty(), 'the basket that would have been the second order is gone');
        $this->assertSame(0, AbandonedCart::where('recovered', false)->count(), 'the follow-up desk must not chase her');
    }

    public function test_someone_else_with_the_same_number_and_pieces_learns_only_that_it_exists(): void
    {
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();
        $order = Order::sole();

        // Another browser: nothing ties it to the first order.
        $this->flushSession();
        $this->buyNow($p);
        $res = $this->checkout(['name' => 'Someone Else']);

        $res->assertRedirect('/cart');
        $res->assertSessionHas('success');
        $this->assertSame(1, Order::count());

        // The confirmation page carries her name and delivery details.
        $this->get(route('order.confirmation', $order->order_number))->assertRedirect('/track');
    }

    public function test_a_different_basket_on_the_same_number_goes_through(): void
    {
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();

        // One piece more is a different order.
        $this->post('/cart/add/'.$p->slug, ['qty' => 2]);
        $this->checkout()->assertRedirect();

        $this->assertSame(2, Order::count());
    }

    public function test_the_same_pieces_on_another_number_go_through(): void
    {
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();

        $this->buyNow($p);
        $this->checkout(['phone' => '01812345678']);

        $this->assertSame(2, Order::count());
    }

    public function test_the_same_pieces_after_the_window_go_through(): void
    {
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();

        $this->travel(DuplicateOrders::WINDOW_MINUTES + 1)->minutes();
        $this->buyNow($p);
        $this->checkout();

        $this->assertSame(2, Order::count());
    }

    public function test_a_cancelled_order_does_not_stop_the_same_order_again(): void
    {
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();
        Order::sole()->update(['status' => 'cancelled']);

        $this->buyNow($p);
        $this->checkout();

        $this->assertSame(2, Order::count());
    }

    public function test_with_the_switch_off_the_checkout_behaves_as_before(): void
    {
        $this->turnOff();
        $p = $this->product();
        $this->buyNow($p);
        $this->checkout();

        // An empty cart is an empty cart…
        $this->checkout()->assertRedirect('/cart')->assertSessionHas('error', 'Your cart is empty.');

        // …and the same basket again is a second order.
        $this->buyNow($p);
        $this->checkout();
        $this->assertSame(2, Order::count());
    }

    // ── Admin → Settings ─────────────────────────────────────────────────────

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'dup@admin.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
    }

    public function test_the_settings_page_shows_the_switch_ticked(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings')
            ->assertOk()
            ->assertSee('Stop duplicate orders')
            ->assertSee('name="prevent_duplicate_orders" value="1" class="mt-0.5" checked', false);
    }

    public function test_the_owner_can_turn_it_off_and_on_again(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/settings', ['prevent_duplicate_orders' => '0'])->assertRedirect();
        $this->assertFalse(DuplicateOrders::enabled());

        $this->post('/admin/settings', ['prevent_duplicate_orders' => '1'])->assertRedirect();
        $this->assertTrue(DuplicateOrders::enabled());
    }

    public function test_a_save_without_the_field_leaves_the_switch_alone(): void
    {
        $this->turnOff();

        $this->actingAs($this->admin())->post('/admin/settings', ['store_name' => 'NoyChoy'])->assertRedirect();

        $this->assertFalse(DuplicateOrders::enabled(), 'a save that did not carry the box must not change it');
    }
}
