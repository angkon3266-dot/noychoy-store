<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a returning customer has bought before, in the two places the owner
 * asks the question (2026-09-24).
 *
 * On an order: "she is on the phone about this one — what else has she had from
 * us?" Every other order on the number folds open on the order page itself,
 * each one onto its own lines.
 *
 * On the customer: "what has she got from us so far?" — one line per piece,
 * with the running totals, instead of opening every order in turn.
 *
 * Cancelled and returned orders are left out of the customer's product list,
 * the rule her spend and order counts already follow (Order::NOT_SALES): a
 * parcel refused at the door was never bought. They still appear among the
 * earlier orders on an order page, where the question is delivery history.
 */
class CustomerPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Nusrat Jahan',
            'phone' => '01712345678',
            'total_orders' => 2,
            'total_spent' => 5200,
        ], $attrs));
    }

    protected function product(string $name, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'price' => 1850,
            'status' => 'published',
            'in_stock' => true,
        ], $attrs));
    }

    /**
     * @param  array<int, array{0: ?Product, 1: string, 2: int, 3: float}>  $lines  [product, name, qty, subtotal]
     */
    protected function order(?Customer $customer, string $number, array $lines, array $attrs = [], ?string $placedAt = null): Order
    {
        $order = Order::create(array_merge([
            'order_number' => $number,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? 'Rahim',
            'customer_phone' => $customer?->phone ?? '01712345678',
            'shipping_address' => 'House 12, Dhanmondi, Dhaka',
            'subtotal' => 1850, 'shipping_cost' => 70, 'discount' => 0, 'total' => 1920,
            'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'delivered', 'source' => 'web',
        ], $attrs));

        foreach ($lines as [$product, $name, $qty, $subtotal]) {
            $order->items()->create([
                'product_id' => $product?->id,
                'name' => $name,
                'price' => $subtotal / max($qty, 1),
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ]);
        }

        // created_at is not mass assignable.
        if ($placedAt) {
            $order->forceFill(['created_at' => $placedAt])->save();
        }

        return $order;
    }

    // ── On an order: the customer's earlier orders ───────────────────────────

    public function test_an_order_lists_the_customers_earlier_orders_and_what_was_in_them(): void
    {
        $nusrat = $this->customer();
        $ring = $this->product('Pearl Drop Necklace');

        $this->order($nusrat, 'NOY-1001', [[$ring, 'Pearl Drop Necklace', 2, 3700]], [], '2026-08-01 10:00:00');
        $current = $this->order($nusrat, 'NOY-1002', [[$ring, 'Pearl Drop Necklace', 1, 1850]], ['status' => 'pending']);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $current))
            ->assertOk()
            ->assertSee('Earlier orders')
            ->assertSee('NOY-1001')
            // The lines of that earlier order, open on this page.
            ->assertSee('Pearl Drop Necklace')
            ->assertSee('× 2', false);
    }

    public function test_a_first_order_has_no_earlier_orders_panel(): void
    {
        $rafi = $this->customer(['name' => 'Rafi Ahmed', 'phone' => '01812345678']);
        $order = $this->order($rafi, 'NOY-2001', [[$this->product('Gold Bangle'), 'Gold Bangle', 1, 4200]]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Earlier orders');
    }

    public function test_another_customers_orders_are_not_shown_as_history(): void
    {
        $nusrat = $this->customer();
        $rafi = $this->customer(['name' => 'Rafi Ahmed', 'phone' => '01812345678']);

        $this->order($rafi, 'NOY-3001', [[$this->product('Silver Anklet'), 'Silver Anklet', 1, 900]]);
        $current = $this->order($nusrat, 'NOY-3002', [[$this->product('Gold Bangle'), 'Gold Bangle', 1, 4200]]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $current))
            ->assertOk()
            ->assertDontSee('NOY-3001');

        // Not by name in the HTML: the admin bell names every out-of-stock
        // product, whoever bought it.
        $this->assertTrue($response->viewData('insight')['orders']->isEmpty());
    }

    // ── On the customer: everything bought so far ────────────────────────────

    public function test_the_customer_page_lists_every_product_bought_with_its_totals(): void
    {
        $nusrat = $this->customer();
        $necklace = $this->product('Pearl Drop Necklace');
        $bangle = $this->product('Gold Bangle', ['price' => 4200]);

        $this->order($nusrat, 'NOY-4001', [[$necklace, 'Pearl Drop Necklace', 2, 3700]], [], '2026-08-01 10:00:00');
        $this->order($nusrat, 'NOY-4002', [[$necklace, 'Pearl Drop Necklace', 1, 1850]], [], '2026-09-01 10:00:00');
        $this->order($nusrat, 'NOY-4003', [[$bangle, 'Gold Bangle', 1, 4200]], [], '2026-09-10 10:00:00');

        $response = $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $nusrat))
            ->assertOk()
            ->assertSee('Products bought')
            ->assertSee('Gold Bangle')
            ->assertSee('Pearl Drop Necklace')
            // One line per product, gathered across both of its orders.
            ->assertSee('3 pieces across 2 orders')
            ->assertSee('1 piece across 1 order')
            // The product opens in the editor from here.
            ->assertSee(route('admin.products.edit', $necklace));

        // Newest purchase first, and the running totals are per product.
        $purchases = $response->viewData('purchases');
        $this->assertSame(['Gold Bangle', 'Pearl Drop Necklace'], $purchases->pluck('name')->all());
        $this->assertSame(3, $purchases->firstWhere('name', 'Pearl Drop Necklace')['quantity']);
        $this->assertSame(5550.0, $purchases->firstWhere('name', 'Pearl Drop Necklace')['spent']);
    }

    public function test_a_cancelled_order_is_not_counted_as_bought(): void
    {
        $nusrat = $this->customer();
        $refused = $this->product('Ruby Studs');

        $this->order($nusrat, 'NOY-5001', [[$refused, 'Ruby Studs', 1, 2200]], ['status' => 'cancelled']);
        $this->order($nusrat, 'NOY-5002', [[$this->product('Gold Bangle'), 'Gold Bangle', 1, 4200]]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $nusrat))
            ->assertOk()
            ->assertSee('Gold Bangle');

        // The cancelled order is still in the order history table below — it is
        // only the list of pieces she owns that leaves it out. (The page names
        // every product elsewhere, in the offer form's picker, so the list is
        // read from the page's own data rather than from the HTML.)
        $this->assertSame(['Gold Bangle'], $response->viewData('purchases')->pluck('name')->all());
        $response->assertSee('NOY-5001');
    }

    public function test_a_product_deleted_since_still_reads_as_what_was_sold(): void
    {
        $nusrat = $this->customer();
        $gone = $this->product('Discontinued Cuff');
        $this->order($nusrat, 'NOY-6001', [[$gone, 'Discontinued Cuff', 1, 1500]]);
        $gone->delete();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $nusrat))
            ->assertOk()
            ->assertSee('Discontinued Cuff')
            // Nothing left to open in the editor.
            ->assertDontSee(route('admin.products.edit', $gone));

        $this->assertNull($response->viewData('purchases')->first()['product']);
    }

    public function test_the_products_bought_list_shows_the_pictures(): void
    {
        $nusrat = $this->customer();
        $necklace = $this->product('Pearl Drop Necklace');
        ProductImage::create([
            'product_id' => $necklace->id, 'path' => 'products/hero.webp', 'position' => 1, 'is_primary' => true,
        ]);

        $this->order($nusrat, 'NOY-7001', [[$necklace, 'Pearl Drop Necklace', 1, 1850]]);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $nusrat))
            ->assertOk()
            ->assertSee('products/hero.webp', false);
    }

    public function test_a_customer_who_has_bought_nothing_says_so(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $this->customer(['total_orders' => 0, 'total_spent' => 0])))
            ->assertOk()
            ->assertSee('Nothing bought yet');
    }
}
