<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\Meta\MetaSettings;
use App\Support\OfferPricing;
use App\Support\Storefront\ProductCardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Offers on the shelf (owner, 19 Sep 2026).
 *
 * An offer from Admin → Offers used to be a note on the product page and came
 * off only in the cart. Now a product it covers LISTS at the discounted price
 * with its regular price struck through — cards, product page, structured
 * data, feeds — and goes back the moment the offer does. The promise that
 * makes it safe: the cart charges exactly what the shelf showed, line by line.
 */
class OfferPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function category(string $name): Category
    {
        return Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_active' => true]);
    }

    protected function product(string $name, float $price, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => Str::slug($name),
            'status' => 'published',
            'price' => $price,
            'manage_stock' => false,
            'in_stock' => true,
        ], $extra));
    }

    protected function offer(array $attrs = []): Offer
    {
        return Offer::create(array_merge([
            'title' => 'Auto Applied at checkout', 'description' => 'Limited Time only',
            'type' => 'order_percent', 'applies_to' => 'all', 'percent' => 20,
            'badge_label' => '20% Flat Off', 'show_on_pdp' => true, 'is_active' => true, 'sort' => 0,
        ], $attrs));
    }

    protected function card(Product $product): array
    {
        return ProductCardData::make($product->fresh());
    }

    // ── The listed price ─────────────────────────────────────────────────────

    public function test_a_storewide_offer_lists_every_piece_at_the_discounted_price(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        $this->offer();

        $card = $this->card($ring);

        $this->assertSame(1000.0, $card['price']);
        $this->assertSame('৳1,000', $card['price_text']);
        $this->assertSame('৳1,250', $card['compare_text'], 'the regular price is the struck one');
        $this->assertTrue($card['on_sale']);
        $this->assertSame(20, $card['discount_percent']);
    }

    public function test_deleting_the_offer_puts_the_regular_price_back(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        $offer = $this->offer();
        $this->assertSame(1000.0, $this->card($ring)['price']);

        $offer->delete();

        $card = $this->card($ring);
        $this->assertSame(1250.0, $card['price']);
        $this->assertNull($card['compare_text']);
        $this->assertFalse($card['on_sale']);
    }

    public function test_pausing_the_offer_puts_the_regular_price_back(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        $offer = $this->offer();

        $offer->update(['is_active' => false]);

        $this->assertSame(1250.0, $this->card($ring)['price']);
    }

    public function test_an_offer_with_a_condition_stays_a_note(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        $this->offer(['min_subtotal' => 3000]);
        $this->offer(['title' => 'Two or more', 'min_qty' => 2, 'percent' => 30]);

        // A single piece cannot promise either, so neither moves its price.
        $this->assertSame(1250.0, $this->card($ring)['price']);
        $this->assertNull($this->card($ring)['compare_text']);
    }

    public function test_an_offer_not_shown_on_product_pages_does_not_move_the_price_but_still_applies(): void
    {
        $ring = $this->product('Rose Ring', 1000);
        $this->offer(['show_on_pdp' => false]);

        $this->assertSame(1000.0, $this->card($ring)['price'], 'a checkout-only surprise');

        $cart = app(CartService::class);
        $cart->add($ring, null, 1);
        $this->assertSame(200.0, $cart->promoDiscount());
    }

    public function test_the_best_offer_covering_the_piece_wins(): void
    {
        $earrings = $this->category('Earrings');
        $drop = $this->product('Pearl Drop', 1000, ['category_id' => $earrings->id]);
        $ring = $this->product('Plain Band', 1000);

        $this->offer();
        $this->offer(['title' => 'Earring week', 'applies_to' => 'categories', 'category_ids' => [$earrings->id], 'percent' => 30]);

        $this->assertSame(700.0, $this->card($drop)['price']);
        $this->assertSame(800.0, $this->card($ring)['price']);
    }

    public function test_a_hand_typed_compare_at_price_still_shows_when_no_offer_covers_the_piece(): void
    {
        $ring = $this->product('Rose Ring', 1250, ['compare_at_price' => 1470]);

        $card = $this->card($ring);
        $this->assertSame(1250.0, $card['price']);
        $this->assertSame('৳1,470', $card['compare_text']);
        $this->assertSame(15, $card['discount_percent']);

        // An offer takes over: the regular price is struck, not the compare-at.
        $this->offer();
        $card = $this->card($ring);
        $this->assertSame(1000.0, $card['price']);
        $this->assertSame('৳1,250', $card['compare_text']);
    }

    public function test_a_members_only_offer_moves_the_member_price_for_members_only(): void
    {
        $ring = $this->product('Rose Ring', 1000);
        $this->offer();
        $this->offer(['title' => 'Members save more', 'members_only' => true, 'percent' => 10]);

        $this->assertNull($this->card($ring)['member'], 'a guest sees the public price');
        $this->assertSame(800.0, $this->card($ring)['price']);

        $this->actingAs(Customer::create(['name' => 'Member', 'phone' => '01711111111', 'password' => 'secret-pass']), 'customer');

        $card = $this->card($ring);
        // Everything comes off the full price in the cart, so it adds up: the
        // storewide 20%, the members' 10% and the standing 3% member discount.
        $this->assertSame('৳670', $card['member']['price_text']);
        $this->assertSame('13', $card['member']['pct']);

        $cart = app(CartService::class);
        $cart->add($ring, null, 1);
        $this->assertSame(300.0, $cart->promoDiscount());
        $this->assertSame(330.0, $cart->discount(), 'the member pays what the card showed');
    }

    // ── The cart charges what the shelf showed ───────────────────────────────

    public function test_each_line_takes_the_best_offer_covering_it(): void
    {
        $earrings = $this->category('Earrings');
        $drop = $this->product('Pearl Drop', 1000, ['category_id' => $earrings->id]);
        $necklace = $this->product('Long Necklace', 3000);

        $this->offer(['title' => 'Storewide']);
        $this->offer(['title' => 'Earring week', 'badge_label' => null, 'applies_to' => 'categories', 'category_ids' => [$earrings->id], 'percent' => 30]);

        $cart = app(CartService::class);
        $cart->add($drop, null, 1);
        $cart->add($necklace, null, 1);

        // 30% of the earring (as its page said) + 20% of the necklace. The old
        // rule took the single offer worth most across the cart — 20% of
        // everything — and charged ৳100 more for the earring than it listed at.
        $this->assertSame(900.0, $cart->promoDiscount());

        $lines = collect($cart->discountLines())->pluck('amount', 'label')->all();
        $this->assertSame(['Storewide' => 600.0, 'Earring week' => 300.0], $lines);
    }

    public function test_a_piece_filed_under_a_second_category_is_charged_what_its_page_listed(): void
    {
        $rings = $this->category('Rings');
        $gifts = $this->category('Gifts');
        $ring = $this->product('Gift Ring', 1000, ['category_id' => $rings->id]);
        $ring->categories()->attach($gifts->id);

        $this->offer(['applies_to' => 'categories', 'category_ids' => [$gifts->id], 'percent' => 25]);

        $this->assertSame(750.0, $this->card($ring)['price']);

        // The line's own category_id is only the primary; the cart used to
        // miss the second category and charge the full ৳1,000.
        $cart = app(CartService::class);
        $cart->add($ring, null, 1);
        $this->assertSame(250.0, $cart->promoDiscount());
    }

    public function test_the_cart_line_carries_a_tag_naming_the_offer(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        $this->offer();

        $this->post(route('cart.add', $ring), ['qty' => 1])->assertRedirect();

        $this->get(route('cart'))->assertInertia(fn (Assert $page) => $page
            ->where('items.0.price_text', '৳1,250')          // the line keeps its regular price
            ->where('items.0.promo.label', '20% Flat Off')
            ->where('items.0.promo.saving_text', '৳250')
            ->where('summary.discount_lines.0.label', 'Auto Applied at checkout')
            ->where('summary.total_text', '৳1,000'));
    }

    // ── The product page ─────────────────────────────────────────────────────

    public function test_the_product_page_is_told_the_offer_and_its_schema_quotes_the_listed_price(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        $this->offer();

        $response = $this->get(route('product.show', $ring));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pp.price', 1250)                        // the cart line's unit
            ->where('pp.offer.percent', 20)
            ->where('pp.offer.label', '20% Flat Off')
            ->where('product.price', 1000));                 // the ViewContent value

        $html = $response->getContent();
        $this->assertStringContainsString('"price":"1000.00"', $html);
        $this->assertStringContainsString('"priceType":"https://schema.org/StrikethroughPrice"', $html);
        $this->assertStringContainsString('<meta property="product:sale_price:amount" content="1000.00">', $html);
    }

    // ── Feeds ────────────────────────────────────────────────────────────────

    public function test_the_meta_feed_carries_the_offer_as_a_sale_price_until_it_ends(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        ProductImage::create(['product_id' => $ring->id, 'path' => 'products/rose.jpg', 'is_primary' => true]);
        $offer = $this->offer();

        $row = $this->metaRow();
        $this->assertSame('1250.00 BDT', $row['price']);
        $this->assertSame('1000.00 BDT', $row['sale_price']);

        $offer->delete();
        offer_pricing()->forget();

        $row = $this->metaRow();
        $this->assertSame('1250.00 BDT', $row['price']);
        $this->assertSame('', $row['sale_price']);
    }

    public function test_the_google_feed_carries_the_offer_as_a_sale_price(): void
    {
        $ring = $this->product('Rose Ring', 1250);
        ProductImage::create(['product_id' => $ring->id, 'path' => 'products/rose.jpg', 'is_primary' => true]);
        $this->offer();

        $xml = $this->get('/feed/google.xml')->assertOk()->streamedContent();

        $this->assertStringContainsString('<g:price>1250.00 BDT</g:price>', $xml);
        $this->assertStringContainsString('<g:sale_price>1000.00 BDT</g:sale_price>', $xml);
    }

    /** @return array<string,string> the feed's only data row */
    protected function metaRow(): array
    {
        $lines = array_values(array_filter(explode("\n", $this->get('/feed/meta.csv')->assertOk()->streamedContent())));
        $cols = str_getcsv(array_shift($lines));

        return array_combine($cols, array_pad(str_getcsv($lines[0]), count($cols), ''));
    }

    // ── Finding by price ─────────────────────────────────────────────────────

    public function test_a_budget_is_judged_on_the_listed_price(): void
    {
        $this->product('Listed At A Thousand', 1250);
        $this->product('Over Budget', 1300);
        $this->offer();

        // ৳1,250 less 20% is ৳1,000 — inside "Under ৳1,000". ৳1,300 is ৳1,040.
        $this->get(route('shop', ['price_max' => 1000]))->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Listed At A Thousand'));
    }

    public function test_price_sorting_follows_the_listed_price(): void
    {
        $earrings = $this->category('Earrings');
        $this->product('Half Price Drop', 1000, ['category_id' => $earrings->id]);
        $this->product('Plain Band', 800);
        $this->offer(['applies_to' => 'categories', 'category_ids' => [$earrings->id], 'percent' => 50]);

        $this->get(route('shop', ['sort' => 'price_asc']))->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Half Price Drop')     // ৳500 listed
            ->where('products.data.1.name', 'Plain Band'));        // ৳800
    }

    // ── Meta events ──────────────────────────────────────────────────────────

    public function test_the_server_view_content_values_the_piece_at_its_listed_price(): void
    {
        Setting::put('meta_integration', [
            'enabled' => true, 'pixel_id' => '1234567890', 'pixel_enabled' => true,
            'capi_enabled' => true, 'capi_token_encrypted' => Crypt::encryptString('test-token'),
        ]);
        app()->forgetInstance(MetaSettings::class);
        Http::fake(['*' => Http::response(['events_received' => 1], 200)]);

        $ring = $this->product('Rose Ring', 1250);
        $this->offer();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36')
            ->get(route('product.show', $ring))->assertOk();

        $event = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/events'))
            ->map(fn ($pair) => $pair[0]->data()['data'][0])
            ->firstWhere('event_name', 'ViewContent');

        $this->assertEquals(1000, $event['custom_data']['value'], 'the browser Pixel sends the same');
    }

    // ── Variants ─────────────────────────────────────────────────────────────

    public function test_a_variant_is_quoted_at_its_own_price_less_the_offer(): void
    {
        $ring = $this->product('Sized Ring', 1000, ['has_variants' => true]);
        $big = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'big', 'attributes' => ['Size' => '8'], 'price' => 1500, 'stock_quantity' => 3, 'is_active' => true]);
        $this->offer();

        $quote = offer_pricing()->quote($ring, $big);

        $this->assertSame(1200.0, $quote['price']);
        $this->assertSame(1500.0, $quote['was']);
    }

    public function test_less_rounds_to_the_paisa_like_the_cart(): void
    {
        $this->assertSame(799.2, OfferPricing::less(999, 20));
        $this->assertSame(1000.0, OfferPricing::less(1250, 20));
        $this->assertSame(1000.0, OfferPricing::less(1000, 0));
    }

    // ── The clean-up ─────────────────────────────────────────────────────────

    public function test_the_migration_clears_every_compare_at_price_and_can_put_them_back(): void
    {
        $ring = $this->product('Rose Ring', 1250, ['compare_at_price' => 1470]);
        $binned = $this->product('Old Ring', 900, ['compare_at_price' => 1100]);
        $binned->delete();
        $plain = $this->product('Plain Band', 800);
        $variant = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'v1', 'attributes' => ['Size' => '7'], 'price' => 1250, 'compare_at_price' => 1500, 'stock_quantity' => 2, 'is_active' => true]);

        $migration = require database_path('migrations/2026_09_19_120000_clear_compare_at_prices.php');
        Schema::dropIfExists('compare_at_price_backups');   // RefreshDatabase already ran it on an empty table
        $migration->up();

        $this->assertNull($ring->fresh()->compare_at_price);
        $this->assertNull(Product::withTrashed()->find($binned->id)->compare_at_price);
        $this->assertNull($variant->fresh()->compare_at_price);
        $this->assertSame(3, DB::table('compare_at_price_backups')->count());
        $this->assertSame(0, DB::table('compare_at_price_backups')->where('product_id', $plain->id)->count());

        // Set again by hand after the clean-up: rolling back must not clobber it.
        $ring->update(['compare_at_price' => 1400]);

        $migration->down();

        $this->assertEquals(1400, $ring->fresh()->compare_at_price);
        $this->assertEquals(1100, Product::withTrashed()->find($binned->id)->compare_at_price);
        $this->assertEquals(1500, $variant->fresh()->compare_at_price);
        $this->assertFalse(Schema::hasTable('compare_at_price_backups'));
    }
}
