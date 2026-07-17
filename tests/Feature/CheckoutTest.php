<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n,
            'slug' => 'ring-'.$n,
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => true,
            'stock_quantity' => 5,
            'in_stock' => true,
        ], $attrs));
    }

    protected function addToCart(Product $p, int $qty = 1): void
    {
        // Drive the real add endpoint so the item lands in the test session that
        // the subsequent checkout request will read.
        $this->post('/cart/add/'.$p->slug, ['qty' => $qty])->assertRedirect();
    }

    protected function checkoutData(array $override = []): array
    {
        return array_merge([
            'name' => 'Test Buyer',
            'phone' => '01712345678',
            'address' => '123 Road, Dhaka',
            'is_inside_dhaka' => 1,
        ], $override);
    }

    public function test_successful_checkout_creates_order_and_decrements_stock(): void
    {
        $p = $this->product(['stock_quantity' => 5, 'price' => 1000]);
        $this->addToCart($p, 2);

        $res = $this->post('/checkout', $this->checkoutData());

        $res->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $order = Order::first();
        $this->assertSame('01712345678', $order->customer_phone);   // stored canonically
        $this->assertEquals(2000, (float) $order->subtotal);
        $this->assertSame(3, $p->fresh()->stock_quantity);          // 5 - 2
    }

    public function test_checkout_is_blocked_when_stock_is_insufficient(): void
    {
        $p = $this->product(['stock_quantity' => 1]);
        $this->addToCart($p, 3);   // want 3, only 1 available

        $res = $this->post('/checkout', $this->checkoutData());

        $res->assertRedirect('/cart');
        $res->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $p->fresh()->stock_quantity);   // untouched
    }

    public function test_checkout_is_blocked_when_price_changed(): void
    {
        $p = $this->product(['price' => 1000, 'stock_quantity' => 5]);
        $this->addToCart($p, 1);

        // Admin raises the price after it's in the cart.
        $p->update(['price' => 1500]);

        $res = $this->post('/checkout', $this->checkoutData());

        $res->assertRedirect('/cart');
        $res->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        // Cart line was repriced to the live price.
        $this->assertEquals(1500, app(CartService::class)->items()->first()['price']);
    }

    public function test_checkout_is_blocked_when_product_unpublished(): void
    {
        $p = $this->product();
        $this->addToCart($p, 1);
        $p->update(['status' => 'draft']);

        $res = $this->post('/checkout', $this->checkoutData());

        $res->assertRedirect('/cart');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_confirmation_page_requires_authorization(): void
    {
        $order = Order::create([
            'order_number' => '10001', 'customer_name' => 'A', 'customer_phone' => '01712345678',
            'shipping_address' => 'X', 'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 1000, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);

        // A stranger (no session marker, not the owner) is redirected to /track.
        $this->get('/order/'.$order->order_number.'/confirmation')->assertRedirect('/track');
    }

    public function test_checkout_queues_post_order_effects(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $p = $this->product();
        $this->addToCart($p, 1);

        $this->post('/checkout', $this->checkoutData());

        // SMS / invoice email / Meta CAPI run on the queue, not in-request.
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendOrderPlacedEffects::class);
    }

    public function test_signed_link_opens_confirmation_for_email_recipient(): void
    {
        $order = Order::create([
            'order_number' => '10002', 'customer_name' => 'B', 'customer_phone' => '01712345679',
            'shipping_address' => 'Y', 'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 1000, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);

        $url = \Illuminate\Support\Facades\URL::signedRoute('order.confirmation', ['orderNumber' => $order->order_number]);

        // The invoice email's signed link works from any device/session.
        $this->get($url)->assertOk();
    }

    public function test_buyer_can_view_their_own_confirmation(): void
    {
        $p = $this->product();
        $this->addToCart($p, 1);
        $this->post('/checkout', $this->checkoutData());

        $order = Order::first();
        // The placing session is authorized, so the confirmation renders.
        $this->get('/order/'.$order->order_number.'/confirmation')->assertOk();
    }
}
