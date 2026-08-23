<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Services\StorefrontFilters;
use App\Support\DailyDeals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The caches added for page speed, and — more importantly — the proof they go
 * stale when they should. A sidebar that never refreshes is worse than a slow
 * one: the owner adds a colour, cannot filter by it, and has no idea why.
 */
class StorefrontSpeedTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $slug, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Ring '.$slug,
            'slug' => $slug,
            'status' => 'published',
            'price' => 2000,
            'manage_stock' => false,
            'in_stock' => true,
        ], $attrs));
    }

    public function test_the_filter_sidebar_is_not_rebuilt_on_every_request(): void
    {
        $this->product('filter-a', ['tags' => 'gift']);

        $this->get('/shop')->assertOk();

        DB::enableQueryLog();
        $this->get('/shop')->assertOk();
        $warm = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Not an absolute budget — just proof the catalogue-wide scan is not
        // repeated. Before this it hydrated every published product, with its
        // JSON casts, on every single catalogue page view.
        $this->assertLessThan(40, $warm, 'a warm /shop is still running a lot of queries');
    }

    public function test_saving_a_product_refreshes_the_filter_sidebar(): void
    {
        $product = $this->product('filter-b', ['tags' => 'gift']);

        $before = StorefrontFilters::version();
        $this->get('/shop')->assertOk();

        $product->update(['tags' => 'gift, wedding']);

        $this->assertGreaterThan(
            $before,
            StorefrontFilters::version(),
            'the cached sidebar would have kept serving the old tag list'
        );
    }

    public function test_deleting_a_product_refreshes_the_filter_sidebar(): void
    {
        $product = $this->product('filter-c');
        $before = StorefrontFilters::version();

        $product->delete();

        $this->assertGreaterThan($before, StorefrontFilters::version());
    }

    public function test_deals_of_the_day_are_cached_and_cleared_when_an_offer_changes(): void
    {
        Cache::flush();

        $offer = Offer::create([
            'title' => 'Eid offer', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 10, 'is_active' => true, 'members_only' => false,
        ]);

        $first = DailyDeals::cards();
        $this->assertNotNull(Cache::get('home.deals.guest'), 'the deals were not cached');

        // The owner edits the deal — she must not wait ten minutes to see it.
        $offer->update(['title' => 'Eid offer — extended']);

        $this->assertNull(Cache::get('home.deals.guest'), 'an edited offer left a stale card cached');
        unset($first);
    }

    public function test_a_members_only_deal_is_cached_separately_from_the_guest_view(): void
    {
        Cache::flush();

        Offer::create([
            'title' => 'Members only', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 15, 'is_active' => true, 'members_only' => true,
        ]);

        DailyDeals::cards();

        // Sharing one cache entry would dangle a members-only deal in front of
        // a guest, which is a dead end.
        $this->assertNotNull(Cache::get('home.deals.guest'));
        $this->assertNull(Cache::get('home.deals.member'));
    }
}
