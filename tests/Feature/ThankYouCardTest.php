<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Thank-you cards are packing-slip inserts, so they print for orders being
 * processed and for nothing else — a delivered or cancelled order must never
 * turn up on the sheet, even when its id is passed explicitly.
 */
class ThankYouCardTest extends TestCase
{
    use RefreshDatabase;

    protected function order(string $status, string $number, string $name): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => $name, 'customer_phone' => '01712345678',
            'shipping_address' => 'X', 'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 1000, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => $status, 'source' => 'web',
        ]);
    }

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    public function test_only_processing_orders_get_a_card(): void
    {
        $this->order('processing', '30001', 'Packing Now');
        $this->order('delivered', '30002', 'Already Delivered');
        $this->order('cancelled', '30003', 'Cancelled Order');

        $res = $this->actingAs($this->admin())->get('/admin/orders/cards');

        $res->assertOk()
            ->assertSee('Packing Now')
            ->assertDontSee('Already Delivered')
            ->assertDontSee('Cancelled Order');
    }

    public function test_explicitly_selected_non_processing_orders_are_skipped_with_a_note(): void
    {
        $packing = $this->order('processing', '30004', 'Packing Now');
        $delivered = $this->order('delivered', '30005', 'Already Delivered');

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/cards?ids='.$packing->id.','.$delivered->id);

        $res->assertOk()
            ->assertSee('Packing Now')
            ->assertDontSee('Already Delivered')
            ->assertSee('1 selected order skipped', false);
    }
}
