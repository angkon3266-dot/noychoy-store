<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue feed's `id` column and the Pixel/CAPI `content_id` must be the
 * same string. When they drifted (feed sent "179", pixel sent "prod-179") Meta
 * matched nothing and the catalogue match rate sat at 0%, which caps what the
 * ad account can retarget.
 */
class MetaFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        $product = Product::create(array_merge([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'published',
            'price' => 1000, 'category_id' => $category->id, 'stock_quantity' => 5, 'in_stock' => true,
        ], $attrs));

        // Meta requires an image_link, so a product without one is skipped.
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'is_primary' => true]);

        return $product->fresh();
    }

    /** @return array<int, array<string, string>> */
    protected function rows(): array
    {
        $csv = $this->get('/feed/meta.csv')->assertOk()->streamedContent();

        $lines = array_filter(explode("\n", trim($csv)));
        $cols = str_getcsv(array_shift($lines));

        return array_map(fn ($l) => array_combine($cols, array_pad(str_getcsv($l), count($cols), '')), $lines);
    }

    public function test_the_feed_id_equals_the_pixel_content_id(): void
    {
        $product = $this->product('Solitaire Ring');

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(meta_content_id($product), $rows[0]['id']);
        $this->assertSame('prod-'.$product->id, $rows[0]['id']);
    }

    public function test_variants_are_listed_with_the_ids_purchase_events_send(): void
    {
        $product = $this->product('Bangle', ['has_variants' => true]);
        $small = ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'S'], 'price' => 900, 'stock_quantity' => 2]);
        $large = ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'L'], 'price' => 950, 'stock_quantity' => 0]);

        $rows = collect($this->rows())->keyBy('id');

        // Matches MetaTrackingService::retailerForOrderItem for a variant line.
        $this->assertTrue($rows->has('prod-'.$product->id.'-var-'.$small->id));
        $this->assertTrue($rows->has('prod-'.$product->id.'-var-'.$large->id));

        // Grouped under the parent so Meta treats them as one product family.
        $this->assertSame('prod-'.$product->id, $rows['prod-'.$product->id.'-var-'.$small->id]['item_group_id']);

        // Availability is per variant, not inherited from the parent.
        $this->assertSame('in stock', $rows['prod-'.$product->id.'-var-'.$small->id]['availability']);
        $this->assertSame('out of stock', $rows['prod-'.$product->id.'-var-'.$large->id]['availability']);
    }

    public function test_brand_follows_the_store_name_and_is_never_hardcoded(): void
    {
        $this->product('Pendant');
        Setting::put('store_name', 'Meridian Éclat');

        $rows = $this->rows();

        $this->assertSame('Meridian Éclat', $rows[0]['brand']);
        $this->assertStringNotContainsStringIgnoringCase('noychoy', $rows[0]['brand']);
    }

    public function test_google_category_comes_from_the_category_and_inherits_from_a_parent(): void
    {
        $parent = Category::create(['name' => 'Jewellery', 'slug' => 'jewellery', 'google_category' => '188']);
        $child = Category::create(['name' => 'Earrings', 'slug' => 'earrings', 'parent_id' => $parent->id]);

        $onChild = $this->product('Hoops');
        $onChild->forceFill(['category_id' => $child->id])->save();

        // The child has none of its own, so it inherits the parent's value.
        $this->assertSame('188', $onChild->fresh()->googleCategory());

        // Its own value wins once set.
        $child->update(['google_category' => '191']);
        $this->assertSame('191', $onChild->fresh()->googleCategory());

        $rows = collect($this->rows())->firstWhere('id', meta_content_id($onChild));
        $this->assertSame('191', $rows['google_product_category']);
    }

    public function test_a_product_with_no_google_category_leaves_the_column_blank(): void
    {
        $product = $this->product('Plain Ring');

        $this->assertNull($product->googleCategory());
        $this->assertSame('', collect($this->rows())->firstWhere('id', meta_content_id($product))['google_product_category']);
    }
}
