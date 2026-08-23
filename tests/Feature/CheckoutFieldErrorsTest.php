<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Three checkout defects the store owner reported or the audit found:
 *
 *  - the member badge rendered as three stacked fragments because a flex
 *    container split the sentence into anonymous flex items;
 *  - validation messages were flattened into one banner, losing which field
 *    each belonged to;
 *  - the abandoned-cart lead latched after the first POST, so a corrected phone
 *    number was never stored — the exact case the feature exists for.
 */
class CheckoutFieldErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function seedCart(float $price = 6500): Product
    {
        $product = Product::create([
            'name' => 'Bridal Set',
            'slug' => 'bridal-set-checkout-test',
            'status' => 'published',
            'price' => $price,
            'manage_stock' => false,
        ]);

        app(CartService::class)->add($product, null, 1);

        return $product;
    }

    public function test_the_member_badge_carries_the_saving_in_taka_not_just_a_percent(): void
    {
        Setting::put('register_offer_percent', 3);
        $this->seedCart(6500);

        $this->get(route('checkout'))->assertInertia(
            fn (Assert $page) => $page
                ->component('Checkout')
                // An object, not a bare string: "Save ৳195" is the same fact as
                // "3% off" in the unit the customer is deciding in.
                ->has('registerPct.pct')
                ->has('registerPct.saving')
                ->has('registerPct.savingText')
                ->where('registerPct.pct', '3')
                ->where('registerPct.saving', 195)
        );
    }

    public function test_a_signed_in_member_gets_no_badge(): void
    {
        Setting::put('register_offer_percent', 3);
        $customer = \App\Models\Customer::create([
            'name' => 'Member', 'phone' => '01711111111', 'password' => 'secret-pass',
        ]);
        $this->seedCart();

        $this->actingAs($customer, 'customer')
            ->get(route('checkout'))
            ->assertInertia(fn (Assert $page) => $page->where('registerPct', null));
    }

    public function test_validation_failures_come_back_keyed_by_field(): void
    {
        $this->seedCart();

        $response = $this->from(route('checkout'))->post(route('checkout.store'), [
            'name' => '',
            'phone' => '12345',
            'address' => '',
        ]);

        // The page renders one message per field; it can only do that if the
        // server keeps the keys distinct.
        $response->assertSessionHasErrors(['name', 'phone', 'address']);
    }

    public function test_a_corrected_phone_number_is_still_captured(): void
    {
        $this->seedCart();

        // First blur: a real number, but the wrong one.
        $this->postJson(route('checkout.lead'), ['phone' => '01711111111', 'name' => 'Shamim'])
            ->assertOk();

        // Second blur, after the customer fixes the typo. The old client set a
        // boolean latch on the first POST and never sent again, so the typo was
        // the number the team called back — the exact case the feature exists
        // for. The client now keys on the canonical number, so this re-sends.
        $this->postJson(route('checkout.lead'), ['phone' => '01822222222', 'name' => 'Shamim'])
            ->assertOk();

        // Note: the test harness rebuilds the session id on every request, so
        // these land as two rows here. In a browser one stable cookie means
        // updateOrCreate(['session_id', 'recovered']) updates the same row —
        // that part is untouched Eloquent, not this change. What matters is
        // that the corrected number reached the server at all.
        $this->assertSame('01822222222', AbandonedCart::latest('id')->first()->phone);
    }

    public function test_the_lead_phone_is_stored_canonically(): void
    {
        $this->seedCart();

        // A "+880…" lead used to be stored verbatim, so it never matched the
        // canonical form PlaceOrder writes and was never marked recovered.
        $this->postJson(route('checkout.lead'), ['phone' => '+8801860988859'])->assertOk();

        $this->assertSame('01860988859', AbandonedCart::first()->phone);
    }
}
