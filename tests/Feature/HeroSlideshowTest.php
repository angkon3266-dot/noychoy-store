<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Couture homepage hero — a slideshow the admin can fully take over.
 *
 * Nothing added under Appearance → Hero slider: the hero auto-builds from the
 * uploaded hero image plus up to 5 featured products, so a fresh install still
 * has a working hero on day one. The moment the admin adds even one slide —
 * image or video — that list replaces the auto-built one entirely, because at
 * that point silently blending "what you chose" with "what we guessed" would
 * be more confusing than useful.
 */
class HeroSlideshowTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function useCouture(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);
    }

    protected function product(string $name = 'Test Ring'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        return Product::create([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'sku' => uniqid(), 'price' => 1000, 'category_id' => $category->id,
            'status' => 'published', 'stock_quantity' => 5,
        ]);
    }

    protected function baseAppearancePayload(): array
    {
        // Everything AppearanceController::update() requires that isn't the
        // point of these tests.
        return [
            'homepage_template' => 'couture',
            'product_template' => 'showcase',
        ];
    }

    // ── The admin panel itself ───────────────────────────────────────────────

    public function test_the_appearance_page_renders_with_a_mix_of_image_and_video_slides(): void
    {
        Setting::put('home_content', ['hero_slides' => [
            ['image' => 'hero/a.jpg', 'link' => ''],
            ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'link' => ''],
            ['video' => 'hero-videos/clip.mp4', 'link' => ''],
        ]]);

        $this->actingAs($this->admin())->get('/admin/appearance')
            ->assertOk()
            ->assertSee('Hero slider')
            ->assertSee('Add a video slide');
    }

    // ── Saving slides ────────────────────────────────────────────────────────

    public function test_a_pasted_video_url_is_saved_as_a_hero_slide(): void
    {
        $this->actingAs($this->admin())->post('/admin/appearance', $this->baseAppearancePayload() + [
            'hero_slide_video_urls' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ]);

        $slides = Setting::get('home_content', [])['hero_slides'] ?? [];

        $this->assertCount(1, $slides);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $slides[0]['video']);
    }

    public function test_an_uploaded_video_file_is_stored_and_saved_as_a_hero_slide(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->create('clip.mp4', 2000, 'video/mp4');

        $this->actingAs($this->admin())->post('/admin/appearance', $this->baseAppearancePayload() + [
            'hero_slide_videos' => [$file],
        ]);

        $slides = Setting::get('home_content', [])['hero_slides'] ?? [];

        $this->assertCount(1, $slides);
        Storage::disk('public')->assertExists($slides[0]['video']);
        $this->assertStringStartsWith('hero-videos/', $slides[0]['video']);
    }

    public function test_a_blank_video_row_saves_nothing(): void
    {
        $this->actingAs($this->admin())->post('/admin/appearance', $this->baseAppearancePayload() + [
            'hero_slide_video_urls' => [''],
        ]);

        $this->assertSame([], Setting::get('home_content', [])['hero_slides'] ?? []);
    }

    public function test_editing_the_link_on_a_mixed_image_and_video_list_hits_the_right_slide(): void
    {
        // The edit form's row index has to line up with the raw stored array
        // once slides stop being uniformly "has an image" — this is exactly
        // the kind of off-by-one a filtered-then-reindexed view would produce.
        Setting::put('home_content', ['hero_slides' => [
            ['image' => 'hero/a.jpg', 'link' => ''],
            ['video' => 'https://vimeo.com/12345', 'link' => ''],
            ['image' => 'hero/b.jpg', 'link' => ''],
        ]]);

        $this->actingAs($this->admin())->post('/admin/appearance', $this->baseAppearancePayload() + [
            'hero_slides' => [
                1 => ['link' => '/pages/our-story'],
            ],
        ]);

        $slides = Setting::get('home_content', [])['hero_slides'];

        $this->assertSame('', $slides[0]['link']);
        $this->assertSame('/pages/our-story', $slides[1]['link']);
        $this->assertSame('https://vimeo.com/12345', $slides[1]['video']);
        $this->assertSame('', $slides[2]['link']);
    }

    public function test_removing_a_video_slide_by_index_leaves_the_others_intact(): void
    {
        Setting::put('home_content', ['hero_slides' => [
            ['image' => 'hero/a.jpg', 'link' => ''],
            ['video' => 'https://vimeo.com/12345', 'link' => ''],
        ]]);

        $this->actingAs($this->admin())->post('/admin/appearance', $this->baseAppearancePayload() + [
            'hero_slides' => [
                1 => ['remove' => '1'],
            ],
        ]);

        $slides = Setting::get('home_content', [])['hero_slides'];

        $this->assertCount(1, $slides);
        $this->assertSame('hero/a.jpg', $slides[0]['image']);
    }

    // ── Rendering on the homepage ────────────────────────────────────────────

    public function test_a_youtube_slide_renders_as_a_muted_looping_embed(): void
    {
        $this->useCouture();
        Setting::put('home_content', ['hero_slides' => [
            ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'link' => ''],
        ]]);

        // The ambient-autoplay params are precomputed server-side into the
        // slide payload the React hero renders from.
        $this->get('/')->assertOk()->assertInertia(
            fn ($page) => $page->component('Home')
                ->where('hero.slides.0.video.embedUrl', function ($url) {
                    return str_contains((string) $url, 'youtube.com/embed/dQw4w9WgXcQ')
                        && str_contains((string) $url, 'autoplay=1')
                        && str_contains((string) $url, 'mute=1')
                        && str_contains((string) $url, 'loop=1');
                })
        );
    }

    public function test_an_uploaded_video_slide_renders_as_a_muted_looping_video_tag(): void
    {
        $this->useCouture();
        Storage::fake('public');
        Storage::disk('public')->put('hero-videos/clip.mp4', 'fake');
        Setting::put('home_content', ['hero_slides' => [
            ['video' => 'hero-videos/clip.mp4', 'link' => ''],
        ]]);

        // A type:"file" slide is what makes the React hero render
        // <video autoPlay muted loop playsInline> — assert that contract.
        $this->get('/')->assertOk()->assertInertia(
            fn ($page) => $page->component('Home')
                ->where('hero.slides.0.type', 'video')
                ->where('hero.slides.0.video.type', 'file')
                ->where('hero.slides.0.video.src', fn ($src) => str_contains((string) $src, 'hero-videos/clip.mp4'))
        );
    }

    public function test_a_video_slide_with_no_link_is_not_wrapped_in_an_anchor(): void
    {
        $this->useCouture();
        Setting::put('home_content', ['hero_slides' => [
            ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'link' => ''],
        ]]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<a href="" aria-label', $html);
    }

    public function test_a_video_slide_with_a_link_is_clickable(): void
    {
        $this->useCouture();
        Setting::put('home_content', ['hero_slides' => [
            ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'link' => '/pages/our-story'],
        ]]);

        $this->get('/')->assertOk()->assertInertia(
            fn ($page) => $page->component('Home')
                ->where('hero.slides.0.link', '/pages/our-story')
        );
    }

    public function test_any_saved_slide_replaces_the_auto_built_hero_entirely(): void
    {
        // Deliberately NOT featured: with no hero_image and no featured product
        // configured, the auto-built fallback would render zero hero slides. So
        // seeing the custom slide here already proves the admin list won —
        // there is nothing else that could have produced it.
        $this->useCouture();
        Setting::put('home_content', ['hero_slides' => [
            ['image' => 'hero/custom.jpg', 'link' => '/promo', 'alt' => 'Custom slide'],
        ]]);

        $this->get('/')->assertOk()->assertInertia(
            fn ($page) => $page->component('Home')
                ->has('hero.slides', 1)
                ->where('hero.slides.0.image', fn ($img) => str_contains((string) $img, 'hero/custom.jpg'))
        );
    }

    public function test_an_empty_hero_slides_list_falls_back_to_the_auto_built_hero(): void
    {
        $this->useCouture();
        $product = $this->product('Fallback Product');
        $product->update(['is_featured' => true]);
        // The fallback only turns a featured product into a slide when it has a
        // photo to show — give it one so the auto-built hero really triggers.
        \App\Models\ProductImage::create([
            'product_id' => $product->id, 'path' => 'products/fallback.webp',
            'is_primary' => true, 'position' => 1,
        ]);
        Setting::put('home_content', ['hero_slides' => []]);

        $url = route('product.show', $product);

        $this->get('/')->assertOk()->assertInertia(
            fn ($page) => $page->component('Home')
                ->where('hero.slides', fn ($slides) => collect($slides)->contains(fn ($s) => ($s['link'] ?? null) === $url))
        );
    }
}
