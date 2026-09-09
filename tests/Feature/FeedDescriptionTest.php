<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Meta\MetaProductMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Descriptions are authored in the light-markdown the storefront renders.
 * Google, the Meta CSV feed and the Meta Graph sync all print this field
 * verbatim, so the syntax reached the shopper along with the words — every one
 * of the 147 live items showed "## Design" and "**Turquoise & Rose**".
 *
 * One helper now serves all three, so a product reads the same wherever it is
 * listed. These tests hold that in place surface by surface.
 */
class FeedDescriptionTest extends TestCase
{
    use RefreshDatabase;

    /** The shape real product copy takes in this catalogue. */
    private const MARKED_UP = <<<'MD'
        "Two hearts at your ears."

        **Turquoise & Rose — two soft colours.**

        ## Design

        - Hand-set stones, [see the guide](https://noychoy.com/guide).
        - Rhodium plated and `hypoallergenic`.
        MD;

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        $product = Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name), 'status' => 'published',
            'price' => 1000, 'category_id' => $category->id, 'stock_quantity' => 5, 'in_stock' => true,
        ], $attrs));

        ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'is_primary' => true]);

        return $product->fresh();
    }

    /** Every syntax marker gone, every word kept. */
    protected function assertFlattened(string $description): void
    {
        foreach (['**', '## ', '](', '`'] as $marker) {
            $this->assertStringNotContainsString($marker, $description, "Markdown marker \"{$marker}\" survived.");
        }

        $this->assertStringContainsString('Turquoise & Rose — two soft colours.', $description);
        $this->assertStringContainsString('Design', $description);
        $this->assertStringContainsString('see the guide', $description);
        $this->assertStringContainsString('hypoallergenic', $description);
        $this->assertStringContainsString('• Rhodium plated', $description);
    }

    public function test_the_helper_strips_every_marker_and_keeps_the_words(): void
    {
        $product = $this->product('Heart Earrings', ['description' => self::MARKED_UP]);

        $this->assertFlattened(feed_description($product));
    }

    public function test_the_meta_csv_feed_carries_flattened_copy(): void
    {
        $this->product('Heart Earrings', ['description' => self::MARKED_UP]);

        $csv = $this->get('/feed/meta.csv')->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $cols = str_getcsv(array_shift($lines));
        $row = array_combine($cols, array_pad(str_getcsv(implode("\n", $lines)), count($cols), ''));

        $this->assertFlattened($row['description']);
    }

    /**
     * The Graph sync is the path that actually writes the live catalogue, so a
     * fix that stopped at the CSV would have left the real listings marked up.
     */
    public function test_the_meta_graph_sync_carries_flattened_copy(): void
    {
        $product = $this->product('Heart Earrings', ['description' => self::MARKED_UP]);

        $data = app(MetaProductMapper::class)->items($product)[0]['data'];

        $this->assertFlattened($data['description']);
    }

    public function test_the_google_feed_carries_flattened_copy(): void
    {
        $this->product('Heart Earrings', ['description' => self::MARKED_UP]);

        $xml = simplexml_load_string($this->get('/feed/google.xml')->assertOk()->streamedContent());

        $this->assertFlattened((string) $xml->channel->item[0]->description);
    }

    public function test_a_product_with_no_description_falls_back_to_its_name(): void
    {
        $product = $this->product('Bare Ring', ['description' => null, 'short_description' => null]);

        $this->assertSame('Bare Ring', feed_description($product));
    }

    public function test_the_short_description_is_used_when_the_long_one_is_empty(): void
    {
        $product = $this->product('Brief Ring', ['description' => null, 'short_description' => '**Small and bright.**']);

        $this->assertSame('Small and bright.', feed_description($product));
    }

    /** Meta allows 5000 and Google 5000; both reject anything longer. */
    public function test_long_copy_is_cut_to_the_limit_it_is_given(): void
    {
        $product = $this->product('Wordy Ring', ['description' => str_repeat('a', 6000)]);

        $this->assertSame(4900, mb_strlen(feed_description($product)));
        $this->assertSame(5000, mb_strlen(feed_description($product, 5000)));
    }

    public function test_runs_of_blank_lines_are_collapsed(): void
    {
        $product = $this->product('Airy Ring', ['description' => "One.\n\n\n\n\nTwo."]);

        $this->assertSame("One.\n\nTwo.", feed_description($product));
    }

    /**
     * plain_copy also backs the SEO shell and meta descriptions, so the links
     * and code ticks it learned to strip have to stay stripped there too.
     */
    public function test_plain_copy_strips_links_and_ticks_for_its_seo_callers(): void
    {
        $this->assertSame('Read the guide today.', plain_copy('Read [the guide](https://noychoy.com/g) today.'));
        $this->assertSame('Nickel free.', plain_copy('`Nickel free.`'));
        $this->assertSame('Design', plain_copy('#### Design'));
    }
}
