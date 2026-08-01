<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkout re-validates availability, not just stock counts.
 *
 * The product page has always hidden "Add to cart" for an unavailable product,
 * but nothing re-checked it at checkout — so an item that went sold out (or a
 * variant that was retired) *while sitting in a cart* still went through.
 */
class CheckoutAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 1000, 'manage_stock' => false, 'in_stock' => true,
        ], $attrs));
    }

    protected function checkout(array $o = [])
    {
        return $this->post('/checkout', array_merge([
            'name' => 'B', 'phone' => '01712345678', 'address' => '1 Rd', 'is_inside_dhaka' => 1,
        ], $o));
    }

    public function test_item_marked_sold_out_while_in_the_cart_is_rejected(): void
    {
        $p = $this->product(['manage_stock' => false, 'in_stock' => true]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        $p->update(['in_stock' => false]);   // admin marks it sold out

        $this->checkout()->assertRedirect('/cart');
        $this->assertSame(0, Order::count());
    }

    public function test_a_retired_variant_in_the_cart_is_rejected(): void
    {
        $p = $this->product(['has_variants' => true]);
        $v = ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'V1', 'attributes' => ['Size' => 'M'],
            'price' => 1000, 'stock_quantity' => 5, 'is_active' => true,
        ]);
        $this->post('/cart/add/'.$p->slug, ['variant_id' => $v->id, 'qty' => 1]);

        $v->update(['is_active' => false]);

        $this->checkout()->assertRedirect('/cart');
        $this->assertSame(0, Order::count());
    }

    public function test_an_unpublished_product_cannot_be_added_to_the_cart(): void
    {
        $p = $this->product(['status' => 'draft', 'price' => 4321]);

        $this->post('/cart/add/'.$p->slug, ['qty' => 1])->assertNotFound();
        $this->assertSame(0.0, app(CartService::class)->subtotal());
    }

    public function test_an_available_item_still_checks_out(): void
    {
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 5]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 2]);

        $this->checkout();

        $this->assertSame(1, Order::count());
        $this->assertSame(3, $p->fresh()->stock_quantity);
    }

    public function test_a_member_ordering_to_another_number_creates_no_orphan_customer(): void
    {
        $p = $this->product();
        $c = Customer::create(['name' => 'Real', 'phone' => '01711111111', 'password' => bcrypt('x')]);
        $this->actingAs($c, 'customer');

        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);
        $this->checkout(['phone' => '01822222222']);   // shipping a gift

        $this->assertSame(1, Customer::count());
        $this->assertSame($c->id, (int) Order::first()->customer_id);
    }
}
