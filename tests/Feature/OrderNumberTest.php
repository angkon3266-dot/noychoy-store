<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * order_number is UNIQUE, and a soft-deleted order keeps its number in that
 * index. Handing that number out again is fatal: production saw
 * "Duplicate entry '10001' for key 'orders_order_number_unique'" on every
 * checkout, because the generator only looked at live rows.
 */
class OrderNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function order(string $number): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => 'A', 'customer_phone' => '01712345678',
            'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 100, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);
    }

    protected function product(): Product
    {
        return Product::create([
            'name' => 'Ring', 'slug' => 'ring-'.uniqid(), 'status' => 'published', 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 50, 'in_stock' => true,
        ]);
    }

    protected function checkout(Product $p): \Illuminate\Testing\TestResponse
    {
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        return $this->post('/checkout', [
            'name' => 'Buyer', 'phone' => '01712345678',
            'address' => '1 Road, Dhaka', 'is_inside_dhaka' => 1,
        ]);
    }

    public function test_the_first_order_starts_the_sequence(): void
    {
        $this->assertSame('10001', Order::generateNumber());
    }

    public function test_it_counts_from_the_highest_number_not_the_newest_row(): void
    {
        $this->order('10005');
        $this->order('10002');       // created later, lower number

        $this->assertSame('10006', Order::generateNumber());
    }

    public function test_a_soft_deleted_order_does_not_release_its_number(): void
    {
        // The exact production state: the only order holding 10001 was deleted.
        $this->order('10001')->delete();

        $this->assertSame('10002', Order::generateNumber());
    }

    public function test_checkout_succeeds_when_every_previous_order_was_deleted(): void
    {
        // This is what 500'd: generator saw no live orders, returned 10001,
        // and collided with the trashed row three times over.
        $this->order('10001')->delete();
        $this->order('10002')->delete();

        $res = $this->checkout($this->product());

        $res->assertRedirect();
        $this->assertSame('10003', Order::latest('id')->first()->order_number);
    }

    public function test_legacy_prefixed_numbers_are_ignored(): void
    {
        $this->order('NOY-260614-0001');

        $this->assertSame('10001', Order::generateNumber());
    }

    public function test_the_attempt_offset_steps_past_a_taken_number(): void
    {
        $this->order('10001');

        $this->assertSame('10002', Order::generateNumber(0));
        $this->assertSame('10003', Order::generateNumber(1));
        $this->assertSame('10004', Order::generateNumber(2));
    }

    public function test_consecutive_checkouts_get_consecutive_numbers(): void
    {
        $p = $this->product();

        foreach (['10001', '10002', '10003'] as $expected) {
            $this->checkout($p)->assertRedirect();
            $this->assertSame($expected, Order::latest('id')->first()->order_number);
        }
    }

    public function test_it_passes_five_digits_without_truncating(): void
    {
        $this->order('99999');

        $this->assertSame('100000', Order::generateNumber());
    }
}
