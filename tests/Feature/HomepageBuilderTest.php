<?php

namespace Tests\Feature;

use App\Http\Controllers\Shop\HomeController;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The homepage builder (Admin → Appearance → Homepage, owner 2026-10-24):
 * every section in page order with a switch, and everything a section shows
 * — words, links, products, the Featured tile, which reviews — editable.
 */
class HomepageBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'a@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
    }

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        return Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'sku' => uniqid(),
            'price' => 1000, 'category_id' => $category->id, 'status' => 'published', 'stock_quantity' => 5,
        ], $attrs));
    }

    protected function save(array $payload): void
    {
        $this->actingAs($this->admin())->post('/admin/appearance', ['homepage_template' => 'couture'] + $payload)
            ->assertSessionHasNoErrors();
        Cache::forget(HomeController::CACHE_KEY);
    }

    protected function home(): Assert
    {
        $captured = null;
        $this->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$captured) {
            $captured = $page->component('Home');
        });

        return $captured;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('theme', ['homepage_template' => 'couture']);
    }

    public function test_the_builder_lists_every_section_in_the_saved_order(): void
    {
        Setting::put('home_content', ['section_order' => ['featured', 'ribbon']]);

        $html = $this->actingAs($this->admin())->get('/admin/appearance')->assertOk()->getContent();

        $featured = strpos($html, 'name="section_order[]" value="featured"');
        $ribbon = strpos($html, 'name="section_order[]" value="ribbon"');
        $categories = strpos($html, 'name="section_order[]" value="categories"');
        $this->assertNotFalse($featured);
        $this->assertTrue($featured < $ribbon, 'Featured was moved above the ticker');
        // Sections missing from the saved order come back in their default place.
        $this->assertTrue($ribbon < $categories);
    }

    public function test_the_order_and_custom_sections_reach_the_homepage(): void
    {
        $this->save([
            'section_order_present' => 1,
            'home_blocks_present' => 1,
            'home_blocks' => ['promo1' => json_encode(['type' => 'richtext', 'enabled' => true, 'title' => 'Hello', 'html' => '<p>Hi</p>'])],
            // The builder posts every section; here New arrivals and the custom
            // section were dragged to the top. Unknown tokens are dropped.
            'section_order' => array_merge(['new', 'block:promo1', 'featured', 'not-a-section'],
                array_values(array_diff(array_keys(config('home.sections')), ['new', 'featured']))),
        ]);

        $stored = Setting::get('home_content');
        $this->assertSame('promo1', $stored['sections'][0]['id']);
        $this->assertSame(['new', 'block:promo1', 'featured', 'ribbon'], array_slice($stored['section_order'], 0, 4));
        $this->assertNotContains('not-a-section', $stored['section_order']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('order.0', 'new')
            ->where('order.1', 'block:promo1')
            ->where('order.2', 'featured')
            ->where('blocks.0.id', 'promo1'));
    }

    public function test_a_switched_off_section_is_hidden(): void
    {
        $this->save(['home_show_featured_present' => 1, 'home_show_membership_present' => 1, 'home_show_reviews_band_present' => 1]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('featured.show', false)
            ->where('membershipBand.show', false)
            ->where('reviewsSection.show', false));
    }

    public function test_featured_can_be_hand_picked_with_a_chosen_large_tile(): void
    {
        $a = $this->product('Alpha Ring');
        $b = $this->product('Bravo Ring');
        $c = $this->product('Charlie Ring');

        $this->save([
            'home' => ['featured_source' => 'products', 'featured_lead_mode' => 'product', 'featured_lead_product_id' => (string) $b->id, 'featured_subtitle' => 'Our picks'],
            'home_products_present' => ['featured' => 1],
            'home_products' => ['featured' => [$c->id, $a->id, $b->id]],
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('featured.subtitle', 'Our picks')
            ->where('featured.lead.type', 'product')
            ->where('featured.lead.card.id', $b->id)
            ->where('featured.cards.0.id', $c->id)
            ->where('featured.cards.1.id', $a->id)
            ->has('featured.cards', 2));
    }

    public function test_a_custom_large_tile_uses_the_owners_picture_and_words(): void
    {
        $this->product('Alpha Ring', ['is_featured' => true]);

        $this->save([
            'home' => ['featured_lead_mode' => 'custom', 'featured_lead_heading' => 'The Eid edit', 'featured_lead_link' => '/collection/eid'],
            'featured_lead_image_url' => 'https://cdn.example.test/eid.jpg',
        ]);
        $this->assertNotNull(Setting::get('home_content')['featured_lead_image'] ?? null);
    }

    public function test_a_product_section_can_come_from_a_collection(): void
    {
        $in = $this->product('Gift Ring', ['tags' => 'gift']);
        $this->product('Plain Ring');
        $collection = Collection::create(['name' => 'Eid Gifts', 'type' => 'smart', 'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'is', 'value' => 'gift']], 'is_active' => true]);

        $this->save(['home' => ['new_source' => 'collection', 'new_collection_id' => (string) $collection->id]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('newArrivals.cards', 1)
            ->where('newArrivals.cards.0.id', $in->id));
    }

    public function test_automatic_reviews_are_five_star_only_and_picks_win(): void
    {
        $p = $this->product('Review Ring');
        $five = Review::create(['product_id' => $p->id, 'author_name' => 'Five', 'rating' => 5, 'status' => 'approved', 'body' => 'Lovely.']);
        Review::create(['product_id' => $p->id, 'author_name' => 'Four', 'rating' => 4, 'status' => 'approved', 'body' => 'Good, but delivery took five days.']);
        $four = Review::where('author_name', 'Four')->first();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('reviews', 1)->where('reviews.0.author', 'Five'));

        $this->save(['reviews_ids_present' => 1, 'reviews_ids' => [$four->id, $five->id]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('reviews', 2)->where('reviews.0.author', 'Four')->where('reviews.1.author', 'Five'));
    }

    public function test_an_existing_slide_can_be_given_a_new_picture_alt_and_timing(): void
    {
        Setting::put('home_content', ['hero_slides' => [['image' => 'hero/a.jpg', 'link' => '/shop']]]);

        $this->save([
            'hero_slides' => [0 => ['position' => 0, 'link' => '/shop', 'alt' => 'Pink necklace', 'seconds' => '9']],
            'hero_slide_image_0_url' => 'https://cdn.example.test/new.jpg',
        ]);

        $slide = Setting::get('home_content')['hero_slides'][0];
        $this->assertSame('https://cdn.example.test/new.jpg', $slide['image']);
        $this->assertSame('Pink necklace', $slide['alt']);
        $this->assertEquals(9, $slide['seconds']);
        $this->assertSame('/shop', $slide['link']);
    }

    public function test_an_untouched_slide_keeps_its_picture(): void
    {
        Setting::put('home_content', ['hero_slides' => [['image' => 'hero/a.jpg', 'link' => '']]]);

        $this->save([
            'hero_slides' => [0 => ['position' => 0, 'link' => '']],
            'hero_slide_image_0_url' => \Illuminate\Support\Facades\Storage::disk('public')->url('hero/a.jpg'),
        ]);

        $this->assertSame('hero/a.jpg', Setting::get('home_content')['hero_slides'][0]['image']);
    }

    public function test_a_video_slide_autoplays_muted_with_a_sound_button_and_its_own_timing(): void
    {
        Setting::put('home_content', ['hero_slides' => [['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'link' => '']]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('hero.slides.0.sound', true)
            ->where('hero.slides.0.seconds', 15)
            ->where('hero.slides.0.video.embedUrl', fn ($url) => str_contains($url, 'mute=1') && str_contains($url, 'enablejsapi=1')));

        $this->save(['hero_slides' => [0 => ['position' => 0, 'link' => '', 'seconds' => '30', 'sound_present' => 1]]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('hero.slides.0.sound', false)
            ->where('hero.slides.0.seconds', 30));
    }

    public function test_the_builder_lists_save_and_an_emptied_list_stays_empty(): void
    {
        $this->save([
            'hero_trust_present' => 1, 'hero_trust' => ['Cash on delivery', '', 'Free returns'],
            'gifting_steps_present' => 1, 'gifting_steps' => [['title' => 'Pick', 'text' => 'Any piece']],
            'gift_promises_present' => 1, 'gift_promises' => [],
        ]);

        $home = Setting::get('home_content');
        $this->assertSame(['Cash on delivery', 'Free returns'], $home['hero_trust']);
        $this->assertSame([['title' => 'Pick', 'text' => 'Any piece']], $home['gifting_steps']);
        $this->assertSame([], $home['gift_promises']);
    }
}
