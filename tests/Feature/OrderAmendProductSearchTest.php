<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding a product to an existing order.
 *
 * The picker used to be a <select> carrying every published product, and it
 * filtered variable products out entirely — so "she also wants the ring, size
 * 8" could not be recorded here at all. It searches now, and a variation is
 * picked in the same breath as the product.
 */
class OrderAmendProductSearchTest extends TestCase
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

    protected function order(): Order
    {
        $order = Order::create([
            'order_number' => '30001', 'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 1000, 'shipping_cost' => 70, 'discount' => 0,
            'total' => 1070, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'processing', 'source' => 'web',
        ]);

        $order->items()->create([
            'product_id' => null, 'name' => 'Existing line', 'price' => 1000,
            'quantity' => 1, 'subtotal' => 1000,
        ]);

        return $order->fresh('items');
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function test_search_needs_two_characters_before_it_answers(): void
    {
        $this->product();

        $this->actingAs($this->admin())
            ->getJson('/admin/orders/product-search?q=p')
            ->assertOk()->assertJson(['results' => []]);
    }

    public function test_search_matches_name_sku_and_product_id(): void
    {
        $p = $this->product(['name' => 'Kyra Zircon Flower Ring', 'sku' => 'KZF-99']);

        foreach (['Kyra', 'KZF-99', (string) $p->serial] as $term) {
            $this->actingAs($this->admin())
                ->getJson('/admin/orders/product-search?q='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('results.0.id', $p->id, "searching for \"$term\" found nothing");
        }
    }

    public function test_a_variable_product_is_offered_with_its_variations(): void
    {
        // The old picker excluded these outright — the regression this guards.
        $p = $this->product(['name' => 'Opal Band', 'has_variants' => true]);
        ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'price' => 2100, 'stock_quantity' => 3, 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-9', 'attributes' => ['Size' => '9'],
            'price' => null, 'stock_quantity' => 1, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/admin/orders/product-search?q=Opal')
            ->assertOk()
            ->assertJsonPath('results.0.has_variants', true)
            ->assertJsonCount(2, 'results.0.variants')
            ->assertJsonPath('results.0.variants.0.label', 'Size: 8')
            ->assertJsonPath('results.0.variants.0.price', fn ($v) => (float) $v === 2100.0)
            // No variant price falls back to the parent's, the way the cart does.
            ->assertJsonPath('results.0.variants.1.price', fn ($v) => (float) $v === 1850.0);
    }

    public function test_unpublished_products_are_not_offered(): void
    {
        $this->product(['name' => 'Secret Draft Ring', 'status' => 'draft']);

        $this->actingAs($this->admin())
            ->getJson('/admin/orders/product-search?q=Secret')
            ->assertOk()->assertJson(['results' => []]);
    }

    public function test_the_search_is_behind_the_admin_login(): void
    {
        // The admin guard redirects rather than 401ing, but either way a
        // logged-out browser never sees the catalogue.
        $this->get('/admin/orders/product-search?q=pearl')->assertRedirect();
    }

    // ── Adding the line ──────────────────────────────────────────────────────

    public function test_adding_a_variation_records_which_one_and_takes_its_stock(): void
    {
        $order = $this->order();
        $p = $this->product(['name' => 'Opal Band', 'has_variants' => true, 'stock_quantity' => 10]);
        $variant = ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'price' => 2100, 'stock_quantity' => 3, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())->post("/admin/orders/{$order->id}/amend", [
            'items' => [['id' => $order->items->first()->id, 'price' => 1000, 'quantity' => 1]],
            'new_lines' => [['product_id' => $p->id, 'variant_id' => $variant->id, 'qty' => 2, 'price' => '']],
            'shipping_cost' => 70,
            'discount' => 0,
        ])->assertRedirect();

        $line = $order->fresh('items')->items->firstWhere('variant_id', $variant->id);
        $this->assertNotNull($line, 'the variation line was not created');
        $this->assertSame('OB-8', $line->sku, 'the variant SKU should win over the parent SKU');
        $this->assertSame(['Size' => '8'], $line->attributes, 'which one she bought must be on the line');
        $this->assertSame('2100.00', $line->price, 'a blank price should take the variant price');
        $this->assertSame(1, $variant->fresh()->stock_quantity, 'two of three should be gone');
    }

    public function test_a_variable_product_cannot_be_added_without_choosing_one(): void
    {
        $order = $this->order();
        $p = $this->product(['name' => 'Opal Band', 'has_variants' => true]);
        ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'stock_quantity' => 3, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())->post("/admin/orders/{$order->id}/amend", [
            'items' => [['id' => $order->items->first()->id, 'price' => 1000, 'quantity' => 1]],
            'new_lines' => [['product_id' => $p->id, 'qty' => 1, 'price' => '']],
            'shipping_cost' => 70,
            'discount' => 0,
        ])->assertSessionHas('error');

        $this->assertCount(1, $order->fresh('items')->items, 'nothing should have been added');
    }

    public function test_a_variation_belonging_to_another_product_is_ignored(): void
    {
        $order = $this->order();
        $mine = $this->product(['name' => 'Opal Band', 'has_variants' => true]);
        ProductVariant::create([
            'product_id' => $mine->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'stock_quantity' => 3, 'is_active' => true,
        ]);

        $other = $this->product(['name' => 'Other Ring', 'sku' => 'OTH-1', 'has_variants' => true]);
        $strayVariant = ProductVariant::create([
            'product_id' => $other->id, 'sku' => 'OTH-S', 'attributes' => ['Size' => 'S'],
            'stock_quantity' => 5, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())->post("/admin/orders/{$order->id}/amend", [
            'items' => [['id' => $order->items->first()->id, 'price' => 1000, 'quantity' => 1]],
            'new_lines' => [['product_id' => $mine->id, 'variant_id' => $strayVariant->id, 'qty' => 1, 'price' => 500]],
            'shipping_cost' => 70,
            'discount' => 0,
        ])->assertRedirect();

        // The mismatched pair must never move the other product's stock.
        $this->assertSame(5, $strayVariant->fresh()->stock_quantity);
        $line = $order->fresh('items')->items->firstWhere('product_id', $mine->id);
        $this->assertNotNull($line);
        $this->assertNull($line->variant_id, 'a variation from another product is not this line’s');
    }

    public function test_a_simple_product_still_adds_the_way_it_did(): void
    {
        $order = $this->order();
        $p = $this->product(['name' => 'Simple Studs', 'price' => 950, 'stock_quantity' => 4]);

        $this->actingAs($this->admin())->post("/admin/orders/{$order->id}/amend", [
            'items' => [['id' => $order->items->first()->id, 'price' => 1000, 'quantity' => 1]],
            'new_lines' => [['product_id' => $p->id, 'qty' => 1, 'price' => '']],
            'shipping_cost' => 70,
            'discount' => 0,
        ])->assertRedirect();

        $line = $order->fresh('items')->items->firstWhere('product_id', $p->id);
        $this->assertNotNull($line);
        $this->assertSame('950.00', $line->price);
        $this->assertSame(3, $p->fresh()->stock_quantity);
    }
}
