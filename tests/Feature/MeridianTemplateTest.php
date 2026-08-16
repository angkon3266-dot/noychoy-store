<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Meridian homepage template — an occasion-led gifting funnel modelled on
 * meridianeclat.com, adapted for Bangladesh.
 *
 * The rule worth pinning down is that half-finished content never reaches the
 * storefront: an occasion tile without a picture is a labelled empty box, so
 * such rows drop out and the section hides with them. Everything else is the
 * existing homepage content, so these tests cover the seams rather than
 * re-testing carousels that already have coverage.
 */
class MeridianTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function useMeridian(array $home = []): void
    {
        Setting::put('theme', ['homepage_template' => 'meridian']);
        Setting::put('home_content', $home);
    }

    protected function product(string $name = 'Test Drops'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'earrings'], ['name' => 'Earrings', 'is_active' => true]);

        return Product::create([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'sku' => uniqid(),
            'price' => 1450, 'category_id' => $category->id,
            'status' => 'published', 'stock_quantity' => 5,
        ]);
    }

    public function test_the_template_is_selectable_and_renders(): void
    {
        $this->useMeridian();

        $this->get('/')->assertOk()->assertSee('Shop the collection');
        $this->assertArrayHasKey('meridian', config('theme.homepage_templates'));
    }

    // ── Occasion tiles ───────────────────────────────────────────────────────

    public function test_occasions_render_once_a_tile_has_an_image(): void
    {
        $this->useMeridian(['occasions' => [
            ['label' => 'Eid Gifts', 'tagline' => 'The biggest gifting season', 'image' => 'occasions/eid.webp', 'link' => '/lp/eid'],
        ]]);

        $this->get('/')->assertOk()
            ->assertSee('Shop by occasion')
            ->assertSee('Eid Gifts')
            ->assertSee('The biggest gifting season')
            ->assertSee('/lp/eid', false);
    }

    public function test_an_imageless_occasion_is_dropped_and_the_section_hides_with_it(): void
    {
        // The defaults ship 8 labelled-but-imageless tiles so the admin has
        // rows to fill in — none of them may reach the storefront.
        $this->useMeridian(['occasions' => [
            ['label' => 'Eid Gifts', 'tagline' => 'Not ready yet', 'image' => null, 'link' => null],
        ]]);

        $this->get('/')->assertOk()
            ->assertDontSee('Shop by occasion')
            ->assertDontSee('Not ready yet');
    }

    public function test_a_tile_without_a_link_falls_back_to_the_shop(): void
    {
        $this->useMeridian(['occasions' => [
            ['label' => 'Birthday Gift', 'tagline' => '', 'image' => 'occasions/bday.webp', 'link' => null],
        ]]);

        $this->get('/')->assertOk()->assertSee(route('shop'), false);
    }

    public function test_occasions_can_be_switched_off_wholesale(): void
    {
        $this->useMeridian([
            'show_occasions' => false,
            'occasions' => [['label' => 'Eid Gifts', 'tagline' => '', 'image' => 'occasions/eid.webp', 'link' => null]],
        ]);

        $this->get('/')->assertOk()->assertDontSee('Shop by occasion');
    }

    public function test_the_defaults_are_bangladesh_gifting_occasions(): void
    {
        // The two peaks of the local calendar, not a US bridal funnel.
        $labels = collect(config('home.defaults.occasions'))->pluck('label');

        $this->assertContains('Eid Gifts', $labels);
        $this->assertContains('Pohela Boishakh', $labels);
        $this->assertNotContains('Wedding Guest', $labels);
    }

    // ── Gift finder ──────────────────────────────────────────────────────────

    public function test_budget_bands_link_into_the_shop_price_filter(): void
    {
        $this->useMeridian();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Shopping for someone?', $html);
        $this->assertStringContainsString('price_max=1000', $html);
        $this->assertStringContainsString('price_min=2500', $html);
    }

    // ── Saving from the admin ────────────────────────────────────────────────

    public function test_saving_appearance_persists_occasion_tiles(): void
    {
        $this->actingAs($this->admin())->post('/admin/appearance', [
            'homepage_template' => 'meridian',
            'product_template' => 'showcase',
            'occasions' => [
                ['label' => 'Eid Gifts', 'tagline' => 'Biggest season', 'link' => '/lp/eid'],
                ['label' => '', 'tagline' => 'orphan row', 'link' => ''],
            ],
        ]);

        $saved = Setting::get('home_content', [])['occasions'];

        $this->assertCount(1, $saved, 'A row with no label is not a tile.');
        $this->assertSame('Eid Gifts', $saved[0]['label']);
        $this->assertSame('/lp/eid', $saved[0]['link']);
    }

    public function test_saving_without_touching_an_image_keeps_it(): void
    {
        // The image lives on a separate media field; re-saving the text must
        // not wipe a picture the admin uploaded earlier.
        Setting::put('home_content', ['occasions' => [
            ['label' => 'Eid Gifts', 'tagline' => 'Old', 'link' => null, 'image' => 'occasions/eid.webp'],
        ]]);

        $this->actingAs($this->admin())->post('/admin/appearance', [
            'homepage_template' => 'meridian',
            'product_template' => 'showcase',
            'occasions' => [['label' => 'Eid Gifts', 'tagline' => 'New copy', 'link' => '']],
        ]);

        $saved = Setting::get('home_content', [])['occasions'][0];

        $this->assertSame('occasions/eid.webp', $saved['image']);
        $this->assertSame('New copy', $saved['tagline']);
    }

    // ── FAQ block, now reachable from the homepage builder ───────────────────

    public function test_the_homepage_builder_accepts_a_faq_block(): void
    {
        $this->actingAs($this->admin())->post('/admin/appearance', [
            'homepage_template' => 'meridian',
            'product_template' => 'showcase',
            'home_sections_json' => json_encode([[
                'type' => 'faq', 'enabled' => true, 'title' => 'Questions, answered',
                'faqs' => [['q' => 'Will it tarnish?', 'a' => 'Rhodium-finished pieces keep their colour.']],
            ]]),
        ]);

        $this->get('/')->assertOk()
            ->assertSee('Will it tarnish?')
            ->assertSee('Rhodium-finished pieces keep their colour.');
    }
}
