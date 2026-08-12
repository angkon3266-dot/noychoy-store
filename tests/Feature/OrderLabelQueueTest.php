<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The label queue.
 *
 * A shipping label is only ever wanted for a parcel that still has to be stuck
 * and handed to the courier. Before this, "Print all labels" printed every
 * order that had ever had a consignment — including ones already delivered —
 * so the sheet grew forever and wasted paper on parcels that were long gone.
 *
 * The fix gives that moment its own status. "Send to Steadfast" moves an order
 * to `booked` (registered with the courier, still on the shelf), the label
 * sheet is scoped to exactly that, and the courier moves it on to `shipped`
 * once it is actually travelling.
 */
class OrderLabelQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function order(string $status, string $number, string $name): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => $name, 'customer_phone' => '01712345678',
            'shipping_address' => 'X', 'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0,
            'total' => 1000, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => $status, 'source' => 'web',
        ]);
    }

    protected function withConsignment(Order $order, string $cid = '9001'): Order
    {
        Shipment::create([
            'order_id' => $order->id, 'courier' => 'steadfast', 'consignment_id' => $cid,
            'tracking_code' => 'TRK'.$cid, 'cod_amount' => $order->total, 'status' => 'in_review',
        ]);

        return $order;
    }

    protected function configureSteadfast(): void
    {
        Setting::put('integrations', [
            'steadfast_base_url' => 'https://portal.steadfast.com.bd/api/v1',
            'steadfast_api_key' => 'k', 'steadfast_secret_key' => 's',
        ]);

        Http::fake(['*/create_order' => Http::response([
            'status' => 200,
            'consignment' => ['consignment_id' => 55501, 'tracking_code' => 'ABC123', 'status' => 'in_review'],
        ]), '*' => Http::response([], 200)]);
    }

    // ── Booking ──────────────────────────────────────────────────────────────

    public function test_sending_one_order_to_steadfast_marks_it_booked_not_shipped(): void
    {
        $this->configureSteadfast();
        $order = $this->order('processing', '40001', 'Buyer');

        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/steadfast');

        // "Shipped" would be a lie — the parcel has not left the shop yet, and
        // saying so both tells the customer too early and hides the order from
        // the label queue it has only just joined.
        $this->assertSame('booked', $order->fresh()->status);
    }

    public function test_the_bulk_send_marks_orders_booked(): void
    {
        $this->configureSteadfast();
        $a = $this->order('processing', '40002', 'A');
        $b = $this->order('pending', '40003', 'B');

        $this->actingAs($this->admin())
            ->post('/admin/orders/bulk-steadfast', ['ids' => [$a->id, $b->id]]);

        $this->assertSame('booked', $a->fresh()->status);
        $this->assertSame('booked', $b->fresh()->status);
    }

    public function test_booking_records_a_history_entry(): void
    {
        $this->configureSteadfast();
        $order = $this->order('processing', '40004', 'Buyer');

        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/steadfast');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'status' => 'booked',
        ]);
    }

    public function test_a_settled_order_is_not_dragged_back_to_booked(): void
    {
        $this->configureSteadfast();
        $order = $this->order('delivered', '40005', 'Done');

        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/steadfast');

        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ── The label sheet ──────────────────────────────────────────────────────

    public function test_only_booked_orders_appear_on_the_label_sheet(): void
    {
        $this->withConsignment($this->order('booked', '41001', 'Awaiting Label'), '9001');
        $this->withConsignment($this->order('delivered', '41002', 'Already Delivered'), '9002');
        $this->withConsignment($this->order('shipped', '41003', 'On The Road'), '9003');
        $this->order('processing', '41004', 'Not Booked Yet');

        $res = $this->actingAs($this->admin())->get('/admin/orders/labels');

        $res->assertOk()
            ->assertSee('Awaiting Label')
            ->assertDontSee('Already Delivered')
            ->assertDontSee('On The Road')
            ->assertDontSee('Not Booked Yet');
    }

    public function test_a_booked_order_without_a_consignment_is_still_excluded(): void
    {
        // Nothing to put on the label — there is no consignment id.
        $this->order('booked', '41005', 'No Consignment');

        $this->actingAs($this->admin())->get('/admin/orders/labels')
            ->assertOk()
            ->assertDontSee('No Consignment');
    }

    /**
     * The label sheet used to load a barcode library from jsDelivr — a page an
     * admin prints from must not depend on a third-party CDN being reachable.
     * Tracking is shown as plain text instead.
     */
    public function test_the_label_sheet_loads_no_external_script(): void
    {
        $order = $this->withConsignment($this->order('booked', '41008', 'Awaiting Label'), '9009');

        $html = $this->actingAs($this->admin())->get('/admin/orders/labels')->assertOk()->getContent();

        $this->assertStringNotContainsString('jsdelivr.net', $html);
        $this->assertStringNotContainsString('JsBarcode', $html);
        $this->assertStringContainsString('TRK9009', $html);
    }

    public function test_explicitly_selected_non_booked_orders_are_skipped_with_a_note(): void
    {
        $booked = $this->withConsignment($this->order('booked', '41006', 'Awaiting Label'), '9004');
        $delivered = $this->withConsignment($this->order('delivered', '41007', 'Already Delivered'), '9005');

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/labels?ids='.$booked->id.','.$delivered->id);

        $res->assertOk()
            ->assertSee('Awaiting Label')
            ->assertDontSee('Already Delivered')
            ->assertSee('1 selected order(s) skipped', false);
    }

    // ── The default view of the orders list ──────────────────────────────────

    public function test_the_orders_list_opens_on_processing_only(): void
    {
        $this->order('processing', '42001', 'Needs Packing');
        $this->order('delivered', '42002', 'Already Delivered');
        $this->order('booked', '42003', 'With Courier');

        $this->actingAs($this->admin())->get('/admin/orders')
            ->assertOk()
            ->assertSee('Needs Packing')
            ->assertDontSee('Already Delivered')
            ->assertDontSee('With Courier');
    }

    public function test_all_statuses_is_reachable_explicitly(): void
    {
        $this->order('processing', '42004', 'Needs Packing');
        $this->order('delivered', '42005', 'Already Delivered');

        $this->actingAs($this->admin())->get('/admin/orders?status=all')
            ->assertOk()
            ->assertSee('Needs Packing')
            ->assertSee('Already Delivered');
    }

    public function test_a_chosen_status_still_wins(): void
    {
        $this->order('processing', '42006', 'Needs Packing');
        $this->order('delivered', '42007', 'Already Delivered');

        $this->actingAs($this->admin())->get('/admin/orders?status=delivered')
            ->assertOk()
            ->assertSee('Already Delivered')
            ->assertDontSee('Needs Packing');
    }

    public function test_a_search_looks_past_the_default_filter(): void
    {
        // Searching an order number and being told it doesn't exist — because
        // it happens to be delivered — is worse than useless.
        $this->order('delivered', '42008', 'Already Delivered');

        $this->actingAs($this->admin())->get('/admin/orders?q=42008')
            ->assertOk()
            ->assertSee('Already Delivered');
    }

    public function test_the_trash_view_is_not_filtered_to_processing(): void
    {
        $order = $this->order('delivered', '42009', 'Deleted Order');
        $order->delete();

        $this->actingAs($this->admin())->get('/admin/orders?trashed=1')
            ->assertOk()
            ->assertSee('Deleted Order');
    }

    // ── The courier's own updates ────────────────────────────────────────────

    /** The webhook fails closed without a shared secret, so arm one first. */
    protected function webhook(string $cid, string $invoice, string $status): TestResponse
    {
        Setting::put('integrations', ['steadfast_webhook_secret' => 'hook-secret']);

        return $this->postJson('/webhooks/steadfast?token=hook-secret', [
            'consignment_id' => $cid, 'invoice' => $invoice, 'delivery_status' => $status,
        ]);
    }

    public function test_the_just_booked_courier_state_does_not_clear_the_label_queue(): void
    {
        $order = $this->withConsignment($this->order('booked', '43001', 'Awaiting Label'), '9006');

        $this->webhook('9006', '43001', 'in_review')->assertOk();

        // in_review is Steadfast still reviewing the booking — the parcel is
        // exactly where we left it, waiting for its label.
        $this->assertSame('booked', $order->fresh()->status);
    }

    public function test_the_courier_moving_the_parcel_marks_it_shipped(): void
    {
        $order = $this->withConsignment($this->order('booked', '43002', 'On Its Way'), '9007');

        $this->webhook('9007', '43002', 'pending')->assertOk();

        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_a_delivery_still_settles_the_order(): void
    {
        $order = $this->withConsignment($this->order('booked', '43003', 'Delivered Now'), '9008');

        $this->webhook('9008', '43003', 'delivered')->assertOk();

        $this->assertSame('delivered', $order->fresh()->status);
    }
}
