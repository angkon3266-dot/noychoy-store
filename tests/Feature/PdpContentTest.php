<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The product page payload after the phone de-clutter: the vertical
 * trust-badge list beside the buy button stays, while the member pill, the
 * arrival box, the gift-ladder line and the Shipping & returns accordion are
 * gone from the page and from the props. Care came back on 2026-09-22 as a
 * folded section, the same text on every product.
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
                ->has('pdpPoints.items', count(config('theme.defaults.pdp_points')))
                ->where('pdpPoints.items.0.title', config('theme.defaults.pdp_points.0.title'))
                ->where('care', config('theme.defaults.pdp_care_text'))
                ->missing('returns')
                ->missing('refundUrl')
                ->missing('delivery')
                ->missing('giftBadge')
                // The ladder came back on 17 Sep 2026 as a quote on the price
                // and a row under Add to cart — not the old line — and only
                // when the ladder is live, which it is not by default.
                ->where('ladderQuote', null)
                ->missing('memberBanner')
                ->missing('ui.registerPct')
                // Still read by the offers list and the reviews perk box.
                ->has('ui.isMember')
                ->has('ui.registerUrl')
                ->has('ui.loginUrl'),
            );
    }

    public function test_care_instructions_follow_appearance_and_blank_hides_them(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'care@b.test', 'password' => bcrypt('secret'), 'role' => 'admin']);
        $payload = ['homepage_template' => 'couture', 'product_template' => 'showcase'];
        $product = $this->product();
        $care = "- Keep it dry.\n- Store it in the pouch.";

        $this->actingAs($admin)->post('/admin/appearance', $payload + ['pdp_care_text' => $care])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page->where('care', $care));

        // A save that leaves the box out of the post keeps the text.
        $this->post('/admin/appearance', $payload)->assertRedirect();
        $this->assertSame($care, theme('pdp_care_text'));

        $this->post('/admin/appearance', $payload + ['pdp_care_text' => ''])->assertRedirect();

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page->where('care', null));

        $this->get('/admin/appearance')->assertOk()->assertSee('name="pdp_care_text"', false);
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
