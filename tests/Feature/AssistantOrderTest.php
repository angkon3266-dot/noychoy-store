<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\Ai\ChatOrder;
use App\Services\CartService;
use App\Support\GiftLadder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The assistant can take an order end to end — which means it can send a real
 * parcel to a real address and put a real number in front of a rider. These
 * pin the guards that make that safe:
 *
 * - the model can never name a price, a discount or a total (no tool accepts
 *   one, and the figures come from the same cascade the cart page uses);
 * - nothing is placed that the customer was not quoted, to the taka;
 * - one intent produces one order, however many times the model asks;
 * - the shopper's own basket is never touched;
 * - and the owner can switch the whole thing off.
 */
class AssistantOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function enable(): void
    {
        config([
            'services.openai.assistant_enabled' => true,
            'services.openai.key' => 'sk-test',
            'services.openai.orders_enabled' => true,
        ]);
        Setting::put('shipping_inside', 80);
        Setting::put('shipping_outside', 90);
        Queue::fake();   // the order-placed SMS/Meta/courier effects are queued
    }

    protected function product(string $name, float $price, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => $name, 'slug' => str($name)->slug(), 'status' => 'published',
            'price' => $price, 'manage_stock' => false, 'in_stock' => true,
        ], $attrs));
    }

    protected function orders(): ChatOrder
    {
        return app(ChatOrder::class);
    }

    /** Every answer the checkout form would have asked for. */
    protected function details(array $override = []): array
    {
        return array_merge([
            'name' => 'Rima Sultana',
            'phone' => '01711100022',
            'address' => 'House 12, Road 5, Dhanmondi',
            'area' => 'Dhanmondi',
            'district' => 'Dhaka',
            'is_inside_dhaka' => true,
        ], $override);
    }

    /**
     * A whole conversation up to the moment of agreement: the piece chosen,
     * every question answered, the summary read out — and then the customer
     * writing back, which is the turn the assistant is allowed to order on.
     */
    protected function readyToPlace(Product $p, array $override = []): array
    {
        $this->orders()->nextTurn();                 // she says "I want this one"
        $this->orders()->chooseItem($p->slug);
        $this->orders()->setDetails($this->details($override));
        $quote = $this->orders()->quote(forReading: true);   // the summary is read to her
        $this->orders()->nextTurn();                          // she answers…
        $this->orders()->rememberLastWords('ji, confirm korun');   // …and it is a yes

        return $quote;
    }

    // ── The switch ──────────────────────────────────────────────────────────

    public function test_the_owner_can_switch_order_taking_off_without_switching_the_assistant_off(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);
        config(['services.openai.orders_enabled' => false]);

        $this->assertFalse($this->orders()->enabled());

        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Order the piece from its page.']]]])]);
        $this->postJson(route('assistant.chat'), ['messages' => [['role' => 'user', 'content' => 'order this for me']]])->assertOk();

        // The tools are not even offered, so the model cannot try.
        Http::assertSent(function (ClientRequest $request) {
            $names = collect($request['tools'])->pluck('function.name')->all();

            return ! in_array('place_order', $names, true) && ! in_array('choose_item', $names, true);
        });

        // And the door is bolted behind the tool list too.
        $this->assertFalse($this->orders()->place('anything')['ok']);
        $this->assertSame(0, Order::count());
        $this->assertSame('Order the piece from its page.', 'Order the piece from its page.');
        unset($p);
    }

    // ── Choosing the piece ──────────────────────────────────────────────────

    public function test_a_pasted_product_link_an_exact_name_and_a_search_all_find_the_piece(): void
    {
        $this->enable();
        $p = $this->product('Emerald Halo Statement Ring', 450);

        foreach ([
            'https://noychoy.com/product/'.$p->slug,
            '/product/'.$p->slug.'?utm_source=fb',
            'Emerald Halo Statement Ring',
            'emerald halo statement ring',
        ] as $reference) {
            $result = $this->orders()->chooseItem($reference);
            $this->assertTrue($result['ok'], "failed for: {$reference}");
            $this->assertSame(money(450), $result['order']['subtotal'], "wrong piece for: {$reference}");
            $this->assertSame($p->id, (int) $this->orders()->cart()->items()->first()['product_id']);
        }
    }

    public function test_an_ambiguous_name_is_never_guessed_at(): void
    {
        $this->enable();
        $this->product('Rose Gold Ring', 700);
        $this->product('Rose Gold Bracelet', 800);

        $result = $this->orders()->chooseItem('rose gold');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_match', $result['reason']);
        $this->assertTrue($this->orders()->cart()->isEmpty());
    }

    public function test_a_piece_with_options_asks_which_one_before_it_can_be_ordered(): void
    {
        $this->enable();
        $p = $this->product('Adjustable Band', 600, ['has_variants' => true]);
        ProductVariant::create(['product_id' => $p->id, 'sku' => 'AB-G', 'attributes' => ['colour' => 'Gold'], 'price' => 600, 'stock_quantity' => 5, 'is_active' => true]);
        ProductVariant::create(['product_id' => $p->id, 'sku' => 'AB-S', 'attributes' => ['colour' => 'Silver'], 'price' => 650, 'stock_quantity' => 5, 'is_active' => true]);

        $asked = $this->orders()->chooseItem($p->slug);
        $this->assertFalse($asked['ok']);
        $this->assertSame('variant_needed', $asked['reason']);
        $this->assertCount(2, $asked['options']);

        // The customer's own words pick it, and the variant's price is used.
        $chosen = $this->orders()->chooseItem($p->slug, 'silver');
        $this->assertTrue($chosen['ok']);
        $this->assertSame(650.0, $this->orders()->cart()->subtotal());
    }

    /**
     * Found by an adversarial review of this file's own code: the
     * single-option shortcut ran before the "did she ask for something?"
     * check, so a customer asking for a size we do not stock was handed the
     * one we do — silently, and she finds out when it will not fit.
     */
    public function test_a_size_we_do_not_have_is_never_quietly_swapped_for_one_we_do(): void
    {
        $this->enable();
        $p = $this->product('Adjustable Band', 600, ['has_variants' => true]);
        ProductVariant::create(['product_id' => $p->id, 'sku' => 'AB-16', 'attributes' => ['size' => '16'], 'price' => 600, 'stock_quantity' => 5, 'is_active' => true]);

        $result = $this->orders()->chooseItem($p->slug, 'size 18');

        $this->assertFalse($result['ok']);
        $this->assertSame('variant_unavailable', $result['reason']);
        $this->assertSame('size 18', $result['asked_for']);
        $this->assertTrue($this->orders()->cart()->isEmpty());

        // Asking with no preference on a one-option piece still just works.
        $this->assertTrue($this->orders()->chooseItem($p->slug)['ok']);
    }

    public function test_a_piece_with_no_price_is_never_sold_for_nothing(): void
    {
        $this->enable();
        $p = $this->product('Mystery Piece', 0);

        $result = $this->orders()->chooseItem($p->slug);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_price', $result['reason']);
        $this->assertTrue($this->orders()->cart()->isEmpty());
    }

    /**
     * Assigned coupons are matched on the phone, so pricing a basket against a
     * number the customer has not otherwise proved any connection to would let
     * anyone read a stranger's private offer out of the summary.
     */
    public function test_a_stranger_s_number_alone_does_not_price_the_basket(): void
    {
        $this->enable();
        $this->orders()->chooseItem($this->product('Pearl Ring', 900)->slug);

        $this->orders()->setDetails(['phone' => '01711100022']);
        $this->assertNull(session('checkout_phone:chat'));

        // With the rest of her details, it is her order and it prices normally.
        $this->orders()->setDetails($this->details());
        $this->assertSame('01711100022', session('checkout_phone:chat'));
    }

    public function test_a_gift_ordered_in_chat_travels_as_a_gift(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);

        $this->orders()->nextTurn();
        $this->orders()->chooseItem($p->slug);
        $this->orders()->setDetails($this->details(['is_gift' => true, 'card_message' => 'Shubho jonmodin!']));
        $quote = $this->orders()->quote(forReading: true);
        $this->orders()->nextTurn();
        $this->orders()->rememberLastWords('ji confirm');
        $this->orders()->place($quote['quote_id']);

        $order = Order::firstOrFail();
        $this->assertTrue((bool) $order->is_gift);
        $this->assertSame('Shubho jonmodin!', $order->card_message);
    }

    // ── The answers ─────────────────────────────────────────────────────────

    public function test_a_bad_phone_or_a_half_written_address_is_refused_and_not_stored(): void
    {
        $this->enable();
        $this->orders()->chooseItem($this->product('Pearl Ring', 900)->slug);

        $result = $this->orders()->setDetails(['name' => 'Rima', 'phone' => '12345', 'address' => 'Dhaka']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertArrayHasKey('address', $result['errors']);      // under 10 characters is not an address
        $this->assertSame(['Rima'], array_values($this->orders()->state()['details']));   // only the good one stuck
        $this->assertContains('phone', $this->orders()->missing());
    }

    public function test_an_unclear_answer_about_dhaka_is_asked_again_rather_than_assumed(): void
    {
        $this->enable();
        $this->orders()->chooseItem($this->product('Pearl Ring', 900)->slug);

        $result = $this->orders()->setDetails(['is_inside_dhaka' => 'maybe near Savar']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('is_inside_dhaka', $result['errors']);
        $this->assertContains('is_inside_dhaka', $this->orders()->missing());
    }

    public function test_changing_the_address_after_she_agreed_costs_a_fresh_confirmation(): void
    {
        $this->enable();
        $quote = $this->readyToPlace($this->product('Pearl Ring', 900));

        // Same pieces, same total, different doorstep.
        $this->orders()->setDetails(['address' => 'A different house entirely, Mirpur 10']);

        $result = $this->orders()->place($quote['quote_id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale_quote', $result['reason']);
        $this->assertSame(0, Order::count());
    }

    public function test_her_address_does_not_linger_in_the_session_after_the_order_is_placed(): void
    {
        $this->enable();
        $quote = $this->readyToPlace($this->product('Pearl Ring', 900));

        $this->orders()->place($quote['quote_id']);

        $this->assertSame([], $this->orders()->state()['details']);
        $this->assertTrue($this->orders()->cart()->isEmpty());
        $this->assertNotNull($this->orders()->state()['order_number']);   // still one order only
    }

    public function test_the_phone_is_stored_in_the_one_canonical_form(): void
    {
        $this->enable();
        $this->orders()->setDetails(['phone' => '+8801711100022']);

        $this->assertSame('01711100022', $this->orders()->state()['details']['phone']);
    }

    // ── The money ───────────────────────────────────────────────────────────

    public function test_a_chat_order_costs_exactly_what_the_website_would_charge(): void
    {
        $this->enable();
        Setting::put('gift_ladder_enabled', true);
        app()->forgetInstance(GiftLadder::class);
        $p = $this->product('Pearl Ring', 900);

        $quote = $this->readyToPlace($p);

        // The same basket, priced by the shopper's own cart, to the taka.
        $web = app(CartService::class);
        $web->add($p, null, 1);
        $this->assertSame(money($web->subtotal()), $quote['subtotal']);
        $this->assertSame(
            money(max(0, $web->subtotal() - $web->discount() + $web->shipping(true))),
            $quote['total'],
        );
        // The ladder's first rung applied here as it does everywhere else.
        $this->assertNotSame([], $quote['savings']);
    }

    public function test_the_delivery_zone_the_customer_gives_changes_the_charge(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);

        $inside = $this->readyToPlace($p, ['is_inside_dhaka' => true]);
        $outside = $this->readyToPlace($p, ['is_inside_dhaka' => false]);

        $this->assertSame(money(80), $inside['delivery']);
        $this->assertSame(money(90), $outside['delivery']);
        $this->assertSame(10.0, round($outside['total_raw'] - $inside['total_raw'], 2));
    }

    // ── Confirmation ────────────────────────────────────────────────────────

    public function test_nothing_is_placed_before_every_question_is_answered(): void
    {
        $this->enable();
        $this->orders()->chooseItem($this->product('Pearl Ring', 900)->slug);
        $this->orders()->setDetails(['name' => 'Rima']);

        $result = $this->orders()->place('whatever');

        $this->assertFalse($result['ok']);
        $this->assertSame('incomplete', $result['reason']);
        $this->assertContains('phone', $result['missing']);
        $this->assertSame(0, Order::count());
    }

    /**
     * The gap this closes: a model can call review_order and place_order in
     * the same breath, so the first the customer hears of the total is "your
     * order is placed". The summary has to be sent and answered first, and
     * that is decided here rather than trusted to the model.
     */
    public function test_a_total_the_customer_has_not_answered_yet_cannot_be_ordered(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);

        $this->orders()->nextTurn();
        $this->orders()->chooseItem($p->slug);
        $this->orders()->setDetails($this->details());
        $quote = $this->orders()->quote(forReading: true);

        // Same message, straight to placing it.
        $rushed = $this->orders()->place($quote['quote_id']);
        $this->assertFalse($rushed['ok']);
        $this->assertSame('not_confirmed_yet', $rushed['reason']);
        $this->assertSame(0, Order::count());

        // She writes back with a yes; now it may be placed.
        $this->orders()->nextTurn();
        $this->orders()->rememberLastWords('ok confirm');
        $this->assertTrue($this->orders()->place($quote['quote_id'])['ok']);
        $this->assertSame(1, Order::count());
    }

    /**
     * Recording an answer needs the running total, so the tools that do it
     * return one — but a total the assistant worked out for itself is not a
     * total the customer was shown. Only the explicit read-back arms an order.
     */
    public function test_a_total_calculated_while_taking_answers_does_not_arm_an_order(): void
    {
        $this->enable();
        $this->orders()->nextTurn();
        $this->orders()->chooseItem($this->product('Pearl Ring', 900)->slug);

        // set_order_details answers with the running total, but never says it
        // out loud — so its quote_id cannot be ordered against, even a turn later.
        $running = $this->orders()->setDetails($this->details())['order'];
        $this->orders()->nextTurn();

        $result = $this->orders()->place($running['quote_id']);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_confirmed_yet', $result['reason']);
        $this->assertSame(0, Order::count());

        // Read it out, let her answer, and the same id now works.
        $read = $this->orders()->quote(forReading: true);
        $this->assertSame($running['quote_id'], $read['quote_id']);
        $this->orders()->nextTurn();
        $this->orders()->rememberLastWords('ok');
        $this->assertTrue($this->orders()->place($read['quote_id'])['ok']);
    }

    /**
     * "She replied" is not "she agreed". Without this the assistant could
     * order off "koto porbe?" or "amar husband ke jigges kori" — the model was
     * the only judge of consent, and consent is what decides whether a rider
     * turns up at someone's door.
     */
    public function test_a_reply_that_is_not_a_yes_does_not_place_the_order(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);

        foreach (['amar husband ke jigges kori', 'koto porbe?', 'thak, লাগবে না', 'ভাবছি'] as $notYes) {
            $quote = $this->readyToPlace($p);
            $this->orders()->rememberLastWords($notYes);

            $result = $this->orders()->place($quote['quote_id']);
            $this->assertFalse($result['ok'], "placed on: {$notYes}");
            $this->assertSame('no_clear_yes', $result['reason']);
            $this->orders()->clear();
        }
        $this->assertSame(0, Order::count());

        // And the ways a Bangladeshi customer actually says yes all work. The
        // caps are lifted here: this is about the words, not the limits.
        config(['services.openai.orders_per_day' => 99]);

        foreach (['ok', 'ji korun', 'হ্যাঁ', 'thik ache, pathiye din', 'confirm'] as $yes) {
            $quote = $this->readyToPlace($p);
            $this->orders()->rememberLastWords($yes);

            $this->assertTrue($this->orders()->place($quote['quote_id'])['ok'], "refused: {$yes}");
            $this->orders()->clear();
            session()->forget('chat_order_placed');
            Order::query()->forceDelete();
        }
    }

    public function test_an_order_can_only_be_placed_against_the_quote_the_customer_agreed_to(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);
        $quote = $this->readyToPlace($p);

        // A made-up id is refused…
        $this->assertSame('stale_quote', $this->orders()->place('0000000000000000')['reason']);
        $this->assertSame(0, Order::count());

        // …and so is yesterday's id once anything about the order changes.
        $this->orders()->setQuantity(2);
        $this->assertSame('stale_quote', $this->orders()->place($quote['quote_id'])['reason']);
        $this->assertSame(0, Order::count());

        // The changed order has to be read back and agreed to again — then it
        // goes through.
        $fresh = $this->orders()->quote(forReading: true);
        $this->orders()->nextTurn();
        $this->assertTrue($this->orders()->place($fresh['quote_id'])['ok']);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, (int) Order::firstOrFail()->items->first()->quantity);
    }

    public function test_placing_it_writes_the_same_order_the_checkout_page_would(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);
        $quote = $this->readyToPlace($p);

        $result = $this->orders()->place($quote['quote_id']);
        $order = Order::firstOrFail();

        $this->assertTrue($result['ok']);
        $this->assertSame($order->order_number, $result['order_number']);
        $this->assertSame('chat', $order->source);              // the owner can see where it came from
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('Rima Sultana', $order->customer_name);
        $this->assertSame('01711100022', $order->customer_phone);
        $this->assertSame('House 12, Road 5, Dhanmondi', $order->shipping_address);
        $this->assertTrue((bool) $order->is_inside_dhaka);
        $this->assertSame($quote['total_raw'], round((float) $order->total, 2));
        $this->assertCount(1, $order->items);
        $this->assertSame($p->id, $order->items->first()->product_id);

        // The buyer can open the confirmation page for it, as after checkout.
        $this->assertContains($order->order_number, session('placed_orders', []));
    }

    public function test_asking_twice_does_not_send_two_parcels(): void
    {
        $this->enable();
        $quote = $this->readyToPlace($this->product('Pearl Ring', 900));

        $first = $this->orders()->place($quote['quote_id']);
        $second = $this->orders()->place($quote['quote_id']);

        $this->assertSame(1, Order::count());
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['already_placed']);
        $this->assertSame($first['order_number'], $second['order_number']);
    }

    /**
     * The draft's order number is the first guard against a second parcel, but
     * a replayed transcript can call choose_item again and wipe it. So the
     * real guard is the order itself: same customer, same money, moments ago.
     */
    public function test_a_replayed_conversation_does_not_send_a_second_parcel(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);
        $this->orders()->place($this->readyToPlace($p)['quote_id']);

        // The whole conversation arrives again — a resent message, a retry.
        $again = $this->orders()->place($this->readyToPlace($p)['quote_id']);

        $this->assertSame(1, Order::count());
        $this->assertTrue($again['already_placed']);
        $this->assertSame(Order::firstOrFail()->order_number, $again['order_number']);
    }

    public function test_a_price_that_moved_while_they_were_talking_stops_the_order(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);
        $quote = $this->readyToPlace($p);

        // The shop repriced it between the summary and her "yes".
        $p->update(['price' => 1400]);

        $result = $this->orders()->place($quote['quote_id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('checkout_failed', $result['reason']);
        $this->assertSame(0, Order::count());
    }

    /**
     * The innermost net. The quote check above runs before the transaction, so
     * a total that moves *inside* it — a line repriced under the row lock — is
     * invisible to everything outside. PlaceOrder refuses to write a figure the
     * caller did not agree to, because that figure is what the rider will ask
     * for on the doorstep.
     */
    public function test_place_order_refuses_to_write_a_total_nobody_agreed_to(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);
        $cart = \App\Services\CartService::scoped('test');
        $cart->add($p, null, 1);

        $this->expectException(\App\Exceptions\CheckoutException::class);
        $this->expectExceptionMessageMatches('/price changed/');

        (new \App\Actions\PlaceOrder($cart))->handle($this->details() + ['expected_total' => 1.0]);
    }

    public function test_the_assistant_never_denies_an_order_it_has_already_placed(): void
    {
        $this->enable();
        $quote = $this->readyToPlace($this->product('Pearl Ring', 900));

        // The order is written, and then OpenAI falls over before it can say so.
        $step = 0;
        Http::fake(function () use (&$step, $quote) {
            $step++;

            return $step === 1
                ? Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'c1', 'type' => 'function',
                    'function' => ['name' => 'place_order', 'arguments' => json_encode(['quote_id' => $quote['quote_id']])],
                ]]]]]])
                : Http::response(['error' => ['message' => 'upstream is down']], 500);
        });

        $res = $this->postJson(route('assistant.chat'), ['messages' => [['role' => 'user', 'content' => 'ji confirm']]])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame(1, Order::count());
        // Not the "sorry, I can't answer" apology — that invites her to order
        // the same ring a second time.
        $res->assertJsonPath('ok', true);
        $this->assertStringContainsString($order->order_number, $res->json('reply'));
    }

    public function test_a_chat_order_cannot_take_the_whole_shelf(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);

        $this->orders()->chooseItem($p->slug, null, 9);

        $this->assertSame(2, (int) $this->orders()->cart()->items()->first()['qty']);
    }

    // ── Limits ──────────────────────────────────────────────────────────────

    public function test_an_order_above_the_owners_ceiling_is_handed_to_a_person(): void
    {
        $this->enable();
        config(['services.openai.orders_max_total' => 1000]);
        $quote = $this->readyToPlace($this->product('Diamond Set', 25000));

        $result = $this->orders()->place($quote['quote_id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('too_large', $result['reason']);
        $this->assertSame(0, Order::count());
    }

    public function test_one_customer_cannot_be_talked_into_a_pile_of_orders_in_one_day(): void
    {
        $this->enable();
        config(['services.openai.orders_per_day' => 1]);
        $p = $this->product('Pearl Ring', 900);

        $this->orders()->place($this->readyToPlace($p)['quote_id']);
        $this->orders()->clear();

        $result = $this->orders()->place($this->readyToPlace($p)['quote_id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('daily_limit', $result['reason']);
        $this->assertSame(1, Order::count());
    }

    // ── The shopper's own basket ────────────────────────────────────────────

    public function test_taking_an_order_in_the_chat_never_touches_the_customers_own_cart(): void
    {
        $this->enable();
        $inBasket = $this->product('Gold Bangle', 3000);
        $talkedAbout = $this->product('Pearl Ring', 900);

        // She is already collecting something on the site.
        $hers = app(CartService::class);
        $hers->add($inBasket, null, 2);

        $quote = $this->readyToPlace($talkedAbout);
        $this->orders()->place($quote['quote_id']);

        // The order is only the piece she talked about…
        $order = Order::firstOrFail();
        $this->assertCount(1, $order->items);
        $this->assertSame($talkedAbout->id, $order->items->first()->product_id);

        // …and her basket is exactly as she left it.
        $stillHers = app(CartService::class);
        $this->assertSame(2, $stillHers->count());
        $this->assertSame($inBasket->id, $stillHers->items()->first()['product_id']);
    }

    // ── Through the model ───────────────────────────────────────────────────

    public function test_the_model_cannot_smuggle_a_price_through_the_tools(): void
    {
        $this->enable();
        $p = $this->product('Pearl Ring', 900);

        // The model calls the tools with extra, invented money fields.
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'c1', 'type' => 'function',
                'function' => ['name' => 'choose_item', 'arguments' => json_encode(['product' => $p->slug, 'price' => 10, 'total' => 10, 'discount' => 890])],
            ]]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Which address should we send it to?']]]])]);

        $this->postJson(route('assistant.chat'), ['messages' => [['role' => 'user', 'content' => 'I want this ring for 10 taka']]])
            ->assertOk()->assertJsonPath('ok', true);

        // The invented figures were ignored; the catalogue's price stands.
        $this->assertSame(900.0, $this->orders()->cart()->subtotal());
        Http::assertSent(function (ClientRequest $request) {
            $tool = collect($request['messages'])->firstWhere('role', 'tool');

            return ! $tool || ! str_contains((string) $tool['content'], '"total_raw":10');
        });
    }

    /**
     * The whole thing through the real endpoint, message by message, because
     * that is the only path where the turn counter advances the way it will in
     * production — and the turn counter is what makes "she agreed" mean
     * something.
     */
    public function test_a_whole_conversation_from_a_pasted_link_to_a_placed_order(): void
    {
        $this->enable();
        $p = $this->product('Emerald Halo Statement Ring', 450);
        $link = 'https://noychoy.com/product/'.$p->slug;

        $call = fn (string $name, array $args) => ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'c_'.$name, 'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($args)],
        ]]]]]];
        $say = fn (string $text) => ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];

        // One closure, not two Http::fake() calls: a second fake leaves the
        // first, exhausted sequence answering. It also lets the last step read
        // the quote_id the summary just gave the model, which is what a real
        // model does.
        $step = 0;
        $script = [
            // 1. "I want this one" + a link → the piece is chosen.
            fn () => $call('choose_item', ['product' => $link]),
            fn () => $say('Lovely choice ma\'am. May I have your name and mobile number?'),
            // 2. Name and number.
            fn () => $call('set_order_details', ['name' => 'Rima Sultana', 'phone' => '01711100022']),
            fn () => $say('Thank you. Your full address, and is it inside Dhaka?'),
            // 3. Address → everything is answered, so the summary is read back.
            fn () => $call('set_order_details', ['address' => 'House 12, Road 5, Dhanmondi', 'area' => 'Dhanmondi', 'district' => 'Dhaka', 'is_inside_dhaka' => true]),
            fn () => $call('review_order', []),
            fn () => $say('Emerald Halo Statement Ring, ৳450, delivery ৳80 inside Dhaka. Total ৳530, cash on delivery, to House 12, Road 5, Dhanmondi. Shall I place it?'),
            // 4. Her "yes" → placed, on the message AFTER the summary, with the
            // quote_id that summary carried.
            fn () => $call('place_order', ['quote_id' => app(ChatOrder::class)->quote()['quote_id']]),
            fn () => $say('Done ma\'am — your order is placed, cash on delivery.'),
        ];

        // A real closure, not an arrow function: `fn ()` captures $step by
        // value, so the script would replay its first line forever.
        Http::fake(function () use (&$step, $script) {
            $next = $script[$step] ?? $script[count($script) - 1];
            $step++;

            return Http::response($next());
        });

        $history = [];
        $ask = function (string $text) use (&$history) {
            $history[] = ['role' => 'user', 'content' => $text];
            $res = $this->postJson(route('assistant.chat'), ['messages' => $history, 'page' => '/product/x']);
            $history[] = ['role' => 'assistant', 'content' => $res->json('reply')];

            return $res;
        };

        $ask('ami eta nibo '.$link)->assertOk();
        $this->assertSame(450.0, $this->orders()->cart()->subtotal());

        $ask('Rima Sultana, 01711100022')->assertOk();
        $ask('House 12, Road 5, Dhanmondi. Dhaka r vitore.')->assertOk();

        // Everything is answered and the total has been read out, but nothing
        // is ordered until she answers it.
        $this->assertSame([], $this->orders()->missing());
        $this->assertSame(0, Order::count());

        $ask('ji, confirm korun')->assertOk()->assertJsonPath('ok', true);

        $order = Order::firstOrFail();
        $this->assertSame('chat', $order->source);
        $this->assertSame('Rima Sultana', $order->customer_name);
        $this->assertSame('01711100022', $order->customer_phone);
        $this->assertSame('House 12, Road 5, Dhanmondi', $order->shipping_address);
        $this->assertTrue((bool) $order->is_inside_dhaka);
        $this->assertSame(450.0, round((float) $order->subtotal, 2));
        $this->assertSame(80.0, round((float) $order->shipping_cost, 2));
        $this->assertSame(530.0, round((float) $order->total, 2));
    }

    public function test_the_prompt_tells_it_to_take_orders_and_never_to_invent_a_total(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Sure.']]]])]);

        $this->postJson(route('assistant.chat'), ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            $system = $request['messages'][0]['content'];

            return str_contains($system, 'TAKING AN ORDER')
                && str_contains($system, 'never state a price')
                && str_contains($system, 'place_order with the quote_id');
        });
    }
}
