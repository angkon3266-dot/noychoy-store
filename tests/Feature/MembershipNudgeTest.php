<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The membership system (member prices, points, tiers) was invisible until a
 * customer had already joined and opened the account dashboard. These pin the
 * one shared source every storefront nudge reads — chrome.membership — and
 * the cart's taka figure, so a guest is told what joining is worth in numbers
 * that match what checkout actually applies.
 */
class MembershipNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function product(float $price = 1000): Product
    {
        return Product::create([
            'name' => 'Ring', 'slug' => 'ring-'.uniqid(), 'status' => 'published',
            'price' => $price, 'manage_stock' => false, 'in_stock' => true,
        ]);
    }

    public function test_every_page_carries_the_membership_facts_for_guests(): void
    {
        Setting::put('register_offer_percent', 3);
        Setting::put('loyalty_earn_per_taka', 0.1);
        Setting::put('register_offer_text', 'Join the club, save on every piece.');

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('chrome.membership.isMember', false)
            ->where('chrome.membership.pct', '3')
            ->where('chrome.membership.pointsPer1000', 100)
            ->where('chrome.membership.text', 'Join the club, save on every piece.')
            ->where('chrome.membership.pitch', 'Members get 3% off every piece, 100 points per ৳1,000 spent.')
            ->where('chrome.membership.tiers.0.label', 'Silver'),
        );
    }

    public function test_the_facts_go_quiet_when_the_programme_is_switched_off(): void
    {
        Setting::put('register_offer_percent', 0);
        Setting::put('loyalty_enabled', false);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('chrome.membership.pct', null)
            ->where('chrome.membership.pointsPer1000', null)
            ->where('chrome.membership.pitch', null),
        );
    }

    public function test_the_cart_tells_a_guest_the_taka_they_would_save(): void
    {
        Setting::put('register_offer_percent', 3);
        $this->post(route('cart.add', $this->product(2000)), ['qty' => 2]);

        $this->get(route('cart'))->assertInertia(fn (Assert $page) => $page
            ->where('memberNudge.pct', '3')
            ->where('memberNudge.saving_text', money(120)),
        );
    }

    public function test_a_member_sees_the_taka_that_would_reach_the_next_tier(): void
    {
        Setting::put('loyalty_earn_per_taka', 0.1);
        $customer = Customer::create(['name' => 'Member', 'phone' => '01722222255', 'password' => 'secret-pass', 'points' => 400, 'points_lifetime' => 1000]);
        $this->actingAs($customer, 'customer');

        // Silver at 1,000 lifetime points; Gold opens at 3,000 → 2,000 points
        // → ৳20,000 of orders at 0.1 point per taka.
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('chrome.membership.tier.current', 'Silver')
            ->where('chrome.membership.tier.next', 'Gold')
            ->where('chrome.membership.tier.toNextPoints', 2000)
            ->where('chrome.membership.tier.toNextSpendText', money(20000))
            ->where('chrome.membership.tier.points', 400),
        );
    }

    public function test_a_member_is_not_nudged_to_join(): void
    {
        Setting::put('register_offer_percent', 3);
        $customer = Customer::create(['name' => 'Member', 'phone' => '01722222244', 'password' => 'secret-pass']);
        $this->actingAs($customer, 'customer');
        $this->post(route('cart.add', $this->product()), ['qty' => 1]);

        $this->get(route('cart'))->assertInertia(fn (Assert $page) => $page
            ->where('memberNudge', null)
            ->where('chrome.membership.isMember', true),
        );
    }
}
