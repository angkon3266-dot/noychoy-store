<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Support\PinnedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An offer with a deadline (owner, 20 Sep 2026: "I want to be able to add a
 * countdown here so customer knows it's ending soon").
 *
 * The countdown is only honest if the offer really stops, so the deadline is
 * part of Offer::scopeActive: at zero the discounted price, the product-page
 * note and the checkout discount all go at once. These tests pin that, and
 * that the deadline reaches every place that counts it down.
 */
class OfferCountdownTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name = 'Rose Ring', float $price = 1250): Product
    {
        return Product::create([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => Str::slug($name),
            'status' => 'published', 'price' => $price, 'manage_stock' => false, 'in_stock' => true,
        ]);
    }

    protected function offer(array $attrs = []): Offer
    {
        return Offer::create(array_merge([
            'title' => 'Auto Applied at checkout', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 20, 'badge_label' => '20% Flat Off', 'show_on_pdp' => true, 'is_active' => true, 'sort' => 0,
        ], $attrs));
    }

    // ── It really ends ───────────────────────────────────────────────────────

    public function test_an_offer_runs_until_its_deadline_and_stops_at_it(): void
    {
        $ring = $this->product();
        $offer = $this->offer(['ends_at' => now()->addHours(3)]);

        $this->assertSame(1000.0, offer_pricing()->priceFor($ring->fresh()));

        $offer->update(['ends_at' => now()->subMinute()]);

        $this->assertSame(1250.0, offer_pricing()->priceFor($ring->fresh()), 'the price is back');
        $this->assertTrue($offer->fresh()->hasEnded());

        // And the cart stops giving it, not just the shelf.
        $cart = app(CartService::class);
        $cart->add($ring, null, 1);
        $this->assertSame(0.0, $cart->promoDiscount());
        $this->assertSame([], $cart->discountLines());
    }

    public function test_an_ended_offer_leaves_the_product_page_alone(): void
    {
        $ring = $this->product();
        $this->offer(['ends_at' => now()->subHour()]);

        $this->get(route('product.show', $ring))->assertInertia(fn (Assert $page) => $page
            ->where('pp.offer', null)
            ->where('product.price', 1250)
            ->has('pdpOffers', 0));
    }

    // ── The deadline reaches everything that counts it down ──────────────────

    public function test_the_deadline_is_sent_to_the_card_the_product_page_and_the_cart(): void
    {
        $ring = $this->product();
        $offer = $this->offer(['ends_at' => now()->addDay()]);
        $ends = $offer->ends_at->getTimestamp();

        $this->get(route('shop'))->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.offer_ends', $ends)
            ->where('products.data.0.price_text', '৳1,000'));

        $this->get(route('product.show', $ring))->assertInertia(fn (Assert $page) => $page
            ->where('pdpOffers.0.ends', $ends)
            ->where('pdpOffers.0.badge', '20% Flat Off'));

        $this->post(route('cart.add', $ring), ['qty' => 1])->assertRedirect();

        $this->get(route('cart'))->assertInertia(fn (Assert $page) => $page
            ->where('items.0.promo.ends', $ends)
            ->where('items.0.promo.label', '20% Flat Off'));
    }

    public function test_an_offer_without_a_deadline_sends_none(): void
    {
        $ring = $this->product();
        $this->offer();

        $this->get(route('product.show', $ring))->assertInertia(fn (Assert $page) => $page
            ->where('pdpOffers.0.ends', null));
    }

    // ── The pinned bar ───────────────────────────────────────────────────────

    public function test_the_pinned_message_can_carry_the_clock(): void
    {
        Setting::put('theme', array_merge((array) Setting::get('theme', []), [
            'pinned_enabled' => true,
            'pinned_text' => '20% off everything — ends in {countdown}',
        ]));
        $offer = $this->offer(['ends_at' => now()->addHours(5)]);

        $pinned = PinnedMessage::current();
        $this->assertSame($offer->ends_at->getTimestamp(), $pinned['countdown']);
        $this->assertStringContainsString('{countdown}', $pinned['text'], 'the browser swaps in the live clock');
    }

    public function test_a_message_written_around_the_clock_stands_down_with_it(): void
    {
        Setting::put('theme', array_merge((array) Setting::get('theme', []), [
            'pinned_enabled' => true,
            'pinned_text' => '20% off everything — ends in {countdown}',
        ]));
        $offer = $this->offer(['ends_at' => now()->addHour()]);

        $this->assertNotNull(PinnedMessage::current());

        // Nothing left to count: "ends in" with no clock is broken copy for a
        // sale that is over, so the whole line goes.
        $offer->update(['ends_at' => now()->subMinute()]);
        $this->assertNull(PinnedMessage::current());

        // And with no deadline anywhere, a message that asks for a clock is
        // never shown rather than shown half-written.
        $offer->update(['ends_at' => null]);
        $this->assertNull(PinnedMessage::current());

        // A message without the placeholder is untouched by any of this.
        Setting::put('theme', array_merge((array) Setting::get('theme', []), ['pinned_text' => '20% off everything']));
        $this->assertSame('20% off everything', PinnedMessage::current()['text']);
    }

    // ── The catalogue feeds ──────────────────────────────────────────────────

    public function test_the_feeds_tell_meta_and_google_when_the_sale_ends(): void
    {
        $ring = $this->product();
        ProductImage::create(['product_id' => $ring->id, 'path' => 'products/ring.jpg', 'is_primary' => true]);
        $offer = $this->offer(['ends_at' => now()->addDays(2)]);
        $ends = store_time($offer->ends_at)->format('Y-m-d\TH:iP');

        $lines = array_values(array_filter(explode("\n", $this->get('/feed/meta.csv')->assertOk()->streamedContent())));
        $cols = str_getcsv(array_shift($lines));
        $row = array_combine($cols, array_pad(str_getcsv($lines[0]), count($cols), ''));

        $this->assertSame('1000.00 BDT', $row['sale_price']);
        $this->assertStringEndsWith('/'.$ends, $row['sale_price_effective_date']);

        $xml = $this->get('/feed/google.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('<g:sale_price_effective_date>', $xml);
        $this->assertStringContainsString('/'.$ends.'</g:sale_price_effective_date>', $xml);
    }

    public function test_the_product_schema_prices_only_until_the_offer_ends(): void
    {
        $ring = $this->product();
        $offer = $this->offer(['ends_at' => now()->addDays(4)]);

        $html = $this->get(route('product.show', $ring))->getContent();

        $this->assertStringContainsString('"priceValidUntil":"'.store_time($offer->ends_at)->toDateString().'"', $html);
    }

    // ── Setting it in the admin ──────────────────────────────────────────────

    public function test_the_offers_page_offers_the_field_its_presets_and_the_deadline(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'form@b.test', 'password' => bcrypt('secret'), 'role' => 'admin']);
        $offer = $this->offer(['title' => 'Eid rush', 'ends_at' => now()->addDays(2)]);

        $html = $this->actingAs($admin)->get(route('admin.offers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="ends_at"', $html);
        $this->assertStringContainsString('Tonight', $html);
        $this->assertStringContainsString(store_time($offer->ends_at)->format('d M, g:i A'), $html, 'the list says when each offer ends');

        // Editing one fills the field with the time the owner typed.
        $edit = $this->actingAs($admin)->get(route('admin.offers.index', ['edit' => $offer->id]))->assertOk()->getContent();
        $this->assertStringContainsString(store_time($offer->ends_at)->format('Y-m-d\TH:i'), $edit);
    }

    public function test_the_admin_types_bangladesh_time(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.offers.store'), [
            'title' => 'Eid rush', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 15, 'ends_at' => '2026-12-25T23:59', 'is_active' => 1, 'show_on_pdp' => 1,
        ])->assertRedirect();

        $offer = Offer::where('title', 'Eid rush')->firstOrFail();
        $this->assertSame('2026-12-25 23:59', store_time($offer->ends_at)->format('Y-m-d H:i'));
        // Stored in UTC like every timestamp: Dhaka is six hours ahead.
        $this->assertSame('2026-12-25 17:59', $offer->ends_at->utc()->format('Y-m-d H:i'));

        // Clearing it puts the offer back to running until it is switched off.
        $this->actingAs($admin)->put(route('admin.offers.update', $offer), [
            'title' => 'Eid rush', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 15, 'ends_at' => '', 'is_active' => 1, 'show_on_pdp' => 1,
        ])->assertRedirect();

        $this->assertNull($offer->fresh()->ends_at);
    }
}
