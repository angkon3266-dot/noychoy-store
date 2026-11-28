<?php

namespace Tests\Feature;

use App\Actions\TransitionOrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two paths moving the same order at once (review, 2026-11-28).
 *
 * The courier sync holds an order across a Steadfast call of up to 30 s. When
 * the webhook cancelled it in the meantime, the sync's stale copy still read
 * "shipped, not restocked", so the cancellation ran again: the pieces went
 * back on the shelf twice. TransitionOrderStatus now re-reads the order under
 * a row lock before deciding anything.
 */
class OrderStatusRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function shippedOrder(): array
    {
        $product = Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 5, 'in_stock' => true,
        ]);
        $customer = Customer::create(['name' => 'B', 'phone' => '01712345678']);
        $order = Order::create([
            'order_number' => '70001', 'customer_id' => $customer->id, 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => '1 Rd', 'subtotal' => 2000, 'shipping_cost' => 0, 'discount' => 0, 'total' => 2000,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'shipped', 'source' => 'web',
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => 'Ring', 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000]);

        return [$order, $product];
    }

    public function test_a_stale_copy_cannot_restock_a_cancellation_twice(): void
    {
        [$order, $product] = $this->shippedOrder();
        $stale = Order::find($order->id);           // the sync, before its Steadfast call

        app(TransitionOrderStatus::class)->handle(Order::find($order->id), 'cancelled', 'Courier reported: cancelled', 'Steadfast webhook');
        $this->assertSame(7, $product->fresh()->stock_quantity);

        // The sync's call comes back with the same verdict, on its old copy.
        $moved = app(TransitionOrderStatus::class)->handle($stale, 'cancelled', 'Courier reported: cancelled', 'Courier sync');

        $this->assertFalse($moved);
        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_a_stale_courier_verdict_already_applied_writes_nothing(): void
    {
        [$order] = $this->shippedOrder();
        $stale = Order::find($order->id);

        app(TransitionOrderStatus::class)->applyCourierStatus(Order::find($order->id), 'cancelled', 'Steadfast webhook');
        $rows = $order->history()->count();

        $this->assertFalse(app(TransitionOrderStatus::class)->applyCourierStatus($stale, 'cancelled', 'Courier sync'));
        $this->assertSame($rows, $order->history()->count());
        $this->assertSame('cancelled', $stale->status);
    }
}
