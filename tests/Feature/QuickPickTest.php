<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Storefront\ProductCardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "Choose" pop-up (Nov 2026): a piece with options goes in the bag from a
 * card or the bag's pairs row without opening its page.
 */
class QuickPickTest extends TestCase
{
    use RefreshDatabase;

    protected function ring(array $attrs = []): array
    {
        $ring = Product::create($attrs + [
            'name' => 'Halo Ring',
            'slug' => Str::slug('Halo Ring'),
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
            'category_id' => Category::create(['name' => 'Rings'])->id,
            'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['6', '7', '8']]],
        ]);
        $six = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H6', 'attributes' => ['Size' => '6'], 'price' => 1000, 'stock_quantity' => 0, 'is_active' => true]);
        $seven = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H7', 'attributes' => ['Size' => '7'], 'price' => 1100, 'stock_quantity' => 3, 'is_active' => true]);
        // Switched off: size 8 is not for sale, so the pop-up must not offer it.
        ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H8', 'attributes' => ['Size' => '8'], 'price' => 1200, 'stock_quantity' => 5, 'is_active' => false]);

        return [$ring, $six, $seven];
    }

    public function test_options_endpoint_lists_live_values_with_stock_and_prices(): void
    {
        [$ring, $six, $seven] = $this->ring();

        $res = $this->getJson(route('product.options', $ring))
            ->assertOk()
            ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')
            ->assertJsonPath('has_variants', true)
            ->assertJsonPath('options.0.name', 'Size')
            ->assertJsonPath('options.0.values', ['6', '7'])
            ->assertJsonPath('add_url', route('cart.add', $ring))
            ->assertJsonPath('from.price_text', money(1000));

        $variants = collect($res->json('variants'))->keyBy('id');
        $this->assertCount(2, $variants);
        $this->assertFalse($variants[$six->id]['in_stock']);
        $this->assertTrue($variants[$seven->id]['in_stock']);
        $this->assertSame(money(1100), $variants[$seven->id]['price_text']);
        $this->assertSame(['Size' => '7'], $variants[$seven->id]['attrs']);
        $this->assertSame(meta_content_id($ring, $seven), $variants[$seven->id]['content_id']);
    }

    public function test_options_endpoint_hides_an_unpublished_piece(): void
    {
        [$ring] = $this->ring(['status' => 'draft']);

        $this->getJson(route('product.options', $ring))->assertNotFound();
    }

    public function test_cards_carry_the_options_url_only_for_pieces_with_options(): void
    {
        [$ring] = $this->ring();
        $plain = Product::create(['name' => 'Slim Bangle', 'slug' => 'slim-bangle', 'status' => 'published', 'price' => 500, 'manage_stock' => false, 'in_stock' => true]);

        $this->assertSame(route('product.options', $ring), ProductCardData::make($ring)['options_url']);
        $this->assertNull(ProductCardData::make($plain)['options_url']);
    }

    public function test_the_chosen_size_goes_in_the_bag(): void
    {
        [$ring, , $seven] = $this->ring();

        $this->postJson(route('cart.add', $ring), ['variant_id' => $seven->id, 'qty' => 1])
            ->assertOk()->assertJsonPath('count', 1);
    }
}
