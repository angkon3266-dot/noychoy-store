<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The courier is the source of truth for what physically happened, so a settled
 * Steadfast outcome moves the order by itself:
 *
 *   delivered          → Delivered, and LOCKED (goods gone, COD collected)
 *   cancelled          → Cancelled, still editable
 *   partial_delivered  → Partially delivered, still editable (someone must settle it)
 *   in-flight states   → no forced change
 */
class CourierStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 1500, 'manage_stock' => true, 'stock_quantity' => 10, 'in_stock' => true,
        ], $attrs));
    }

    protected function orderWithShipment(string $courierStatus = 'in_review'): Order
    {
        $p = $this->product();
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);
        $this->post('/checkout', [
            'name' => 'B', 'phone' => '01712345678', 'address' => '1 Rd', 'is_inside_dhaka' => 1,
        ]);

        $order = Order::latest('id')->firstOrFail();
        Shipment::create([
            'order_id' => $order->id, 'courier' => 'steadfast',
            'consignment_id' => '999'.$order->id, 'tracking_code' => 'TRK'.$order->id,
            'cod_amount' => $order->total, 'status' => $courierStatus,
        ]);

        return $order->fresh();
    }

    protected function webhook(Order $order, string $status): void
    {
        Setting::put('integrations', ['steadfast_webhook_secret' => 'sec']);

        $this->postJson('/webhooks/steadfast?token=sec', [
            'consignment_id' => $order->shipment->consignment_id,
            'delivery_status' => $status,
        ])->assertOk();
    }

    protected function admin(): User
    {
        return User::create(['name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    // ── Mapping ─────────────────────────────────────────────────────────────

    #[DataProvider('courierStatuses')]
    public function test_courier_status_maps_to_the_right_order_status(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Order::statusForCourierStatus($raw));
    }

    public static function courierStatuses(): array
    {
        return [
            'delivered' => ['delivered', 'delivered'],
            'cancelled' => ['cancelled', 'cancelled'],
            'partial delivery is its own outcome' => ['partial_delivered', 'partially_delivered'],
            'in review' => ['in_review', null],
            'pending' => ['pending', null],
            'hold' => ['hold', null],
            'unknown' => ['unknown', null],
            'empty' => ['', null],
            'null' => [null, null],
            'not settled until the courier approves' => ['delivered_approval_pending', null],
            'partial not settled either' => ['partial_delivered_approval_pending', null],
        ];
    }

    // ── Delivered: auto + locked ────────────────────────────────────────────

    public function test_delivered_sets_the_order_delivered_and_locks_it(): void
    {
        $order = $this->orderWithShipment();
        $this->webhook($order, 'delivered');

        $order = $order->fresh()->load('shipment');
        $this->assertSame('delivered', $order->status);
        $this->assertTrue($order->isStatusLocked());
    }

    public function test_a_locked_order_rejects_a_status_change(): void
    {
        $order = $this->orderWithShipment();
        $this->webhook($order, 'delivered');

        $this->actingAs($this->admin())
            ->post('/admin/orders/'.$order->id.'/status', ['status' => 'processing']);

        // Server-side enforcement — a disabled <select> stops nobody.
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_the_orders_list_disables_the_dropdown_for_a_locked_order(): void
    {
        $order = $this->orderWithShipment();
        $this->webhook($order, 'delivered');

        // ?status=all because the list now opens on the packing queue
        // (Processing), and a delivered order is by definition not in it.
        $html = $this->actingAs($this->admin())->get('/admin/orders?status=all')->assertOk()->getContent();

        $this->assertStringContainsString('confirmed by courier', $html);
    }

    // ── Cancelled / partial: auto, but still editable ───────────────────────

    public function test_cancelled_sets_the_order_cancelled_but_leaves_it_editable(): void
    {
        $order = $this->orderWithShipment();
        $this->webhook($order, 'cancelled');

        $order = $order->fresh()->load('shipment');
        $this->assertSame('cancelled', $order->status);
        $this->assertFalse($order->isStatusLocked());

        // And it can still be moved by hand.
        $this->actingAs($this->admin())
            ->post('/admin/orders/'.$order->id.'/status', ['status' => 'processing']);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_a_partial_delivery_is_not_treated_as_a_cancellation(): void
    {
        // The courier handed over some of the parcel and collected money for
        // it. Booking that as a cancellation put the whole order's stock back
        // on the shelf and erased the collected revenue from every report, so
        // it now gets its own status for the owner to settle deliberately.
        $order = $this->orderWithShipment();
        $this->webhook($order, 'partial_delivered');

        $order = $order->fresh()->load('shipment');
        $this->assertSame('partially_delivered', $order->status);
        $this->assertFalse($order->isStatusLocked());
        $this->assertFalse((bool) $order->stock_restored, 'stock was released for a parcel that was partly delivered');
    }

    public function test_a_courier_cancellation_still_returns_stock(): void
    {
        $p = $this->product(['stock_quantity' => 10]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 3]);
        $this->post('/checkout', [
            'name' => 'B', 'phone' => '01712345678', 'address' => '1 Rd', 'is_inside_dhaka' => 1,
        ]);
        $order = Order::latest('id')->firstOrFail();
        Shipment::create([
            'order_id' => $order->id, 'courier' => 'steadfast',
            'consignment_id' => 'C1', 'tracking_code' => 'T1',
            'cod_amount' => $order->total, 'status' => 'in_review',
        ]);
        $this->assertSame(7, $p->fresh()->stock_quantity);

        $this->webhook($order->fresh(), 'cancelled');

        $this->assertSame(10, $p->fresh()->stock_quantity);
    }

    // ── In-flight states force nothing ──────────────────────────────────────

    public function test_an_in_flight_status_does_not_force_a_final_status(): void
    {
        $order = $this->orderWithShipment();
        $this->webhook($order, 'pending');

        $order = $order->fresh()->load('shipment');
        $this->assertNotSame('delivered', $order->status);
        $this->assertNotSame('cancelled', $order->status);
        $this->assertFalse($order->isStatusLocked());
    }

    public function test_repeating_a_delivered_webhook_is_a_no_op(): void
    {
        $order = $this->orderWithShipment();
        $this->webhook($order, 'delivered');
        $historyAfterFirst = $order->fresh()->history()->count();

        $this->webhook($order->fresh(), 'delivered');

        $this->assertSame($historyAfterFirst, $order->fresh()->history()->count());
        $this->assertSame('delivered', $order->fresh()->status);
    }
}
