<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The conversion-audit fixes: checkout autofill, srcset image variants,
 * and JSON-LD structured data.
 *
 * Each of these was a measured gap on the live site — a phone field that
 * opened the full keyboard, 900px images downloaded for 170px card slots,
 * and product pages emitting no schema at all — so each fix gets a test
 * that fails if it quietly regresses.
 */
class ConversionFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function category(): Category
    {
        return Category::firstOrCreate(['slug' => 'earrings'], ['name' => 'Earrings', 'is_active' => true]);
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Test Drops', 'slug' => 'test-drops-'.uniqid(), 'sku' => uniqid(),
            'price' => 1450, 'category_id' => $this->category()->id,
            'status' => 'published', 'stock_quantity' => 5,
            'short_description' => 'Gold-plated drops with cubic zirconia.',
        ], $attrs));
    }

    /** A real 900×900 WebP written to the (faked) public disk. */
    protected function storedImage(string $path = 'products/test-image.webp'): string
    {
        $img = imagecreatetruecolor(900, 900);
        imagefilledrectangle($img, 0, 0, 899, 899, imagecolorallocate($img, 200, 160, 90));
        ob_start();
        imagewebp($img, null, 80);
        Storage::disk('public')->put($path, ob_get_clean());
        imagedestroy($img);

        return $path;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available in this environment.');
        }
    }

    // ── B2: checkout autofill ────────────────────────────────────────────────

    public function test_checkout_fields_invite_autofill_and_the_number_pad(): void
    {
        $this->product();
        $this->post('/cart/add/'.Product::first()->slug, ['qty' => 1]);

        $html = $this->get('/checkout')->assertOk()->getContent();

        // The phone field is the one a COD order lives or dies on.
        $this->assertMatchesRegularExpression('/name="phone"[^>]*autocomplete="tel"/s', preg_replace('/\s+/', ' ', $html));
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('autocomplete="name"', $html);
        $this->assertStringContainsString('autocomplete="street-address"', $html);
    }

    // ── B6: image variants + srcset ──────────────────────────────────────────

    public function test_variant_creates_a_downscaled_sibling(): void
    {
        Storage::fake('public');
        $path = $this->storedImage();

        $variant = app(ImageOptimizer::class)->variant($path, 450);

        $this->assertSame('products/test-image@450.webp', $variant);
        Storage::disk('public')->assertExists($variant);
        // Smaller in pixels AND meaningfully smaller on disk.
        $this->assertLessThan(Storage::disk('public')->size($path), Storage::disk('public')->size($variant));
    }

    public function test_variant_refuses_to_upscale(): void
    {
        Storage::fake('public');
        $img = imagecreatetruecolor(300, 300);
        ob_start();
        imagewebp($img, null, 80);
        Storage::disk('public')->put('products/small.webp', ob_get_clean());
        imagedestroy($img);

        $this->assertNull(app(ImageOptimizer::class)->variant('products/small.webp', 450));
    }

    public function test_the_helper_finds_a_variant_from_the_public_url(): void
    {
        Storage::fake('public');
        $path = $this->storedImage();
        app(ImageOptimizer::class)->variant($path, 450);

        $url = Storage::disk('public')->url($path);

        $this->assertStringContainsString('test-image@450.webp', (string) image_variant($url, 450));
        $this->assertNull(image_variant('https://cdn.example.com/remote.webp'));
        $this->assertNull(image_variant($url, 900)); // that width was never generated
    }

    public function test_product_cards_emit_srcset_when_a_variant_exists(): void
    {
        Storage::fake('public');
        $path = $this->storedImage();
        $product = $this->product(['is_featured' => true]);
        ProductImage::create(['product_id' => $product->id, 'path' => $path, 'is_primary' => true, 'position' => 1]);
        app(ImageOptimizer::class)->variant($path, 450);

        // The React card builds `srcset="… 450w"` from the thumb450 prop, so
        // the guarantee to test is that the prop carries the variant URL.
        $this->get('/shop')->assertOk()->assertInertia(
            fn ($page) => $page->component('Catalog')
                ->where('products.data.0.thumb450', fn ($v) => str_contains((string) $v, 'test-image@450.webp'))
        );
    }

    public function test_the_backfill_command_generates_missing_variants(): void
    {
        Storage::fake('public');
        $path = $this->storedImage();
        $product = $this->product();
        ProductImage::create(['product_id' => $product->id, 'path' => $path, 'is_primary' => true, 'position' => 1]);

        $this->artisan('images:variants')->assertSuccessful();

        Storage::disk('public')->assertExists('products/test-image@450.webp');
    }

    public function test_the_media_library_hides_variant_files(): void
    {
        Storage::fake('public');
        $path = $this->storedImage();
        app(ImageOptimizer::class)->variant($path, 450);
        $admin = User::create(['name' => 'A', 'email' => 'a@b.test', 'password' => bcrypt('x'), 'role' => 'admin']);

        $items = $this->actingAs($admin)->get('/admin/media/picker')
            ->assertOk()->json('items');

        $paths = collect($items)->pluck('path');
        $this->assertTrue($paths->contains($path));
        $this->assertFalse($paths->contains('products/test-image@450.webp'));
    }

    // ── B7: structured data ──────────────────────────────────────────────────

    public function test_the_product_page_emits_product_and_breadcrumb_schema(): void
    {
        $product = $this->product();

        $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"price":"1450.00"', $html);
        $this->assertStringContainsString('"priceCurrency":"BDT"', $html);
        $this->assertStringContainsString('"availability":"https://schema.org/InStock"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"name":"Earrings"', $html);
        // No reviews yet → no aggregateRating (a zero-count rating violates
        // Google's rich-results policy and can cost the whole snippet).
        $this->assertStringNotContainsString('aggregateRating', $html);
    }

    public function test_every_gallery_style_product_template_shows_the_breadcrumb(): void
    {
        // One React product page now serves every template setting, and it
        // always renders the breadcrumb — whatever legacy template the theme
        // still names, the page must keep receiving the category trail that
        // the server-rendered BreadcrumbList JSON-LD describes.
        $product = $this->product();

        foreach (['sticky', 'showcase', 'classic', 'luxe'] as $template) {
            Setting::put('theme', ['product_template' => $template]);

            $this->get('/product/'.$product->slug)->assertOk()->assertInertia(
                fn ($page) => $page->component('Product')
                    ->where('product.category.name', 'Earrings')
            );
        }
    }

    public function test_the_homepage_does_not_claim_to_be_a_product(): void
    {
        // The $product-leak guard, restated for schema: only product.show may
        // emit Product JSON-LD, whatever variables other pages leave behind.
        $this->product(['is_featured' => true]);

        $this->get('/')->assertOk()->assertDontSee('"@type":"Product"', false);
    }

    // ── B10: the promise band speaks for itself ──────────────────────────────

    public function test_the_promise_band_no_longer_parrots_the_hero_subtitle(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);
        Setting::put('home_content', ['hero_subtitle' => 'A very specific hero sentence.']);

        $html = $this->get('/')->assertOk()->getContent();

        // The subtitle also (correctly) serves as the <meta name="description">,
        // so count only what a visitor actually sees: the body.
        $body = Str::after($html, '</head>');
        $this->assertSame(1, substr_count($body, 'A very specific hero sentence.'));
        $this->assertStringContainsString(Str::limit(config('home.defaults.promise_text'), 40, ''), $body);
    }
}
