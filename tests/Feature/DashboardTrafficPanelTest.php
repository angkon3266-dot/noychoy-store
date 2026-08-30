<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use App\Services\DashboardAnalytics;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The traffic panel on the dashboard: the links out of it, the live card, and
 * the ad table.
 *
 * The product links in these panels were built from the product *id*. Product
 * routes are keyed on the slug, so every one of them 404'd — the store could
 * see which products were being viewed and not bought, and could not open a
 * single one of them.
 */
class DashboardTrafficPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    protected function product(string $name, string $slug, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => $name, 'slug' => $slug, 'status' => 'published', 'price' => 1500,
            'manage_stock' => true, 'stock_quantity' => 8, 'in_stock' => true,
        ], $extra));
    }

    public function test_a_viewed_but_never_bought_product_opens_instead_of_404ing(): void
    {
        $product = $this->product('Blooming Garden Ring', 'blooming-garden-ring');

        foreach (range(1, 3) as $i) {
            Visit::create([
                'visitor_token' => str_pad((string) $i, 40, 'a'),
                'event' => 'product', 'path' => 'product/blooming-garden-ring',
                'product_id' => $product->id, 'source' => 'facebook',
            ]);
        }

        $rows = app(DashboardAnalytics::class)->viewedNotSold(DateRange::preset('30d'));

        $this->assertCount(1, $rows);
        $this->assertSame('blooming-garden-ring', $rows[0]['slug']);

        // The link the dashboard actually renders.
        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $rows[0]['slug']))
            ->assertOk();
    }

    public function test_the_operations_panel_links_open_too(): void
    {
        $this->product('Dead Weight Ring', 'dead-weight-ring', ['stock_quantity' => 40]);

        $ops = app(DashboardAnalytics::class)->operations(DateRange::preset('30d'));

        $this->assertNotEmpty($ops['dead_stock']);
        $this->assertSame('dead-weight-ring', $ops['dead_stock'][0]['slug']);

        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $ops['dead_stock'][0]['slug']))
            ->assertOk();
    }

    public function test_the_sources_card_names_the_sites_behind_a_channel(): void
    {
        foreach (['m.facebook.com', 'm.facebook.com', 'someblog.example'] as $i => $host) {
            Visit::create([
                'visitor_token' => str_pad((string) $i, 40, 'b'),
                'event' => 'page', 'path' => '/', 'source' => 'referral', 'referrer_host' => $host,
            ]);
        }

        $sources = app(DashboardAnalytics::class)->trafficSources(DateRange::preset('30d'));
        $referral = $sources->firstWhere('channel', 'referral');

        $this->assertNotNull($referral);
        $this->assertSame(3, $referral['visitors']);
        $this->assertSame('m.facebook.com', $referral['sites'][0]['name']);
        $this->assertSame(2, $referral['sites'][0]['visitors']);
    }

    public function test_an_ad_that_sent_traffic_and_sold_nothing_is_still_listed(): void
    {
        // The whole point: starting from orders would hide this row, and it is
        // the row that is costing money.
        foreach (range(1, 4) as $i) {
            Visit::create([
                'visitor_token' => str_pad((string) $i, 40, 'c'),
                'event' => 'page', 'path' => '/', 'source' => 'facebook_ads',
                'campaign' => 'eid-bridal', 'content' => 'carousel-v2',
            ]);
        }

        $ads = app(DashboardAnalytics::class)->adPerformance(DateRange::preset('30d'));

        $this->assertCount(1, $ads);
        $this->assertSame('eid-bridal', $ads[0]['campaign']);
        $this->assertSame('carousel-v2', $ads[0]['ad']);
        $this->assertSame(4, $ads[0]['visitors']);
        $this->assertSame(0, $ads[0]['orders']);
        $this->assertSame(0.0, $ads[0]['rate']);
    }

    public function test_an_ad_carries_the_revenue_of_the_orders_it_produced(): void
    {
        Visit::create([
            'visitor_token' => str_repeat('d', 40),
            'event' => 'page', 'path' => '/', 'source' => 'facebook_ads',
            'campaign' => 'eid-bridal', 'content' => 'carousel-v2',
        ]);

        Order::create([
            'order_number' => '10001', 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 2000, 'total' => 2000,
            'status' => 'delivered', 'payment_method' => 'cod',
            'source_channel' => 'facebook_ads', 'source_campaign' => 'eid-bridal', 'source_content' => 'carousel-v2',
        ]);

        $ads = app(DashboardAnalytics::class)->adPerformance(DateRange::preset('30d'));

        $this->assertSame(1, $ads[0]['orders']);
        $this->assertSame(2000.0, $ads[0]['revenue']);
    }

    public function test_the_daily_series_carries_every_funnel_step(): void
    {
        $product = $this->product('Ring', 'ring');

        Visit::create(['visitor_token' => str_repeat('e', 40), 'event' => 'page', 'path' => '/']);
        Visit::create(['visitor_token' => str_repeat('e', 40), 'event' => 'product', 'path' => 'product/ring', 'product_id' => $product->id]);
        Visit::create(['visitor_token' => str_repeat('e', 40), 'event' => 'cart_add', 'path' => 'cart', 'product_id' => $product->id]);

        $series = app(DashboardAnalytics::class)->funnelByDay(DateRange::preset('7d'));

        $this->assertCount(7, $series);

        $today = $series->last();
        $this->assertSame(1, $today['visitors']);
        $this->assertSame(1, $today['viewed']);
        $this->assertSame(1, $today['carted']);
        $this->assertSame(0, $today['checkout']);
        $this->assertSame(0, $today['orders']);
    }

    public function test_the_live_endpoint_says_who_is_browsing_and_what_they_are_on(): void
    {
        $product = $this->product('Emerald Halo Ring', 'emerald-halo-ring');

        Visit::create([
            'visitor_token' => str_repeat('f', 40), 'event' => 'product',
            'path' => 'product/emerald-halo-ring', 'product_id' => $product->id,
            'source' => 'facebook_ads', 'campaign' => 'eid-bridal',
        ]);

        // Yesterday's visitor is not "right now". (created_at is not fillable,
        // so it has to be pushed back after the row exists.)
        $stale = Visit::create(['visitor_token' => str_repeat('g', 40), 'event' => 'page', 'path' => '/']);
        Visit::whereKey($stale->id)->update(['created_at' => now()->subDay()]);

        $res = $this->actingAs($this->admin())->getJson('/admin/dashboard/live');

        $res->assertOk();
        $res->assertJsonPath('count', 1);
        $res->assertJsonPath('rows.0.where', 'Emerald Halo Ring');
        $res->assertJsonPath('rows.0.channel_label', 'Facebook Ads');
        $res->assertJsonPath('rows.0.campaign', 'eid-bridal');
    }

    public function test_the_live_endpoint_describes_a_plain_page_in_words(): void
    {
        Visit::create(['visitor_token' => str_repeat('h', 40), 'event' => 'page', 'path' => 'cart']);

        $this->actingAs($this->admin())->getJson('/admin/dashboard/live')
            ->assertOk()
            ->assertJsonPath('rows.0.where', 'Cart');
    }

    public function test_the_live_endpoint_is_admin_only(): void
    {
        $this->get('/admin/dashboard/live')->assertRedirect(route('admin.login'));
    }
}
