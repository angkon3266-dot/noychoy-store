<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Support\CoPurchase;
use App\Support\DeliveryEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The revenue-leaking defects found by the August audit.
 *
 * These are grouped because they share a theme: each one quietly costs the shop
 * money in a way nobody would notice from the outside — a total that differs
 * from the quote, points that evaporate, a delivery date that is a day early,
 * a recoverable customer nobody contacts.
 */
class MoneyLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function product(string $slug = 'money-ring', float $price = 3000): Product
    {
        return Product::create([
            'name' => 'Ring '.$slug,
            'slug' => $slug,
            'status' => 'published',
            'price' => $price,
            'manage_stock' => false,
        ]);
    }

    // ── The customer is never charged more than they were quoted ───────────

    public function test_a_capped_coupon_stops_the_order_instead_of_silently_costing_more(): void
    {
        $product = $this->product();
        $coupon = Coupon::create([
            'code' => 'ONCE',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
            'per_customer_limit' => 1,
        ]);

        // A first order on this phone uses the code up.
        $used = Order::create([
            'order_number' => 'NOY-000001', 'customer_name' => 'Buyer',
            'customer_phone' => '01711111111', 'shipping_address' => 'Dhaka',
            'status' => 'delivered', 'subtotal' => 3000, 'total' => 3000,
            'coupon_code' => 'ONCE',
        ]);

        $cart = app(CartService::class);
        $cart->add($product, null, 1);
        $cart->applyCoupon($coupon);

        // The guest sees the discount on the summary — the cap cannot be
        // evaluated until they type the phone on the final POST.
        $this->assertGreaterThan(0, $cart->discount());

        $response = $this->post(route('checkout.store'), [
            'name' => 'Buyer',
            'phone' => '01711111111',
            'address' => 'Dhaka',
        ]);

        // It must bounce, not quietly write a higher total.
        $response->assertRedirect(route('cart'));
        $this->assertSame(1, Order::count(), 'a second order was written despite the coupon being capped');
        $this->assertTrue(str_contains(session('error') ?? '', 'ONCE'));
        unset($used);
    }

    // ── Points spent come back when the order unwinds ──────────────────────

    public function test_points_spent_on_a_cancelled_order_are_returned(): void
    {
        $customer = Customer::create([
            'name' => 'Member', 'phone' => '01722222222', 'password' => 'secret-pass', 'points' => 0,
        ]);

        $order = Order::create([
            'order_number' => 'NOY-000002', 'customer_id' => $customer->id,
            'customer_name' => 'Member', 'customer_phone' => '01722222222',
            'shipping_address' => 'Dhaka', 'status' => 'processing',
            'subtotal' => 3000, 'total' => 2900, 'points_redeemed' => 100,
        ]);

        app(\App\Actions\TransitionOrderStatus::class)->handle($order, 'cancelled');

        // They paid with points and got nothing — the points must come back.
        $this->assertSame(100, (int) $customer->fresh()->points);
        $this->assertSame(0, (int) $order->fresh()->points_redeemed, 'a status flip-flop could refund twice');
    }

    public function test_the_refund_cannot_be_claimed_twice(): void
    {
        $customer = Customer::create([
            'name' => 'Member', 'phone' => '01733333333', 'password' => 'secret-pass', 'points' => 0,
        ]);

        $order = Order::create([
            'order_number' => 'NOY-000003', 'customer_id' => $customer->id,
            'customer_name' => 'Member', 'customer_phone' => '01733333333',
            'shipping_address' => 'Dhaka', 'status' => 'processing',
            'subtotal' => 3000, 'total' => 2900, 'points_redeemed' => 100,
        ]);

        $transition = app(\App\Actions\TransitionOrderStatus::class);
        $transition->handle($order, 'cancelled');
        $transition->handle($order->fresh(), 'returned');

        $this->assertSame(100, (int) $customer->fresh()->points);
    }

    // ── The delivery promise is anchored on Bangladesh, not the server ─────

    public function test_the_delivery_window_uses_the_shop_local_day_not_utc(): void
    {
        // 20:00 UTC is 02:00 the NEXT day in Dhaka. Anchored on UTC the window
        // would be counted from the previous calendar day and land a day early.
        Carbon::setTestNow(Carbon::parse('2026-08-24 20:00:00', 'UTC'));

        config([
            'theme.defaults.delivery_days_min' => 1,
            'theme.defaults.delivery_days_max' => 1,
            'theme.defaults.delivery_off_days' => [],
        ]);

        $estimate = DeliveryEstimate::for(false);

        // Local date is 25 Aug, so +1 working day is 26 Aug — not 25.
        $this->assertSame('26', $estimate->from->format('j'));
    }

    // ── The cart tells the truth about delivery, and suggests real companions

    public function test_the_cart_shows_what_delivery_costs(): void
    {
        Setting::put('shipping_inside', 70);
        Setting::put('shipping_outside', 130);
        app(CartService::class)->add($this->product(), null, 1);

        $this->get(route('cart'))->assertInertia(fn (Assert $page) => $page
            ->component('Cart')
            ->where('summary.ship_inside_text', money(70))
            ->where('summary.ship_outside_text', money(130))
        );
    }

    public function test_cart_suggestions_come_from_real_co_purchases(): void
    {
        $inCart = $this->product('in-cart');
        $bought = $this->product('bought-together');
        $unrelated = $this->product('unrelated');

        // One past order containing both.
        $order = Order::create([
            'order_number' => 'NOY-000004', 'customer_name' => 'B', 'customer_phone' => '01744444444',
            'shipping_address' => 'Dhaka', 'status' => 'delivered', 'subtotal' => 6000, 'total' => 6000,
        ]);
        foreach ([$inCart, $bought] as $p) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
                'price' => 3000, 'quantity' => 1, 'subtotal' => 3000,
            ]);
        }

        $ids = CoPurchase::idsFor([$inCart->id]);

        $this->assertTrue($ids->contains($bought->id), 'the co-purchased product was not suggested');
        $this->assertFalse($ids->contains($unrelated->id));
        $this->assertFalse($ids->contains($inCart->id), 'the seed product must not suggest itself');
    }

    // ── Guests get chased too ──────────────────────────────────────────────

    public function test_a_guest_cart_is_texted_and_a_member_account_is_not_required(): void
    {
        Queue::fake();
        Setting::put('abandoned_sms_enabled', true);
        Setting::put('abandoned_sms_delay_minutes', 60);

        $cart = AbandonedCart::create([
            'session_id' => 'sess-1', 'phone' => '01755555555', 'name' => 'Guest',
            'items' => [], 'subtotal' => 3000, 'item_count' => 1, 'recovered' => false,
        ]);
        // Left 2 hours ago, so past the delay window.
        $cart->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('sms:abandoned-cart')->assertExitCode(0);

        Queue::assertPushed(\App\Jobs\SendAbandonedCartSms::class, 1);
        $this->assertNotNull($cart->fresh()->sms_reminded_at);
    }

    public function test_nobody_is_texted_twice_and_nothing_goes_out_while_it_is_off(): void
    {
        Queue::fake();

        $cart = AbandonedCart::create([
            'session_id' => 'sess-2', 'phone' => '01766666666',
            'items' => [], 'subtotal' => 3000, 'item_count' => 1, 'recovered' => false,
        ]);
        $cart->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        // Off by default — it spends the owner's SMS credit.
        $this->artisan('sms:abandoned-cart');
        Queue::assertNothingPushed();

        Setting::put('abandoned_sms_enabled', true);
        $this->artisan('sms:abandoned-cart');
        $this->artisan('sms:abandoned-cart');

        Queue::assertPushed(\App\Jobs\SendAbandonedCartSms::class, 1);
    }

    public function test_a_recovered_cart_is_never_chased(): void
    {
        Queue::fake();
        Setting::put('abandoned_sms_enabled', true);

        $cart = AbandonedCart::create([
            'session_id' => 'sess-3', 'phone' => '01777777777',
            'items' => [], 'subtotal' => 3000, 'item_count' => 1, 'recovered' => true,
        ]);
        $cart->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('sms:abandoned-cart');

        Queue::assertNothingPushed();
    }

    public function test_the_recovery_link_puts_the_cart_back(): void
    {
        $product = $this->product('restore-me');

        $cart = AbandonedCart::create([
            'session_id' => 'sess-4', 'phone' => '01788888888',
            'items' => [['product_id' => $product->id, 'variant_id' => null, 'name' => $product->name, 'qty' => 2, 'price' => 3000]],
            'subtotal' => 6000, 'item_count' => 2, 'recovered' => false,
        ]);

        $link = URL::signedRoute('cart.restore', ['cart' => $cart->id]);

        $this->get($link)->assertRedirect(route('cart'));
        $this->assertSame(2, app(CartService::class)->count());
    }

    public function test_an_unsigned_recovery_link_is_refused(): void
    {
        $cart = AbandonedCart::create([
            'session_id' => 'sess-5', 'phone' => '01799999999',
            'items' => [], 'subtotal' => 0, 'item_count' => 0, 'recovered' => false,
        ]);

        // The id is guessable and a cart's contents are the shopper's business.
        $this->get(route('cart.restore', $cart->id))->assertForbidden();
    }

    public function test_a_restore_skips_products_that_are_gone(): void
    {
        $live = $this->product('still-here');
        $gone = $this->product('withdrawn');
        $gone->update(['status' => 'draft']);

        $cart = AbandonedCart::create([
            'session_id' => 'sess-6', 'phone' => '01712121212',
            'items' => [
                ['product_id' => $live->id, 'variant_id' => null, 'name' => 'a', 'qty' => 1, 'price' => 3000],
                ['product_id' => $gone->id, 'variant_id' => null, 'name' => 'b', 'qty' => 1, 'price' => 3000],
            ],
            'subtotal' => 6000, 'item_count' => 2, 'recovered' => false,
        ]);

        $this->get(URL::signedRoute('cart.restore', ['cart' => $cart->id]))->assertRedirect(route('cart'));

        // The snapshot is replayed against today's catalogue, not trusted.
        $this->assertSame(1, app(CartService::class)->count());
    }
}
