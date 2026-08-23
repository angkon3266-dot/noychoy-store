<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\AdminAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The things that stopped the owner running the shop from the admin panel.
 *
 * She is not a developer: anything that needs SSH, a database client or a log
 * file is, from her side, simply broken. These cover the daily ones — fixing a
 * wrong address, a screen that crashed, and finding out when the plumbing fails.
 */
class AdminUsabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@admin.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'NOY-'.random_int(100000, 999999),
            'customer_name' => 'Buyer',
            'customer_phone' => '01711111111',
            'shipping_address' => 'Wrong address',
            'status' => 'processing',
            'subtotal' => 3000, 'total' => 3000,
        ], $attrs));
    }

    // ── Correcting a delivery address ──────────────────────────────────────

    public function test_the_owner_can_correct_a_wrong_address(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->post(route('admin.orders.details', $order), [
                'customer_name' => 'Shamim Mostafa',
                'customer_phone' => '01860988859',
                'shipping_address' => 'Flat 4B, Norshinghapur, Ashulia',
                'area' => 'Ashulia',
                'district' => 'Dhaka',
                'is_inside_dhaka' => '1',
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('Flat 4B, Norshinghapur, Ashulia', $order->shipping_address);
        $this->assertSame('01860988859', $order->customer_phone);
        $this->assertTrue((bool) $order->is_inside_dhaka);

        // Who changed a delivery address, and when, is exactly what you want
        // on a disputed parcel.
        $this->assertStringContainsString(
            'Delivery details corrected',
            $order->history()->latest('id')->first()->note
        );
    }

    public function test_a_booked_parcel_cannot_have_its_address_changed_underneath_it(): void
    {
        $order = $this->order();
        $order->shipment()->create([
            'consignment_id' => 'CID-1', 'tracking_code' => 'TRK-1', 'status' => 'in_review',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.details', $order), [
                'customer_name' => 'Someone Else',
                'customer_phone' => '01860988859',
                'shipping_address' => 'A different address entirely',
            ])->assertRedirect();

        // The courier holds its own copy; letting these drift apart is worse
        // than refusing the edit.
        $this->assertSame('Wrong address', $order->fresh()->shipping_address);
    }

    public function test_a_bad_phone_number_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->post(route('admin.orders.details', $order), [
                'customer_name' => 'Buyer',
                'customer_phone' => '12345',
                'shipping_address' => 'Somewhere',
            ])->assertSessionHasErrors('customer_phone');
    }

    // ── The screen that crashed ────────────────────────────────────────────

    public function test_the_reviews_screen_survives_a_deleted_product(): void
    {
        $product = Product::create([
            'name' => 'Doomed Ring', 'slug' => 'doomed-ring', 'status' => 'published',
            'price' => 1000, 'manage_stock' => false,
        ]);

        Review::create([
            'product_id' => $product->id, 'author_name' => 'B', 'rating' => 5, 'status' => 'pending',
        ]);

        // Products soft-delete, so the review survives with a null relation —
        // and route(..., null) threw, taking the whole screen down.
        $product->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index'))
            ->assertOk()
            ->assertSee('deleted product');
    }

    // ── Knowing when the plumbing breaks ───────────────────────────────────

    public function test_the_owner_is_told_when_sms_is_silently_dropping_messages(): void
    {
        Cache::flush();

        // The gateway is off, but the shop kept "sending" — every one of these
        // went nowhere and nothing said so.
        foreach (range(1, 3) as $i) {
            SmsLog::create([
                'phone' => '0171111111'.$i, 'message' => 'x', 'direction' => 'out',
                'status' => 'disabled',
            ]);
        }

        $titles = app(AdminAlerts::class)->all()->pluck('title')->implode(' | ');

        $this->assertStringContainsString('SMS is not sending', $titles);
    }

    public function test_the_owner_is_told_when_orders_never_reached_the_courier(): void
    {
        Cache::flush();

        $order = $this->order(['status' => 'processing']);
        $order->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $titles = app(AdminAlerts::class)->all()->pluck('title')->implode(' | ');

        $this->assertStringContainsString('no courier consignment', $titles);
    }

    public function test_a_healthy_shop_raises_no_integration_alarms(): void
    {
        Cache::flush();

        // Nothing sent, nothing stuck — the alerts must stay quiet, or the
        // owner learns to ignore them.
        $titles = app(AdminAlerts::class)->all()->pluck('title')->implode(' | ');

        $this->assertStringNotContainsString('SMS is not sending', $titles);
        $this->assertStringNotContainsString('no courier consignment', $titles);
    }

    // ── Taking an order by hand ────────────────────────────────────────────

    public function test_the_owner_can_record_a_messenger_sale(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $product = Product::create([
            'name' => 'DM Ring', 'slug' => 'dm-ring', 'status' => 'published',
            'price' => 5000, 'manage_stock' => true, 'stock_quantity' => 4,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.store-manual'), [
                'name' => 'DM Buyer',
                'phone' => '01860988859',
                'address' => 'Banani, Dhaka',
                'is_inside_dhaka' => '1',
                'shipping_cost' => 70,
                'discount' => 0,
                'status' => 'confirmed',
                'lines' => [
                    ['product_id' => $product->id, 'qty' => 2, 'price' => null],
                ],
            ])->assertRedirect();

        $order = Order::latest('id')->first();

        $this->assertSame('DM Buyer', $order->customer_name);
        $this->assertSame(10070.0, (float) $order->total);
        $this->assertSame('admin', $order->source_channel, 'a DM sale must be distinguishable from a storefront one');
        $this->assertSame(2, (int) $order->items->sum('quantity'));

        // Stock comes off, exactly as it would from the storefront.
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);

        // And the customer still gets their confirmation.
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendOrderPlacedEffects::class);
    }

    public function test_a_hand_typed_price_is_honoured(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $product = Product::create([
            'name' => 'Negotiated Ring', 'slug' => 'negotiated-ring', 'status' => 'published',
            'price' => 5000, 'manage_stock' => false,
        ]);

        // She agreed a figure on the phone.
        $this->actingAs($this->admin())
            ->post(route('admin.orders.store-manual'), [
                'name' => 'Buyer', 'phone' => '01860988859', 'address' => 'Dhaka',
                'lines' => [['product_id' => $product->id, 'qty' => 1, 'price' => 4200]],
            ])->assertRedirect();

        $this->assertSame(4200.0, (float) Order::latest('id')->first()->items->first()->price);
    }

    public function test_a_manual_order_cannot_oversell(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $product = Product::create([
            'name' => 'Last One', 'slug' => 'last-one', 'status' => 'published',
            'price' => 5000, 'manage_stock' => true, 'stock_quantity' => 1,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.store-manual'), [
                'name' => 'Buyer', 'phone' => '01860988859', 'address' => 'Dhaka',
                'lines' => [['product_id' => $product->id, 'qty' => 5, 'price' => null]],
            ])->assertRedirect();

        $this->assertSame(0, Order::count(), 'an order was written for stock that does not exist');
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }

    public function test_the_new_order_screen_opens(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('New order');
    }

    // ── Amending an order's items ──────────────────────────────────────────

    protected function orderWithItem(int $stock = 10, int $qty = 2): array
    {
        $product = Product::create([
            'name' => 'Amend Ring '.uniqid(), 'slug' => 'amend-'.uniqid(), 'status' => 'published',
            'price' => 1000, 'manage_stock' => true, 'stock_quantity' => $stock,
        ]);

        $order = $this->order(['subtotal' => 1000 * $qty, 'total' => 1000 * $qty]);

        $item = $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name,
            'price' => 1000, 'quantity' => $qty, 'subtotal' => 1000 * $qty,
        ]);

        return [$order, $product, $item];
    }

    public function test_raising_a_quantity_takes_the_extra_units_off_the_shelf(): void
    {
        [$order, $product, $item] = $this->orderWithItem(stock: 10, qty: 2);

        $this->actingAs($this->admin())->post(route('admin.orders.amend', $order), [
            'items' => [['id' => $item->id, 'price' => 1000, 'quantity' => 5]],
            'shipping_cost' => 0, 'discount' => 0,
        ])->assertRedirect();

        // Three more units are now committed to this order. Before, amending a
        // quantity left stock untouched entirely.
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);
        $this->assertSame(5000.0, (float) $order->fresh()->total);
    }

    public function test_removing_an_item_puts_its_units_back(): void
    {
        [$order, $product, $item] = $this->orderWithItem(stock: 10, qty: 2);

        $second = Product::create([
            'name' => 'Keeper', 'slug' => 'keeper-'.uniqid(), 'status' => 'published',
            'price' => 500, 'manage_stock' => false,
        ]);
        $keep = $order->items()->create([
            'product_id' => $second->id, 'name' => 'Keeper',
            'price' => 500, 'quantity' => 1, 'subtotal' => 500,
        ]);

        // The removed line is simply absent from the payload.
        $this->actingAs($this->admin())->post(route('admin.orders.amend', $order), [
            'items' => [['id' => $keep->id, 'price' => 500, 'quantity' => 1]],
            'shipping_cost' => 0, 'discount' => 0,
        ])->assertRedirect();

        $this->assertSame(12, (int) $product->fresh()->stock_quantity, 'the removed units never went back');
        $this->assertSame(1, $order->fresh()->items()->count());
        unset($item);
    }

    public function test_a_product_can_be_added_to_an_existing_order(): void
    {
        [$order, , $item] = $this->orderWithItem(stock: 10, qty: 1);

        $extra = Product::create([
            'name' => 'Matching Earrings', 'slug' => 'earrings-'.uniqid(), 'status' => 'published',
            'price' => 800, 'manage_stock' => true, 'stock_quantity' => 4,
        ]);

        $this->actingAs($this->admin())->post(route('admin.orders.amend', $order), [
            'items' => [['id' => $item->id, 'price' => 1000, 'quantity' => 1]],
            'new_lines' => [['product_id' => $extra->id, 'qty' => 2, 'price' => null]],
            'shipping_cost' => 0, 'discount' => 0,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(2600.0, (float) $order->total);
        $this->assertSame(2, (int) $extra->fresh()->stock_quantity, 'the added units were not taken');
    }

    public function test_an_order_cannot_be_emptied_of_every_item(): void
    {
        [$order, , ] = $this->orderWithItem();

        $this->actingAs($this->admin())->post(route('admin.orders.amend', $order), [
            'items' => [], 'shipping_cost' => 0, 'discount' => 0,
        ])->assertSessionHas('error');

        $this->assertSame(1, $order->fresh()->items()->count());
    }

    public function test_amending_a_cancelled_order_does_not_invent_stock(): void
    {
        [$order, $product, $item] = $this->orderWithItem(stock: 10, qty: 2);

        // Cancelling already put the units back.
        $order->update(['status' => 'cancelled', 'stock_restored' => true]);

        $this->actingAs($this->admin())->post(route('admin.orders.amend', $order), [
            'items' => [['id' => $item->id, 'price' => 1000, 'quantity' => 5]],
            'shipping_cost' => 0, 'discount' => 0,
        ])->assertRedirect();

        $this->assertSame(10, (int) $product->fresh()->stock_quantity, 'stock moved on an order that no longer holds any');
    }

    // ── The COD money trail ────────────────────────────────────────────────

    public function test_delivering_an_order_records_the_money_as_collected(): void
    {
        [$order, , ] = $this->orderWithItem();

        $this->assertSame('unpaid', $order->fresh()->payment_status);

        app(\App\Actions\TransitionOrderStatus::class)->handle($order, 'delivered');

        // On cash on delivery, delivered IS paid — the courier took the money.
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_returned_parcel_is_not_left_marked_paid(): void
    {
        [$order, , ] = $this->orderWithItem();
        $transition = app(\App\Actions\TransitionOrderStatus::class);

        $transition->handle($order, 'delivered');
        $transition->handle($order->fresh(), 'returned');

        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_the_owner_can_set_the_payment_status_by_hand(): void
    {
        [$order, , ] = $this->orderWithItem();

        $this->actingAs($this->admin())
            ->post(route('admin.orders.payment', $order), ['payment_status' => 'refunded'])
            ->assertRedirect();

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertStringContainsString('Payment marked refunded', $order->history()->latest('id')->first()->note);
    }

    public function test_a_refund_is_not_overwritten_by_a_later_delivery(): void
    {
        [$order, , ] = $this->orderWithItem();
        $order->update(['payment_status' => 'refunded']);

        app(\App\Actions\TransitionOrderStatus::class)->handle($order, 'delivered');

        $this->assertSame('refunded', $order->fresh()->payment_status);
    }
}
