<?php

namespace Tests\Feature;

use App\Jobs\SendReviewRequest;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\ReviewThankYouOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The thank-you discount that rides along with a post-delivery review request.
 *
 * Three promises are made to the customer by SMS, and each is pinned here:
 * the code is hers alone, it is worth what the message says, and it lasts as
 * long as the message says. The fourth property is for the shop: an exclusive
 * code must never quietly stack on top of the offers already running.
 */
class ReviewThankYouOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name = 'Ring', float $price = 1000): Product
    {
        return Product::create([
            'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(),
            'status' => 'published', 'price' => $price,
            'manage_stock' => false, 'in_stock' => true,
        ]);
    }

    protected function deliveredOrder(string $phone = '01711111111', int $daysAgo = 5): Order
    {
        $product = $this->product();

        $order = Order::create([
            'order_number' => 'NOY-'.random_int(100000, 999999),
            'customer_name' => 'Nadia', 'customer_phone' => $phone,
            'shipping_address' => 'Dhaka', 'status' => 'delivered',
            'subtotal' => 1000, 'total' => 1000,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => $product->name, 'price' => 1000, 'quantity' => 1, 'subtotal' => 1000,
        ]);

        $order->history()->create(['status' => 'delivered'])
            ->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $order;
    }

    protected function enableOffer(float $percent = 10, int $days = 30): void
    {
        Setting::put('review_offer_enabled', true);
        Setting::put('review_offer_percent', $percent);
        Setting::put('review_offer_days', $days);
    }

    // ── The coupon itself ──────────────────────────────────────────────────

    public function test_no_coupon_is_minted_while_the_offer_is_off(): void
    {
        $this->assertNull(app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder()));
        $this->assertSame(0, Coupon::count());
    }

    public function test_it_mints_a_private_one_use_code_that_expires(): void
    {
        $this->enableOffer(10, 30);
        $order = $this->deliveredOrder('01711111111');

        $coupon = app(ReviewThankYouOffer::class)->forOrder($order);

        $this->assertSame('percent', $coupon->type);
        $this->assertSame('10.00', (string) $coupon->value);
        $this->assertSame('01711111111', $coupon->reserved_for_phone);
        $this->assertTrue($coupon->is_exclusive);
        $this->assertSame(1, $coupon->usage_limit);
        $this->assertSame(30, (int) now()->startOfDay()->diffInDays($coupon->expires_at->startOfDay()));
        $this->assertStringContainsString($order->order_number, $coupon->label);
    }

    public function test_asking_twice_returns_the_same_coupon(): void
    {
        $this->enableOffer();
        $order = $this->deliveredOrder();
        $service = app(ReviewThankYouOffer::class);

        // A retried job must not hand her a second discount.
        $first = $service->forOrder($order);
        $second = $service->forOrder($order->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Coupon::count());
    }

    // ── "Only for her" ─────────────────────────────────────────────────────

    public function test_a_forwarded_code_is_refused_at_checkout(): void
    {
        $this->enableOffer();
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder('01711111111'));

        // Somebody else got hold of the code. The cart cannot tell yet — a
        // guest has typed no phone — so it applies and the discount shows.
        $cart = app(CartService::class);
        $cart->add($this->product('Necklace', 2000), null, 1);
        $cart->applyCoupon($hers);
        $this->assertGreaterThan(0, $cart->discount());

        // The phone at checkout is what decides it.
        $this->post(route('checkout.store'), [
            'name' => 'Someone Else', 'phone' => '01799999999', 'address' => 'Dhaka',
        ])->assertRedirect(route('cart'));

        $this->assertSame(0, Order::where('customer_name', 'Someone Else')->count());
        $this->assertStringContainsString($hers->code, session('error') ?? '');
    }

    public function test_the_owner_of_the_code_can_spend_it(): void
    {
        $this->enableOffer();
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder('01711111111'));
        Setting::put('shipping_outside', 130);

        $cart = app(CartService::class);
        $cart->add($this->product('Necklace', 2000), null, 1);
        $cart->applyCoupon($hers);

        $this->post(route('checkout.store'), [
            'name' => 'Nadia', 'phone' => '01711111111', 'address' => 'Dhaka',
        ]);

        // latest(), not first-by-name: the delivered order this coupon came
        // from carries the same name and would match too.
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(200.0, (float) $order->discount);
        $this->assertSame(2000.0 - 200.0 + 130.0, (float) $order->total);
    }

    public function test_a_logged_in_stranger_is_turned_away_at_the_cart(): void
    {
        $this->enableOffer();
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder('01711111111'));

        $stranger = Customer::create([
            'name' => 'Other', 'phone' => '01788888888', 'password' => 'secret-pass',
        ]);
        $this->actingAs($stranger, 'customer');

        app(CartService::class)->add($this->product('Necklace', 2000), null, 1);

        $this->post(route('cart.coupon'), ['code' => $hers->code])
            ->assertSessionHas('error');

        // Her member discount still applies — only the coupon is refused.
        $this->assertSame(0.0, app(CartService::class)->couponDiscount());
        $this->assertNull(app(CartService::class)->coupon());
    }

    // ── "Best one wins" ────────────────────────────────────────────────────

    public function test_the_code_wins_when_it_beats_the_running_offers(): void
    {
        $this->enableOffer(10);
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder());

        // A 5% storewide offer is running; her 10% is worth more.
        Offer::create([
            'title' => '5% off', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 5, 'is_active' => true,
        ]);

        $cart = app(CartService::class);
        $cart->add($this->product('Necklace', 2000), null, 1);
        $cart->applyCoupon($hers);

        $this->assertSame(200.0, $cart->couponDiscount());
        $this->assertSame(0.0, $cart->promoDiscount(), 'the storewide offer must stand down');
        $this->assertSame(200.0, $cart->discount(), 'never both');
    }

    public function test_the_running_offer_wins_and_her_code_is_kept_for_later(): void
    {
        $this->enableOffer(10);
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder());

        // A 25% storewide offer beats her 10%.
        Offer::create([
            'title' => '25% off', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 25, 'is_active' => true,
        ]);

        $cart = app(CartService::class);
        $cart->add($this->product('Necklace', 2000), null, 1);
        $cart->applyCoupon($hers);

        $this->assertSame(500.0, $cart->promoDiscount());
        $this->assertSame(0.0, $cart->couponDiscount());
        $this->assertSame(500.0, $cart->discount());

        // And she is told why, rather than left thinking the code is broken.
        $this->assertStringContainsString($hers->code, (string) $cart->couponNotice());

        // Unspent: still usable on an order where it is the better deal.
        $this->assertSame(0, (int) $hers->fresh()->used_count);
    }

    public function test_an_ordinary_coupon_still_stacks_as_before(): void
    {
        $coupon = Coupon::create([
            'code' => 'PLAIN10', 'type' => 'percent', 'value' => 10, 'is_active' => true,
        ]);
        Offer::create([
            'title' => '25% off', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 25, 'is_active' => true,
        ]);

        $cart = app(CartService::class);
        $cart->add($this->product('Necklace', 2000), null, 1);
        $cart->applyCoupon($coupon);

        // 25% of 2000 = 500, then 10% of the remaining 1500 = 150.
        $this->assertSame(500.0, $cart->promoDiscount());
        $this->assertSame(150.0, $cart->couponDiscount());
        $this->assertNull($cart->couponNotice());
    }

    // ── The message ────────────────────────────────────────────────────────

    public function test_the_sms_carries_the_code_and_the_expiry(): void
    {
        $this->enableOffer(10, 30);
        $order = $this->deliveredOrder();

        $sent = [];
        $this->mock(\App\Services\SmsService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('template')->andReturn(config('sms.templates.review_request'));
            $mock->shouldReceive('sendTemplate')->andReturnUsing(function ($key, $order, $extra, $tpl = null) use (&$sent) {
                $sent[] = strtr($tpl ?? '', $extra);

                return true;
            });
        });

        app(SendReviewRequest::class, ['order' => $order])->handle(
            app(\App\Services\SmsService::class), app(ReviewThankYouOffer::class),
        );

        $coupon = Coupon::firstOrFail();
        $this->assertStringContainsString($coupon->code, $sent[0]);
        $this->assertStringContainsString('10% off', $sent[0]);
        $this->assertStringContainsString($coupon->expires_at->format('j M'), $sent[0]);
    }

    public function test_a_customised_template_without_the_placeholder_still_carries_the_code(): void
    {
        $this->enableOffer();
        $order = $this->deliveredOrder();

        // The owner rewrote the SMS wording before this feature existed.
        Setting::put('sms_templates', ['review_request' => 'Rate your order {order}: {link}']);

        $sent = [];
        $this->mock(\App\Services\SmsService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('template')->andReturn('Rate your order {order}: {link}');
            $mock->shouldReceive('sendTemplate')->andReturnUsing(function ($key, $order, $extra, $tpl = null) use (&$sent) {
                $sent[] = strtr($tpl ?? '', $extra);

                return true;
            });
        });

        app(SendReviewRequest::class, ['order' => $order])->handle(
            app(\App\Services\SmsService::class), app(ReviewThankYouOffer::class),
        );

        // A discount was created for her, so it must not be silently withheld.
        $this->assertStringContainsString(Coupon::firstOrFail()->code, $sent[0]);
    }

    public function test_the_message_closes_cleanly_when_the_offer_is_off(): void
    {
        $order = $this->deliveredOrder();

        $sent = [];
        $this->mock(\App\Services\SmsService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('template')->andReturn(config('sms.templates.review_request'));
            $mock->shouldReceive('sendTemplate')->andReturnUsing(function ($key, $order, $extra, $tpl = null) use (&$sent) {
                $sent[] = strtr($tpl ?? '', $extra);

                return true;
            });
        });

        app(SendReviewRequest::class, ['order' => $order])->handle(
            app(\App\Services\SmsService::class), app(ReviewThankYouOffer::class),
        );

        $this->assertSame(0, Coupon::count());
        // No dangling placeholder, and no trailing space where the offer
        // sentence would have been.
        $this->assertStringNotContainsString('{offer}', $sent[0]);
        $this->assertSame($sent[0], rtrim($sent[0]));
    }

    // ── The 160-character budget ───────────────────────────────────────────

    /** Every character the GSM-7 alphabet can send in a single byte. */
    private const GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        ."¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Render the review SMS exactly as the job would, and hand it back. */
    protected function renderedSms(Order $order): string
    {
        $sent = [];
        $this->mock(\App\Services\SmsService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('template')->andReturn(config('sms.templates.review_request'));
            $mock->shouldReceive('sendTemplate')->andReturnUsing(function ($key, $o, $extra, $tpl = null) use (&$sent) {
                $sent[] = strtr($tpl ?? '', array_merge([
                    '{store}' => store_name(),
                    '{order}' => $o->order_number,
                ], $extra));

                return true;
            });
        });

        app(SendReviewRequest::class, ['order' => $order])->handle(
            app(\App\Services\SmsService::class), app(ReviewThankYouOffer::class),
        );

        return $sent[0];
    }

    public function test_the_whole_message_fits_one_sms_segment(): void
    {
        $this->enableOffer(10, 30);
        $order = $this->deliveredOrder();
        $order->update(['customer_name' => 'Kazi Rahat']);

        $sms = $this->renderedSms($order->fresh());
        $coupon = Coupon::firstOrFail();

        // Everything that was asked for is still in it.
        $this->assertStringContainsString('/r/'.$order->order_number.'/', $sms, 'review link');
        $this->assertStringContainsString('10% off', $sms, 'discount');
        $this->assertStringContainsString($coupon->code, $sms, 'coupon code');
        $this->assertStringContainsString($coupon->expires_at->format('j M'), $sms, 'expiry');
        $this->assertStringContainsString(store_name(), $sms, 'store name');

        $this->assertLessThanOrEqual(160, mb_strlen($sms), "One segment. Message was:\n".$sms);
    }

    public function test_a_long_customer_name_cannot_push_it_over(): void
    {
        $this->enableOffer(10, 30);
        $order = $this->deliveredOrder();
        $order->update(['customer_name' => 'Mohammad Abdur Rahman Chowdhury']);

        $sms = $this->renderedSms($order->fresh());

        // Only the first name is used, so the four-word version costs the
        // same as the short one.
        $this->assertStringContainsString('Mohammad', $sms);
        $this->assertStringNotContainsString('Chowdhury', $sms);
        $this->assertLessThanOrEqual(160, mb_strlen($sms), "One segment. Message was:\n".$sms);
    }

    public function test_every_character_is_gsm7_so_the_gateway_does_not_switch_to_unicode(): void
    {
        $this->enableOffer(10, 30);
        $order = $this->deliveredOrder();
        $order->update(['customer_name' => 'Kazi Rahat']);

        // One non-GSM-7 character (an em dash, a curly quote) turns the whole
        // message into UCS-2, where a segment is 70 characters, not 160 — so
        // the budget above would be quietly blown by a typo in the wording.
        $stray = collect(mb_str_split($this->renderedSms($order->fresh())))
            ->reject(fn ($c) => mb_strpos(self::GSM7, $c) !== false)
            ->unique()->values()->all();

        $this->assertSame([], $stray, 'non-GSM-7 characters: '.implode(' ', $stray));
    }

    public function test_the_code_is_short_and_free_of_look_alike_characters(): void
    {
        $this->enableOffer();
        $coupon = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder());

        // Six characters, typed off a phone screen with one thumb.
        $this->assertSame(6, strlen($coupon->code));
        // Nothing left that can be misread: no O/0, no I/L/1.
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $coupon->code);
    }

    public function test_a_code_typed_with_a_space_or_dash_still_works(): void
    {
        $this->enableOffer();
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder('01711111111'));

        app(CartService::class)->add($this->product('Necklace', 2000), null, 1);

        $spaced = substr($hers->code, 0, 3).' '.substr($hers->code, 3);
        $this->post(route('cart.coupon'), ['code' => $spaced])->assertSessionHas('success');

        $this->assertSame(200.0, app(CartService::class)->couponDiscount());
    }

    public function test_the_short_link_opens_the_review_page(): void
    {
        $order = $this->deliveredOrder();

        $short = \App\Http\Controllers\Shop\ReviewController::shortLink($order->order_number);

        // It hands off to the signed URL the review page already trusts.
        $this->get($short)->assertRedirectContains('signature=');
        $this->followingRedirects()->get($short)->assertOk()
            ->assertInertia(fn ($page) => $page->component('ReviewInvite'));
    }

    public function test_a_guessed_short_link_is_turned_away(): void
    {
        $order = $this->deliveredOrder();

        $this->get('/r/'.$order->order_number.'/'.str_repeat('a', 16))
            ->assertRedirect(route('track'));

        // And it cannot be reached by simply dropping the token.
        $this->get('/r/'.$order->order_number.'/')->assertNotFound();
    }

    // ── Defects found in review ────────────────────────────────────────────

    public function test_a_coupon_earns_nothing_on_a_free_gift_unit(): void
    {
        // The last place free units were still billable: a scoped coupon
        // counted the gift-ladder freebie, inflating both its own discount and
        // its side of the exclusive comparison.
        $gift = $this->product('Gift stud', 500);
        $collection = \App\Models\Collection::create(['name' => 'Gifts', 'type' => 'manual', 'is_active' => true]);
        $collection->products()->attach($gift->id, ['position' => 0]);

        Setting::put('gift_ladder_enabled', true);
        Setting::put('gift_ladder_buy', 2);
        Setting::put('gift_ladder_max', 3);
        Setting::put('gift_ladder_gifts_collection_id', $collection->id);
        app()->forgetInstance(\App\Support\GiftLadder::class);

        $coupon = Coupon::create([
            'code' => 'SCOPED20', 'type' => 'percent', 'value' => 20, 'is_active' => true,
            'applies_to' => 'products', 'product_ids' => [$gift->id],
        ]);

        $cart = app(CartService::class);
        $cart->add($gift, null, 3);                       // one of these is free
        $cart->add($this->product('Bangle', 2000), null, 1);
        $cart->applyCoupon($coupon);

        $this->assertSame(500.0, $cart->giftDiscount());
        // 20% of the two PAID studs (1000), never of all three (1500).
        $this->assertSame(200.0, $cart->couponDiscount());
    }

    public function test_free_delivery_counts_when_an_exclusive_code_is_weighed(): void
    {
        Setting::put('shipping_outside', 130);

        $coupon = Coupon::create([
            'code' => 'SHIP5', 'type' => 'percent', 'value' => 5,
            'free_shipping' => true, 'is_exclusive' => true, 'is_active' => true,
        ]);
        Offer::create([
            'title' => '10% off', 'type' => 'order_percent', 'applies_to' => 'all',
            'percent' => 10, 'is_active' => true,
        ]);

        $cart = app(CartService::class);
        $cart->add($this->product('Ring', 1000), null, 1);
        $cart->applyCoupon($coupon);

        // Money alone says the 10% offer (100) beats the code (50). With the
        // 130 delivery the code is really worth 180, and a losing code loses
        // its free delivery too — so weighing money against money left her
        // 80 worse off.
        $this->assertSame(50.0, $cart->couponDiscount());
        $this->assertSame(0.0, $cart->promoDiscount());
        $this->assertTrue($cart->hasFreeShipping());
        $this->assertSame(950.0, $cart->total(false));
    }

    public function test_a_single_use_code_cannot_be_spent_twice(): void
    {
        $this->enableOffer();
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder('01711111111'));

        for ($i = 0; $i < 2; $i++) {
            $cart = app(CartService::class);
            $cart->clear();
            $cart->add($this->product('Ring '.$i, 2000), null, 1);
            $cart->applyCoupon($hers);

            $this->post(route('checkout.store'), [
                'name' => 'Nadia', 'phone' => '01711111111', 'address' => 'Dhaka',
            ]);
        }

        // Two orders, but the one-use code may only have paid out once.
        $discounted = Order::where('coupon_code', $hers->code)->count();
        $this->assertSame(1, $discounted);
        $this->assertSame(1, (int) $hers->fresh()->used_count);
    }

    public function test_a_suppressed_personal_offer_is_not_burned(): void
    {
        Setting::put('register_offer_percent', 0);

        $customer = Customer::create([
            'name' => 'Nadia', 'phone' => '01711111111', 'password' => 'secret-pass',
        ]);
        $offer = $customer->offers()->create([
            'title' => 'Just for you', 'type' => 'fixed', 'value' => 100,
            'applies_to' => 'all', 'max_redemptions' => 1, 'is_active' => true,
        ]);
        $this->actingAs($customer, 'customer');

        $coupon = Coupon::create([
            'code' => 'BIG200', 'type' => 'fixed', 'value' => 200,
            'is_exclusive' => true, 'is_active' => true,
            'reserved_for_phone' => '01711111111',
        ]);

        $cart = app(CartService::class);
        $cart->add($this->product('Ring', 2000), null, 1);
        $cart->applyCoupon($coupon);

        $this->assertSame(200.0, $cart->couponDiscount());
        $this->assertSame(0.0, $cart->customerOfferDiscount());

        $this->post(route('checkout.store'), [
            'name' => 'Nadia', 'phone' => '01711111111', 'address' => 'Dhaka',
        ]);

        // Her one-use personal offer paid out nothing, so it is still hers.
        $this->assertSame(0, (int) $offer->fresh()->redemptions);
        $this->assertNull($offer->fresh()->redeemed_at);
    }

    public function test_a_reused_coupon_is_never_advertised_already_expired(): void
    {
        $this->enableOffer(10, 30);
        $order = $this->deliveredOrder();
        $service = app(ReviewThankYouOffer::class);

        $coupon = $service->forOrder($order);
        // The first send failed; weeks pass and the order is asked again.
        $coupon->update(['expires_at' => now()->subDay()]);

        $again = $service->forOrder($order->fresh());

        $this->assertTrue($again->expires_at->isFuture());
        $this->assertStringContainsString($again->expires_at->format('j M'), $service->smsLine($again));
    }

    public function test_a_coupon_without_an_expiry_does_not_break_the_message(): void
    {
        $coupon = Coupon::create([
            'code' => 'NOEXPIRY', 'type' => 'percent', 'value' => 10,
            'is_active' => true, 'expires_at' => null,
        ]);

        // The admin coupon form allows a blank date; dereferencing it inside
        // the queued job would silence that order's request for good.
        $line = app(ReviewThankYouOffer::class)->smsLine($coupon);
        $this->assertStringContainsString('NOEXPIRY', $line);
        $this->assertStringNotContainsString('valid till', $line);
    }

    public function test_a_collision_never_hands_a_buyer_someone_elses_code(): void
    {
        $this->enableOffer();
        $order = $this->deliveredOrder('01711111111');

        // Squat on the code this order would derive, as another customer.
        $squatter = Coupon::create([
            'code' => app(ReviewThankYouOffer::class)->codeFor($order),
            'type' => 'percent', 'value' => 10, 'is_active' => true,
            'reserved_for_phone' => '01799999999',
        ]);

        $hers = app(ReviewThankYouOffer::class)->forOrder($order);

        $this->assertNotSame($squatter->code, $hers->code);
        $this->assertSame('01711111111', $hers->reserved_for_phone);
    }

    public function test_a_failing_mint_never_costs_the_review_request(): void
    {
        $this->enableOffer();
        $order = $this->deliveredOrder();

        $this->mock(ReviewThankYouOffer::class, function ($mock) {
            $mock->shouldReceive('forOrder')->andThrow(new \RuntimeException('database is down'));
        });

        $sent = [];
        $this->mock(\App\Services\SmsService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('template')->andReturn(config('sms.templates.review_request'));
            $mock->shouldReceive('sendTemplate')->andReturnUsing(function ($key, $order, $extra, $tpl = null) use (&$sent) {
                $sent[] = strtr($tpl ?? '', $extra);

                return true;
            });
        });

        app(SendReviewRequest::class, ['order' => $order])->handle(
            app(\App\Services\SmsService::class), app(ReviewThankYouOffer::class),
        );

        // The ask still went out — the discount is a bonus, not the point.
        $this->assertCount(1, $sent);
        $this->assertStringContainsString($order->order_number, $sent[0]);
    }

    public function test_private_codes_are_kept_out_of_the_broadcast_picker(): void
    {
        $this->enableOffer();
        $hers = app(ReviewThankYouOffer::class)->forOrder($this->deliveredOrder());
        Coupon::create(['code' => 'EIDSALE', 'type' => 'percent', 'value' => 15, 'is_active' => true]);

        $admin = \App\Models\User::create([
            'name' => 'Admin', 'email' => 'picker@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        // One wrong click would otherwise blast her private code to every
        // push subscriber — and bounce every one of them at checkout.
        $html = $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->getContent();

        $this->assertStringContainsString('EIDSALE', $html);
        $this->assertStringNotContainsString($hers->code, $html);
    }

    public function test_the_admin_can_save_the_offer_settings(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)->post(route('admin.notifications.review-requests'), [
            'review_request_enabled' => 1,
            'review_request_delay_days' => 3,
            'review_request_max_days' => 30,
            'review_request_per_run' => 100,
            'review_offer_enabled' => 1,
            'review_offer_percent' => 10,
            'review_offer_days' => 30,
        ])->assertSessionHas('success');

        $this->assertTrue((bool) Setting::get('review_request_enabled'));
        $this->assertTrue((bool) Setting::get('review_offer_enabled'));
        $this->assertSame(10.0, (float) Setting::get('review_offer_percent'));
        $this->assertSame(30, (int) Setting::get('review_offer_days'));
    }
}
