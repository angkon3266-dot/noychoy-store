<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Booking an order with the courier again.
 *
 * The owner asked on 2026-09-17: once an order had been sent to Steadfast it
 * could never be sent again, so a changed COD, a corrected address or a
 * replacement parcel had no way to reach the courier — and editing the address
 * of a booked order was refused outright, telling her to "re-book" through a
 * path that did not exist.
 *
 * Now a booked order can be edited, the order page says exactly what the
 * courier's copy is missing, and "Book again with courier" sends a new
 * consignment from the order as it is now. Steadfast refuses a repeated
 * invoice, so the Nth booking goes out as "<order number>-N". The replaced
 * consignment is kept and still tracked, but only the current one may move
 * the order — its cancellation at Steadfast is usually the whole point, and
 * must not cancel the order that replaced it.
 */
class CourierRebookTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function order(array $attrs = [], ?Product $product = null, int $qty = 1): Order
    {
        $order = Order::create(array_merge([
            'order_number' => '40001', 'customer_name' => 'Buyer', 'customer_phone' => '01712345678',
            'shipping_address' => 'House 4, Road 2', 'area' => 'Dhanmondi', 'district' => 'Dhaka',
            'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0, 'total' => 1000,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ], $attrs));

        $order->items()->create([
            'product_id' => $product?->id, 'name' => $product?->name ?? 'Ring',
            'price' => 1000 / $qty, 'quantity' => $qty, 'subtotal' => 1000,
        ]);

        return $order;
    }

    /** A Steadfast that accepts a booking with this consignment id. */
    protected static function accepts(int $cid): array
    {
        return ['status' => 200, 'body' => [
            'status' => 200,
            'message' => 'Consignment has been created successfully.',
            'consignment' => ['consignment_id' => $cid, 'tracking_code' => 'TRK'.$cid, 'status' => 'in_review'],
        ]];
    }

    /**
     * Arm the integration and answer each booking in turn. Anything booked
     * beyond the list empties the sequence and fails the test loudly — an
     * accidental extra consignment is exactly the bug these tests guard.
     */
    protected function steadfast(array $bookings, array $routes = []): void
    {
        $this->arm();

        $sequence = Http::sequence();
        foreach ($bookings as $booking) {
            $sequence->push($booking['body'], $booking['status']);
        }

        Http::fake(['*/create_order' => $sequence] + $routes + ['*' => Http::response([], 200)]);
    }

    /** Steadfast keys and the webhook secret, with no answers faked yet. */
    protected function arm(): void
    {
        Setting::put('integrations', [
            'steadfast_base_url' => 'https://portal.steadfast.com.bd/api/v1',
            'steadfast_api_key' => 'k', 'steadfast_secret_key' => 's',
            'steadfast_webhook_secret' => 'sec',
        ]);
    }

    /** A Steadfast that refuses a booking because the invoice was used before. */
    protected static function invoiceTaken(): array
    {
        return ['status' => 422, 'body' => ['status' => 400, 'errors' => ['invoice' => ['The invoice has already been taken.']]]];
    }

    /** Every invoice Steadfast was asked to book, in order. */
    protected function bookedInvoices(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/create_order'))
            ->map(fn ($pair) => $pair[0]['invoice'])
            ->values()
            ->all();
    }

    /** Record the SMS templates sent, without a gateway. */
    protected function captureSms(): \ArrayObject
    {
        $sent = new \ArrayObject;
        $this->mock(SmsService::class, function ($mock) use ($sent) {
            $mock->shouldReceive('sendTemplate')->andReturnUsing(function ($key) use ($sent) {
                $sent[] = $key;

                return true;
            });
        });

        return $sent;
    }

    protected function book(Order $order): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.orders.steadfast', $order));
    }

    protected function bookAgain(Order $order, ?int $replaces = null, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.orders.steadfast.rebook', $order), [
            'replaces' => $replaces ?? $order->shipment()->first()?->id,
        ] + $extra);
    }

    protected function webhook(array $payload): void
    {
        $this->postJson('/webhooks/steadfast?token=sec', $payload)->assertOk();
    }

    protected function correctAddress(Order $order, string $address): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.orders.details', $order), [
            'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone,
            'shipping_address' => $address, 'area' => $order->area, 'district' => $order->district,
            'is_inside_dhaka' => 1,
        ]);
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    public function test_the_first_booking_still_goes_out_under_the_plain_order_number(): void
    {
        $this->steadfast([self::accepts(55501)]);
        $order = $this->order();

        $this->book($order)->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/create_order') && $r['invoice'] === '40001');

        $shipment = $order->fresh()->shipment;
        $this->assertSame('40001', $shipment->invoice);
        $this->assertSame('House 4, Road 2, Dhanmondi, Dhaka', $shipment->request['recipient_address']);
        $this->assertSame('booked', $order->fresh()->status);
    }

    public function test_booking_again_after_a_value_change_sends_the_current_total_under_a_numbered_invoice(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $first = $order->fresh()->shipment;

        // The customer asked for express delivery after the parcel was booked.
        $item = $order->items()->first();
        $this->actingAs($this->admin())->post(route('admin.orders.amend', $order), [
            'items' => [['id' => $item->id, 'price' => 1000, 'quantity' => 1]],
            'shipping_cost' => 250, 'discount' => 0,
        ])->assertSessionHas('warning'); // saved, with the "book again" nudge

        $this->bookAgain($order)->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/create_order')
            && $r['invoice'] === '40001-2'
            && (float) $r['cod_amount'] === 1250.0);

        $order = $order->fresh();
        $this->assertSame(2, $order->shipments()->count());
        $this->assertSame('55502', (string) $order->shipment->consignment_id);
        $this->assertSame('40001-2', $order->shipment->invoice);
        $this->assertSame(1250.0, (float) $order->shipment->cod_amount);
        $this->assertFalse($order->shipment->isSuperseded());

        $first->refresh();
        $this->assertTrue($first->isSuperseded());
        $this->assertSame($order->shipment->id, (int) $first->superseded_by);
    }

    public function test_a_third_booking_is_numbered_three(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502), self::accepts(55503)]);
        $order = $this->order();

        $this->book($order);
        $this->bookAgain($order);
        $this->bookAgain($order)->assertSessionHas('success');

        $this->assertSame('40001-3', $order->fresh()->shipment->invoice);
        $this->assertSame(2, $order->shipments()->whereNotNull('superseded_at')->count());
    }

    // ── Failures say why ─────────────────────────────────────────────────────

    public function test_steadfasts_own_reason_is_shown_when_booking_again_fails_and_nothing_is_replaced(): void
    {
        $this->steadfast([
            self::accepts(55501),
            ['status' => 422, 'body' => ['status' => 400, 'errors' => ['recipient_phone' => ['The recipient phone must be 11 digits.']]]],
        ]);
        $order = $this->order();
        $this->book($order);
        $first = $order->fresh()->shipment;

        $this->bookAgain($order)->assertSessionHas('error',
            fn ($message) => str_contains($message, 'The recipient phone must be 11 digits')
                && str_contains($message, '55501')
                && ! str_contains($message, 'Check the logs'));

        $this->assertSame(1, $order->shipments()->count());
        $this->assertFalse($first->fresh()->isSuperseded());
        $this->assertSame('booked', $order->fresh()->status);
        $this->assertDatabaseMissing('order_status_history', ['order_id' => $order->id, 'note' => 'Re-booked with Steadfast']);
    }

    public function test_steadfasts_own_reason_is_shown_when_a_first_booking_fails(): void
    {
        $this->steadfast([['status' => 401, 'body' => ['message' => 'Unauthenticated.']]]);
        $order = $this->order();

        $this->book($order)->assertSessionHas('error',
            fn ($message) => str_contains($message, 'Unauthenticated.') && ! str_contains($message, 'Check the logs'));

        $this->assertSame(0, $order->shipments()->count());
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_a_booking_that_gets_no_answer_does_not_claim_nothing_was_booked(): void
    {
        $this->arm();
        Http::fake(['*/create_order' => Http::failedConnection('cURL error 28: Operation timed out'), '*' => Http::response([], 200)]);
        $order = $this->order();

        $this->book($order)->assertSessionHas('error', fn ($m) => str_contains($m, 'may or may not have been booked')
            && str_contains($m, 'check the Steadfast panel')
            && ! str_contains($m, 'Nothing was booked')
            && ! str_contains($m, 'did not accept'));

        $this->assertSame(0, $order->shipments()->count());
        $this->assertSame('processing', $order->fresh()->status);
    }

    // ── An invoice Steadfast already holds ───────────────────────────────────

    public function test_a_booking_that_is_not_a_rebooking_always_goes_under_the_plain_order_number(): void
    {
        // Steadfast refusing a repeated invoice is what stops a second parcel
        // when two requests book one order at once — a numbered invoice here
        // would be accepted as a second live consignment.
        $this->steadfast([self::accepts(55501), self::invoiceTaken()]);
        $order = $this->order();
        $this->book($order);

        $this->assertNull(app(SteadfastService::class)->createForOrder($order->fresh()));

        $this->assertSame(['40001', '40001'], $this->bookedInvoices());
        $this->assertSame(1, $order->shipments()->count());
    }

    public function test_a_first_booking_refused_as_already_taken_links_the_consignment_steadfast_names(): void
    {
        // The earlier attempt timed out after Steadfast had booked it.
        $this->steadfast([self::invoiceTaken()], [
            '*/status_by_invoice/40001' => Http::response([
                'status' => 200, 'delivery_status' => 'in_review',
                'consignment' => ['consignment_id' => 55500, 'tracking_code' => 'TRK55500', 'cod_amount' => 1000],
            ]),
        ]);
        $order = $this->order();

        $this->book($order)->assertSessionHas('warning', fn ($m) => str_contains($m, '#55500') && str_contains($m, 'linked'));

        $shipment = $order->fresh()->shipment;
        $this->assertSame('55500', (string) $shipment->consignment_id);
        $this->assertSame('40001', $shipment->invoice);
        $this->assertSame('TRK55500', $shipment->tracking_code);
        $this->assertSame('booked', $order->fresh()->status);
        $this->assertSame(['40001'], $this->bookedInvoices());
    }

    public function test_a_first_booking_refused_as_already_taken_is_not_retried_under_a_numbered_invoice(): void
    {
        $this->steadfast([self::invoiceTaken()]);
        $order = $this->order();

        $this->book($order)->assertSessionHas('error', fn ($m) => str_contains($m, 'The invoice has already been taken')
            && str_contains($m, 'invoice 40001') && str_contains($m, 'Steadfast panel'));

        $this->assertSame(['40001'], $this->bookedInvoices());
        $this->assertSame(0, $order->shipments()->count());
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_booking_again_links_the_consignment_steadfast_already_holds_under_the_numbered_invoice(): void
    {
        $this->steadfast([self::accepts(55501), self::invoiceTaken()], [
            '*/status_by_invoice/40001-2' => Http::response([
                'status' => 200, 'delivery_status' => 'in_review',
                'consignment' => ['consignment_id' => 55599, 'tracking_code' => 'TRK55599'],
            ]),
        ]);
        $order = $this->order();
        $this->book($order);
        $first = $order->fresh()->shipment;

        $this->bookAgain($order)->assertSessionHas('success', fn ($m) => str_contains($m, '#55599') && str_contains($m, 'linked'));

        $current = $order->fresh()->shipment;
        $this->assertSame('55599', (string) $current->consignment_id);
        $this->assertSame('40001-2', $current->invoice);
        $this->assertTrue($first->fresh()->isSuperseded());
        $this->assertSame(['40001', '40001-2'], $this->bookedInvoices());
    }

    public function test_booking_again_moves_past_a_numbered_invoice_steadfast_holds_but_cannot_name(): void
    {
        // "40001-2" was made by hand in the Steadfast panel: without this, every
        // later attempt was refused as "already taken" for good.
        $this->steadfast([self::accepts(55501), self::invoiceTaken(), self::accepts(55503)]);
        $order = $this->order();
        $this->book($order);

        $this->bookAgain($order)->assertSessionHas('success',
            fn ($m) => str_contains($m, 'invoice 40001-3') && str_contains($m, 'already had invoice 40001-2'));

        $this->assertSame(['40001', '40001-2', '40001-3'], $this->bookedInvoices());
        $this->assertSame('55503', (string) $order->fresh()->shipment->consignment_id);
        $this->assertSame(2, $order->shipments()->count());
    }

    // ── Editing a booked order ───────────────────────────────────────────────

    public function test_delivery_details_can_be_corrected_on_a_booked_order_with_a_warning(): void
    {
        $this->steadfast([self::accepts(55501)]);
        $order = $this->order();
        $this->book($order);

        $this->correctAddress($order->fresh(), 'Flat 9, Road 12')
            ->assertSessionHas('warning', 'Saved. The courier still has the old details — use Book again with courier to send these.');

        $this->assertSame('Flat 9, Road 12', $order->fresh()->shipping_address);
    }

    public function test_the_out_of_date_callout_appears_after_an_edit_and_clears_after_booking_again(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Out of date with the courier')
            ->assertSee('Book again with courier')
            // Only a prepaid consignment turning into a COD one asks for this.
            ->assertDontSee('name="confirm_cod"', false);

        $this->correctAddress($order->fresh(), 'Flat 9, Road 12');

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Out of date with the courier')
            ->assertSee('House 4, Road 2, Dhanmondi, Dhaka')
            ->assertSee('Flat 9, Road 12, Dhanmondi, Dhaka');

        $this->bookAgain($order)->assertSessionHas('success');

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Out of date with the courier')
            ->assertSee('Replaced consignments');
    }

    public function test_a_shipment_booked_before_the_request_was_kept_is_flagged_on_a_cod_change(): void
    {
        $this->steadfast([]);
        $order = $this->order(['status' => 'booked']);
        Shipment::create([
            'order_id' => $order->id, 'courier' => 'steadfast', 'consignment_id' => '9001',
            'tracking_code' => 'T9001', 'cod_amount' => 1000, 'status' => 'in_review',
        ]);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()->assertDontSee('Out of date with the courier');

        $order->update(['total' => 1200]);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()->assertSee('Out of date with the courier');
    }

    public function test_a_delivered_cash_on_delivery_order_is_not_out_of_date_with_the_courier(): void
    {
        // Delivery marks a COD order paid, and a paid order is booked as COD 0 —
        // so every delivered order used to read "COD ৳1,000 → ৳0" with a
        // primary "Book again" button beside it.
        $this->steadfast([self::accepts(55501)]);
        $order = $this->order();
        $this->book($order);

        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'delivered']);
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Out of date with the courier')
            ->assertSee('Book again with courier'); // still there, in the courier card
    }

    // ── Only the current consignment moves the order ─────────────────────────

    public function test_a_cancellation_of_the_replaced_consignment_does_not_cancel_the_rebooked_order(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $this->bookAgain($order);

        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'cancelled']);

        $this->assertSame('booked', $order->fresh()->status);
        $this->assertSame('cancelled', Shipment::where('consignment_id', '55501')->first()->status,
            'the update for the replaced consignment should still be recorded');

        $this->webhook(['consignment_id' => 55502, 'invoice' => '40001-2', 'delivery_status' => 'cancelled']);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_an_invoice_only_update_is_matched_through_the_numbered_invoice(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $this->bookAgain($order);

        $this->webhook(['invoice' => '40001', 'delivery_status' => 'cancelled']);
        $this->assertSame('booked', $order->fresh()->status);

        $this->webhook(['invoice' => '40001-2', 'delivery_status' => 'delivered']);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_refreshing_the_status_only_lets_the_current_consignment_move_the_order(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)], [
            '*/status_by_cid/55501' => Http::response(['status' => 200, 'delivery_status' => 'cancelled']),
            '*/status_by_cid/55502' => Http::sequence()
                ->push(['status' => 200, 'delivery_status' => 'in_review'])
                ->push(['status' => 200, 'delivery_status' => 'cancelled']),
        ]);
        $order = $this->order();
        $this->book($order);
        $this->bookAgain($order);

        $this->actingAs($this->admin())->post(route('admin.orders.steadfast.refresh', $order))->assertSessionHas('success');

        $this->assertSame('booked', $order->fresh()->status);
        $this->assertSame('cancelled', Shipment::where('consignment_id', '55501')->first()->status);

        $this->actingAs($this->admin())->post(route('admin.orders.steadfast.refresh', $order));

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_delivery_on_a_replaced_consignment_delivers_the_order_and_its_unused_successor_cannot_cancel_it(): void
    {
        $sms = $this->captureSms();
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $product = Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 500,
            'manage_stock' => true, 'stock_quantity' => 8, 'in_stock' => true,
        ]);
        $customer = Customer::create(['name' => 'Buyer', 'phone' => '01712345678', 'password' => bcrypt('x'), 'points' => 0]);
        $order = $this->order(['customer_id' => $customer->id, 'points_redeemed' => 50, 'points_discount' => 50], $product, 2);
        $this->book($order);
        $this->bookAgain($order);

        // Nobody cancelled 55501, and the rider delivered THAT parcel.
        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'delivered']);

        $order = $order->fresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull(Shipment::where('consignment_id', '55501')->first()->delivered_after_superseded_at);
        $this->assertTrue($order->history()->where('note', 'like', '%#55501%')->where('note', 'like', '%#55502 appears unused%')->exists());
        $this->assertSame(['order_delivered'], $sms->getArrayCopy());

        // The owner tidies up the unused consignment.
        $this->webhook(['consignment_id' => 55502, 'invoice' => '40001-2', 'delivery_status' => 'cancelled']);

        $order = $order->fresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(8, $product->fresh()->stock_quantity, 'delivered stock went back on the shelf');
        $this->assertSame(50, (int) $order->points_redeemed, 'the points the customer spent were refunded');
        $this->assertSame(['order_delivered'], $sms->getArrayCopy(), 'the customer was texted a cancellation');
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'status' => 'delivered',
            'note' => 'Consignment #55502 cancelled at the courier; #55501 was delivered — order left as it is',
        ]);

        // And the unused consignment is not put on the label sheet.
        $this->actingAs($this->admin())->get(route('admin.orders.labels'))->assertOk()->assertDontSee('TRK55502');
    }

    public function test_refreshing_the_status_applies_a_delivery_on_a_replaced_consignment_first(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)], [
            '*/status_by_cid/55501' => Http::response(['status' => 200, 'delivery_status' => 'delivered']),
            '*/status_by_cid/55502' => Http::response(['status' => 200, 'delivery_status' => 'cancelled']),
        ]);
        $order = $this->order();
        $this->book($order);
        $this->bookAgain($order);

        // Both answers arrive in the same refresh: the delivery has to win.
        $this->actingAs($this->admin())->post(route('admin.orders.steadfast.refresh', $order))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '#55501') && str_contains($m, 'marked delivered'));

        $this->assertSame('delivered', $order->fresh()->status);

        $this->actingAs($this->admin())->post(route('admin.orders.steadfast.refresh', $order));
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(1, $order->history()->where('note', 'like', 'Consignment #55502 cancelled at the courier%')->count());
    }

    public function test_opening_the_order_page_applies_a_delivery_on_a_replaced_consignment_first(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)], [
            '*/status_by_cid/55501' => Http::response(['status' => 200, 'delivery_status' => 'delivered']),
            '*/status_by_cid/55502' => Http::response(['status' => 200, 'delivery_status' => 'cancelled']),
        ]);
        $order = $this->order();
        $this->book($order);
        $this->bookAgain($order);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))->assertOk();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(1, $order->history()->where('note', 'like', 'Consignment #55502 cancelled at the courier%')->count());
    }

    public function test_a_consignment_delivered_before_it_was_replaced_does_not_hold_back_the_new_ones_cancellation(): void
    {
        // Booking a delivered order again is a deliberate second parcel, and
        // the confirmation says its outcome drives the order from then on.
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'delivered']);
        $this->bookAgain($order);

        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'delivered']);
        $this->assertNull(Shipment::where('consignment_id', '55501')->first()->delivered_after_superseded_at);

        $this->webhook(['consignment_id' => 55502, 'invoice' => '40001-2', 'delivery_status' => 'cancelled']);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ── What happens to the order ────────────────────────────────────────────

    public function test_a_courier_cancelled_order_booked_again_goes_back_to_booked_and_takes_its_stock_back(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $product = Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 500,
            'manage_stock' => true, 'stock_quantity' => 8, 'in_stock' => true,
        ]);
        $order = $this->order([], $product, 2);
        $this->book($order);

        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'cancelled']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $this->bookAgain($order)->assertSessionHas('success', fn ($m) => str_contains($m, 'to Booked with courier'));

        $this->assertSame('booked', $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'status' => 'booked',
            'note' => 'Re-booked with Steadfast: consignment 55502 replaces 55501 (COD '.money(1000).')',
        ]);
    }

    public function test_a_shipped_order_booked_again_keeps_its_status_and_notes_it_in_history(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $order->fresh()->update(['status' => 'shipped']);

        $this->bookAgain($order)->assertSessionHas('success',
            fn ($m) => str_contains($m, 'stays Shipped') && str_contains($m, 'NOT cancelled at Steadfast'));

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'status' => 'shipped',
            'note' => 'Re-booked with Steadfast: consignment 55502 replaces 55501 (COD '.money(1000).')',
        ]);
    }

    public function test_a_shipped_order_booked_again_can_print_its_new_label(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $order->fresh()->update(['status' => 'shipped']);
        $labelLink = e(route('admin.orders.labels', ['ids' => $order->id]));

        // One consignment, already on the road: nothing to print.
        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))->assertDontSee($labelLink, false);

        $this->bookAgain($order)->assertSessionHas('success');

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))->assertSee($labelLink, false);
        $this->actingAs($this->admin())->get(route('admin.orders.labels', ['ids' => $order->id]))
            ->assertOk()
            ->assertSee('TRK55502')
            ->assertSee('Inv 40001-2')
            ->assertDontSee('selected order(s) skipped');

        // Once the courier has picked the new parcel up, it leaves the queue.
        $this->webhook(['consignment_id' => 55502, 'invoice' => '40001-2', 'delivery_status' => 'pending']);
        $this->actingAs($this->admin())->get(route('admin.orders.labels'))->assertOk()->assertDontSee('TRK55502');
    }

    public function test_a_prepaid_consignment_is_not_booked_again_as_cash_on_delivery_without_a_confirmation(): void
    {
        // Paid up front, booked with COD 0, then cancelled at the courier —
        // which resets the order to unpaid, so booking it again would quietly
        // ask the rider to collect the full total a second time.
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order(['payment_status' => 'paid']);
        $this->book($order);
        $this->assertSame(0.0, (float) $order->fresh()->shipment->cod_amount);
        $this->webhook(['consignment_id' => 55501, 'invoice' => '40001', 'delivery_status' => 'cancelled']);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()->assertSee('name="confirm_cod"', false);

        $this->bookAgain($order)->assertSessionHas('error',
            fn ($m) => str_contains($m, 'prepaid') && str_contains($m, money(1000)));

        $this->assertSame(['40001'], $this->bookedInvoices());
        $this->assertSame(1, $order->shipments()->count());

        $this->bookAgain($order, null, ['confirm_cod' => 1])->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/create_order')
            && $r['invoice'] === '40001-2' && (float) $r['cod_amount'] === 1000.0);
    }

    public function test_a_stale_page_cannot_book_the_same_order_again(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $first = $order->fresh()->shipment;

        $this->bookAgain($order, $first->id)->assertSessionHas('success');
        // The same form, submitted again from a tab that still shows 55501.
        $this->bookAgain($order, $first->id)->assertSessionHas('error',
            fn ($m) => str_contains($m, 'already been booked again'));

        $this->assertSame(2, $order->shipments()->count());
    }

    public function test_the_first_booking_button_refuses_an_order_that_already_has_a_consignment(): void
    {
        $this->steadfast([self::accepts(55501)]);
        $order = $this->order();
        $this->book($order);

        $this->book($order)->assertSessionHas('error', fn ($m) => str_contains($m, 'Book again with courier'));

        $this->assertSame(1, $order->shipments()->count());
    }

    public function test_bulk_booking_still_skips_orders_already_booked_and_names_them(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $booked = $this->order();
        $this->book($booked);
        $fresh = $this->order(['order_number' => '40002']);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.bulk-steadfast'), ['ids' => [$booked->id, $fresh->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '#40001') && str_contains($m, 'Book again with courier'));

        $this->assertSame(1, $booked->shipments()->count());
        $this->assertSame('40002', $fresh->fresh()->shipment->invoice);
    }

    public function test_bulk_booking_does_not_book_an_order_booked_elsewhere_after_the_list_was_loaded(): void
    {
        $this->arm();
        $first = $this->order();
        $second = $this->order(['order_number' => '40002']);

        // While the bulk send is booking 40001, the order page books 40002.
        Http::fake(['*/create_order' => function (HttpRequest $request) use ($second) {
            if ($request['invoice'] === '40001') {
                Shipment::create([
                    'order_id' => $second->id, 'courier' => 'steadfast', 'consignment_id' => '55600',
                    'tracking_code' => 'TRK55600', 'invoice' => '40002', 'cod_amount' => 1000, 'status' => 'in_review',
                ]);
            }

            return Http::response(self::accepts($request['invoice'] === '40001' ? 55501 : 55502)['body']);
        }, '*' => Http::response([], 200)]);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.bulk-steadfast'), ['ids' => [$first->id, $second->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Sent 1 order(s)') && str_contains($m, '#40002'));

        $this->assertSame(['40001'], $this->bookedInvoices());
        $this->assertSame(1, $second->shipments()->count());
    }

    public function test_bulk_booking_skips_an_order_that_is_being_booked_at_that_moment(): void
    {
        $this->steadfast([self::accepts(55501)]);
        $order = $this->order();
        $held = Cache::lock('steadfast-booking:'.$order->id, 60);
        $this->assertTrue($held->get());

        $this->actingAs($this->admin())
            ->post(route('admin.orders.bulk-steadfast'), ['ids' => [$order->id]])
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '#40001') && str_contains($m, 'being booked'));

        $held->release();
        $this->assertSame([], $this->bookedInvoices());
        $this->assertSame(0, $order->shipments()->count());
    }

    // ── Counting ─────────────────────────────────────────────────────────────

    public function test_the_courier_tally_counts_a_rebooked_order_once(): void
    {
        $this->steadfast([self::accepts(55501), self::accepts(55502)]);
        $order = $this->order();
        $this->book($order);
        $this->bookAgain($order);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Courier outcomes across 1 shipment(s)');
    }

    public function test_the_confirmation_warns_harder_when_the_order_was_delivered(): void
    {
        $this->steadfast([self::accepts(55501)]);
        $order = $this->order();
        $this->book($order);
        $order->fresh()->update(['status' => 'delivered']);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('This order is marked DELIVERED', false)
            ->assertSee('is NOT cancelled at Steadfast by this', false);
    }
}
