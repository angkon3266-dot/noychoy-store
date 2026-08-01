<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The courier and the admin panel must apply identical side effects.
 *
 * Stock restoration and the loyalty award used to live inside
 * Admin\OrderController, while the Steadfast webhook wrote `status` directly —
 * so a courier cancellation never returned its stock and a courier delivery
 * never paid the customer's points. Both now go through TransitionOrderStatus.
 */
class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 2000, 'manage_stock' => true, 'stock_quantity' => 5, 'in_stock' => true,
        ], $attrs));
    }

    protected function placeOrder(Product $p, int $qty = 2): Order
    {
        $this->post('/cart/add/'.$p->slug, ['qty' => $qty]);
        $this->post('/checkout', [
            'name' => 'B', 'phone' => '01712345678', 'address' => '1 Rd', 'is_inside_dhaka' => 1,
        ]);

        return Order::latest('id')->firstOrFail();
    }

    protected function webhook(Order $order, string $status): void
    {
        Setting::put('integrations', ['steadfast_webhook_secret' => 'sec']);

        $this->postJson('/webhooks/steadfast?token=sec', [
            'invoice' => $order->order_number,
            'delivery_status' => $status,
        ])->assertOk();
    }

    public function test_courier_cancellation_returns_stock_to_inventory(): void
    {
        $p = $this->product(['stock_quantity' => 5]);
        $order = $this->placeOrder($p, 2);
        $this->assertSame(3, $p->fresh()->stock_quantity);

        $this->webhook($order, 'cancelled');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $p->fresh()->stock_quantity);
        $this->assertTrue((bool) $order->fresh()->stock_restored);
    }

    public function test_courier_delivery_awards_loyalty_points(): void
    {
        Setting::put('loyalty_enabled', 1);

        $p = $this->product(['price' => 2000]);
        $order = $this->placeOrder($p, 1);

        $this->webhook($order, 'delivered');

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertGreaterThan(0, (int) $order->fresh()->customer->points);
        $this->assertGreaterThan(0, (int) $order->fresh()->points_earned);
    }

    public function test_courier_and_admin_cancellation_agree(): void
    {
        $p1 = $this->product(['stock_quantity' => 5]);
        $viaWebhook = $this->placeOrder($p1, 2);
        $this->webhook($viaWebhook, 'cancelled');

        $p2 = $this->product(['stock_quantity' => 5]);
        $viaAdmin = $this->placeOrder($p2, 2);
        $admin = User::create(['name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin']);
        $this->actingAs($admin)->post('/admin/orders/'.$viaAdmin->id.'/status', ['status' => 'cancelled']);

        $this->assertSame($p2->fresh()->stock_quantity, $p1->fresh()->stock_quantity);
        $this->assertSame(5, $p1->fresh()->stock_quantity);
    }

    public function test_repeated_webhook_deliveries_do_not_double_restock(): void
    {
        $p = $this->product(['stock_quantity' => 5]);
        $order = $this->placeOrder($p, 2);

        $this->webhook($order, 'cancelled');
        $this->webhook($order, 'cancelled');   // courier retries are normal

        $this->assertSame(5, $p->fresh()->stock_quantity);
    }

    public function test_moving_back_out_of_cancelled_re_reserves_stock(): void
    {
        $p = $this->product(['stock_quantity' => 5]);
        $order = $this->placeOrder($p, 2);

        $this->webhook($order, 'cancelled');
        $this->assertSame(5, $p->fresh()->stock_quantity);

        $admin = User::create(['name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin']);
        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/status', ['status' => 'processing']);

        $this->assertSame(3, $p->fresh()->stock_quantity);
        $this->assertFalse((bool) $order->fresh()->stock_restored);
    }
}
