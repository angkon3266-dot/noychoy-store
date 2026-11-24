<?php

namespace Tests\Feature\Mcp;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class McpProductToolsTest extends McpTestCase
{
    public function test_search_returns_compact_summaries_and_filters_by_status(): void
    {
        $this->product('Pearl Drops');
        $this->product('Gold Hoops', ['status' => 'draft']);

        $all = $this->ok('search_products', ['query' => 'o'])['products'];
        $this->assertCount(2, $all);
        $this->assertArrayNotHasKey('description', $all[0]);

        $drafts = $this->ok('search_products', ['status' => 'draft'])['products'];
        $this->assertSame(['Gold Hoops'], array_column($drafts, 'name'));
    }

    public function test_get_product_by_slug_sku_or_id(): void
    {
        $p = $this->product();

        foreach ([$p->slug, $p->sku, (string) $p->id] as $ref) {
            $this->assertSame($p->id, $this->ok('get_product', ['product' => $ref])['id']);
        }

        $this->assertStringContainsString('search_products', $this->fails('get_product', ['product' => 'nope']));
    }

    public function test_create_is_a_draft_with_category_and_seo(): void
    {
        $this->product(); // creates the earrings category

        $created = $this->ok('create_product', [
            'name' => 'Ruby Studs', 'price' => 990, 'category' => 'earrings',
            'description' => 'Two small rubies.', 'meta_title' => 'Ruby Studs | Store', 'stock_quantity' => 4,
        ]);

        $this->assertSame('draft', $created['status']);
        $this->assertSame('Earrings', $created['category']['name']);
        $this->assertSame('Ruby Studs | Store', $created['meta_title']);
        $this->assertTrue($created['in_stock']);

        $this->assertStringContainsString('name and price', $this->fails('create_product', ['name' => 'No price']));
        $this->assertStringContainsString('list_categories', $this->fails('create_product', ['name' => 'X', 'price' => 1, 'category' => 'nope']));
    }

    public function test_update_touches_only_what_is_passed_and_keeps_stock_flag_honest(): void
    {
        $p = $this->product('Pearl Drops', ['description' => 'Keep me']);

        $this->ok('update_product', ['product' => $p->slug, 'price' => 2100, 'short_description' => 'Short', 'stock_quantity' => 0]);

        $p->refresh();
        $this->assertEquals(2100, $p->price);
        $this->assertSame('Short', $p->short_description);
        $this->assertSame('Keep me', $p->description);
        $this->assertFalse($p->in_stock);
    }

    public function test_publish_and_unpublish(): void
    {
        $p = $this->product('Gold Hoops', ['status' => 'draft']);

        $this->assertSame('published', $this->ok('publish_product', ['product' => $p->slug])['status']);
        $this->assertSame('draft', $this->ok('unpublish_product', ['product' => $p->slug])['status']);
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_variants_replace_and_sum_stock_then_revert_to_simple(): void
    {
        $p = $this->product();

        $res = $this->ok('set_product_variants', ['product' => $p->id, 'variants' => [
            ['attributes' => ['Size' => '6'], 'stock' => 2],
            ['attributes' => ['Size' => '7'], 'stock' => 3, 'price' => 1600],
        ]]);

        $this->assertTrue($res['has_variants']);
        $this->assertSame([['name' => 'Size', 'values' => ['6', '7']]], $res['options']);
        $this->assertCount(2, $res['variants']);
        $this->assertSame(5, $p->fresh()->stock_quantity);
        $this->assertEquals(1600, $res['variants'][1]['price']);

        $simple = $this->ok('set_product_variants', ['product' => $p->id, 'variants' => []]);
        $this->assertFalse($simple['has_variants']);
        $this->assertSame(0, $p->variants()->count());
    }

    public function test_delete_needs_confirm_and_is_a_soft_delete(): void
    {
        $p = $this->product();

        $this->assertStringContainsString('confirm=true', $this->fails('delete_product', ['product' => $p->slug]));
        $this->assertNotNull(Product::find($p->id));

        $this->ok('delete_product', ['product' => $p->slug, 'confirm' => true]);
        $this->assertNull(Product::find($p->id));
        $this->assertNotNull(Product::withTrashed()->find($p->id));
    }

    public function test_images_upload_primary_and_delete(): void
    {
        Storage::fake('public');
        $img = imagecreatetruecolor(600, 600);
        ob_start();
        imagewebp($img, null, 80);
        $binary = ob_get_clean();
        Storage::disk('public')->put('_fixtures/remote.webp', $binary);
        Http::fake(['*' => Http::response($binary, 200, ['Content-Type' => 'image/webp'])]);

        $p = $this->product();
        $url = Storage::disk('public')->path('_fixtures/remote.webp');

        $this->assertStringContainsString('http', $this->fails('upload_product_image', ['product' => $p->slug, 'image_url' => $url]));

        $first = $this->ok('upload_product_image', ['product' => $p->slug, 'image_url' => 'https://example.test/a.webp']);
        $second = $this->ok('upload_product_image', ['product' => $p->slug, 'image_url' => 'https://example.test/b.webp']);
        $this->assertTrue($first['product']['images'][0]['is_primary']);

        $this->ok('set_primary_image', ['product' => $p->slug, 'image_id' => $second['uploaded_image_id']]);
        $this->assertTrue($p->images()->find($second['uploaded_image_id'])->is_primary);

        $this->ok('delete_product_image', ['product' => $p->slug, 'image_id' => $second['uploaded_image_id']]);
        $this->assertSame(1, $p->images()->count());
        $this->assertTrue($p->images()->first()->is_primary);

        $other = $this->product('Other');
        $this->assertStringContainsString('does not belong', $this->fails('set_primary_image', ['product' => $other->slug, 'image_id' => $first['uploaded_image_id']]));
    }

    public function test_stock_summary_lists_sold_out_and_low(): void
    {
        $this->product('Gone', ['stock_quantity' => 0, 'in_stock' => false]);
        $this->product('Nearly', ['stock_quantity' => 1]);
        $this->product('Plenty', ['stock_quantity' => 50]);

        $s = $this->ok('stock_summary');

        $this->assertSame(['Gone'], array_column($s['sold_out'], 'name'));
        $this->assertSame(['Nearly'], array_column($s['low_stock'], 'name'));
        $this->assertSame(3, $s['products']['published']);
    }
}
