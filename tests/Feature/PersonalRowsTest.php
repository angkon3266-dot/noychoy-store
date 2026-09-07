<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Support\GiftProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The home page's personal rows and the always-on ladder strip are built from
 * per-visitor state, so these pin that they never leak across visitors (they
 * are decided outside the shared plan cache), that they hide themselves
 * without a real signal, and that the gift finder's answers are remembered
 * and actually shape the picks.
 */
class PersonalRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, float $price, string $tags = ''): Product
    {
        return Product::create([
            'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => 'published',
            'price' => $price, 'manage_stock' => false, 'in_stock' => true, 'tags' => $tags, 'views' => random_int(1, 50),
        ]);
    }

    protected function reactHome(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);
    }

    public function test_recently_viewed_appears_after_two_products_and_not_before(): void
    {
        $this->reactHome();
        $a = $this->product('Ring A', 900);
        $b = $this->product('Ring B', 1200);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('recentlyViewed.show', false));

        $this->get(route('product.show', $a));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('recentlyViewed.show', false));

        $this->get(route('product.show', $b));
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('recentlyViewed.show', true)
            ->where('recentlyViewed.cards.0.name', 'Ring B')   // most recent first
            ->where('recentlyViewed.cards.1.name', 'Ring A'));
    }

    public function test_the_gift_finder_answers_are_remembered_and_drive_the_picks(): void
    {
        $this->reactHome();
        foreach (range(1, 5) as $i) {
            $this->product("Birthday stud {$i}", 700, 'Earring, Gift, Birthday');
        }
        $this->product('Pricey birthday set', 5000, 'Necklace, Gift, Birthday');
        $this->product('Anniversary bracelet', 800, 'Bracelet, Gift, Anniversary');

        // The budget link from the finder, with the answers riding on it.
        $this->get('/shop?price_max=1000&gift=1&for=her&occasion=birthday')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 5));   // the occasion narrows the grid
        $this->assertSame('birthday', session(GiftProfile::KEY)['occasion']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pickedForYou.show', true)
            ->where('pickedForYou.title', 'Picked for you — for her, birthday, under ৳1,000')
            ->has('pickedForYou.cards', 5)
            ->where('giftFinder.profile.for', 'her'));

        // A member keeps it on their record for next time.
        $customer = Customer::create(['name' => 'Rima', 'phone' => '01711100021', 'password' => 'secret123']);
        $this->actingAs($customer, 'customer')->get('/shop?price_max=1000&gift=1&occasion=anniversary');
        $this->assertSame('anniversary', $customer->fresh()->gift_profile['occasion']);
    }

    public function test_picks_stay_hidden_without_a_signal(): void
    {
        $this->reactHome();
        foreach (range(1, 6) as $i) {
            $this->product("Piece {$i}", 900, 'Ring, Gift');
        }

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('pickedForYou.show', false));
    }

    public function test_every_page_shares_the_ladder_for_the_header_strip(): void
    {
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('ladder', null));

        Setting::put('gift_ladder_enabled', true);
        app()->forgetInstance(\App\Support\GiftLadder::class);

        // An empty cart still gets the strip: rung 0, first rung next.
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('ladder.tier', 0)
            ->where('ladder.next.n', 1)
            ->where('ladder.next.more', 1)
            ->has('ladder.tiers', 10));
    }
}
