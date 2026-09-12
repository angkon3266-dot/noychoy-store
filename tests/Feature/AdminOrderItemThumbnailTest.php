<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product pictures on the order detail page.
 *
 * The line items were text only, so recognising what was actually sold meant
 * opening each product in another tab. The picture is the fastest way to read
 * an order, and for a variable line it has to be the variation's own photo —
 * the same rule the packing label already follows.
 */
class AdminOrderItemThumbnailTest extends TestCase
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
        ], $attrs));
    }

    protected function order(): Order
    {
        return Order::create([
            'order_number' => '30001', 'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 1850, 'shipping_cost' => 70, 'discount' => 0,
            'total' => 1920, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'processing', 'source' => 'web',
        ]);
    }

    public function test_the_line_shows_the_products_primary_picture(): void
    {
        $p = $this->product();
        ProductImage::create([
            'product_id' => $p->id, 'path' => 'products/secondary.webp', 'position' => 2, 'is_primary' => false,
        ]);
        ProductImage::create([
            'product_id' => $p->id, 'path' => 'products/hero.webp', 'position' => 1, 'is_primary' => true,
        ]);

        $order = $this->order();
        $order->items()->create([
            'product_id' => $p->id, 'name' => $p->name, 'price' => 1850,
            'quantity' => 1, 'subtotal' => 1850,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSee('products/hero.webp', false)
            ->assertDontSee('products/secondary.webp', false);
    }

    public function test_a_variation_line_shows_that_variations_own_picture(): void
    {
        $p = $this->product(['name' => 'Opal Band', 'has_variants' => true]);
        $generic = ProductImage::create([
            'product_id' => $p->id, 'path' => 'products/generic.webp', 'position' => 1, 'is_primary' => true,
        ]);
        $sizeEight = ProductImage::create([
            'product_id' => $p->id, 'path' => 'products/size-8.webp', 'position' => 2, 'is_primary' => false,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $p->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'price' => 1999, 'stock_quantity' => 3, 'is_active' => true, 'image_id' => $sizeEight->id,
        ]);

        $order = $this->order();
        $order->items()->create([
            'product_id' => $p->id, 'variant_id' => $variant->id, 'name' => $p->name,
            'attributes' => ['Size' => '8'], 'price' => 1999, 'quantity' => 1, 'subtotal' => 1999,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/orders/'.$order->id)
            ->assertOk()
            // The variation's photo wins over the product's primary.
            ->assertSee('products/size-8.webp', false)
            ->assertDontSee($generic->path, false);
    }

    public function test_a_line_whose_product_is_gone_still_renders(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_id' => null, 'name' => 'Deleted piece', 'price' => 500,
            'quantity' => 1, 'subtotal' => 500,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Deleted piece');
    }
}
