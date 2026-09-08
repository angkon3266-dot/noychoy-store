<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Google Shopping feed. Merchant Center rejects items rather than ignoring
 * bad fields, so the parts Google is strict about — underscored availability,
 * a shipping block, identifier_exists on a catalogue with no GTINs, and
 * well-formed XML — are what these tests hold in place.
 */
class GoogleFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        $product = Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name), 'status' => 'published',
            'price' => 1000, 'category_id' => $category->id, 'stock_quantity' => 5, 'in_stock' => true,
        ], $attrs));

        // Google requires an image_link, so a product without one is skipped.
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'is_primary' => true]);

        return $product->fresh();
    }

    protected function xml(): \SimpleXMLElement
    {
        $body = $this->get('/feed/google.xml')->assertOk()->streamedContent();

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml, 'The feed is not well-formed XML.');

        return $xml;
    }

    /** @return array<int, array<string, mixed>> Items flattened to plain arrays. */
    protected function items(): array
    {
        $items = [];

        foreach ($this->xml()->channel->item as $item) {
            $flat = [];
            foreach (['title', 'description', 'link'] as $plain) {
                $flat[$plain] = (string) $item->{$plain};
            }
            foreach ($item->children('g', true) as $name => $value) {
                // additional_image_link repeats, so collect it as a list.
                $flat['g:'.$name] = isset($flat['g:'.$name])
                    ? array_merge((array) $flat['g:'.$name], [(string) $value])
                    : (string) $value;
            }
            $flat['g:shipping_price'] = (string) $item->children('g', true)->shipping->children('g', true)->price;
            $items[] = $flat;
        }

        return $items;
    }

    public function test_the_feed_is_well_formed_rss_with_the_google_namespace(): void
    {
        $this->product('Solitaire Ring');

        $xml = $this->xml();

        $this->assertSame('rss', $xml->getName());
        $this->assertArrayHasKey('g', $xml->getNamespaces(true));
        $this->assertCount(1, $xml->channel->item);
    }

    public function test_item_ids_match_the_ids_the_rest_of_the_stack_uses(): void
    {
        $product = $this->product('Solitaire Ring');

        $items = $this->items();

        $this->assertSame(meta_content_id($product), $items[0]['g:id']);
        $this->assertSame('prod-'.$product->id, $items[0]['g:id']);
    }

    /**
     * "in stock" with a space is Meta's spelling. Google wants the underscore
     * and disapproves the item without it.
     */
    public function test_availability_uses_googles_underscored_spelling(): void
    {
        $this->product('In Stock Ring');
        $this->product('Sold Out Ring', ['stock_quantity' => 0, 'in_stock' => false, 'manage_stock' => true]);

        $items = collect($this->items())->keyBy('title');

        $this->assertSame('in_stock', $items['In Stock Ring']['g:availability']);
        $this->assertSame('out_of_stock', $items['Sold Out Ring']['g:availability']);
    }

    /**
     * Without identifier_exists Google demands a GTIN and disapproves the whole
     * feed — this catalogue has none, and the SKU is a store code, not an MPN.
     */
    public function test_every_item_declares_that_it_has_no_manufacturer_identifier(): void
    {
        $this->product('Plain Ring', ['sku' => 'NC-001']);

        $items = $this->items();

        $this->assertSame('no', $items[0]['g:identifier_exists']);
        $this->assertArrayNotHasKey('g:gtin', $items[0]);
        $this->assertArrayNotHasKey('g:mpn', $items[0]);
    }

    public function test_prices_carry_the_currency_and_a_sale_shows_as_a_reduced_price(): void
    {
        $this->product('Full Price Ring');
        $this->product('Discounted Ring', ['price' => 800, 'compare_at_price' => 1200]);

        $items = collect($this->items())->keyBy('title');

        $this->assertSame('1000.00 BDT', $items['Full Price Ring']['g:price']);
        $this->assertArrayNotHasKey('g:sale_price', $items['Full Price Ring']);

        // price is the "was", sale_price the "now" — the reverse loses the strike-through.
        $this->assertSame('1200.00 BDT', $items['Discounted Ring']['g:price']);
        $this->assertSame('800.00 BDT', $items['Discounted Ring']['g:sale_price']);
    }

    public function test_shipping_follows_the_rate_set_in_admin(): void
    {
        $this->product('Ring');
        Setting::put('shipping_inside', 90);

        $this->assertSame('90.00 BDT', $this->items()[0]['g:shipping_price']);
    }

    public function test_variants_are_listed_and_grouped_under_the_parent(): void
    {
        $product = $this->product('Bangle', ['has_variants' => true]);
        $small = ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'S', 'Color' => 'Gold'], 'price' => 900, 'stock_quantity' => 2]);
        $large = ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'L'], 'price' => 950, 'stock_quantity' => 0]);

        $items = collect($this->items())->keyBy('g:id');

        $smallId = 'prod-'.$product->id.'-var-'.$small->id;
        $largeId = 'prod-'.$product->id.'-var-'.$large->id;

        $this->assertTrue($items->has($smallId));
        $this->assertSame('prod-'.$product->id, $items[$smallId]['g:item_group_id']);

        // Availability is per variant, not inherited from the parent.
        $this->assertSame('in_stock', $items[$smallId]['g:availability']);
        $this->assertSame('out_of_stock', $items[$largeId]['g:availability']);

        // Attributes are read case-insensitively off the variant.
        $this->assertSame('S', $items[$smallId]['g:size']);
        $this->assertSame('Gold', $items[$smallId]['g:color']);
    }

    public function test_a_simple_product_is_not_given_an_item_group_id(): void
    {
        $this->product('Standalone Ring');

        // Google treats a lone item_group_id as a one-variant family and can
        // suppress it, so the field has to be absent rather than empty.
        $this->assertArrayNotHasKey('g:item_group_id', $this->items()[0]);
    }

    public function test_brand_follows_the_store_name_and_is_never_hardcoded(): void
    {
        $this->product('Pendant');
        Setting::put('store_name', 'Meridian Éclat');

        $this->assertSame('Meridian Éclat', $this->items()[0]['g:brand']);
    }

    public function test_google_category_comes_from_the_category_tree(): void
    {
        $parent = Category::create(['name' => 'Jewellery', 'slug' => 'jewellery', 'google_category' => '188']);
        $child = Category::create(['name' => 'Earrings', 'slug' => 'earrings', 'parent_id' => $parent->id]);

        $product = $this->product('Hoops');
        $product->forceFill(['category_id' => $child->id])->save();

        $this->assertSame('188', collect($this->items())->firstWhere('g:id', meta_content_id($product))['g:google_product_category']);
    }

    /** A title with an ampersand must not break the document for every item after it. */
    public function test_special_characters_in_a_title_are_escaped(): void
    {
        $this->product('Gold & Pearl <Ring>');

        $items = $this->items();

        $this->assertSame('Gold & Pearl <Ring>', $items[0]['title']);
    }

    public function test_draft_products_stay_out_of_the_feed(): void
    {
        $this->product('Live Ring');
        $this->product('Hidden Ring', ['status' => 'draft']);

        $titles = collect($this->items())->pluck('title');

        $this->assertContains('Live Ring', $titles);
        $this->assertNotContains('Hidden Ring', $titles);
    }

    /**
     * A price of 0 means unpriced, not free. Google rejects the item, and
     * sending it would advertise a giveaway.
     */
    public function test_unpriced_products_are_left_out(): void
    {
        $this->product('Priced Ring');
        $this->product('Unpriced Ring', ['price' => 0]);

        $titles = collect($this->items())->pluck('title');

        $this->assertContains('Priced Ring', $titles);
        $this->assertNotContains('Unpriced Ring', $titles);
    }

    public function test_an_unpriced_variant_is_left_out_without_dropping_its_siblings(): void
    {
        $product = $this->product('Mixed Bangle', ['has_variants' => true]);
        $priced = ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'S'], 'price' => 900, 'stock_quantity' => 2]);
        $free = ProductVariant::create(['product_id' => $product->id, 'attributes' => ['Size' => 'L'], 'price' => 0, 'stock_quantity' => 2]);

        $ids = collect($this->items())->pluck('g:id');

        $this->assertContains('prod-'.$product->id.'-var-'.$priced->id, $ids);
        $this->assertNotContains('prod-'.$product->id.'-var-'.$free->id, $ids);
    }

    /**
     * Descriptions are authored in Markdown and rendered by the storefront, but
     * Google prints this field verbatim — every one of the 147 live items would
     * otherwise reach a shopper with "##" and "**" still attached.
     */
    public function test_markdown_is_flattened_out_of_the_description(): void
    {
        $this->product('Marked Up Ring', ['description' => implode("\n", [
            '**Turquoise & Rose — two soft colours.**',
            '',
            '## Design',
            '',
            '- Hand-set stones, [see the guide](https://noychoy.com/guide).',
            '- Rhodium plated.',
        ])]);

        $description = $this->items()[0]['description'];

        $this->assertStringNotContainsString('**', $description);
        $this->assertStringNotContainsString('## ', $description);
        $this->assertStringNotContainsString('](', $description);

        // The words survive — only the syntax goes.
        $this->assertStringContainsString('Turquoise & Rose — two soft colours.', $description);
        $this->assertStringContainsString('Design', $description);
        $this->assertStringContainsString('see the guide', $description);
        $this->assertStringContainsString('• Rhodium plated.', $description);
    }

    public function test_a_product_with_no_image_is_skipped(): void
    {
        $withImage = $this->product('Photographed Ring');

        $bare = Product::create([
            'name' => 'Bare Ring', 'slug' => 'bare-ring', 'status' => 'published',
            'price' => 500, 'category_id' => $withImage->category_id, 'stock_quantity' => 1, 'in_stock' => true,
        ]);

        $ids = collect($this->items())->pluck('g:id');

        $this->assertContains(meta_content_id($withImage), $ids);
        $this->assertNotContains(meta_content_id($bare), $ids);
    }
}
