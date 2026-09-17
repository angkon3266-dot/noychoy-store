<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\CartService;
use App\Services\Meta\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Taking a piece out of the order without leaving the checkout.
 *
 * The owner asked for it on 2026-09-17: the order summary listed what was
 * being bought, but the only way to drop a piece was to go back to the cart.
 * The page removes the line through the cart's own endpoint and then re-reads
 * just its basket props with an Inertia partial reload. That reload is the
 * same checkout, so it must not count as a second checkout start or send Meta
 * a second InitiateCheckout, which the mounted page would never twin.
 */
class CheckoutRemoveItemTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $slug, float $price): Product
    {
        return Product::create([
            'name' => ucfirst($slug), 'slug' => $slug, 'status' => 'published',
            'price' => $price, 'manage_stock' => false,
        ]);
    }

    /** The headers Inertia sends when a page reloads only some of its props. */
    protected function partialReload(array $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Checkout',
            'X-Inertia-Partial-Data' => implode(',', $props),
        ];
    }

    public function test_every_summary_line_carries_the_key_that_removes_it(): void
    {
        $ring = $this->product('ring', 1500);
        app(CartService::class)->add($ring, null, 1);

        $this->get('/checkout')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Checkout')
            ->where('items.0.key', app(CartService::class)->items()->first()['key'])
            ->where('items.0.name', 'Ring'));
    }

    public function test_removing_a_piece_leaves_the_rest_of_the_order_on_the_checkout(): void
    {
        $ring = $this->product('ring', 1500);
        $studs = $this->product('studs', 600);
        $cart = app(CartService::class);
        $cart->add($ring, null, 1);
        $cart->add($studs, null, 1);
        $ringKey = $cart->items()->firstWhere('product_id', $ring->id)['key'];

        $this->deleteJson('/cart/remove', ['key' => $ringKey])->assertOk();

        $this->withHeaders($this->partialReload(['items', 'summary']))
            ->get('/checkout')
            ->assertOk()
            ->assertJsonPath('component', 'Checkout')
            ->assertJsonCount(1, 'props.items')
            ->assertJsonPath('props.items.0.name', 'Studs');
    }

    public function test_the_refresh_after_a_removal_is_not_a_second_checkout_start(): void
    {
        Setting::put('meta_integration', [
            'enabled' => true, 'pixel_id' => '1234567890', 'pixel_enabled' => true, 'capi_enabled' => true,
            'capi_token_encrypted' => Crypt::encryptString('test-token'),
        ]);
        app()->forgetInstance(MetaSettings::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        app(CartService::class)->add($this->product('ring', 1500), null, 2);

        // One request only: terminating callbacks are not cleared between
        // requests in a test process (see MetaCapiDeferredTest).
        $this->withHeaders($this->partialReload(['items', 'summary']))->get('/checkout')->assertOk();

        $this->assertSame(0, Visit::where('event', 'checkout_start')->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_removing_the_last_piece_sends_her_back_to_the_cart(): void
    {
        $ring = $this->product('ring', 1500);
        $cart = app(CartService::class);
        $cart->add($ring, null, 1);

        $this->deleteJson('/cart/remove', ['key' => $cart->items()->first()['key']])->assertOk();

        $this->withHeaders($this->partialReload(['items', 'summary']))
            ->get('/checkout')
            ->assertRedirect(route('cart'));
    }
}
