<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\DeliveryEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The delivery window the buyer is shown.
 *
 * Two things a Bangladeshi courier knows that the old inline Carbon did not:
 * inside Dhaka is faster than outside, and nobody delivers on Friday. Counting
 * plain calendar days across a Friday quotes a date the courier will miss —
 * and on a cash-on-delivery order that means the customer arranged to be home,
 * with the money, on the wrong day.
 */
class DeliveryEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_never_quotes_a_friday(): void
    {
        // Wednesday 26 Aug 2026. +2 working days would land on Friday 28th if
        // Fridays counted; it must roll to Saturday instead.
        Carbon::setTestNow(Carbon::parse('2026-08-26'));

        config(['theme.defaults.delivery_days_min' => 2, 'theme.defaults.delivery_days_max' => 2]);

        $estimate = DeliveryEstimate::for(false);

        $this->assertNotSame('Friday', $estimate->from->format('l'));
        $this->assertSame('Saturday', $estimate->from->format('l'));
    }

    public function test_a_friday_in_the_middle_pushes_the_whole_window_out(): void
    {
        // Thursday: +1 working day is Saturday, not Friday.
        Carbon::setTestNow(Carbon::parse('2026-08-27'));

        config(['theme.defaults.delivery_days_min' => 1, 'theme.defaults.delivery_days_max' => 3]);

        $estimate = DeliveryEstimate::for(false);

        $this->assertSame('Saturday', $estimate->from->format('l'));
        $this->assertSame('Monday', $estimate->to->format('l'));
    }

    public function test_inside_dhaka_is_quoted_faster_than_outside(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));   // a Monday

        $inside = DeliveryEstimate::for(true);
        $outside = DeliveryEstimate::for(false);

        $this->assertTrue(
            $inside->from->lt($outside->from),
            'inside Dhaka should arrive sooner than outside'
        );
    }

    public function test_an_unknown_zone_quotes_the_slower_nationwide_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));

        // The product page does not know where the buyer is, so it must not
        // promise the faster inside-Dhaka window to someone in Sylhet.
        $this->assertEquals(
            DeliveryEstimate::for(false)->from,
            DeliveryEstimate::for(null)->from
        );
    }

    public function test_it_returns_nothing_when_the_owner_turns_it_off(): void
    {
        config(['theme.defaults.show_delivery_estimate' => false]);

        $this->assertNull(DeliveryEstimate::for(true));
    }

    public function test_seven_off_days_does_not_hang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));
        config(['theme.defaults.delivery_off_days' => [0, 1, 2, 3, 4, 5, 6]]);

        // A courier that works no days would loop forever; it is read as
        // "none configured" instead.
        $this->assertNotNull(DeliveryEstimate::for(false));
    }

    public function test_a_single_day_window_has_no_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));
        config(['theme.defaults.delivery_days_min' => 2, 'theme.defaults.delivery_days_max' => 2]);

        $estimate = DeliveryEstimate::for(false);

        $this->assertNull($estimate->to);
        $this->assertStringNotContainsString('–', $estimate->label());
    }

    public function test_dispatch_is_the_next_working_day(): void
    {
        // Thursday: the next working day is Saturday — Friday is off.
        Carbon::setTestNow(Carbon::parse('2026-08-27'));

        $estimate = DeliveryEstimate::for(false);

        $this->assertSame('Saturday', $estimate->dispatch->format('l'));
        $this->assertSame($estimate->dispatch->format('D, d M'), $estimate->productPageShape()['dispatch']);
    }

    public function test_dispatch_never_lands_after_the_promised_arrival(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));   // a Monday
        config(['theme.defaults.delivery_days_min' => 0, 'theme.defaults.delivery_days_max' => 0]);

        $estimate = DeliveryEstimate::for(false);

        $this->assertTrue($estimate->dispatch->lte($estimate->from));
    }

    public function test_a_stale_confirmation_page_stops_quoting_a_past_window(): void
    {
        $order = $this->confirmedOrder();
        $order->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

        // Revisited weeks later, "the courier delivers Sun 23" reads as broken.
        $this->withSession(['placed_orders' => [$order->order_number]])
            ->get(route('order.confirmation', $order->order_number))
            ->assertInertia(fn (Assert $page) => $page->where('estimate', null));
    }

    protected function confirmedOrder(): Order
    {
        $product = Product::create([
            'name' => 'Bangle '.uniqid(), 'slug' => 'bangle-'.uniqid(), 'status' => 'published',
            'price' => 2400, 'manage_stock' => false,
        ]);

        $order = Order::create([
            'order_number' => 'NOY-'.random_int(100000, 999999),
            'customer_name' => 'Buyer',
            'customer_phone' => '01711111111',
            'shipping_address' => 'Dhaka',
            'is_inside_dhaka' => true,
            'status' => 'pending',
            'subtotal' => 2400, 'shipping_cost' => 0, 'total' => 2400,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => $product->name, 'price' => 2400, 'quantity' => 1, 'subtotal' => 2400,
        ]);

        return $order;
    }

    public function test_the_confirmation_page_tells_the_buyer_what_to_have_ready(): void
    {
        $product = Product::create([
            'name' => 'Bangle', 'slug' => 'bangle-estimate', 'status' => 'published',
            'price' => 2400, 'manage_stock' => false,
        ]);

        $order = Order::create([
            'order_number' => 'NOY-777001',
            'customer_name' => 'Buyer',
            'customer_phone' => '01711111111',
            'shipping_address' => 'Dhaka',
            'is_inside_dhaka' => true,
            'status' => 'pending',
            'subtotal' => 2400, 'shipping_cost' => 0, 'total' => 2400,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => $product->name, 'price' => 2400, 'quantity' => 1, 'subtotal' => 2400,
        ]);

        $this->withSession(['placed_orders' => [$order->order_number]])
            ->get(route('order.confirmation', $order->order_number))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Confirmation')
                ->has('estimate.label')
                ->where('estimate.zoneText', 'inside Dhaka')
                // The COD total is what the buyer has to physically have.
                ->where('order.totalText', money(2400))
                ->has('storePhone')
            );
    }
}
