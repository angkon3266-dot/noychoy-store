<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning a chased lead into a real order.
 *
 * She rings the number, the customer says yes, and until now the only way to
 * record it was to re-type a name, an address and every line by hand off the
 * lead page sitting in the next tab. "Convert to order" carries the basket into
 * the manual order form instead.
 *
 * The form is the review step on purpose: a snapshot is what the customer saw,
 * not what is true now, so anything that cannot be carried over is said on the
 * page rather than thrown on save.
 */
class AbandonedCartConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function product(array $attrs = []): Product
    {
        $cat = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings', 'is_active' => true]);

        return Product::create(array_merge([
            'name' => 'Pearl Drop Necklace',
            'slug' => 'pearl-drop-'.uniqid(),
            'sku' => 'PDN-1',
            'price' => 1850,
            'status' => 'published',
            'category_id' => $cat->id,
            'in_stock' => true,
            'manage_stock' => true,
            'stock_quantity' => 10,
        ], $attrs));
    }

    protected function cart(array $items, array $attrs = []): AbandonedCart
    {
        return AbandonedCart::create(array_merge([
            'session_id' => 'sess-'.uniqid(),
            'name' => 'Farida Bari',
            'phone' => '01712345678',
            'email' => 'farida@example.com',
            'address' => 'House 8, Road 3, Lalmatia',
            'area' => 'Lalmatia',
            'is_inside_dhaka' => true,
            'items' => $items,
            'subtotal' => collect($items)->sum(fn ($i) => $i['price'] * $i['qty']),
            'item_count' => count($items),
        ], $attrs));
    }

    // ── The form arrives filled in ───────────────────────────────────────────

    public function test_convert_opens_the_order_form_with_her_details_and_basket(): void
    {
        $p = $this->product();
        $cart = $this->cart([
            ['product_id' => $p->id, 'name' => $p->name, 'qty' => 2, 'price' => 1850],
        ]);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id)
            ->assertOk()
            ->assertSee('Farida Bari')
            ->assertSee('01712345678')
            ->assertSee('House 8, Road 3, Lalmatia')
            ->assertSee('Converting');

        // The basket rides in as the seeded line, quantity and all. Asserted on
        // the view data rather than the rendered JSON, which Blade escapes.
        $prefill = $res->viewData('prefill');
        $this->assertCount(1, $prefill['lines']);
        $this->assertSame($p->id, $prefill['lines'][0]['product_id']);
        $this->assertSame(2, $prefill['lines'][0]['qty']);
        $this->assertTrue($prefill['customer']['is_inside_dhaka']);
    }

    public function test_the_price_carried_over_is_the_one_the_customer_was_shown(): void
    {
        // She is ringing to honour the figure in the basket, not today's.
        $p = $this->product(['price' => 2400]);
        $cart = $this->cart([
            ['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 1850],
        ]);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id)
            ->assertOk();

        $this->assertSame(1850.0, $res->viewData('prefill')['lines'][0]['price']);
    }

    public function test_a_piece_no_longer_on_sale_is_left_off_and_said_plainly(): void
    {
        $live = $this->product(['name' => 'Still Sold']);
        $gone = $this->product(['name' => 'Withdrawn Piece', 'status' => 'draft']);

        $cart = $this->cart([
            ['product_id' => $live->id, 'name' => $live->name, 'qty' => 1, 'price' => 1850],
            ['product_id' => $gone->id, 'name' => $gone->name, 'qty' => 1, 'price' => 900],
        ]);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id)
            ->assertOk()
            ->assertSee('Withdrawn Piece is no longer on sale');

        // Only the line that can still be sold was seeded.
        $lines = $res->viewData('prefill')['lines'];
        $this->assertCount(1, $lines);
        $this->assertSame($live->id, $lines[0]['product_id']);
    }

    public function test_a_short_stock_line_is_flagged_before_she_rings(): void
    {
        $p = $this->product(['name' => 'Last One', 'stock_quantity' => 1]);
        $cart = $this->cart([
            ['product_id' => $p->id, 'name' => $p->name, 'qty' => 3, 'price' => 1850],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id)
            ->assertOk()
            ->assertSee('only 1 left, and the basket has 3');
    }

    public function test_a_variation_is_carried_through_so_the_right_size_is_sold(): void
    {
        $p = $this->product(['name' => 'Opal Band', 'has_variants' => true]);
        $variant = ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'price' => 1999, 'stock_quantity' => 4, 'is_active' => true,
        ]);

        $cart = $this->cart([
            ['product_id' => $p->id, 'variant_id' => $variant->id, 'name' => $p->name, 'qty' => 1, 'price' => 1999],
        ]);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id)
            ->assertOk();

        $line = $res->viewData('prefill')['lines'][0];
        $this->assertSame($variant->id, $line['variant_id']);
        $this->assertSame('Size: 8', $line['variation']);
    }

    public function test_a_variation_that_has_since_been_deactivated_is_flagged(): void
    {
        $p = $this->product(['name' => 'Opal Band', 'has_variants' => true]);
        $variant = ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'price' => 1999, 'stock_quantity' => 4, 'is_active' => false,
        ]);

        $cart = $this->cart([
            ['product_id' => $p->id, 'variant_id' => $variant->id, 'name' => $p->name, 'qty' => 1, 'price' => 1999],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id)
            ->assertOk()
            ->assertSee('the option they chose is gone');
    }

    // ── Saving it ────────────────────────────────────────────────────────────

    public function test_saving_creates_the_order_and_settles_the_lead(): void
    {
        $p = $this->product();
        $cart = $this->cart([
            ['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 1850],
        ]);

        $this->actingAs($this->admin())->post('/admin/orders/create', [
            'name' => 'Farida Bari',
            'phone' => '01712345678',
            'address' => 'House 8, Road 3, Lalmatia',
            'is_inside_dhaka' => 1,
            'shipping_cost' => 70,
            'abandoned_cart_id' => $cart->id,
            'lines' => [['product_id' => $p->id, 'qty' => 1, 'price' => 1850]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();

        $this->assertNotNull($order);
        $this->assertSame($cart->id, $order->abandoned_cart_id, 'the order should remember the lead it came from');
        $this->assertTrue($cart->fresh()->recovered, 'the lead should be marked recovered');
        $this->assertSame($order->id, $cart->fresh()->recoveredOrder->id);
    }

    public function test_an_order_taken_by_hand_settles_a_matching_lead_even_without_the_button(): void
    {
        // The gap this closes: before, only a storefront checkout marked a cart
        // recovered, so a sale closed on the phone left the lead in the queue
        // and the follow-up list kept chasing a customer who had already bought.
        $p = $this->product();
        $cart = $this->cart([
            ['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 1850],
        ]);

        $this->actingAs($this->admin())->post('/admin/orders/create', [
            'name' => 'Farida Bari',
            'phone' => '01712345678',
            'address' => 'House 8, Road 3, Lalmatia',
            'lines' => [['product_id' => $p->id, 'qty' => 1, 'price' => 1850]],
        ])->assertRedirect();

        $cart = $cart->fresh();
        $this->assertTrue($cart->recovered);
        // Nothing to point at: she never went through the convert button.
        $this->assertNull($cart->recoveredOrder);
    }

    public function test_an_unrelated_lead_is_left_alone(): void
    {
        $p = $this->product();
        $other = $this->cart(
            [['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 1850]],
            ['phone' => '01999999999', 'session_id' => 'other'],
        );

        $this->actingAs($this->admin())->post('/admin/orders/create', [
            'name' => 'Farida Bari',
            'phone' => '01712345678',
            'address' => 'House 8, Road 3, Lalmatia',
            'lines' => [['product_id' => $p->id, 'qty' => 1, 'price' => 1850]],
        ])->assertRedirect();

        $this->assertFalse($other->fresh()->recovered);
    }

    public function test_the_lead_page_offers_the_button_and_links_the_order_once_converted(): void
    {
        $p = $this->product();
        $cart = $this->cart([
            ['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 1850],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/abandoned-carts/'.$cart->id)
            ->assertOk()
            ->assertSee('Convert to order');

        $order = Order::create([
            'order_number' => '30009', 'customer_name' => 'Farida Bari', 'customer_phone' => '01712345678',
            'shipping_address' => 'Lalmatia', 'subtotal' => 1850, 'shipping_cost' => 70, 'discount' => 0,
            'total' => 1920, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'confirmed', 'source' => 'admin', 'abandoned_cart_id' => $cart->id,
        ]);
        $cart->forceFill(['recovered' => true])->save();

        $this->actingAs($this->admin())
            ->get('/admin/abandoned-carts/'.$cart->id)
            ->assertOk()
            ->assertSee('became order 30009')
            ->assertDontSee('Convert to order');

        $this->actingAs($this->admin())
            ->get('/admin/abandoned-carts')
            ->assertOk()
            ->assertSee('Order 30009');
    }
}
