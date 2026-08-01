<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quantity/bundle offer tiers are snapshotted into the cart when an item is
 * added. Checkout re-validated the price but never the tiers, so a session
 * could keep claiming a discount the admin had since cut or withdrawn.
 */
class StaleOfferSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 1000, 'manage_stock' => false, 'in_stock' => true,
        ], $attrs));
    }

    protected function checkout()
    {
        return $this->post('/checkout', [
            'name' => 'B', 'phone' => '01712345678', 'address' => '1 Rd', 'is_inside_dhaka' => 1,
        ]);
    }

    protected function tiers(float $percent): array
    {
        return [['min_qty' => 1, 'type' => 'percent', 'value' => $percent]];
    }

    public function test_a_withdrawn_offer_stops_discounting_the_cart(): void
    {
        $p = $this->product(['quantity_offers' => $this->tiers(30)]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);
        $this->assertSame(300.0, app(CartService::class)->offerDiscount());

        $p->update(['quantity_offers' => []]);      // admin withdraws the offer

        $this->checkout()->assertRedirect('/cart');
        $this->assertSame(0, Order::count());
        // Snapshot refreshed, so the cart now shows the truth.
        $this->assertSame(0.0, app(CartService::class)->offerDiscount());
    }

    public function test_a_reduced_offer_makes_the_customer_re_confirm(): void
    {
        $p = $this->product(['quantity_offers' => $this->tiers(30)]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        $p->update(['quantity_offers' => $this->tiers(10)]);   // 30% cut to 10%

        $this->checkout()->assertRedirect('/cart');
        $this->assertSame(0, Order::count());
        $this->assertSame(100.0, app(CartService::class)->offerDiscount());
    }

    public function test_an_improved_offer_applies_without_interrupting_checkout(): void
    {
        $p = $this->product(['quantity_offers' => $this->tiers(10)]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        $p->update(['quantity_offers' => $this->tiers(30)]);   // a better deal

        $this->checkout();

        // No bounce: a bigger discount costs the customer nothing to accept.
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(300.0, (float) $order->discount);
        $this->assertSame(700.0, (float) $order->subtotal - (float) $order->discount);
    }

    public function test_an_unchanged_offer_checks_out_normally(): void
    {
        $p = $this->product(['quantity_offers' => $this->tiers(20)]);
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        $this->checkout();

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(200.0, (float) $order->discount);
    }

    public function test_offer_signature_survives_a_session_round_trip(): void
    {
        // Ints becoming floats through JSON must not read as a changed offer.
        $a = [['min_qty' => 2, 'percent' => 15]];
        $b = json_decode(json_encode([['min_qty' => 2.0, 'percent' => 15.0]]), true);

        $this->assertSame(CartService::offerSignature($a), CartService::offerSignature($b));
    }
}
