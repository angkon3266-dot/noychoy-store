<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Ai\AssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chat assistant costs money per call and can see the order table, so
 * these pin the two things that must never slip: it only speaks when the
 * owner switched it on with a key, and an order is only described when the
 * order number AND the phone on it both match — exactly like the public
 * Track page. The OpenAI side is faked throughout.
 */
class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    protected function enable(): void
    {
        config(['services.openai.assistant_enabled' => true, 'services.openai.key' => 'sk-test']);
    }

    protected function product(string $name, float $price): Product
    {
        return Product::create([
            'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => 'published',
            'price' => $price, 'manage_stock' => false, 'in_stock' => true, 'short_description' => 'Lovely '.$name.'.',
        ]);
    }

    /** An OpenAI chat completion whose assistant turn is plain text. */
    protected function text(string $content): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    /** An OpenAI chat completion whose assistant turn calls one tool. */
    protected function toolCall(string $name, array $args): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'call_1', 'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($args)],
        ]]]]]];
    }

    protected function ask(string $text, array $history = [])
    {
        $messages = array_merge($history, [['role' => 'user', 'content' => $text]]);

        return $this->postJson(route('assistant.chat'), ['messages' => $messages, 'page' => '/shop']);
    }

    /**
     * Seen live: one long reply from the assistant (a policy quoted in full)
     * made every later message in that chat fail validation, so the widget
     * kept saying "I can't answer right now". Its own turns must pass; only
     * the customer's new message is held to the limit.
     */
    public function test_the_assistants_own_long_replies_do_not_break_the_chat(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response($this->text('Ji sir, ache!'))]);

        $long = str_repeat('Refund policy line. ', 100);   // ~2,000 characters
        $this->assertGreaterThan(AssistantService::MAX_CHARS, mb_strlen($long));

        $this->ask('adjustable ring ache?', [
            ['role' => 'user', 'content' => 'return policy?'],
            ['role' => 'assistant', 'content' => $long],
        ])->assertOk()->assertJsonPath('reply', 'Ji sir, ache!');

        $this->ask(str_repeat('x', AssistantService::MAX_CHARS + 1))->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_switched_off_means_no_call_and_no_widget(): void
    {
        Http::fake();

        $this->ask('Hello')->assertStatus(503)->assertJsonPath('ok', false);
        Http::assertNothingSent();

        $this->get('/shop')->assertOk()->assertDontSee('id="noy-chat"', false);
    }

    public function test_a_key_without_the_switch_stays_off(): void
    {
        config(['services.openai.key' => 'sk-test', 'services.openai.assistant_enabled' => false]);
        Http::fake();

        $this->ask('Hello')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_the_widget_mounts_when_on_and_the_model_is_grounded_in_store_facts(): void
    {
        $this->enable();
        Setting::put('store_phone', '01634347164');
        Setting::put('theme', ['whatsapp_number' => '01634347164']);
        Setting::put('shipping_inside', 70);
        Setting::put('shipping_outside', 130);
        Http::fake(['api.openai.com/*' => Http::response($this->text('Delivery is ৳70 inside Dhaka.'))]);

        $this->get('/shop')->assertOk()->assertSee('id="noy-chat"', false);

        $this->ask('Delivery charge?')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Delivery is ৳70 inside Dhaka.')
            ->assertJsonPath('products', []);

        Http::assertSent(function (ClientRequest $request) {
            $system = $request['messages'][0]['content'];

            return $request->hasHeader('Authorization', 'Bearer sk-test')
                && $request['model'] === 'gpt-5-mini'
                && str_contains($system, '৳70 inside Dhaka')
                && str_contains($system, '01634347164')
                && str_contains($system, 'cash on delivery')
                && str_contains($system, 'https://wa.me/8801634347164')        // the tappable way to reach a person
                && str_contains($system, 'never reply in Latin-letter Banglish')  // Bangla / Banglish → Bangla script
                && str_contains($system, 'WHAT YOU CANNOT DO')                    // no "I will WhatsApp the team"
                && ! str_contains($system, '[CONFIRM')   // unresolved owner questions never reach a customer
                && collect($request['tools'])->pluck('function.name')->all() === ['search_products', 'order_status'];
        });
    }

    public function test_product_questions_go_through_the_live_search_and_come_back_as_cards(): void
    {
        $this->enable();
        $this->product('Pearl Drop Earrings', 1150);
        $this->product('Crimson Zircon Ring', 550);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->toolCall('search_products', ['query' => 'pearl earrings']))
            ->push($this->text('The Pearl Drop Earrings at ৳1,150 would be lovely — I\'ve put them below.'))]);

        $res = $this->ask('Any pearl earrings?')->assertOk();
        $res->assertJsonPath('products.0.name', 'Pearl Drop Earrings')
            ->assertJsonPath('products.0.price_text', money(1150))
            ->assertJsonCount(1, 'products');

        // The second call carried the tool's answer back to the model.
        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request) {
            $tool = collect($request['messages'])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], 'Pearl Drop Earrings') && str_contains($tool['content'], money(1150));
        });
    }

    public function test_an_order_is_only_described_when_number_and_phone_both_match(): void
    {
        $this->enable();
        $order = Order::create([
            'order_number' => 'NC-1001', 'customer_name' => 'Areeba', 'customer_phone' => '01711111111',
            'shipping_address' => 'Dhaka', 'subtotal' => 1000, 'shipping_cost' => 70, 'discount' => 0, 'total' => 1070,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'shipped',
        ]);

        // One faked sequence for both conversations: a second Http::fake()
        // would leave the first, exhausted sequence answering first.
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->toolCall('order_status', ['order_number' => 'NC-1001', 'phone' => '01799999999']))
            ->push($this->text('I could not find that order — please check the number and phone.'))
            ->push($this->toolCall('order_status', ['order_number' => 'NC-1001', 'phone' => '01711111111']))
            ->push($this->text('Your order NC-1001 has been shipped.'))]);

        // Wrong phone: the tool answers not_found and nothing about the order leaves the server.
        $this->ask('Where is order NC-1001? phone 01799999999')->assertOk();
        Http::assertSent(function (ClientRequest $request) {
            $tool = collect($request['messages'])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], '"found":false') && ! str_contains($tool['content'], 'Shipped');
        });

        // Right phone: the public status set, and no more than that.
        $this->ask('Where is order NC-1001? phone 01711111111')->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(function (ClientRequest $request) use ($order) {
            $tool = collect($request['messages'])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], '"found":true')
                && str_contains($tool['content'], '"status":"Shipped"')
                && ! str_contains($tool['content'], $order->shipping_address)
                && ! str_contains($tool['content'], '1070');
        });
    }

    public function test_a_signed_in_member_gets_their_own_orders_without_typing_anything(): void
    {
        $this->enable();
        $customer = \App\Models\Customer::create(['name' => 'Areeba Khan', 'phone' => '01711111122', 'password' => 'secret-pass', 'points' => 250]);
        $order = Order::create([
            'order_number' => 'NC-2002', 'customer_id' => $customer->id, 'customer_name' => 'Areeba Khan', 'customer_phone' => '01711111122',
            'shipping_address' => 'House 9, Dhanmondi', 'subtotal' => 1150, 'shipping_cost' => 80, 'discount' => 0, 'total' => 1230,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'processing',
        ]);
        $order->items()->create(['product_id' => $this->product('Pearl Drop Earrings', 1150)->id, 'name' => 'Pearl Drop Earrings', 'price' => 1150, 'quantity' => 1, 'subtotal' => 1150]);
        $this->actingAs($customer, 'customer');

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->toolCall('my_orders', []))
            ->push($this->text('Areeba, your Pearl Drop Earrings are being prepared.'))]);

        $this->ask('where is my order?')->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(function (ClientRequest $request) {
            $system = $request['messages'][0]['content'];
            $tools = collect($request['tools'])->pluck('function.name')->all();

            return str_contains($system, 'Signed in as Areeba') && str_contains($system, 'Points balance: 250')
                && $tools === ['my_orders', 'search_products', 'order_status'];
        });
        Http::assertSent(function (ClientRequest $request) {
            $tool = collect($request['messages'])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], 'NC-2002') && str_contains($tool['content'], 'Pearl Drop Earrings × 1')
                && str_contains($tool['content'], '"status":"Processing"');
        });
    }

    public function test_a_guest_is_never_offered_the_member_tool(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response($this->text('Sure — what is the order number and phone?'))]);

        $this->ask('where is my order?')->assertOk();

        Http::assertSent(fn (ClientRequest $request) => collect($request['tools'])->pluck('function.name')->all() === ['search_products', 'order_status']
            && ! str_contains($request['messages'][0]['content'], 'CUSTOMER (signed in'));
    }

    public function test_oversized_conversations_are_refused_before_any_call(): void
    {
        $this->enable();
        Http::fake();

        $history = array_fill(0, 12, ['role' => 'user', 'content' => 'hi']);
        $this->ask('one too many', $history)->assertStatus(422);

        $this->ask(str_repeat('a', 1201))->assertStatus(422);

        $this->postJson(route('assistant.chat'), ['messages' => [['role' => 'assistant', 'content' => 'I speak last']]])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_upstream_failure_is_a_clean_answer_not_a_500(): void
    {
        $this->enable();
        Setting::put('store_phone', '01634347164');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['type' => 'server_error']], 500)]);

        $this->ask('Hello')->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reply', 'Sorry, I can\'t answer right now. Call or WhatsApp us on 01634347164 and a person will help.');
    }

    public function test_the_meter_stops_a_runaway_client(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response($this->text('ok'))]);

        for ($i = 0; $i < 15; $i++) {
            $this->ask('again')->assertOk();
        }
        $this->ask('again')->assertStatus(429);
    }
}
