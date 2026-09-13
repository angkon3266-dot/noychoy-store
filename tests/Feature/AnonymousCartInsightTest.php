<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Services\AnonymousCartInsight;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The abandoned-cart desk only ever showed shoppers who typed a phone number.
 * On this store that is a small minority — 155 visitors added to cart in a
 * month and 22 left a number — so the majority of the funnel's drop-off was
 * invisible, and with it the only evidence of which pieces get picked up and
 * put back down.
 *
 * The hard part is the boundary: "no contact details" has to mean exactly the
 * people we cannot reach, or the screen either hides real anonymous carts or
 * lists shoppers who are already on the callable list.
 */
class AnonymousCartInsightTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(string $slug = 'pearl-ring', float $price = 1500, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Ring '.$slug, 'slug' => $slug, 'status' => 'published',
            'price' => $price, 'manage_stock' => false, 'in_stock' => true,
        ], $extra));
    }

    /** A browser that added to the cart, and optionally opened the checkout. */
    protected function browse(string $token, ?Product $product, float $value, bool $checkout = false, string $source = 'direct'): void
    {
        if ($product) {
            Visit::create([
                'visitor_token' => $token, 'event' => 'cart_add', 'path' => '/cart',
                'product_id' => $product->id, 'value' => $value, 'source' => $source,
            ]);
        }

        if ($checkout) {
            Visit::create([
                'visitor_token' => $token, 'event' => 'checkout_start', 'path' => '/checkout',
                'value' => $value, 'source' => $source,
            ]);
        }
    }

    protected function report(): array
    {
        return app(AnonymousCartInsight::class)->report(DateRange::preset('30d'));
    }

    // ── Who counts as unreachable ──────────────────────────────────────────

    public function test_a_shopper_who_left_a_phone_number_is_not_counted_as_anonymous(): void
    {
        $p = $this->product();

        $this->browse('tok-anon', $p, 1500);
        $this->browse('tok-known', $p, 1500);

        // The lead capture stamps the browser token on the row, which is the
        // only thing linking a phone number back to this behaviour.
        AbandonedCart::create([
            'session_id' => 's1', 'visitor_token' => 'tok-known', 'phone' => '01712345678',
            'items' => [], 'subtotal' => 1500, 'item_count' => 1,
        ]);

        $report = $this->report();

        $this->assertSame(1, $report['summary']['sessions'], 'only the one we cannot reach');
        $this->assertSame(1, $report['sessions']->count());
        $this->assertSame('TOK-AN', $report['sessions']->first()['short']);
    }

    public function test_a_buyer_is_excluded_because_ordering_means_leaving_a_number(): void
    {
        $p = $this->product();
        $this->browse('tok-buyer', $p, 1500, checkout: true);

        AbandonedCart::create([
            'session_id' => 's2', 'visitor_token' => 'tok-buyer', 'phone' => '01712345678',
            'items' => [], 'subtotal' => 1500, 'item_count' => 1, 'recovered' => true,
        ]);

        Order::create([
            'order_number' => 'A1', 'customer_name' => 'Buyer', 'customer_phone' => '01712345678',
            'shipping_address' => 'x', 'subtotal' => 1500, 'total' => 1500, 'status' => 'pending',
        ]);

        $this->assertSame(0, $this->report()['summary']['sessions']);
    }

    public function test_a_visitor_who_only_browsed_is_not_an_abandoned_basket(): void
    {
        // Page and product views are not intent to buy; without this the screen
        // would report every passer-by as a lost sale.
        Visit::create(['visitor_token' => 'tok-browser', 'event' => 'page', 'path' => '/']);
        Visit::create(['visitor_token' => 'tok-browser', 'event' => 'product', 'path' => '/product/x']);

        $this->assertSame(0, $this->report()['summary']['sessions']);
    }

    // ── Where they stopped ─────────────────────────────────────────────────

    public function test_the_cart_and_checkout_steps_are_counted_separately(): void
    {
        $p = $this->product();

        $this->browse('tok-1', $p, 1500, checkout: true);
        $this->browse('tok-2', $p, 900);
        $this->browse('tok-3', $p, 900);

        $summary = $this->report()['summary'];

        $this->assertSame(3, $summary['sessions']);
        $this->assertSame(1, $summary['reached_checkout']);
        $this->assertSame(2, $summary['cart_only']);
    }

    public function test_the_value_is_summed_over_adds_and_says_what_it_could_not_measure(): void
    {
        $p = $this->product();

        $this->browse('tok-1', $p, 1500);
        $this->browse('tok-2', $p, 500);

        // A row from before the value column existed. Null is not zero, and
        // summing it as zero would report a real basket as nothing.
        Visit::create(['visitor_token' => 'tok-3', 'event' => 'cart_add', 'path' => '/cart',
            'product_id' => $p->id, 'value' => null]);

        $summary = $this->report()['summary'];

        $this->assertSame(2000.0, $summary['value']);
        $this->assertSame(1, $summary['unmeasured']);
    }

    // ── What they picked up ────────────────────────────────────────────────

    public function test_products_are_ranked_by_people_not_by_adds(): void
    {
        $popular = $this->product('popular');
        $fiddled = $this->product('fiddled');

        // Three different people reached for one piece…
        foreach (['a', 'b', 'c'] as $t) {
            $this->browse('tok-'.$t, $popular, 1000);
        }
        // …and one undecided shopper added the other five times.
        for ($i = 0; $i < 5; $i++) {
            $this->browse('tok-undecided', $fiddled, 1000);
        }

        $products = $this->report()['products'];

        $this->assertSame($popular->id, $products->first()['id'], 'three people beats five adds by one');
        $this->assertSame(3, $products->first()['sessions']);
        $this->assertSame(5, $products->firstWhere('id', $fiddled->id)['adds']);
        $this->assertSame(1, $products->firstWhere('id', $fiddled->id)['sessions']);
    }

    public function test_a_product_says_how_many_of_its_carts_reached_checkout(): void
    {
        $p = $this->product();

        $this->browse('tok-1', $p, 1500, checkout: true);
        $this->browse('tok-2', $p, 1500);

        $row = $this->report()['products']->firstWhere('id', $p->id);

        $this->assertSame(2, $row['sessions']);
        $this->assertSame(1, $row['reached_checkout']);
    }

    public function test_a_piece_that_can_no_longer_be_bought_is_flagged(): void
    {
        $out = $this->product('out-of-stock', 1500, ['manage_stock' => true, 'stock_quantity' => 0]);
        $gone = $this->product('withdrawn', 1500, ['status' => 'draft']);

        $this->browse('tok-1', $out, 1500);
        $this->browse('tok-2', $gone, 1500);

        $products = $this->report()['products'];

        $this->assertFalse($products->firstWhere('id', $out->id)['in_stock']);
        $this->assertTrue($products->firstWhere('id', $gone->id)['gone']);
        $this->assertNull($products->firstWhere('id', $gone->id)['url'], 'a draft 404s on the storefront');
    }

    // ── The signals, which are evidence and not reasons ────────────────────

    public function test_a_basket_under_the_free_delivery_line_is_named_as_such(): void
    {
        Setting::put('free_shipping_threshold', 3000);
        $p = $this->product();

        $this->browse('tok-small', $p, 900, checkout: true);

        $session = $this->report()['sessions']->first();

        $this->assertStringContainsString('Delivery fee', $session['signal']['label']);
    }

    public function test_an_unbuyable_piece_outranks_the_delivery_signal(): void
    {
        Setting::put('free_shipping_threshold', 3000);
        $out = $this->product('gone', 1500, ['manage_stock' => true, 'stock_quantity' => 0]);

        $this->browse('tok-1', $out, 900, checkout: true);

        $this->assertStringContainsString('unbuyable', $this->report()['sessions']->first()['signal']['label']);
    }

    public function test_the_delivery_signal_disappears_when_free_delivery_is_switched_off(): void
    {
        Setting::put('free_shipping_threshold', null);
        $p = $this->product();

        $this->browse('tok-1', $p, 900, checkout: true);

        $report = $this->report();

        $this->assertNull($report['threshold']);
        $this->assertSame('Left at the checkout form', $report['sessions']->first()['signal']['label']);
        $this->assertCount(2, $report['signals'], 'no threshold means no postage line to report');
    }

    // ── The screen ─────────────────────────────────────────────────────────

    public function test_the_screen_lists_the_pieces_and_where_the_visitor_came_from(): void
    {
        $p = $this->product();
        $this->browse('tok-fb', $p, 1500, checkout: true, source: 'facebook_ads');

        $this->actingAs($this->admin())
            ->get(route('admin.abandoned.anonymous'))
            ->assertOk()
            ->assertSee('Ring pearl-ring')
            ->assertSee('Facebook Ads')
            ->assertSee('Left at the checkout form');
    }

    public function test_the_anonymous_screen_is_not_swallowed_by_the_lead_route(): void
    {
        // /abandoned-carts/{cart} would match the word "anonymous" and 404 on
        // the model lookup if it were registered first.
        $this->actingAs($this->admin())
            ->get(route('admin.abandoned.anonymous'))
            ->assertOk();

        $this->assertSame('/admin/abandoned-carts/anonymous', route('admin.abandoned.anonymous', absolute: false));
    }

    public function test_the_period_buttons_change_the_window(): void
    {
        $p = $this->product();
        $this->browse('tok-old', $p, 1500);

        Visit::where('visitor_token', 'tok-old')->update(['created_at' => now()->subDays(45)]);

        $this->assertSame(0, $this->report()['summary']['sessions'], '45 days ago is outside the 30-day window');
        $this->assertSame(1, app(AnonymousCartInsight::class)
            ->report(DateRange::preset('all'))['summary']['sessions']);
    }
}
