<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both catalogue feeds are pasted into someone else's dashboard — Commerce
 * Manager, Merchant Center — so the only way the store owner finds the URL is
 * if the admin panel shows it. The Google feed shipped as a route with nothing
 * pointing at it, and it took a "where is this in my settings?" to notice.
 */
class FeedUrlsInAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    public function test_the_integrations_page_shows_both_feed_urls(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.system-config.integrations'))
            ->assertOk();

        $response->assertSee(route('feed.meta'), false);
        $response->assertSee(route('feed.google'), false);
    }

    public function test_both_feed_urls_are_reachable_from_the_storefront(): void
    {
        $this->get(route('feed.meta'))->assertOk();
        $this->get(route('feed.google'))->assertOk();
    }
}
