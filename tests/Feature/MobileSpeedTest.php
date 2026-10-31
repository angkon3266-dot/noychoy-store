<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Services\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The 31 Oct 2026 Lighthouse audit: mobile 44/100 on the homepage. Three of
 * its causes live in the payload the server builds: the hero clip with no
 * poster, product-card hover photos sent at full size, and the fonts
 * stylesheet fetched as a render-blocking link.
 */
class MobileSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function image(string $path = 'products/second.webp', int $size = 1200): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 150, 100));
        ob_start();
        imagewebp($img, null, 80);
        Storage::disk('public')->put($path, ob_get_clean());
        imagedestroy($img);

        return $path;
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Speed Ring', 'slug' => 'speed-ring', 'status' => 'published',
            'price' => 1200, 'manage_stock' => false, 'is_featured' => true,
        ]);
    }

    // ── Hover photo ──────────────────────────────────────────────────────────

    public function test_a_cards_hover_photo_is_served_at_card_size_with_a_srcset(): void
    {
        Storage::fake('public');
        $product = $this->product();
        ProductImage::create(['product_id' => $product->id, 'path' => $this->image('products/first.webp'), 'is_primary' => true, 'position' => 0]);
        ProductImage::create(['product_id' => $product->id, 'path' => $this->image('products/second.webp'), 'is_primary' => false, 'position' => 1]);
        app(ImageOptimizer::class)->variant('products/second.webp', 450);

        $this->get('/shop')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.hover', fn ($v) => str_contains((string) $v, 'second@450.webp'))
            ->where('products.data.0.hover_srcset', fn ($v) => str_contains((string) $v, 'second@450.webp 450w') && str_contains((string) $v, 'second.webp 1600w')));
    }

    public function test_a_product_with_one_photo_has_no_hover(): void
    {
        Storage::fake('public');
        $product = $this->product();
        ProductImage::create(['product_id' => $product->id, 'path' => $this->image('products/only.webp'), 'is_primary' => true, 'position' => 0]);

        $this->get('/shop')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.hover', null)
            ->where('products.data.0.hover_srcset', null));
    }

    // ── Hero clip poster ─────────────────────────────────────────────────────

    public function test_a_still_beside_the_hero_clip_is_its_poster_and_the_preloaded_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('hero-videos/clip.mp4', 'not really a video');
        $this->image('hero-videos/clip.webp', 1080);
        Setting::put('theme', ['homepage_template' => 'couture']); // the React homepage
        Setting::put('home_content', array_merge((array) Setting::get('home_content', []), [
            'hero_slides' => [['video' => 'hero-videos/clip.mp4', 'link' => '']],
        ]));

        $res = $this->get('/')->assertOk();

        $res->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hero.slides.0.type', 'video')
            ->where('hero.slides.0.video.type', 'file')
            ->where('hero.slides.0.image', fn ($v) => str_ends_with((string) $v, 'hero-videos/clip.webp')));

        // The browser is told about the poster before React runs.
        $this->assertMatchesRegularExpression('/<link rel="preload" as="image" href="[^"]*hero-videos\/clip\.webp"/', $res->getContent());
    }

    public function test_a_hero_clip_with_no_still_has_no_poster(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('hero-videos/clip.mp4', 'not really a video');
        Setting::put('theme', ['homepage_template' => 'couture']); // the React homepage
        Setting::put('home_content', array_merge((array) Setting::get('home_content', []), [
            'hero_slides' => [['video' => 'hero-videos/clip.mp4', 'link' => '']],
        ]));

        $res = $this->get('/')->assertOk();

        $res->assertInertia(fn (AssertableInertia $page) => $page->where('hero.slides.0.image', null));
        $this->assertStringNotContainsString('rel="preload" as="image"', $res->getContent());
    }

    public function test_a_still_the_owner_attached_to_a_video_slide_wins(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('hero-videos/clip.mp4', 'x');
        $this->image('hero-videos/clip.webp', 1080);
        $this->image('hero/chosen.webp', 1080);
        Setting::put('theme', ['homepage_template' => 'couture']); // the React homepage
        Setting::put('home_content', array_merge((array) Setting::get('home_content', []), [
            'hero_slides' => [['video' => 'hero-videos/clip.mp4', 'image' => 'hero/chosen.webp', 'link' => '']],
        ]));

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hero.slides.0.image', fn ($v) => str_ends_with((string) $v, 'hero/chosen.webp')));
    }

    // ── Fonts ────────────────────────────────────────────────────────────────

    public function test_the_brand_font_faces_are_inlined_rather_than_linked(): void
    {
        if (! brand_font_css_url()) {
            $this->markTestSkipped('no built fonts stylesheet in this checkout');
        }

        foreach (['/', '/login'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('@font-face', $html, $url);
            $this->assertStringContainsString('Instrument Sans', $html, $url);
            $this->assertDoesNotMatchRegularExpression('/<link rel="stylesheet" href="[^"]*\/fonts-[^"]*\.css"/', $html, $url);
        }
    }
}
