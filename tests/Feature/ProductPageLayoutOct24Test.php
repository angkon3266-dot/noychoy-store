<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The owner's 24 Oct 2026 product-page and contact changes:
 *  - a product with no reviews shows the store's 5-star reviews of other pieces;
 *  - "frequently bought together" moved into the bag as "Pairs beautifully with";
 *  - no phone number on the storefront (WhatsApp, Messenger and email instead).
 */
class ProductPageLayoutOct24Test extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        return Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'sku' => uniqid(),
            'price' => 1000, 'category_id' => $category->id, 'status' => 'published', 'stock_quantity' => 5,
        ], $attrs));
    }

    public function test_a_product_without_reviews_borrows_the_stores_five_star_reviews(): void
    {
        $reviewed = $this->product('Reviewed Ring');
        Review::create(['product_id' => $reviewed->id, 'author_name' => 'Nusrat', 'rating' => 5, 'status' => 'approved', 'body' => 'Beautiful.']);
        Review::create(['product_id' => $reviewed->id, 'author_name' => 'Mixed', 'rating' => 3, 'status' => 'approved', 'body' => 'Late delivery.']);
        $fresh = $this->product('New Ring');

        $this->get(route('product.show', $fresh))->assertInertia(fn (Assert $page) => $page
            ->where('reviews.count', 0)
            ->has('storeReviews', 1)
            ->where('storeReviews.0.author', 'Nusrat')
            ->where('storeReviews.0.product.name', 'Reviewed Ring'));

        // A product with its own reviews shows only those.
        $this->get(route('product.show', $reviewed))->assertInertia(fn (Assert $page) => $page->where('storeReviews', []));
    }

    public function test_the_bag_suggests_pieces_to_pair_with_what_is_in_it(): void
    {
        $partner = $this->product('Partner Earrings');
        $ring = $this->product('Ring', ['cross_sell_ids' => [$partner->id]]);

        $this->postJson(route('cart.add', $ring), ['qty' => 1])->assertOk();

        $pairs = $this->getJson('/cart/mini')->assertOk()->json('pairs');
        $this->assertSame($partner->id, $pairs[0]['id']);
        $this->assertNotContains($ring->id, array_column($pairs, 'id'));
    }

    public function test_the_bag_suggests_nothing_when_it_is_empty(): void
    {
        $this->product('Anything');

        $this->assertSame([], $this->getJson('/cart/mini')->json('pairs'));
    }

    public function test_the_storefront_offers_no_phone_number(): void
    {
        Setting::put('store_phone', '01700000000');
        Setting::put('theme', ['show_call_button' => true, 'messenger_url' => 'https://m.me/noychoylove', 'whatsapp_number' => '8801634347164']);

        $this->get('/contact')->assertInertia(fn (Assert $page) => $page
            ->missing('details.phone')
            ->where('details.messenger', 'https://m.me/noychoylove')
            ->missing('chrome.footer.phone')
            ->where('chrome.footer.messenger', 'https://m.me/noychoylove')
            ->where('chrome.floats.call', null));
    }
}
