<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Setting;
use App\Support\DailyDeals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Deals of the Day" — the homepage promo carousel.
 *
 * It is deliberately built from the offers that already run the discounts at
 * checkout, rather than from a second list of promos kept somewhere else. That
 * is the whole point: the shop window cannot advertise a deal the cart does not
 * honour, because they are the same record.
 *
 * The rules worth pinning down are where a card sends you (an offer for one
 * product goes to that product; anything broader has no single destination and
 * goes to the shop) and when the section is allowed to exist at all.
 */
class DailyDealsTest extends TestCase
{
    use RefreshDatabase;

    protected function category(string $name = 'Rings'): Category
    {
        return Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
    }

    protected function product(string $name = 'Test Ring', ?Category $category = null): Product
    {
        return Product::create([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => Str::slug($name),
            'price' => 1000, 'category_id' => ($category ?? $this->category())->id,
            'status' => 'published', 'stock_quantity' => 5,
        ]);
    }

    protected function offer(array $attrs = []): Offer
    {
        return Offer::create(array_merge([
            'title' => 'Save today', 'description' => 'A good deal', 'type' => 'order_percent',
            'applies_to' => 'all', 'percent' => 20, 'is_active' => true, 'sort' => 1,
        ], $attrs));
    }

    protected function deals(array $home = []): array
    {
        Setting::put('home_content', $home);

        return DailyDeals::cards()->all();
    }

    // ── When the section exists at all ───────────────────────────────────────

    public function test_no_cards_when_no_offers_are_running(): void
    {
        $this->assertSame([], $this->deals());
    }

    public function test_an_inactive_offer_is_not_a_deal(): void
    {
        $this->offer(['is_active' => false]);

        $this->assertSame([], $this->deals());
    }

    public function test_the_section_can_be_switched_off(): void
    {
        $this->offer();

        $this->assertSame([], $this->deals(['show_deals' => false]));
    }

    public function test_a_passed_deadline_takes_the_section_down(): void
    {
        $this->offer();

        // Not merely "stop the countdown" — a deal that ended is not a deal, and
        // leaving it up is a promise the shop no longer keeps.
        $this->assertSame([], $this->deals(['deals_ends_at' => now()->subMinute()->toIso8601String()]));
    }

    public function test_a_future_deadline_leaves_it_running(): void
    {
        $this->offer();

        $this->assertCount(1, $this->deals(['deals_ends_at' => now()->addHour()->toIso8601String()]));
    }

    public function test_a_malformed_deadline_does_not_break_the_homepage(): void
    {
        $this->offer();

        $this->assertCount(1, $this->deals(['deals_ends_at' => 'whenever']));
        $this->assertNull(DailyDeals::endsAt());
    }

    // ── Where a card sends you ───────────────────────────────────────────────

    public function test_an_offer_on_one_product_links_to_that_product(): void
    {
        $product = $this->product('Gold Bangle');
        $this->offer(['applies_to' => 'products', 'product_ids' => [$product->id]]);

        $card = $this->deals()[0];

        $this->assertSame(route('product.show', $product), $card['href']);
    }

    public function test_an_offer_on_one_category_links_to_that_category(): void
    {
        $category = $this->category('Necklaces');
        $this->offer(['applies_to' => 'categories', 'category_ids' => [$category->id]]);

        $card = $this->deals()[0];

        $this->assertSame(route('category.show', $category), $card['href']);
    }

    public function test_an_offer_spanning_several_products_links_to_the_shop(): void
    {
        // No single right destination, so don't pick one arbitrarily.
        $a = $this->product('Ring A');
        $b = $this->product('Ring B');
        $this->offer(['applies_to' => 'products', 'product_ids' => [$a->id, $b->id]]);

        $this->assertSame(route('shop'), $this->deals()[0]['href']);
    }

    public function test_a_whole_order_offer_links_to_the_shop(): void
    {
        $this->product();
        $this->offer(['applies_to' => 'all']);

        $this->assertSame(route('shop'), $this->deals()[0]['href']);
    }

    public function test_a_product_offer_pointing_at_an_unpublished_product_falls_back_to_the_shop(): void
    {
        $product = $this->product();
        $product->update(['status' => 'draft']);
        $this->offer(['applies_to' => 'products', 'product_ids' => [$product->id]]);

        $this->assertSame(route('shop'), $this->deals()[0]['href']);
    }

    // ── What the card says ───────────────────────────────────────────────────

    public function test_a_percentage_offer_shows_the_percentage(): void
    {
        $this->offer(['percent' => 25]);

        $this->assertSame('25% OFF', $this->deals()[0]['discount']);
    }

    public function test_a_whole_percentage_is_not_padded_with_zeros(): void
    {
        $this->offer(['percent' => 12.50]);

        $this->assertSame('12.5% OFF', $this->deals()[0]['discount']);
    }

    public function test_a_free_shipping_offer_says_so_instead_of_a_percentage(): void
    {
        $this->offer(['type' => 'free_shipping', 'percent' => 0]);

        $this->assertSame('Free delivery', $this->deals()[0]['discount']);
    }

    public function test_the_offers_own_badge_wins_over_the_generic_type_label(): void
    {
        $this->offer(['badge_label' => 'Flash deal']);

        $this->assertSame('Flash deal', $this->deals()[0]['tag']);
    }

    public function test_without_a_badge_the_type_names_the_card(): void
    {
        $this->offer(['badge_label' => null, 'type' => 'free_shipping']);

        $this->assertSame('Free shipping', $this->deals()[0]['tag']);
    }

    // ── Members-only deals ───────────────────────────────────────────────────

    public function test_a_members_only_deal_is_hidden_from_a_guest(): void
    {
        // Dangling a discount a signed-out visitor cannot claim is a dead end.
        $this->offer(['members_only' => true]);

        $this->assertSame([], $this->deals());
    }

    public function test_a_members_only_deal_shows_for_a_signed_in_member(): void
    {
        $this->offer(['members_only' => true]);
        $this->actingAs(Customer::create(['name' => 'Buyer', 'phone' => '01711195772', 'password' => 'secret123']), 'customer');

        $this->assertCount(1, $this->deals());
    }

    // ── Volume ───────────────────────────────────────────────────────────────

    public function test_the_carousel_is_capped(): void
    {
        foreach (range(1, DailyDeals::MAX_CARDS + 4) as $i) {
            $this->offer(['title' => 'Deal '.$i, 'sort' => $i]);
        }

        $this->assertCount(DailyDeals::MAX_CARDS, $this->deals());
    }

    public function test_cards_follow_the_offer_ordering(): void
    {
        $this->offer(['title' => 'Second', 'sort' => 2]);
        $this->offer(['title' => 'First', 'sort' => 1]);

        $this->assertSame(['First', 'Second'], array_column($this->deals(), 'title'));
    }

    // ── On the page ──────────────────────────────────────────────────────────

    public function test_the_homepage_renders_the_carousel(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);
        $product = $this->product('Gold Bangle');
        $this->offer(['title' => 'Bangle blowout', 'applies_to' => 'products', 'product_ids' => [$product->id]]);
        Setting::put('home_content', []);

        $this->get('/')
            ->assertOk()
            ->assertSee('Deals of the Day')
            ->assertSee('Bangle blowout')
            ->assertInertia(fn ($page) => $page->component('Home')
                ->where('deals.cards.0.href', route('product.show', $product)));
    }

    public function test_the_homepage_omits_the_carousel_entirely_when_nothing_is_on_offer(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);
        Setting::put('home_content', []);
        $this->product();

        $this->get('/')->assertOk()->assertDontSee('Deals of the Day');
    }
}
