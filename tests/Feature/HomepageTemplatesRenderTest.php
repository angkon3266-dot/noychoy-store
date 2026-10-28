<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every homepage design in Admin → Appearance → Homepage → "Homepage design"
 * renders, with the kind of content the live store has: products with photos,
 * reviews, hero slides, occasion tiles and custom builder sections.
 *
 * Owner, 2026-10-28: "the other template for homepage is not working now".
 * The builder (2026-10-24) changed what the controller hands every template;
 * only the React one was being rendered by the suite.
 */
class HomepageTemplatesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function seedStore(): void
    {
        $category = Category::create(['name' => 'Earrings', 'slug' => 'earrings', 'is_active' => true, 'image' => 'categories/e.webp']);
        foreach (range(1, 6) as $n) {
            $p = Product::create([
                'name' => "Piece $n", 'slug' => 'piece-'.$n.'-'.Str::random(4), 'sku' => uniqid(), 'price' => 1000 + $n,
                'category_id' => $category->id, 'status' => 'published', 'stock_quantity' => 5,
                'is_featured' => true, 'is_bestseller' => $n < 4,
            ]);
            $p->images()->create(['path' => "products/p$n.webp", 'position' => 0, 'is_primary' => true]);
            Review::create(['product_id' => $p->id, 'author_name' => "Buyer $n", 'rating' => 5, 'status' => 'approved', 'body' => 'Lovely.']);
        }

        Setting::put('home_content', [
            'hero_slides' => [
                ['image' => 'hero/a.webp', 'link' => '/shop', 'alt' => 'A'],
                ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'link' => ''],
            ],
            'occasions' => [['label' => 'Eid Gifts', 'tagline' => 'x', 'link' => null, 'image' => 'occasions/e.webp']],
            'sections' => [
                ['id' => 'b0', 'type' => 'banner_carousel', 'enabled' => true, 'title' => 'Premium earrings', 'banner' => ['image' => 'sections/b.webp', 'link' => '/shop'], 'source' => 'new', 'limit' => 4],
                ['id' => 'b1', 'type' => 'banner', 'enabled' => true, 'title' => '', 'layout' => 'dual', 'images' => [['image' => 'sections/c.webp', 'link' => '/shop']]],
                ['id' => 'b2', 'type' => 'reviews', 'enabled' => true, 'title' => 'Love', 'review_ids' => [1, 2]],
            ],
            'section_order' => ['featured', 'block:b0', 'ribbon'],
            'highlight_category_ids' => [$category->id],
            'featured_lead_mode' => 'first',
        ]);
    }

    public function test_every_homepage_design_renders(): void
    {
        $this->seedStore();

        foreach (array_keys(config('theme.homepage_templates')) as $key) {
            Setting::put('theme', ['homepage_template' => $key]);
            \Illuminate\Support\Facades\Cache::flush();

            $response = $this->get('/');
            $this->assertSame(200, $response->status(), "Homepage design '$key' did not render: ".Str::limit(strip_tags((string) ($response->exception?->getMessage() ?? $response->getContent())), 400));
        }
    }

    public function test_the_classic_designs_have_no_broken_inline_script(): void
    {
        // The cart seed was written with {{ json_encode() }}; Blade turned its
        // quotes into &quot; and the script died before anything else ran.
        $this->seedStore();
        Setting::put('theme', ['homepage_template' => 'aurelia']);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('__cartSavedText = &quot;', $html);
        $this->assertMatchesRegularExpression("/window.__cartSavedText = '[^']*';/", $html);
    }

    public function test_editorial_is_a_react_design_with_its_own_layout(): void
    {
        $this->seedStore();
        Setting::put('theme', ['homepage_template' => 'editorial']);

        $this->assertTrue(\App\Support\HomePage::isReact('editorial'));
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Home')
            ->where('template', 'editorial')
            ->where('chrome.inertiaHome', true));
    }

    public function test_switching_design_shows_in_the_live_preview_before_saving(): void
    {
        $this->seedStore();
        Setting::put('theme', ['homepage_template' => 'couture']);
        $admin = \App\Models\User::firstOrCreate(['email' => 'a@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);

        $url = $this->actingAs($admin)->postJson('/admin/appearance/preview', ['homepage_template' => 'editorial', 'home' => []])->assertOk()->json('url');

        $this->get($url)->assertInertia(fn ($page) => $page->where('template', 'editorial')->where('preview', true));
        $this->get('/')->assertInertia(fn ($page) => $page->where('template', 'couture'));
        $this->assertSame('couture', theme('homepage_template'));
    }
}
