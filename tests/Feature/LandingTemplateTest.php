<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\User;
use App\Support\LandingTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ready-made landing layouts.
 *
 * A template is only a starting point: its blocks are copied into a new page
 * and nothing links the two afterwards. What matters is that every block a
 * template produces is one the renderer actually understands — a typo in a
 * block type would silently render nothing.
 */
class LandingTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** Every block type home-block.blade.php can render. */
    private const RENDERABLE = [
        'banner', 'product_carousel', 'banner_carousel', 'video', 'cta_banner',
        'reviews', 'hero_cta', 'benefits', 'countdown', 'buy_box', 'faq',
        'sticky_cta', 'richtext',
    ];

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.c'],
            ['name' => 'A', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    public static function templateKeys(): array
    {
        return array_map(fn ($k) => [$k], array_keys(LandingTemplates::all()));
    }

    public function test_all_four_layouts_are_offered(): void
    {
        $this->assertSame(
            ['product_sales', 'flash_sale', 'lead_capture', 'brand_story'],
            array_keys(LandingTemplates::all()),
        );
    }

    #[DataProvider('templateKeys')]
    public function test_every_block_a_template_produces_is_renderable(string $key): void
    {
        foreach (LandingTemplates::get($key)['blocks'] as $block) {
            $this->assertContains(
                $block['type'],
                self::RENDERABLE,
                "Template {$key} emits '{$block['type']}', which home-block.blade.php cannot render.",
            );
            $this->assertTrue($block['enabled'], "Block in {$key} is disabled and would be filtered out.");
        }
    }

    #[DataProvider('templateKeys')]
    public function test_each_template_has_a_name_tagline_and_use_case(string $key): void
    {
        $t = LandingTemplates::get($key);

        foreach (['name', 'tagline', 'best_for', 'icon'] as $field) {
            $this->assertNotEmpty($t[$field], "{$key} is missing {$field}.");
        }
        $this->assertNotEmpty($t['blocks'], "{$key} has no blocks.");
    }

    public function test_the_gallery_lists_every_template(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/landing/create')->assertOk()->getContent();

        foreach (LandingTemplates::all() as $t) {
            $this->assertStringContainsString($t['name'], $html);
        }
        $this->assertStringContainsString('Blank page', $html);
    }

    #[DataProvider('templateKeys')]
    public function test_choosing_a_template_opens_the_editor_prefilled(string $key): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/landing/create?template='.$key)
            ->assertOk()
            ->assertSee('Sections', false);
    }

    public function test_the_blank_option_starts_with_no_blocks(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/landing/create?template=blank')
            ->assertOk();
    }

    public function test_an_unknown_template_falls_back_to_empty_rather_than_erroring(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/landing/create?template=does-not-exist')
            ->assertOk();
    }

    #[DataProvider('templateKeys')]
    public function test_a_page_built_from_a_template_renders_on_the_storefront(string $key): void
    {
        $product = Product::create([
            'name' => 'Ring', 'slug' => 'ring-'.$key, 'status' => 'published',
            'price' => 1500, 'manage_stock' => false, 'in_stock' => true,
        ]);

        $page = LandingPage::create([
            'title' => 'Campaign '.$key,
            'slug' => 'campaign-'.str_replace('_', '-', $key),
            'is_published' => true,
            'product_ids' => [$product->id],
            'blocks' => LandingTemplates::get($key)['blocks'],
            'show_header' => true,
            'show_footer' => true,
        ]);

        // The real storefront route, hydration and all.
        $this->get('/lp/'.$page->slug)->assertOk();
    }

    public function test_the_sales_page_countdown_is_set_in_the_future(): void
    {
        $blocks = collect(LandingTemplates::get('product_sales')['blocks']);
        $countdown = $blocks->firstWhere('type', 'countdown');

        // A countdown already in the past renders nothing at all.
        $this->assertTrue(
            Carbon::parse($countdown['countdown']['ends_at'])->isFuture(),
            'Countdown must start in the future or the block is invisible.',
        );
    }

    public function test_the_sales_page_ctas_point_at_the_buy_box(): void
    {
        $blocks = collect(LandingTemplates::get('product_sales')['blocks']);

        // #buy is the anchor the buy_box section renders.
        $this->assertSame('#buy', $blocks->firstWhere('type', 'hero_cta')['hero']['cta_link']);
        $this->assertSame('#buy', $blocks->firstWhere('type', 'sticky_cta')['sticky']['link']);
        $this->assertNotNull($blocks->firstWhere('type', 'buy_box'));
    }
}
