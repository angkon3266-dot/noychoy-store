<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The product page payload after the phone de-clutter: the vertical
 * trust-badge list beside the buy button stays, while the member pill, the
 * arrival box, the gift-ladder line and the Care / Shipping & returns
 * accordions are gone from the page and from the props.
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

    public function test_page_carries_the_trust_list_and_none_of_the_removed_blocks(): void
    {
        $this->get(route('product.show', $this->product()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Product')
                // The config default promise list, whatever its current wording.
                ->has('trustBadges', count(config('theme.defaults.trust_badges')))
                ->where('trustBadges.0.title', config('theme.defaults.trust_badges.0.title'))
                ->missing('care')
                ->missing('returns')
                ->missing('refundUrl')
                ->missing('delivery')
                ->missing('giftBadge')
                ->missing('memberBanner')
                ->missing('ui.registerPct')
                // Still read by the offers list and the reviews perk box.
                ->has('ui.isMember')
                ->has('ui.registerUrl')
                ->has('ui.loginUrl'),
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
}
