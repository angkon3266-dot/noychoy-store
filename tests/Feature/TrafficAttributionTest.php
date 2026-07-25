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
}
