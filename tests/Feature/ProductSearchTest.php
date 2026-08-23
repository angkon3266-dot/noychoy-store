<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Search used to be one LIKE '%whole phrase%'.
 *
 * That meant a shopper typing two words — which is what shoppers type — got
 * nothing at all unless a product name contained that exact string. "gold ring"
 * missed "Aurora Ring — Gold Plated" entirely, and the empty page offered one
 * generic line and a button back to the top.
 */
class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.$n,
            'status' => 'published',
            'price' => 2000,
            'manage_stock' => false,
            'in_stock' => true,
        ], $attrs));
    }

    protected function results(string $q): array
    {
        return Product::published()->search($q)->pluck('name')->all();
    }

    // ── The case that was completely broken ────────────────────────────────

    public function test_two_words_in_any_order_find_the_product(): void
    {
        $this->product('Aurora Ring — Gold Plated');
        $this->product('Silver Anklet');

        foreach (['gold ring', 'ring gold', 'Gold Ring'] as $query) {
            $this->assertContains('Aurora Ring — Gold Plated', $this->results($query), "failed for: $query");
            $this->assertNotContains('Silver Anklet', $this->results($query));
        }
    }

    public function test_every_word_has_to_match_not_just_one(): void
    {
        $this->product('Gold Ring');
        $this->product('Silver Bangle');

        // "gold bangle" must not return both just because each word appears
        // somewhere in the catalogue.
        $this->assertSame([], $this->results('gold bangle'));
    }

    public function test_a_word_can_match_the_tags_or_the_category(): void
    {
        $category = Category::create(['name' => 'Bridal', 'slug' => 'bridal', 'is_active' => true]);

        $this->product('Aurora Set', ['tags' => 'gift, eid', 'category_id' => $category->id]);

        $this->assertContains('Aurora Set', $this->results('gift'));
        $this->assertContains('Aurora Set', $this->results('bridal'));
        $this->assertContains('Aurora Set', $this->results('bridal gift'));
    }

    public function test_noise_words_do_not_break_a_search(): void
    {
        $this->product('Gold Ring');

        // "the a and" carry no meaning; requiring them would match nothing.
        $this->assertContains('Gold Ring', $this->results('a gold ring'));
    }

    // ── Ranking ────────────────────────────────────────────────────────────

    public function test_the_best_match_comes_first_not_the_newest(): void
    {
        // Deliberately created oldest-first, so a "newest" sort would invert it.
        $this->product('Ring');
        $this->product('Ring With Pearl Drop');
        $this->product('Necklace', ['tags' => 'ring-adjacent']);

        $ordered = ProductSearch::orderByRelevance(
            Product::published()->search('ring'), 'ring'
        )->pluck('name')->all();

        $this->assertSame('Ring', $ordered[0], 'an exact name match should win');
    }

    public function test_a_search_page_defaults_to_best_match(): void
    {
        $this->product('Ring');

        $this->get('/shop?q=ring')->assertInertia(
            fn (Assert $page) => $page->where('sort', 'new')->has('products.data', 1)
        );
    }

    // ── Typos ──────────────────────────────────────────────────────────────

    public function test_a_misspelling_is_offered_a_correction(): void
    {
        $this->product('Gold Ring');

        // One transposed letter.
        $this->assertSame('ring', ProductSearch::didYouMean('rign'));
    }

    public function test_a_word_that_is_already_correct_gets_no_suggestion(): void
    {
        $this->product('Gold Ring');

        $this->assertNull(ProductSearch::didYouMean('ring'));
    }

    public function test_a_wildly_different_word_is_not_corrected(): void
    {
        $this->product('Gold Ring');

        // A bad guess is worse than no guess.
        $this->assertNull(ProductSearch::didYouMean('motorcycle'));
    }

    // ── The dead end ───────────────────────────────────────────────────────

    public function test_a_search_with_no_results_offers_a_way_forward(): void
    {
        $this->product('Gold Ring', ['is_bestseller' => true]);

        $this->get('/shop?q=rign')->assertInertia(
            fn (Assert $page) => $page
                ->component('Catalog')
                ->where('products.total', 0)
                ->where('noResults.term', 'rign')
                ->where('noResults.didYouMean', 'ring')
                ->has('noResults.didYouMeanUrl')
        );
    }

    public function test_a_too_narrow_search_falls_back_to_the_nearest_products(): void
    {
        $this->product('Gold Ring');

        // "gold ring bracelet" matches nothing as a whole, but two of the three
        // words do — show those rather than an empty page.
        $this->get('/shop?q=gold+ring+bracelet')->assertInertia(
            fn (Assert $page) => $page
                ->where('products.total', 0)
                ->has('noResults.nearest', 1)
        );
    }

    public function test_browsing_with_no_search_term_gets_no_recovery_block(): void
    {
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('noResults', null));
    }

    // ── Tokeniser ──────────────────────────────────────────────────────────

    public function test_the_tokeniser_drops_noise_and_single_characters(): void
    {
        $this->assertSame(['gold', 'ring'], ProductSearch::tokens('the Gold a Ring'));
        $this->assertSame(['ring'], ProductSearch::tokens('  RING  '));
        $this->assertSame([], ProductSearch::tokens('   '));
    }

    public function test_an_absurdly_long_query_is_capped(): void
    {
        $tokens = ProductSearch::tokens('one two three four five six seven eight nine');

        $this->assertLessThanOrEqual(6, count($tokens));
    }
}
