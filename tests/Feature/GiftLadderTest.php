<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Support\GiftLadder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reward ladder — every paid piece climbs a rung and each rung's reward
 * stays unlocked (৳50 off at 1, 2% at 2, free delivery at 3 … a free gift at
 * 9, ৳100 at 10). These tests pin the cumulative math, that a free gift unit
 * can never climb the ladder for itself, that percentage stages further down
 * the cascade only price what is actually paid, and that everything is
 * resolved server-side from the session cart.
 */
class GiftLadderTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, float $price): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'status' => 'published',
            'price' => $price,
            'manage_stock' => false,
            'in_stock' => true,
        ]);
    }

    /** A manual collection holding exactly the given products. */
    protected function collection(string $name, array $products): Collection
    {
        $c = Collection::create(['name' => $name, 'type' => 'manual', 'is_active' => true]);
        foreach (array_values($products) as $i => $p) {
            $c->products()->attach($p->id, ['position' => $i]);
        }

        return $c;
    }

    /** Switch the ladder on — the shipped ten rungs unless custom ones are given. */
    protected function enableLadder(?Collection $gifts = null, ?array $tiers = null): void
    {
        Setting::put('gift_ladder_enabled', true);
        if ($tiers !== null) {
            Setting::put('gift_ladder_tiers', $tiers);
        }
        Setting::put('gift_ladder_gifts_collection_id', $gifts?->id ?? 0);
        // The singleton memoises settings and collection lookups per request;
        // tests change them mid-"request", so start it fresh.
        app()->forgetInstance(GiftLadder::class);
    }

    /** N ৳1,000 rings in a fresh cart. */
    protected function cartOfRings(int $n): CartService
    {
        $cart = app(CartService::class);
        $cart->add($this->product('Ring', 1000), null, $n);

        return $cart;
    }

    public function test_off_by_default_even_with_a_full_cart(): void
    {
        $cart = $this->cartOfRings(4);

        $this->assertSame(0.0, $cart->giftDiscount());
        $this->assertSame(0.0, $cart->discount());
        $this->assertFalse($cart->hasFreeShipping());
        $this->assertNull($cart->giftProgress());
    }

    public function test_the_first_piece_takes_fifty_off(): void
    {
        $this->enableLadder();
        $cart = $this->cartOfRings(1);

        $this->assertSame(50.0, $cart->giftDiscount());
        $this->assertSame(50.0, $cart->discount());
        $this->assertContains('Ladder · ৳50 off (1 piece)', array_column($cart->discountLines(), 'label'));

        $p = $cart->giftProgress();
        $this->assertSame(1, $p['tier']);
        $this->assertSame(10, $p['count']);
        $this->assertSame(2, $p['next']['n']);
        $this->assertSame(1, $p['next']['more']);
        $this->assertSame('2% off', $p['next']['label']);
    }

    public function test_rewards_accumulate_rung_by_rung(): void
    {
        $this->enableLadder();
        Setting::put('shipping_outside', 130);
        $cart = $this->cartOfRings(4);

        // ৳50 (rung 1) + 2% of ৳4,000 (rung 2) + ৳60 (rung 4) — and rung 3
        // makes delivery free rather than adding money.
        $this->assertSame(50.0 + 80.0 + 60.0, $cart->giftDiscount());
        $this->assertTrue($cart->hasFreeShipping());
        $this->assertSame(0.0, $cart->shipping(false));

        $p = $cart->giftProgress();
        $this->assertSame(4, $p['tier']);
        $this->assertSame('৳50 off · 2% off · Free delivery · ৳60 off', $p['summary']);
        $this->assertTrue($p['free_delivery']);
        $this->assertSame(5, $p['next']['n']);
    }

    /**
     * Free delivery is money the customer keeps, so every "saved" figure
     * counts it — valued at the LOWER of the two zone rates, because the zone
     * is not known until checkout and an overstated saving is a broken promise.
     */
    public function test_free_delivery_counts_towards_what_the_cart_has_saved(): void
    {
        $this->enableLadder();
        Setting::put('shipping_inside', 80);
        Setting::put('shipping_outside', 90);

        // Two pieces: the money rungs only, delivery still charged.
        $two = $this->cartOfRings(2);
        $this->assertFalse($two->hasFreeShipping());
        $this->assertSame(0.0, $two->deliverySaving());
        $this->assertSame($two->discount(), $two->totalSaved());

        // Three: the delivery rung opens, and ৳80 joins the saving.
        $three = $this->cartOfRings(3);
        $this->assertTrue($three->hasFreeShipping());
        $this->assertSame(80.0, $three->deliverySaving());
        $this->assertSame(round($three->discount() + 80, 2), $three->totalSaved());

        // The ladder's own line says the larger number too…
        $this->assertSame(money($three->discount() + 80), $three->giftProgress()['saved_text']);

        // …while the order total is untouched: delivery is its own line, and
        // counting it as a discount would bill the customer ৳80 short.
        $this->assertSame(round($three->subtotal() - $three->discount(), 2), round($three->total(false), 2));
    }

    public function test_the_ninth_piece_frees_the_cheapest_gift_unit(): void
    {
        $cheap = $this->product('Cheap gift', 500);
        $dear = $this->product('Dear gift', 900);
        $this->enableLadder($this->collection('Free gifts', [$cheap, $dear]));

        // 8 rings + 2 gift pieces = 10 units → 9 paid + the cheap gift free.
        $cart = $this->cartOfRings(8);
        $cart->add($dear, null, 1);
        $cart->add($cheap, null, 1);

        $flats = 50 + 60 + 65 + 70 + 70 + 80;              // rungs 1,4,5,6,7,8
        $percent = round((8000 + 900) * 0.02, 2);           // 2% of what is paid
        $this->assertSame($flats + $percent + 500.0, $cart->giftDiscount());
        $this->assertContains('Free gift — Cheap gift', array_column($cart->discountLines(), 'label'));

        $p = $cart->giftProgress();
        $this->assertSame(9, $p['tier']);
        $this->assertSame(9, $p['units']);
        $this->assertSame('Cheap gift', $p['gift']['name']);
        $this->assertFalse($p['gift']['pick_needed']);
        $this->assertSame(10, $p['next']['n']);
        $this->assertSame(1, $p['next']['more']);
    }

    public function test_a_gift_unit_never_climbs_the_ladder_for_itself(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Free gifts', [$gift]));

        // Nine paid pieces: the gift rung is open, nothing picked yet.
        $cart = $this->cartOfRings(9);
        $p = $cart->giftProgress();
        $this->assertSame(9, $p['tier']);
        $this->assertTrue($p['gift']['pick_needed']);
        $this->assertSame('Free gifts', $p['gift']['collection']['name']);

        // Adding the gift makes it free — and it does NOT count as the 10th
        // paid piece, so rung 10 stays shut.
        $cart->add($gift, null, 1);
        $p = $cart->giftProgress();
        $this->assertSame(9, $p['tier']);
        $this->assertFalse($p['gift']['pick_needed']);
        $this->assertSame(10, $p['next']['n']);
        $this->assertContains('Free gift — Gift stud', array_column($cart->discountLines(), 'label'));
    }

    public function test_the_gift_rung_stays_shut_without_a_populated_gifts_collection(): void
    {
        // No collection at all: the money rungs carry on, nothing is promised.
        $this->enableLadder();
        $cart = $this->cartOfRings(9);

        $this->assertSame(50 + 60 + 65 + 70 + 70 + 80 + 180.0, $cart->giftDiscount());
        $this->assertSame(9, $cart->giftProgress()['tier']);
        $this->assertFalse($cart->giftProgress()['gift']['pick_needed']);
        $this->assertNull($cart->giftProgress()['gift']['collection']);
    }

    public function test_checkout_writes_the_ladder_to_the_order(): void
    {
        $this->enableLadder();
        Setting::put('shipping_outside', 130);

        $cart = $this->cartOfRings(3);

        $this->post(route('checkout.store'), [
            'name' => 'Test Buyer',
            'phone' => '01712345678',
            'address' => 'House 1, Road 2, Dhaka',
        ]);

        $order = \App\Models\Order::firstOrFail();
        $this->assertSame(3000.0, (float) $order->subtotal);
        $this->assertSame(50.0 + 60.0, (float) $order->discount);   // ৳50 + 2% of ৳3,000
        $this->assertSame(0.0, (float) $order->shipping_cost);        // rung 3
        $this->assertSame(3000.0 - 110.0, (float) $order->total);
        $this->assertSame(3, $order->ladder_tier);
        $this->assertSame(['৳50 off', '2% off', 'Free delivery'], array_column($order->ladder_rewards, 'label'));
    }

    public function test_percentage_offers_price_only_what_is_actually_paid(): void
    {
        // 8 × ৳1,000 rings + 2 × ৳500 gift studs with a sitewide 10% offer.
        // One stud is the free gift; the 10% must apply to the ৳8,500 paid,
        // never to the pre-gift ৳9,000 — otherwise the store leaks pct × gift
        // value on every stacked percentage.
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Free gifts', [$gift]));

        \App\Models\Offer::create([
            'title' => '10% off everything', 'type' => 'order_percent',
            'applies_to' => 'all', 'percent' => 10, 'is_active' => true,
        ]);

        $cart = $this->cartOfRings(8);
        $cart->add($gift, null, 2);

        $ladder = (50 + 60 + 65 + 70 + 70 + 80) + round(8500 * 0.02, 2) + 500.0;
        $this->assertSame($ladder, $cart->giftDiscount());
        $this->assertSame(850.0, $cart->promoDiscount());
        $this->assertSame($ladder + 850.0, $cart->discount());
    }

    public function test_member_discount_earns_nothing_on_the_free_gift_unit(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Free gifts', [$gift]));
        Setting::put('register_offer_percent', 3);

        $customer = \App\Models\Customer::create([
            'name' => 'Member', 'phone' => '01722222233', 'password' => 'secret-pass',
        ]);
        $this->actingAs($customer, 'customer');

        $cart = $this->cartOfRings(8);
        $cart->add($gift, null, 2);

        // 3% of the ৳8,500 paid, not of ৳9,000.
        $this->assertSame(255.0, $cart->memberSignupDiscount());
    }

    public function test_a_coupon_free_delivery_is_worth_nothing_once_the_ladder_ships_free(): void
    {
        $this->enableLadder();
        Setting::put('shipping_outside', 130);
        $coupon = Coupon::create(['code' => 'SHIPFREE', 'type' => 'percent', 'value' => 5, 'is_active' => true, 'free_shipping' => true]);

        // Two pieces: rung 3 is shut, so the coupon's delivery is worth the courier rate.
        $cart = $this->cartOfRings(2);
        $this->assertSame(130.0, $cart->deliveryValueOf($coupon));

        // Three pieces: the ladder already ships free — the coupon adds nothing there.
        $cart->update($cart->items()->first()['key'], 3);
        $this->assertSame(0.0, $cart->deliveryValueOf($coupon));
    }

    public function test_mini_cart_payload_carries_the_ladder(): void
    {
        $this->enableLadder();

        $ring = $this->product('Ring A', 1000);
        $this->post(route('cart.add', $ring), ['qty' => 2]);

        $this->getJson(route('cart.mini'))
            ->assertOk()
            ->assertJsonPath('gift.tier', 2)
            ->assertJsonPath('gift.next.n', 3)
            ->assertJsonPath('gift.next.label', 'Free delivery')
            ->assertJsonPath('free_shipping', false);
    }

    public function test_custom_rungs_from_the_admin_drive_the_math(): void
    {
        $this->enableLadder(tiers: [
            ['threshold' => 2, 'type' => 'percent', 'value' => 10],
            ['threshold' => 1, 'type' => 'flat', 'value' => 25],   // out of order on purpose
            ['threshold' => 0, 'type' => 'flat', 'value' => 999],  // invalid: dropped
            ['threshold' => 3, 'type' => 'flat', 'value' => 0],    // invalid: dropped
        ]);

        $cart = $this->cartOfRings(1);
        $this->assertSame(25.0, $cart->giftDiscount());
        $this->assertSame(2, $cart->giftProgress()['count']);

        $cart->update($cart->items()->first()['key'], 2);
        $this->assertSame(25.0 + 200.0, $cart->giftDiscount());
        $this->assertNull($cart->giftProgress()['next']);
    }

    public function test_admin_saves_the_ladder_and_refuses_a_gift_rung_without_gifts(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin']);
        $empty = Collection::create(['name' => 'Empty', 'type' => 'manual', 'is_active' => true]);
        $tiers = [
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 3, 'type' => 'free_delivery', 'value' => null],
            ['threshold' => 9, 'type' => 'free_gift', 'value' => null],
        ];

        $this->actingAs($admin)->post(route('admin.offers.gift-ladder'), [
            'enabled' => 1, 'tiers' => $tiers, 'gifts_collection_id' => $empty->id,
        ])->assertSessionHas('error');
        $this->assertFalse((bool) Setting::get('gift_ladder_enabled', false));

        $gifts = $this->collection('Gifts', [$this->product('Gift stud', 500)]);
        $this->actingAs($admin)->post(route('admin.offers.gift-ladder'), [
            'enabled' => 1, 'tiers' => $tiers, 'gifts_collection_id' => $gifts->id,
        ])->assertSessionHas('success');

        $this->assertTrue((bool) Setting::get('gift_ladder_enabled'));
        $this->assertSame($gifts->id, (int) Setting::get('gift_ladder_gifts_collection_id'));
        $this->assertSame([1, 3, 9], array_column(Setting::get('gift_ladder_tiers'), 'threshold'));

        // Switching on with no usable rung at all is refused too.
        $this->actingAs($admin)->post(route('admin.offers.gift-ladder'), [
            'enabled' => 1, 'tiers' => [['threshold' => 1, 'type' => 'flat', 'value' => 0]],
        ])->assertSessionHas('error');
    }

    public function test_pdp_badge_names_the_first_money_rung_the_delivery_rung_and_the_gift_rung(): void
    {
        $gifts = $this->collection('Free gifts', [$this->product('Gift stud', 500)]);
        $this->enableLadder($gifts);

        $badge = app(GiftLadder::class)->pdpBadge();
        $this->assertSame('Add more, save more — ৳50 off from the 1st piece, free delivery from the 3rd piece, a free gift at the 9th piece', $badge['label']);
        $this->assertSame($gifts->url(), $badge['url']);

        // Without gifts the promise drops the gift clause rather than lying.
        $this->enableLadder();
        $this->assertStringNotContainsString('gift', app(GiftLadder::class)->pdpBadge()['label']);
    }

    public function test_apply_copy_refuses_rows_without_a_matching_slug(): void
    {
        $product = $this->product('Ring A', 1000);
        $original = $product->description;

        $file = tempnam(sys_get_temp_dir(), 'copy').'.json';
        file_put_contents($file, json_encode([
            ['id' => $product->id, 'description' => 'hijacked'],                       // no slug at all
            ['id' => $product->id, 'slug' => 'some-other-slug', 'description' => 'hijacked'],
        ]));

        $this->artisan('catalog:apply-copy', ['file' => $file])
            ->expectsOutputToContain('Updated 0 product(s)')
            ->assertSuccessful();

        $this->assertSame($original, $product->fresh()->description);

        // The same row WITH the right slug applies, and specs merge in.
        file_put_contents($file, json_encode([
            ['id' => $product->id, 'slug' => $product->slug, 'description' => 'New copy.', 'specs' => ['Metal' => 'Brass']],
        ]));
        $this->artisan('catalog:apply-copy', ['file' => $file])->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame('New copy.', $fresh->description);
        $this->assertSame([['label' => 'Metal', 'value' => 'Brass', 'show' => true]], $fresh->customFieldList());
        unlink($file);
    }
}
