<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders Booked with courier move to Shipped together (owner, 4 Oct 2026),
 * and nothing else in the selection moves.
 */
class BulkMarkShippedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'a@b.c'], ['name' => 'A', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    private function order(string $number, string $status): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => 'Buyer', 'customer_phone' => '0171100'.$number,
            'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0, 'discount' => 0,
            'total' => 100, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => $status, 'source' => 'web',
        ]);
    }

    public function test_only_booked_orders_move_to_shipped(): void
    {
        $a = $this->order('4001', 'booked');
        $b = $this->order('4002', 'booked');
        $c = $this->order('4003', 'processing');
        $this->mock(SmsService::class)->shouldNotReceive('sendTemplate');

        $this->actingAs($this->admin())
            ->post(route('admin.orders.bulk-shipped'), ['ids' => [$a->id, $b->id, $c->id]])
            ->assertSessionHas('success', '2 order(s) marked Shipped. 1 skipped — only orders Booked with courier move.');

        $this->assertSame(['shipped', 'shipped', 'processing'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
    }

    public function test_customers_are_texted_only_when_asked(): void
    {
        $a = $this->order('4011', 'booked');
        $this->mock(SmsService::class)->shouldReceive('sendTemplate')->once()->with('order_shipped', \Mockery::any());

        $this->actingAs($this->admin())
            ->post(route('admin.orders.bulk-shipped'), ['ids' => [$a->id], 'notify' => 1])
            ->assertSessionHas('success');
    }

    public function test_a_selection_with_nothing_booked_says_so(): void
    {
        $c = $this->order('4021', 'processing');

        $this->actingAs($this->admin())
            ->post(route('admin.orders.bulk-shipped'), ['ids' => [$c->id]])
            ->assertSessionHas('error');

        $this->assertSame('processing', $c->fresh()->status);
    }

    public function test_the_list_offers_select_booked_and_mark_shipped(): void
    {
        $this->order('4031', 'booked');

        $this->actingAs($this->admin())->get(route('admin.orders.index', ['status' => 'booked']))
            ->assertOk()
            ->assertSee('Select booked (1)')
            ->assertSee(route('admin.orders.bulk-shipped'), false);
    }
}
