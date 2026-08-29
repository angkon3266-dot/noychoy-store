<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Support\GiftLadder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The milestone gift ladder — "every 3rd piece free" from an admin-picked
 * gifts collection, the meridianeclat.com mechanic. These tests pin the
 * Shopify-BxGy math (cheapest gift free, per-order cap, gift units never
 * count as their own qualifiers) and that everything is resolved
 * server-side from the session cart.
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

    protected function enableLadder(Collection $gifts, ?Collection $qualifying = null, int $buy = 2, int $max = 3): void
    {
        Setting::put('gift_ladder_enabled', true);
        Setting::put('gift_ladder_buy', $buy);
        Setting::put('gift_ladder_max', $max);
        Setting::put('gift_ladder_gifts_collection_id', $gifts->id);
        Setting::put('gift_ladder_qualifying_collection_id', $qualifying?->id ?? 0);
        // The singleton memoises collection lookups per request; tests change
        // settings mid-"request", so start it fresh.
        app()->forgetInstance(GiftLadder::class);
    }

    public function test_off_by_default_even_with_a_qualifying_cart(): void
    {
        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 2);
        $cart->add($this->product('Gift stud', 500), null, 1);

        $this->assertSame(0.0, $cart->giftDiscount());
        $this->assertSame(0.0, $cart->discount());
        $this->assertNull($cart->giftProgress());
    }

    public function test_every_third_piece_free_zeroes_the_gift(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));

        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 1);
        $cart->add($this->product('Ring B', 1200), null, 1);
        $cart->add($gift, null, 1);

        $this->assertSame(500.0, $cart->giftDiscount());
        $this->assertSame(1000.0 + 1200.0 + 500.0 - 500.0, $cart->subtotal() - $cart->discount());

        $labels = array_column($cart->discountLines(), 'label');
        $this->assertContains('Free gift — Gift stud', $labels);
    }

    public function test_cheapest_gift_unit_goes_free_first(): void
    {
        $cheap = $this->product('Cheap gift', 400);
        $dear = $this->product('Dear gift', 900);
        $this->enableLadder($this->collection('Milestone Gifts', [$cheap, $dear]));

        // 2 paid + 2 gift pieces = 4 units → only one application (4 < 2×3).
        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 2);
        $cart->add($dear, null, 1);
        $cart->add($cheap, null, 1);

        $this->assertSame(400.0, $cart->giftDiscount());
    }

    public function test_gift_only_cart_matches_shopify_semantics(): void
    {
        // On .com the qualifying collection is "anything paid", so three
        // milestone pieces alone still earn one free — 2 bought + 1 free.
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));

        $cart = app(CartService::class);
        $cart->add($gift, null, 3);

        $this->assertSame(500.0, $cart->giftDiscount());
    }

    public function test_per_order_cap_holds(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]), buy: 1, max: 2);

        // buy=1: every 2nd piece free, capped at 2 gifts however big the cart.
        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 6);
        $cart->add($gift, null, 4);

        $this->assertSame(1000.0, $cart->giftDiscount());
    }

    public function test_disjoint_qualifying_collection_scopes_the_ladder(): void
    {
        $gift = $this->product('Gift stud', 500);
        $earringA = $this->product('Earring A', 800);
        $other = $this->product('Necklace', 2000);

        $this->enableLadder(
            $this->collection('Milestone Gifts', [$gift]),
            $this->collection('Qualifying', [$earringA]),
        );

        // The non-qualifying necklace cannot unlock anything.
        $cart = app(CartService::class);
        $cart->add($other, null, 2);
        $cart->add($gift, null, 1);
        $this->assertSame(0.0, $cart->giftDiscount());

        // Two qualifying earrings can.
        $cart->add($earringA, null, 2);
        $this->assertSame(500.0, $cart->giftDiscount());
    }

    public function test_checkout_writes_the_discounted_total(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));
        Setting::put('shipping_outside', 130);

        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 2);
        $cart->add($gift, null, 1);

        $this->post(route('checkout.store'), [
            'name' => 'Test Buyer',
            'phone' => '01712345678',
            'address' => 'House 1, Road 2, Dhaka',
        ]);

        $order = \App\Models\Order::firstOrFail();
        $this->assertSame(2500.0, (float) $order->subtotal);
        $this->assertSame(500.0, (float) $order->discount);
        $this->assertSame(2500.0 - 500.0 + 130.0, (float) $order->total);
        // The free unit still leaves the shelf.
        $this->assertCount(2, $order->items);
    }

    public function test_progress_payload_speaks_the_milestone_language(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));

        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 2);

        // Two paid pieces: a gift is earned but not yet in the cart.
        $p = $cart->giftProgress();
        $this->assertSame(0, $p['unlocked']);
        $this->assertSame(1, $p['potential']);
        $this->assertTrue($p['pick_needed']);
        $this->assertSame([3, 6, 9], $p['milestones']);
        $this->assertSame('Milestone Gifts', $p['collection']['name']);

        // Adding it: gift 1 of 3, three more pieces to the next.
        $cart->add($gift, null, 1);
        $p = $cart->giftProgress();
        $this->assertSame(1, $p['unlocked']);
        $this->assertFalse($p['pick_needed']);
        $this->assertSame(3, $p['next_more']);
    }

    public function test_mini_cart_payload_carries_the_gift_progress(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));

        $ring = $this->product('Ring A', 1000);
        $this->post(route('cart.add', $ring), ['qty' => 2]);

        $this->getJson(route('cart.mini'))
            ->assertOk()
            ->assertJsonPath('gift.potential', 1)
            ->assertJsonPath('gift.pick_needed', true);
    }

    public function test_percentage_offers_price_only_what_is_actually_paid(): void
    {
        // The review's reproduction: 2×৳1,000 rings + a ৳500 gift, with a
        // sitewide 10% offer. The gift stage zeroes ৳500; the 10% must apply
        // to the ৳2,000 the customer pays (৳200), never to the pre-gift
        // ৳2,500 (৳250) — otherwise the store leaks pct × gift value on
        // every stacked percentage.
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));

        \App\Models\Offer::create([
            'title' => '10% off everything', 'type' => 'order_percent',
            'applies_to' => 'all', 'percent' => 10, 'is_active' => true,
        ]);

        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 2);
        $cart->add($gift, null, 1);

        $this->assertSame(500.0, $cart->giftDiscount());
        $this->assertSame(200.0, $cart->promoDiscount());
        $this->assertSame(700.0, $cart->discount());
        // Paid: 2500 − 700 = 1800.
        $this->assertSame(1800.0, $cart->subtotal() - $cart->discount());
    }

    public function test_member_discount_earns_nothing_on_the_free_gift_unit(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]));
        Setting::put('register_offer_percent', 3);

        $customer = \App\Models\Customer::create([
            'name' => 'Member', 'phone' => '01722222233', 'password' => 'secret-pass',
        ]);
        $this->actingAs($customer, 'customer');

        $cart = app(CartService::class);
        $cart->add($this->product('Ring A', 1000), null, 2);
        $cart->add($gift, null, 1);

        // 3% of the ৳2,000 paid, not of ৳2,500.
        $this->assertSame(500.0, $cart->giftDiscount());
        $this->assertSame(60.0, $cart->memberSignupDiscount());
    }

    public function test_a_vanished_qualifying_collection_fails_closed_not_open(): void
    {
        $gift = $this->product('Gift stud', 500);
        $earring = $this->product('Earring A', 800);
        $qualifying = $this->collection('Qualifying', [$earring]);
        $this->enableLadder($this->collection('Milestone Gifts', [$gift]), $qualifying);

        $cart = app(CartService::class);
        $cart->add($earring, null, 2);
        $cart->add($gift, null, 1);
        $this->assertSame(500.0, $cart->giftDiscount());

        // The admin deactivates the qualifying collection. Treating the stale
        // id as "blank = everything qualifies" would silently widen the
        // giveaway — the whole ladder must switch off instead.
        $qualifying->update(['is_active' => false]);
        app()->forgetInstance(GiftLadder::class);
        // A real cart mutation clears the memoised cascade.
        $cart->update($cart->items()->first()['key'], 2);

        $this->assertSame(0.0, $cart->giftDiscount());
        $this->assertNull($cart->giftProgress());
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

    public function test_admin_cannot_enable_without_a_populated_gifts_collection(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin']);
        $empty = Collection::create(['name' => 'Empty', 'type' => 'manual', 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.offers.gift-ladder'), [
            'enabled' => 1, 'buy' => 2, 'max' => 3,
            'gifts_collection_id' => $empty->id,
        ])->assertSessionHas('error');

        $this->assertFalse((bool) Setting::get('gift_ladder_enabled', false));

        $gifts = $this->collection('Gifts', [$this->product('Gift stud', 500)]);
        $this->actingAs($admin)->post(route('admin.offers.gift-ladder'), [
            'enabled' => 1, 'buy' => 2, 'max' => 3,
            'gifts_collection_id' => $gifts->id,
        ])->assertSessionHas('success');

        $this->assertTrue((bool) Setting::get('gift_ladder_enabled'));
        $this->assertSame($gifts->id, (int) Setting::get('gift_ladder_gifts_collection_id'));
    }

    public function test_pdp_badge_advertises_cap_times_priciest_gift(): void
    {
        $this->enableLadder($this->collection('Milestone Gifts', [
            $this->product('Cheap gift', 400),
            $this->product('Dear gift', 900),
        ]));

        $badge = app(GiftLadder::class)->pdpBadge();
        $this->assertStringContainsString(money(2700.0), $badge['label']);
    }
}
