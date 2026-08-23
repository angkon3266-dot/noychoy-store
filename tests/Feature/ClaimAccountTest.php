<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Turning a guest checkout into an account.
 *
 * Right after buying is the best moment this shop gets to ask, and it was the
 * one moment it never did — normal registration would have rejected them
 * anyway, because checkout already took their phone and PlaceOrder created a
 * customer row with it.
 *
 * The security question is the whole feature: order numbers are sequential and
 * a phone number is not a secret, so "claim the account attached to this order"
 * must require proof the order is yours.
 */
class ClaimAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function guestOrder(string $phone = '01860988859'): Order
    {
        $product = Product::create([
            'name' => 'Claim Ring', 'slug' => 'claim-ring-'.uniqid(), 'status' => 'published',
            'price' => 3000, 'manage_stock' => false,
        ]);

        // What PlaceOrder does for a guest: a customer row with no password.
        $customer = Customer::create(['name' => 'Guest Buyer', 'phone' => $phone]);

        $order = Order::create([
            'order_number' => 'NOY-'.random_int(100000, 999999),
            'customer_id' => $customer->id,
            'customer_name' => 'Guest Buyer',
            'customer_phone' => $phone,
            'shipping_address' => 'Dhaka',
            'status' => 'pending',
            'subtotal' => 3000, 'shipping_cost' => 0, 'total' => 3000,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name,
            'price' => 3000, 'quantity' => 1, 'subtotal' => 3000,
        ]);

        return $order;
    }

    public function test_a_guest_is_offered_an_account_on_the_confirmation_page(): void
    {
        $order = $this->guestOrder();

        $this->withSession(['placed_orders' => [$order->order_number]])
            ->get(route('order.confirmation', $order->order_number))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('claimAccount.url')
                ->where('claimAccount.phone', '01860988859')
            );
    }

    public function test_the_guest_can_set_a_password_and_is_signed_in(): void
    {
        $order = $this->guestOrder();

        $this->withSession(['placed_orders' => [$order->order_number]])
            ->post(route('order.claim', $order->order_number), [
                'password' => 'secret-pass',
                'password_confirmation' => 'secret-pass',
            ])->assertRedirect(route('account'));

        $customer = $order->fresh()->customer;

        $this->assertTrue(Hash::check('secret-pass', $customer->password));
        $this->assertTrue(auth('customer')->check());
    }

    // ── The gate ───────────────────────────────────────────────────────────

    public function test_a_stranger_cannot_claim_someone_elses_account(): void
    {
        $order = $this->guestOrder();

        // No session entry, no signature — just a guessable order number.
        $this->post(route('order.claim', $order->order_number), [
            'password' => 'stolen-pass',
            'password_confirmation' => 'stolen-pass',
        ])->assertForbidden();

        $this->assertNull($order->fresh()->customer->password);
    }

    public function test_a_signed_link_is_accepted(): void
    {
        $order = $this->guestOrder();

        // The same proof the emailed confirmation link carries.
        $url = URL::signedRoute('order.claim', ['orderNumber' => $order->order_number]);

        $this->post($url, [
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])->assertRedirect(route('account'));

        $this->assertNotNull($order->fresh()->customer->password);
    }

    public function test_an_account_that_already_has_a_password_cannot_be_taken_over(): void
    {
        $order = $this->guestOrder();
        $order->customer->update(['password' => 'the-real-password']);

        $this->withSession(['placed_orders' => [$order->order_number]])
            ->post(route('order.claim', $order->order_number), [
                'password' => 'attacker-pass',
                'password_confirmation' => 'attacker-pass',
            ])->assertRedirect(route('customer.login'));

        // The original password stands.
        $this->assertTrue(Hash::check('the-real-password', $order->fresh()->customer->password));
    }

    public function test_an_already_registered_buyer_is_not_offered_it_again(): void
    {
        $order = $this->guestOrder();
        $order->customer->update(['password' => 'already-a-member']);

        $this->withSession(['placed_orders' => [$order->order_number]])
            ->get(route('order.confirmation', $order->order_number))
            ->assertInertia(fn (Assert $page) => $page->where('claimAccount', null));
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $order = $this->guestOrder();

        $this->withSession(['placed_orders' => [$order->order_number]])
            ->post(route('order.claim', $order->order_number), [
                'password' => 'secret-pass',
                'password_confirmation' => 'different-pass',
            ])->assertSessionHasErrors('password');

        $this->assertNull($order->fresh()->customer->password);
    }
}
