<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned product page: vertical trust-badge list, the arrival box
 * with a dispatch date, the Care / Shipping & returns accordions, and the
 * gift-ladder badge line.
 */
class PdpContentTest extends TestCase
{
    use RefreshDatabase;

    protected function product(): Product
    {
        return Product::create([
            'name' => 'Turquoise Earrings',
            'slug' => 'turquoise-earrings',
            'status' => 'published',
            'price' => 1450,
            'manage_stock' => false,
            'in_stock' => true,
            'description' => "## Design\n- Cascading tassel silhouette.",
        ]);
    }

    public function test_page_carries_badges_accordions_and_dispatch_date(): void
    {
        $this->get(route('product.show', $this->product()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Product')
                ->has('trustBadges', 5)   // the config default promise list
                ->where('trustBadges.0.title', 'Cash on Delivery')
                ->where('care', theme('pdp_care_text'))
                ->where('returns', theme('pdp_returns_text'))
                ->where('refundUrl', route('page.refund'))
                ->has('delivery.dispatch')
                ->where('giftBadge', null),
            );
    }

    public function test_gift_badge_appears_when_the_ladder_is_live(): void
    {
        $gift = Product::create([
            'name' => 'Gift stud', 'slug' => 'gift-stud', 'status' => 'published',
            'price' => 500, 'manage_stock' => false, 'in_stock' => true,
        ]);
        $collection = Collection::create(['name' => 'Milestone Gifts', 'type' => 'manual', 'is_active' => true]);
        $collection->products()->attach($gift->id, ['position' => 0]);

        Setting::put('gift_ladder_enabled', true);
        Setting::put('gift_ladder_gifts_collection_id', $collection->id);

        $this->get(route('product.show', $this->product()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('giftBadge.url', $collection->url())
                ->where('giftBadge.label', 'Shop More, Unlock Up to '.money(1500.0).' in Gifts'),
            );
    }

    public function test_seo_surfaces_print_description_without_markdown_tokens(): void
    {
        $html = $this->get(route('product.show', $this->product()))->getContent();

        // The raw "## Design" stays only in the Inertia props JSON, where React
        // needs it to render the sections. Every crawler-facing surface — the
        // pre-hydration shell, the meta description, the JSON-LD — is stripped.
        $shell = substr($html, strpos($html, 'id="seo-shell"'));
        $this->assertStringContainsString('Cascading tassel silhouette.', $shell);
        $this->assertStringNotContainsString('## Design', $shell);

        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);
        $this->assertStringNotContainsString('##', $m[1] ?? '##');

        preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $html, $ld);
        $this->assertStringNotContainsString('## Design', $ld[1] ?? '## Design');
    }

    public function test_accordions_hide_when_blanked_in_admin(): void
    {
        Setting::put('theme', ['pdp_care_text' => '', 'pdp_returns_text' => '']);

        $this->get(route('product.show', $this->product()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('care', null)
                ->where('returns', null),
            );
    }
}
