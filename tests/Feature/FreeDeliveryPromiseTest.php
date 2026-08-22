<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The announcement bar promised "free delivery over ৳3000" while the checkout
 * still charged for it, because the threshold was env-only and the banner was
 * unrelated free text. These tests pin the two together.
 */
class FreeDeliveryPromiseTest extends TestCase
{
    use RefreshDatabase;

    protected function product(float $price): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => 'Ring '.$n,
            'slug' => 'free-delivery-ring-'.$n,
            'status' => 'published',
            'price' => $price,
            'manage_stock' => false,
            'in_stock' => true,
        ]);
    }

    protected function cartWorth(float $price): CartService
    {
        $cart = app(CartService::class);
        $cart->add($this->product($price), null, 1);

        return $cart;
    }

    public function test_admin_setting_overrides_the_env_default(): void
    {
        config(['store.shipping.free_threshold' => 5000.0]);
        $this->assertSame(5000.0, free_shipping_threshold());

        Setting::put('free_shipping_threshold', 3000);
        $this->assertSame(3000.0, free_shipping_threshold());
    }

    public function test_blank_or_zero_means_the_promise_is_off(): void
    {
        config(['store.shipping.free_threshold' => null]);

        foreach ([null, '', 0, '0'] as $off) {
            Setting::put('free_shipping_threshold', $off);
            $this->assertNull(free_shipping_threshold(), 'Expected '.var_export($off, true).' to disable the promise');
        }
    }

    public function test_checkout_charges_delivery_below_the_threshold_and_not_above(): void
    {
        Setting::put('free_shipping_threshold', 3000);
        Setting::put('shipping_outside', 130);

        $this->assertSame(130.0, $this->cartWorth(2999)->shipping(false));

        app(CartService::class)->clear();

        $cart = $this->cartWorth(3000);
        $this->assertTrue($cart->hasFreeShipping());
        $this->assertSame(0.0, $cart->shipping(false));
    }

    public function test_no_free_delivery_at_any_subtotal_while_the_promise_is_off(): void
    {
        config(['store.shipping.free_threshold' => null]);
        Setting::put('free_shipping_threshold', null);
        Setting::put('shipping_outside', 130);

        $cart = $this->cartWorth(999999);
        $this->assertFalse($cart->hasFreeShipping());
        $this->assertSame(130.0, $cart->shipping(false));
    }

    public function test_banner_prints_the_live_threshold(): void
    {
        Setting::put('free_shipping_threshold', 3000);
        Setting::put('theme', ['announcement_messages' => ['Free delivery on orders over {free_delivery}']]);

        $this->assertSame(['Free delivery on orders over '.money(3000)], announcement_messages());
    }

    public function test_banner_drops_the_promise_entirely_when_it_is_switched_off(): void
    {
        config(['store.shipping.free_threshold' => null]);
        Setting::put('free_shipping_threshold', null);
        Setting::put('theme', ['announcement_messages' => [
            'Free delivery on orders over {free_delivery}',
            'Cash on delivery available all over Bangladesh',
        ]]);

        // The surviving message is untouched; the unhonourable one is gone —
        // not rendered with an empty hole where the number was.
        $this->assertSame(['Cash on delivery available all over Bangladesh'], announcement_messages());
    }

    public function test_the_shipped_default_banner_cannot_advertise_a_promise_that_is_off(): void
    {
        config(['store.shipping.free_threshold' => null]);
        Setting::put('free_shipping_threshold', null);
        Setting::put('theme', null);

        foreach (announcement_messages() as $message) {
            $this->assertStringNotContainsStringIgnoringCase('free delivery', $message);
        }
    }
}
