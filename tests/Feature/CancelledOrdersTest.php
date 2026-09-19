<?php

namespace Tests\Feature;

use App\Actions\TransitionOrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Orders that are being cancelled — hope you are returning the stock — also
 * not considering that as profit" (owner, 2026-09-19).
 *
 * The main paths already did both (OrderStatusTransitionTest, the dashboard
 * tests). These pin the gaps the audit found: a parcel marked Returned after
 * the courier cancelled it lost its stock again, an order typed in already
 * cancelled never gave its stock back, and a cancelled order stayed in the
 * customer's order count and spend for good.
 */
class CancelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'owner@cancel.test'], ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    protected function product(int $stock = 5): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => 'Pendant '.$n, 'slug' => 'cancel-pendant-'.$n, 'status' => 'published',
            'price' => 2000, 'manage_stock' => true, 'stock_quantity' => $stock, 'in_stock' => true,
        ]);
    }

    protected function checkout(Product $p, int $qty = 2, string $phone = '01712345678'): Order
    {
        $this->post('/cart/add/'.$p->slug, ['qty' => $qty]);
        $this->post('/checkout', ['name' => 'Rina', 'phone' => $phone, 'address' => 'Road 2, Dhaka', 'is_inside_dhaka' => 1]);

        return Order::latest('id')->firstOrFail();
    }

    protected function move(Order $order, string $status): void
    {
        app(TransitionOrderStatus::class)->handle($order->fresh(), $status, null, 'Test');
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    public function test_marking_a_cancelled_parcel_returned_keeps_it_on_the_shelf(): void
    {
        $p = $this->product(5);
        $order = $this->checkout($p, 2);

        $this->move($order, 'cancelled');      // the courier cancels: back in stock
        $this->assertSame(5, $p->fresh()->stock_quantity);

        $this->move($order, 'returned');       // the parcel physically arrives
        $this->assertSame(5, $p->fresh()->stock_quantity, 'it used to be taken off the shelf again here');
        $this->assertTrue((bool) $order->fresh()->stock_restored);
    }

    public function test_a_returned_parcel_sent_out_again_takes_its_stock_again(): void
    {
        $p = $this->product(5);
        $order = $this->checkout($p, 2);
        $this->move($order, 'cancelled');
        $this->move($order, 'returned');

        $this->move($order, 'booked');

        $this->assertSame(3, $p->fresh()->stock_quantity);
        $this->assertFalse((bool) $order->fresh()->stock_restored);
    }

    public function test_a_delivered_order_returned_is_not_restocked_until_checked(): void
    {
        $p = $this->product(5);
        $order = $this->checkout($p, 2);
        $this->move($order, 'delivered');

        $this->move($order, 'returned');

        $this->assertSame(3, $p->fresh()->stock_quantity, 'returned goods are looked at before they go back on sale');
    }

    public function test_cancelling_then_reopening_moves_the_stock_both_ways(): void
    {
        $p = $this->product(5);
        $order = $this->checkout($p, 2);

        $this->move($order, 'cancelled');
        $this->move($order, 'processing');

        $this->assertSame(3, $p->fresh()->stock_quantity);
    }

    public function test_an_order_typed_in_already_cancelled_takes_no_stock(): void
    {
        $p = $this->product(5);

        $this->actingAs($this->admin())->post(route('admin.orders.store-manual'), [
            'name' => 'Phone Buyer', 'phone' => '01812345678', 'address' => 'Road 9', 'is_inside_dhaka' => '1',
            'shipping_cost' => 70, 'discount' => 0, 'status' => 'cancelled',
            'lines' => [['product_id' => $p->id, 'qty' => 2, 'price' => null]],
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(5, $p->fresh()->stock_quantity, 'nothing was ever going to put these back');
        $this->assertTrue((bool) $order->stock_restored);

        // Brought back to life, it takes the stock then.
        $this->move($order, 'confirmed');
        $this->assertSame(3, $p->fresh()->stock_quantity);
    }

    // ── Money ────────────────────────────────────────────────────────────────

    public function test_a_cancelled_order_leaves_the_customers_spend_and_comes_back_if_reopened(): void
    {
        $order = $this->checkout($this->product(), 1);
        $customer = $order->customer;
        $this->assertSame(1, (int) $customer->fresh()->total_orders);

        $this->move($order, 'cancelled');
        $this->assertSame(0, (int) $customer->fresh()->total_orders);
        $this->assertEquals(0, (float) $customer->fresh()->total_spent);

        $this->move($order, 'processing');
        $this->assertSame(1, (int) $customer->fresh()->total_orders);
        $this->assertEquals((float) $order->fresh()->total, (float) $customer->fresh()->total_spent);
    }

    public function test_a_manual_order_typed_in_cancelled_is_not_counted_as_spend(): void
    {
        $this->actingAs($this->admin())->post(route('admin.orders.store-manual'), [
            'name' => 'Phone Buyer', 'phone' => '01812345678', 'address' => 'Road 9', 'is_inside_dhaka' => '1',
            'shipping_cost' => 70, 'discount' => 0, 'status' => 'cancelled',
            'lines' => [['product_id' => $this->product()->id, 'qty' => 1, 'price' => null]],
        ]);

        $customer = Customer::where('phone', '01812345678')->sole();
        $this->assertSame(0, (int) $customer->total_orders);
        $this->assertEquals(0, (float) $customer->total_spent);
    }

    public function test_the_recount_command_repairs_totals_written_before_and_is_a_dry_run_by_default(): void
    {
        $order = $this->checkout($this->product(), 1);
        $order->forceFill(['status' => 'cancelled'])->saveQuietly();   // as a row from before this change
        $customer = $order->customer->fresh();
        $this->assertSame(1, (int) $customer->total_orders);

        $this->artisan('customers:recount')->expectsOutputToContain('1 customer(s) would change')->assertSuccessful();
        $this->assertSame(1, (int) $customer->fresh()->total_orders);

        $this->artisan('customers:recount', ['--force' => true])->expectsOutputToContain('Updated 1 customer(s)')->assertSuccessful();
        $this->assertSame(0, (int) $customer->fresh()->total_orders);
        $this->assertEquals(0, (float) $customer->fresh()->total_spent);
    }
}
