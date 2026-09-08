<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Meta\MetaProductMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The live catalogue is fed by the items_batch API, not the CSV, so a video
 * that never reaches {@see MetaProductMapper} shows as "Videos: Missing" in
 * Commerce Manager no matter what the storefront plays.
 */
class MetaCatalogVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        $product = Product::create(array_merge([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'published',
            'price' => 1000, 'category_id' => $category->id, 'stock_quantity' => 5, 'in_stock' => true,
        ], $attrs));

        ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'is_primary' => true]);

        return $product->fresh();
    }

    protected function mapper(): MetaProductMapper
    {
        return app(MetaProductMapper::class);
    }

    public function test_a_gallery_video_is_sent_as_a_direct_file_url(): void
    {
        $product = $this->product('Crimson Bloom', ['video_urls' => ['product-videos/bloom.mp4']]);

        $data = $this->mapper()->items($product)[0]['data'];

        $this->assertArrayHasKey('video', $data);
        $this->assertCount(1, $data['video']);
        $this->assertStringEndsWith('/storage/product-videos/bloom.mp4', $data['video'][0]['url']);
        $this->assertStringStartsWith('http', $data['video'][0]['url']);
    }

    public function test_player_links_are_dropped_because_meta_downloads_the_file(): void
    {
        $product = $this->product('Studio Piece', [
            'video_urls' => ['https://youtu.be/dQw4w9WgXcQ', 'https://vimeo.com/12345'],
        ]);

        $this->assertArrayNotHasKey('video', $this->mapper()->items($product)[0]['data']);
    }

    /**
     * Meta reads `"video": []` as "clear the videos on this item", so a product
     * without any must omit the key rather than send an empty list.
     */
    public function test_a_product_with_no_video_omits_the_key_entirely(): void
    {
        $product = $this->product('Plain Band');

        $this->assertArrayNotHasKey('video', $this->mapper()->items($product)[0]['data']);
    }

    public function test_every_variant_item_carries_the_parents_video(): void
    {
        $product = $this->product('Bangle', [
            'has_variants' => true,
            'video_urls' => ['product-videos/bangle.mp4'],
        ]);
        ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'S'], 'price' => 900, 'stock_quantity' => 2, 'is_active' => true]);
        ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'L'], 'price' => 950, 'stock_quantity' => 1, 'is_active' => true]);

        $items = $this->mapper()->items($product->fresh());

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertStringEndsWith('/storage/product-videos/bangle.mp4', $item['data']['video'][0]['url']);
        }
    }

    /**
     * The sync engine skips a product whose payload hash is unchanged. Adding
     * videos has to move that hash, or the 85 products already marked "synced"
     * would never be re-sent.
     */
    public function test_adding_a_video_changes_the_payload_hash(): void
    {
        $product = $this->product('Hash Check');
        $before = hash('sha256', json_encode($this->mapper()->items($product)));

        $product->update(['video_urls' => ['product-videos/new.mp4']]);
        $after = hash('sha256', json_encode($this->mapper()->items($product->fresh())));

        $this->assertNotSame($before, $after);
    }
}
