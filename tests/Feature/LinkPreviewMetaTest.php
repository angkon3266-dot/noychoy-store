<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Open Graph tags — what Messenger, WhatsApp and Facebook show when someone
 * pastes a link to this shop.
 *
 * Pasting the bare domain used to preview as a random product: title, photo
 * and price all belonged to whichever product happened to be last through a
 * `foreach ($featured as $product)` loop. Blade's @extends shares the child
 * template's entire final variable table with the layout, and a foreach
 * variable outlives its loop, so `$product` was still set by the time the
 * layout's head rendered — and the head trusted `isset($product)` as proof it
 * was on a product page.
 *
 * The rule now is the route, not the variable: only the product page may claim
 * to be a product.
 */
class LinkPreviewMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name = 'Shared Product'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        return Product::create([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => Str::slug($name),
            'price' => 1234, 'category_id' => $category->id,
            'status' => 'published', 'stock_quantity' => 5,
            'short_description' => 'A very specific product description.',
        ]);
    }

    /** @return string[] */
    protected function templates(): array
    {
        return array_keys(config('theme.homepage_templates'));
    }

    public function test_the_homepage_never_advertises_itself_as_a_product(): void
    {
        $product = $this->product();
        $product->update(['is_featured' => true]);

        // Every template loops products into $product somewhere, so check them
        // all rather than only the one this shop happens to run today.
        foreach ($this->templates() as $template) {
            Setting::put('theme', ['homepage_template' => $template]);

            $html = $this->get('/')->assertOk()->getContent();

            $this->assertStringNotContainsString('og:type', $html, "[$template] homepage claims to be a product");
            $this->assertStringNotContainsString('product:price:amount', $html, "[$template] homepage leaks a product price");
            $this->assertStringNotContainsString('Shared Product', $this->headOf($html), "[$template] homepage leaks a product title into <head>");
        }
    }

    public function test_the_homepage_description_is_the_shops_own_not_a_products(): void
    {
        $product = $this->product();
        $product->update(['is_featured' => true]);
        Setting::put('theme', ['homepage_template' => 'couture']);

        $head = $this->headOf($this->get('/')->assertOk()->getContent());

        $this->assertStringNotContainsString('A very specific product description.', $head);
    }

    public function test_the_shop_listing_page_does_not_advertise_a_product_either(): void
    {
        $this->product();

        $html = $this->get('/shop')->assertOk()->getContent();

        $this->assertStringNotContainsString('og:type', $html);
        $this->assertStringNotContainsString('product:price:amount', $html);
    }

    public function test_a_product_page_still_gets_its_own_preview(): void
    {
        // The fix must not go so far that real product links stop previewing.
        $product = $this->product();

        $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('og:type" content="product"', $html);
        $this->assertStringContainsString('og:title" content="Shared Product"', $html);
        $this->assertStringContainsString('product:price:amount" content="1234.00"', $html);
    }

    /** The <head> only — the product's name legitimately appears in page body copy. */
    protected function headOf(string $html): string
    {
        return Str::before(Str::after($html, '<head>'), '</head>');
    }
}
