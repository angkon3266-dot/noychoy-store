<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The product page used to publish the exact stock count of every variant, even
 * though the UI only ever asked "is there any?". That is competitor-readable
 * inventory given away for nothing.
 *
 * The replacement is a boolean. Server-side PlaceOrder re-validates the real
 * quantity, so a client that no longer knows the number cannot over-order.
 */
class VariantStockPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function variantProduct(): Product
    {
        $product = Product::create([
            'name' => 'Stock Ring',
            'slug' => 'stock-ring',
            'status' => 'published',
            'price' => 5000,
            'manage_stock' => true,
            'stock_quantity' => 41,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'attributes' => ['Size' => '6'],
            'price' => 5000,
            'stock_quantity' => 41,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'attributes' => ['Size' => '7'],
            'price' => 5000,
            'stock_quantity' => 0,
        ]);

        return $product;
    }

    public function test_the_page_says_whether_a_variant_is_in_stock_not_how_many(): void
    {
        $product = $this->variantProduct();

        $this->get('/product/'.$product->slug)->assertInertia(
            fn (Assert $page) => $page
                ->component('Product')
                ->where('pp.variants.0.inStock', true)
                ->where('pp.variants.1.inStock', false)
                ->missing('pp.variants.0.stock')
                ->missing('pp.variants.1.stock')
        );
    }

    public function test_the_exact_count_appears_nowhere_in_the_html(): void
    {
        $product = $this->variantProduct();

        // 41 is deliberately an unusual number, so a match is not a coincidence.
        $this->get('/product/'.$product->slug)
            ->assertDontSee('"stock":41', false)
            ->assertDontSee('&quot;stock&quot;:41', false);
    }

    public function test_the_server_still_refuses_to_oversell(): void
    {
        $product = $this->variantProduct();
        $variant = $product->variants()->where('stock_quantity', 0)->first();

        // The cart is provisional — it will hold anything. The authority is
        // PlaceOrder, and it has to be, because the client no longer knows the
        // number it would otherwise be trusted to respect.
        app(\App\Services\CartService::class)->add($product, $variant, 1);

        $this->post(route('checkout.store'), [
            'name' => 'Buyer',
            'phone' => '01711111111',
            'address' => 'Dhaka',
        ])->assertRedirect(route('cart'))
            ->assertSessionHas('error');

        $this->assertSame(0, \App\Models\Order::count(), 'an order was created for a sold-out variant');
    }
}
