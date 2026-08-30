<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repairing traffic that was filed as "Other website" before the classifier
 * understood Meta's short source names.
 */
class ReclassifyVisitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refiles_referral_traffic_with_a_recognisable_referrer(): void
    {
        Visit::create(['visitor_token' => str_repeat('a', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'm.facebook.com']);
        Visit::create(['visitor_token' => str_repeat('b', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'instagram.com']);

        $this->artisan('visits:reclassify')->assertSuccessful();

        $this->assertSame('facebook', Visit::where('visitor_token', str_repeat('a', 40))->value('source'));
        $this->assertSame('instagram', Visit::where('visitor_token', str_repeat('b', 40))->value('source'));
    }

    public function test_it_leaves_genuine_other_websites_alone(): void
    {
        Visit::create(['visitor_token' => str_repeat('c', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'someblog.example']);

        // …and does not touch traffic that was never mis-filed.
        Visit::create(['visitor_token' => str_repeat('d', 40), 'event' => 'page', 'path' => '/',
            'source' => 'direct', 'referrer_host' => null]);

        $this->artisan('visits:reclassify')->assertSuccessful();

        $this->assertSame('referral', Visit::where('visitor_token', str_repeat('c', 40))->value('source'));
        $this->assertSame('direct', Visit::where('visitor_token', str_repeat('d', 40))->value('source'));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Visit::create(['visitor_token' => str_repeat('e', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'm.facebook.com']);

        $this->artisan('visits:reclassify --dry-run')->assertSuccessful();

        $this->assertSame('referral', Visit::where('visitor_token', str_repeat('e', 40))->value('source'));
    }

    public function test_a_meta_campaign_id_separates_ad_clicks_from_organic_reach(): void
    {
        // The referrer only ever says which platform. {{campaign.id}} resolving
        // to eighteen digits is what says somebody paid for the click.
        Visit::create(['visitor_token' => str_repeat('f', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'm.facebook.com', 'campaign' => '120250686619310682']);

        // A campaign somebody named is a post, not an ad.
        Visit::create(['visitor_token' => str_repeat('g', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'm.facebook.com', 'campaign' => 'eid-post']);

        $this->artisan('visits:reclassify')->assertSuccessful();

        $this->assertSame('facebook_ads', Visit::where('visitor_token', str_repeat('f', 40))->value('source'));
        $this->assertSame('facebook', Visit::where('visitor_token', str_repeat('g', 40))->value('source'));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        Visit::create(['visitor_token' => str_repeat('h', 40), 'event' => 'page', 'path' => '/',
            'source' => 'referral', 'referrer_host' => 'instagram.com', 'campaign' => '120250686619310682']);

        $this->artisan('visits:reclassify')->assertSuccessful();
        $first = Visit::where('visitor_token', str_repeat('h', 40))->value('source');

        $this->artisan('visits:reclassify')->assertSuccessful();

        $this->assertSame('instagram_ads', $first);
        $this->assertSame($first, Visit::where('visitor_token', str_repeat('h', 40))->value('source'));
    }

    public function test_it_repairs_the_channel_stamped_on_an_order_too(): void
    {
        Order::create([
            'order_number' => '10001', 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 1000, 'total' => 1000,
            'status' => 'delivered', 'payment_method' => 'cod',
            'source_channel' => 'referral', 'source_referrer' => 'm.facebook.com',
        ]);

        $this->artisan('visits:reclassify')->assertSuccessful();

        $this->assertSame('facebook', Order::first()->source_channel);
    }
}
