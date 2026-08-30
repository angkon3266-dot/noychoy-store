<?php

namespace Tests\Feature;

use App\Models\Visit;
use App\Support\TrafficSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Traffic attribution: classifying where a visitor came from, and carrying that
 * onto the order they place. Getting a channel wrong misdirects ad spend, so
 * each signal path is pinned here.
 */
class TrafficAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function classify(string $uri, array $headers = []): array
    {
        $request = Request::create($uri, 'GET', [], [], [], []);
        foreach ($headers as $k => $v) {
            $request->headers->set($k, $v);
        }

        return TrafficSource::fromRequest($request);
    }

    public function test_no_referrer_is_direct(): void
    {
        $this->assertSame('direct', $this->classify('/')['channel']);
    }

    public function test_referrer_hosts_map_to_channels(): void
    {
        $this->assertSame('facebook', $this->classify('/', ['referer' => 'https://m.facebook.com/'])['channel']);
        $this->assertSame('instagram', $this->classify('/', ['referer' => 'https://l.instagram.com/'])['channel']);
        $this->assertSame('google', $this->classify('/', ['referer' => 'https://www.google.com.bd/'])['channel']);
        $this->assertSame('tiktok', $this->classify('/', ['referer' => 'https://www.tiktok.com/'])['channel']);
        $this->assertSame('referral', $this->classify('/', ['referer' => 'https://someblog.example/'])['channel']);
    }

    public function test_click_ids_survive_a_missing_referrer(): void
    {
        // The usual case: a tap inside the Facebook app, no Referer header.
        $this->assertSame('facebook', $this->classify('/?fbclid=abc123')['channel']);
        $this->assertSame('google_ads', $this->classify('/?gclid=xyz')['channel']);
    }

    public function test_utm_tags_win_and_mark_paid_traffic(): void
    {
        $paid = $this->classify('/?utm_source=facebook&utm_medium=cpc&utm_campaign=eid-bridal');

        $this->assertSame('facebook_ads', $paid['channel']);
        $this->assertSame('eid-bridal', $paid['campaign']);

        $organic = $this->classify('/?utm_source=facebook&utm_medium=social');
        $this->assertSame('facebook', $organic['channel']);
    }

    /**
     * The bug this store actually hit. Meta's `{{site_source_name}}` macro
     * resolves to `fb` / `ig` / `an` / `msg`, and the classifier only matched
     * full platform names — so every tagged ad click fell through to "Other
     * website" and the store's Facebook traffic looked like it came from
     * nowhere.
     */
    public function test_metas_short_source_names_are_not_other_website(): void
    {
        $campaign = '120250686619310682';   // what {{campaign.id}} resolves to

        $this->assertSame('facebook_ads', $this->classify("/?utm_source=fb&utm_campaign={$campaign}")['channel']);
        $this->assertSame('instagram_ads', $this->classify("/?utm_source=ig&utm_campaign={$campaign}")['channel']);
        $this->assertSame('audience_network', $this->classify("/?utm_source=an&utm_campaign={$campaign}")['channel']);
        $this->assertSame('messenger', $this->classify("/?utm_source=msg&utm_campaign={$campaign}")['channel']);
    }

    public function test_a_short_source_without_ad_signals_stays_organic(): void
    {
        // A hand-written link to a normal post is not ad spend.
        $this->assertSame('facebook', $this->classify('/?utm_source=fb&utm_campaign=eid-post')['channel']);
        $this->assertSame('instagram', $this->classify('/?utm_source=ig')['channel']);
    }

    public function test_an_ad_id_in_the_url_marks_the_click_as_paid(): void
    {
        // Meta links often carry only the source and an ad id — no utm_medium.
        $click = $this->classify('/?utm_source=fb&ad_id=120250686619310682');

        $this->assertSame('facebook_ads', $click['channel']);
        $this->assertSame('120250686619310682', $click['ad_id']);
    }

    public function test_the_ad_and_medium_are_kept_alongside_the_campaign(): void
    {
        $click = $this->classify('/?utm_source=fb&utm_medium=paid_social&utm_campaign=Eid+Bridal&utm_content=carousel-v2');

        $this->assertSame('Eid Bridal', $click['campaign']);
        $this->assertSame('carousel-v2', $click['content']);
        $this->assertSame('paid_social', $click['medium']);
    }

    public function test_utm_content_standing_in_for_a_missing_campaign_is_not_also_an_ad(): void
    {
        // utm_content is used as the campaign when there is no utm_campaign;
        // repeating it as the ad would invent a creative that doesn't exist.
        $click = $this->classify('/?utm_source=fb&utm_content=carousel-v2');

        $this->assertSame('carousel-v2', $click['campaign']);
        $this->assertNull($click['content']);
    }

    public function test_a_tagged_visit_stores_the_whole_tag(): void
    {
        $this->get('/?utm_source=fb&utm_medium=paid_social&utm_campaign=eid&utm_content=carousel-v2&ad_id=9911');

        $visit = Visit::latest('id')->first();

        $this->assertNotNull($visit);
        $this->assertSame('facebook_ads', $visit->source);
        $this->assertSame('eid', $visit->campaign);
        $this->assertSame('carousel-v2', $visit->content);
        $this->assertSame('paid_social', $visit->medium);
        $this->assertSame('9911', $visit->ad_id);
    }

    public function test_visits_are_still_recorded_before_the_ad_columns_exist(): void
    {
        // A server that hasn't run the migration must keep counting traffic
        // rather than losing every pageview to a missing column.
        app()->instance(Visit::AD_READY_KEY, false);

        $this->get('/?utm_source=fb&utm_campaign=eid');

        $visit = Visit::latest('id')->first();

        $this->assertNotNull($visit);
        $this->assertSame('eid', $visit->campaign);
    }

    public function test_our_own_pages_are_not_a_source(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertSame('direct', $this->classify('/cart', ['referer' => 'https://'.$host.'/product/x'])['channel']);
    }

    public function test_attribution_keeps_first_and_last_touch(): void
    {
        $token = str_repeat('a', 40);

        // Found the store via Google, came back through a Facebook ad, then
        // returned directly to buy.
        Visit::create(['visitor_token' => $token, 'event' => 'page', 'path' => 'rings', 'source' => 'google']);
        Visit::create(['visitor_token' => $token, 'event' => 'page', 'path' => 'product/a', 'source' => 'facebook_ads', 'campaign' => 'eid-bridal']);
        Visit::create(['visitor_token' => $token, 'event' => 'page', 'path' => 'cart', 'source' => 'direct']);

        $a = Visit::attributionFor($token);

        // A direct hit mid-session must not erase the ad that brought them back.
        $this->assertSame('facebook_ads', $a['source_channel']);
        $this->assertSame('eid-bridal', $a['source_campaign']);
        $this->assertSame('google', $a['first_touch_channel']);
        $this->assertSame('rings', $a['landing_path']);
    }

    public function test_attribution_is_direct_for_an_unknown_visitor(): void
    {
        $this->assertSame('direct', Visit::attributionFor(null)['source_channel']);
        $this->assertSame('direct', Visit::attributionFor('never-seen-token')['source_channel']);
    }

    public function test_a_real_checkout_records_where_the_buyer_came_from(): void
    {
        $product = \App\Models\Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 5, 'in_stock' => true,
        ]);

        // Arrive from a tagged Facebook ad, browse, then check out.
        $this->get('/?utm_source=facebook&utm_medium=cpc&utm_campaign=eid-bridal');
        $token = Visit::latest('id')->first()?->visitor_token;
        $this->assertNotNull($token, 'the landing pageview should have been tracked');

        $this->withCookie('visitor_token', $token)->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->withCookie('visitor_token', $token)->post('/checkout', [
            'name' => 'Test Buyer', 'phone' => '01712345678',
            'address' => '123 Road, Dhaka', 'is_inside_dhaka' => 1,
        ]);

        $order = \App\Models\Order::first();

        $this->assertNotNull($order);
        $this->assertSame('facebook_ads', $order->source_channel);
        $this->assertSame('eid-bridal', $order->source_campaign);
        $this->assertSame('facebook_ads', $order->first_touch_channel);
    }

    public function test_an_order_still_places_when_the_attribution_columns_are_missing(): void
    {
        // Simulates a server whose migration hasn't run (or ran half-way).
        // Attribution is optional; taking the customer's money is not.
        app()->instance(Visit::READY_KEY, false);

        $product = \App\Models\Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 5, 'in_stock' => true,
        ]);

        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $res = $this->post('/checkout', [
            'name' => 'Test Buyer', 'phone' => '01712345678',
            'address' => '123 Road, Dhaka', 'is_inside_dhaka' => 1,
        ]);

        $res->assertRedirect();
        $this->assertSame(1, \App\Models\Order::count());
    }

    public function test_an_unparseable_app_url_does_not_make_every_referrer_look_internal(): void
    {
        // str_contains($host, '') is true in PHP 8 — without a guard this
        // reported all traffic as Direct.
        config(['app.url' => '']);

        $this->assertSame('facebook', $this->classify('/', ['referer' => 'https://m.facebook.com/'])['channel']);
    }
}
