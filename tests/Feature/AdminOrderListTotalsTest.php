<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the orders list adds up to, and what is inside it.
 *
 * The list could say how many orders were in each status but never what they
 * were worth, and the fulfilment panel beside it was pinned to "processing" —
 * so standing on a list of booked orders it answered a question about a
 * different set entirely, and read as empty exactly when she was looking at
 * the queue she meant to pack.
 */
class AdminOrderListTotalsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function product(string $name): Product
    {
        $cat = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings', 'is_active' => true]);

        return Product::create([
            'name' => $name, 'slug' => str()->slug($name).'-'.uniqid(), 'sku' => 'SKU-'.++$this->seq,
            'price' => 1000, 'status' => 'published', 'category_id' => $cat->id, 'in_stock' => true,
        ]);
    }

    /** An order with one line of the given product. */
    protected function order(string $number, string $status, Product $p, int $qty, float $total, string $name = 'Buyer'): Order
    {
        $order = Order::create([
            'order_number' => $number, 'customer_name' => $name,
            'customer_phone' => '01711'.str_pad((string) ++$this->seq, 6, '0', STR_PAD_LEFT),
            'shipping_address' => 'X', 'subtotal' => $total, 'shipping_cost' => 0, 'discount' => 0,
            'total' => $total, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => $status, 'source' => 'web',
        ]);

        $order->items()->create([
            'product_id' => $p->id, 'name' => $p->name, 'price' => $total / max(1, $qty),
            'quantity' => $qty, 'subtotal' => $total,
        ]);

        return $order;
    }

    // ── The totals strip ─────────────────────────────────────────────────────

    public function test_the_strip_totals_the_orders_on_the_page(): void
    {
        $ring = $this->product('Kyra Ring');
        $this->order('40001', 'booked', $ring, 1, 1190);
        $this->order('40002', 'booked', $ring, 2, 2360);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders?status=booked')
            ->assertOk();

        $totals = $res->viewData('pageTotals');
        $this->assertSame(2, $totals['orders']);
        // Line items, so the strip reconciles with the Items column it sits
        // above — the three *pieces* are what the prepare panel counts.
        $this->assertSame(2, $totals['items']);
        $this->assertSame(3550.0, $totals['value'], 'value is what the courier brings back');

        $this->assertSame(3, (int) $res->viewData('processingItems')->sum('qty'));
    }

    public function test_the_totals_follow_the_filter(): void
    {
        $ring = $this->product('Kyra Ring');
        $this->order('40001', 'booked', $ring, 1, 1190);
        $this->order('40002', 'processing', $ring, 1, 500);

        $booked = $this->actingAs($this->admin())->get('/admin/orders?status=booked')->assertOk();
        $this->assertSame(1, $booked->viewData('pageTotals')['orders']);
        $this->assertSame(1190.0, $booked->viewData('pageTotals')['value']);

        $all = $this->actingAs($this->admin())->get('/admin/orders?status=all')->assertOk();
        $this->assertSame(2, $all->viewData('pageTotals')['orders']);
        $this->assertSame(1690.0, $all->viewData('pageTotals')['value']);
    }

    public function test_a_trashed_order_is_left_out_of_the_totals(): void
    {
        $ring = $this->product('Kyra Ring');
        $this->order('40001', 'booked', $ring, 1, 1190);
        $this->order('40002', 'booked', $ring, 1, 800)->delete();

        $res = $this->actingAs($this->admin())->get('/admin/orders?status=booked')->assertOk();

        $this->assertSame(1, $res->viewData('pageTotals')['orders']);
        $this->assertSame(1190.0, $res->viewData('pageTotals')['value']);
    }

    // ── The fulfilment panel ─────────────────────────────────────────────────

    public function test_the_prepare_panel_follows_the_selected_filter(): void
    {
        $booked = $this->product('Booked Piece');
        $processing = $this->product('Processing Piece');

        $this->order('40001', 'booked', $booked, 3, 1190);
        $this->order('40002', 'processing', $processing, 1, 500);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders?status=booked')
            ->assertOk();

        $queue = $res->viewData('processingItems');
        $this->assertCount(1, $queue, 'only the pieces inside the filtered orders');
        $this->assertSame('Booked Piece', $queue->first()->name);
        $this->assertSame(3, (int) $queue->first()->qty);
        $this->assertSame('Booked with courier', $res->viewData('queueLabel'));
    }

    public function test_the_panel_adds_up_the_same_piece_across_orders(): void
    {
        $ring = $this->product('Kyra Ring');
        $this->order('40001', 'booked', $ring, 2, 1000);
        $this->order('40002', 'booked', $ring, 3, 1500);

        $queue = $this->actingAs($this->admin())
            ->get('/admin/orders?status=booked')
            ->assertOk()
            ->viewData('processingItems');

        $this->assertCount(1, $queue);
        $this->assertSame(5, (int) $queue->first()->qty);
        $this->assertSame(2, (int) $queue->first()->orders);
    }

    public function test_the_panel_narrows_with_the_search_box(): void
    {
        $hers = $this->product('Her Piece');
        $his = $this->product('His Piece');

        $this->order('40001', 'booked', $hers, 1, 1000, 'Farida');
        $this->order('40002', 'booked', $his, 1, 1000, 'Rahim');

        $queue = $this->actingAs($this->admin())
            ->get('/admin/orders?status=booked&q=Farida')
            ->assertOk()
            ->viewData('processingItems');

        $this->assertCount(1, $queue);
        $this->assertSame('Her Piece', $queue->first()->name);
    }

    public function test_the_panel_covers_every_unfinished_status_when_the_list_does(): void
    {
        $a = $this->product('Piece A');
        $b = $this->product('Piece B');

        $this->order('40001', 'booked', $a, 1, 1000);
        $this->order('40002', 'cancelled', $b, 1, 1000);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders?status=all')
            ->assertOk();

        $this->assertCount(2, $res->viewData('processingItems'));
        $this->assertSame('All orders', $res->viewData('queueLabel'));
    }

    // ── Delivered parcels are finished work ──────────────────────────────────

    public function test_a_delivered_parcel_is_not_something_left_to_pack(): void
    {
        $waiting = $this->product('Waiting Piece');
        $done = $this->product('Delivered Piece');

        $this->order('40001', 'booked', $waiting, 1, 1000);
        $this->order('40002', 'delivered', $done, 4, 1000);

        $queue = $this->actingAs($this->admin())
            ->get('/admin/orders?status=all')
            ->assertOk()
            ->viewData('processingItems');

        $this->assertCount(1, $queue);
        $this->assertSame('Waiting Piece', $queue->first()->name);
        $this->assertSame(1, (int) $queue->sum('qty'), 'the four delivered pieces are not waiting on anyone');
    }

    public function test_the_delivered_filter_shows_an_empty_queue_rather_than_finished_work(): void
    {
        $done = $this->product('Delivered Piece');
        $this->order('40001', 'delivered', $done, 2, 1000);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders?status=delivered')
            ->assertOk();

        // The order is still in the table — only the "to prepare" panel treats
        // it as finished.
        $this->assertSame(1, $res->viewData('pageTotals')['orders']);
        $this->assertCount(0, $res->viewData('processingItems'));
    }

    public function test_a_partly_delivered_parcel_still_needs_settling(): void
    {
        $part = $this->product('Part Returned Piece');
        $this->order('40001', 'partially_delivered', $part, 2, 1000);

        $queue = $this->actingAs($this->admin())
            ->get('/admin/orders?status=all')
            ->assertOk()
            ->viewData('processingItems');

        $this->assertCount(1, $queue);
        $this->assertSame(2, (int) $queue->first()->qty);
    }

    public function test_a_trashed_order_is_not_in_the_prepare_queue(): void
    {
        $live = $this->product('Live Piece');
        $binned = $this->product('Binned Piece');

        $this->order('40001', 'booked', $live, 1, 1000);
        $this->order('40002', 'booked', $binned, 1, 1000)->delete();

        $queue = $this->actingAs($this->admin())
            ->get('/admin/orders?status=booked')
            ->assertOk()
            ->viewData('processingItems');

        $this->assertCount(1, $queue);
        $this->assertSame('Live Piece', $queue->first()->name);
    }
}
