<?php

namespace Tests\Feature;

use App\Http\Controllers\Shop\HomeController;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The collection spotlight (owner, 2026-11-27 — the Moissanite Edit): a dark
 * campaign band with a looping film, figures and a button, plus the
 * collection's products, all set in Admin → Appearance → Homepage.
 */
class HomeSpotlightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('theme', ['homepage_template' => 'couture']);
    }

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'spot@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
    }

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'earrings'], ['name' => 'Earrings']);

        return Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'sku' => uniqid(),
            'price' => 2290, 'category_id' => $category->id, 'status' => 'published', 'stock_quantity' => 5,
        ], $attrs));
    }

    protected function save(array $payload): void
    {
        $this->actingAs($this->admin())->post('/admin/appearance', ['homepage_template' => 'couture'] + $payload)
            ->assertSessionHasNoErrors();
        Cache::forget(HomeController::CACHE_KEY);
    }

    public function test_it_is_off_until_switched_on_and_sits_under_the_ticker(): void
    {
        $this->product('Sunflower Halo Studs');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('spotlight.show', false)
            ->where('order.0', 'ribbon')
            ->where('order.1', 'spotlight'));
    }

    public function test_a_collection_spotlight_carries_its_products_lowest_price_film_and_figures(): void
    {
        $a = $this->product('Petal Brilliance Studs', ['tags' => 'moissanite', 'price' => 1890]);
        $b = $this->product('Teardrop Pendant', ['tags' => 'moissanite', 'price' => 2690]);
        $this->product('Pearl Studs');
        $collection = Collection::create(['name' => 'Moissanite', 'type' => 'smart', 'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'is', 'value' => 'moissanite']], 'is_active' => true]);

        $this->save([
            'home' => [
                'spotlight_title' => 'Brilliance, forever.',
                'spotlight_highlight' => 'forever.',
                'spotlight_video' => 'hero-videos/moissanite.mp4',
                'spotlight_source' => 'collection',
                'spotlight_collection_id' => (string) $collection->id,
                'spotlight_cta_link' => '/collection/moissanite',
            ],
            'home_show_spotlight_present' => 1,
            'home_show_spotlight' => '1',
            'spotlight_stats_present' => 1,
            'spotlight_stats' => [['value' => 'D', 'label' => 'Colour'], ['value' => '', 'label' => 'dropped'], ['value' => 'VVS1', 'label' => 'Clarity']],
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('spotlight.show', true)
            ->where('spotlight.headingHtml', 'Brilliance, <em>forever.</em>')
            ->where('spotlight.video.src', Storage::disk('public')->url('hero-videos/moissanite.mp4'))
            ->where('spotlight.ctaLink', '/collection/moissanite')
            ->where('spotlight.fromLabel', 'From '.money(1890))
            ->has('spotlight.stats', 2)
            ->where('spotlight.stats.1.value', 'VVS1')
            ->has('spotlight.cards', 2)
            ->where('spotlight.cards', fn ($cards) => collect($cards)->pluck('id')->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all()));
    }

    public function test_the_heading_is_escaped_before_the_italic_is_added(): void
    {
        $this->product('Solitaire Studs');
        Setting::put('home_content', [
            'show_spotlight' => true,
            'spotlight_title' => '<b>Bold</b> forever',
            'spotlight_highlight' => 'forever',
            'spotlight_source' => 'auto',
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('spotlight.headingHtml', '&lt;b&gt;Bold&lt;/b&gt; <em>forever</em>'));
    }

    public function test_switched_on_with_nothing_to_play_or_buy_it_stays_hidden(): void
    {
        Setting::put('home_content', ['show_spotlight' => true, 'spotlight_source' => 'products', 'spotlight_product_ids' => []]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('spotlight.show', false));
    }

    public function test_the_admin_uploads_a_film_and_hand_picks_the_products(): void
    {
        Storage::fake('public');
        $a = $this->product('Clover Studs');
        $b = $this->product('Clover Pendant');

        $this->save([
            'home' => ['spotlight_source' => 'products'],
            'home_show_spotlight_present' => 1,
            'home_show_spotlight' => '1',
            'spotlight_video_file' => UploadedFile::fake()->create('edit.mp4', 900, 'video/mp4'),
            'home_products_present' => ['spotlight' => 1],
            'home_products' => ['spotlight' => [$b->id, $a->id]],
        ]);

        $stored = Setting::get('home_content');
        $this->assertStringStartsWith('hero-videos/', $stored['spotlight_video']);
        Storage::disk('public')->assertExists($stored['spotlight_video']);
        $this->assertSame([$b->id, $a->id], $stored['spotlight_product_ids']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('spotlight.cards.0.id', $b->id)
            ->where('spotlight.cards.1.id', $a->id));
    }

    public function test_the_builder_shows_the_spotlight_panel(): void
    {
        $this->actingAs($this->admin())->get('/admin/appearance')->assertOk()
            ->assertSee('Collection spotlight')
            ->assertSee('name="spotlight_video_file"', false)
            ->assertSee('name="section_order[]" value="spotlight"', false);
    }
}
