<?php

namespace Tests\Feature;

use App\Models\AssistantConversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ConversationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The assistant used to keep nothing, so the store could not see a single
 * question it had been asked. Each exchange is appended now — and the two
 * things that must hold are that a long chat stays whole (the browser only ever
 * sends the last few turns) and that a failure to write it down never reaches
 * the customer.
 */
class AssistantChatHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    private function log(): ConversationLog
    {
        return app(ConversationLog::class);
    }

    private function record(string $uid, string $question, string $reply, bool $ok = true, array $products = []): void
    {
        $this->log()->record(
            Request::create('/assistant/chat', 'POST', ['page' => '/shop']),
            $uid,
            $question,
            ['ok' => $ok, 'reply' => $reply, 'products' => $products],
        );
    }

    public function test_an_exchange_is_stored_as_two_messages_on_one_conversation(): void
    {
        $this->record('abc123', 'Do you have gold hoops?', 'Yes — here are three.');

        $c = AssistantConversation::where('uid', 'abc123')->firstOrFail();

        $this->assertSame(2, $c->message_count);
        $this->assertSame('Do you have gold hoops?', $c->messages()->where('role', 'user')->value('content'));
        $this->assertSame('Yes — here are three.', $c->messages()->where('role', 'assistant')->value('content'));
        $this->assertSame('/shop', $c->first_page);
    }

    public function test_later_turns_append_rather_than_replace(): void
    {
        // The browser sends only a sliding window of turns, so the server has
        // to accumulate — this is the whole reason messages are their own table.
        $this->record('abc123', 'Do you have gold hoops?', 'Yes.');
        $this->record('abc123', 'What about silver?', 'Also yes.');
        $this->record('abc123', 'Delivery to Sylhet?', 'Two days.');

        $c = AssistantConversation::where('uid', 'abc123')->firstOrFail();

        $this->assertSame(6, $c->message_count);
        $this->assertSame(1, AssistantConversation::count(), 'one chat, one conversation');
        $this->assertSame(
            ['Do you have gold hoops?', 'What about silver?', 'Delivery to Sylhet?'],
            $c->messages()->where('role', 'user')->orderBy('id')->pluck('content')->all(),
        );
    }

    public function test_a_reply_the_assistant_could_not_give_is_flagged(): void
    {
        $this->record('fail1', 'Do you ship to Mars?', 'I cannot answer right now.', ok: false);

        $c = AssistantConversation::where('uid', 'fail1')->firstOrFail();

        $this->assertTrue($c->had_failure);
        $this->assertFalse($c->messages()->where('role', 'assistant')->value('ok'));
    }

    public function test_one_failure_marks_the_conversation_even_if_later_turns_work(): void
    {
        $this->record('mixed', 'A?', 'sorry', ok: false);
        $this->record('mixed', 'B?', 'here you go', ok: true);

        $this->assertTrue(AssistantConversation::where('uid', 'mixed')->value('had_failure'));
    }

    public function test_a_blank_conversation_id_stores_nothing(): void
    {
        $this->record('', 'hello?', 'hi');

        $this->assertSame(0, AssistantConversation::count());
    }

    public function test_the_products_a_reply_showed_are_kept(): void
    {
        $this->record('cards', 'gold hoops', 'These three', products: [['name' => 'Hoop A', 'price_text' => '৳1,200']]);

        $m = AssistantConversation::where('uid', 'cards')->firstOrFail()
            ->messages()->where('role', 'assistant')->first();

        $this->assertSame('Hoop A', $m->products[0]['name']);
    }

    public function test_the_endpoint_records_the_turn_it_just_answered(): void
    {
        // The assistant has to be on for the endpoint to get as far as a reply;
        // the HTTP call to OpenAI then fails in the test environment, which is
        // the point — a failed answer is still a question worth recording.
        config(['services.openai.assistant_enabled' => true, 'services.openai.key' => 'sk-test']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);

        $this->postJson('/assistant/chat', [
            'messages' => [['role' => 'user', 'content' => 'Do you have anklets?']],
            'page' => '/shop',
            'cid' => 'endpoint-1',
        ]);

        $c = AssistantConversation::where('uid', 'endpoint-1')->first();
        $this->assertNotNull($c, 'the endpoint did not record the exchange');
        $this->assertSame('Do you have anklets?', $c->messages()->where('role', 'user')->value('content'));
    }

    public function test_a_storage_failure_never_breaks_the_chat(): void
    {
        // The conversations table is gone; record() must still return quietly.
        \Illuminate\Support\Facades\Schema::drop('assistant_messages');
        \Illuminate\Support\Facades\Schema::drop('assistant_conversations');

        $this->record('boom', 'still there?', 'yes');

        $this->assertTrue(true, 'record() threw');
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function test_the_admin_list_shows_the_opening_question(): void
    {
        $this->record('a', 'Do you have gold hoops?', 'Yes.');
        $this->record('a', 'And silver?', 'Also.');

        $this->actingAs($this->admin())->get('/admin/conversations')
            ->assertOk()
            ->assertSee('Do you have gold hoops?', false)
            ->assertDontSee('And silver?', false);
    }

    public function test_the_admin_search_looks_inside_what_was_said(): void
    {
        $this->record('a', 'Do you have gold hoops?', 'Yes.');
        $this->record('b', 'Delivery to Khulna?', 'Two days.');

        $html = $this->actingAs($this->admin())->get('/admin/conversations?q=Khulna')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Delivery to Khulna?', $html);
        $this->assertStringNotContainsString('gold hoops', $html);
    }

    public function test_the_couldnt_answer_filter_finds_the_ones_worth_reading(): void
    {
        $this->record('ok1', 'Fine question', 'Fine answer');
        $this->record('bad1', 'Hard question', 'no idea', ok: false);

        $html = $this->actingAs($this->admin())->get('/admin/conversations?filter=failed')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Hard question', $html);
        $this->assertStringNotContainsString('Fine question', $html);
    }

    public function test_the_transcript_page_shows_both_sides(): void
    {
        $this->record('t', 'Is this real gold?', 'It is gold-plated brass.');
        $c = AssistantConversation::where('uid', 't')->firstOrFail();

        $this->actingAs($this->admin())->get("/admin/conversations/{$c->id}")
            ->assertOk()
            ->assertSee('Is this real gold?', false)
            ->assertSee('It is gold-plated brass.', false);
    }

    public function test_chat_history_is_behind_the_admin_login(): void
    {
        $this->get('/admin/conversations')->assertRedirect();
    }

    public function test_transcripts_older_than_the_retention_window_are_pruned(): void
    {
        $this->record('old', 'ancient', 'reply');
        $this->record('new', 'recent', 'reply');

        AssistantConversation::where('uid', 'old')->update(['last_message_at' => now()->subDays(200)]);

        $this->log()->prune(180);

        $this->assertNull(AssistantConversation::where('uid', 'old')->first());
        $this->assertNotNull(AssistantConversation::where('uid', 'new')->first());
        $this->assertSame(2, \App\Models\AssistantMessage::count(), 'the deleted conversation’s messages should go with it');
    }

    public function test_a_member_conversation_names_the_member(): void
    {
        $customer = Customer::create([
            'name' => 'Ayesha', 'phone' => '01712345678', 'password' => bcrypt('x'),
        ]);

        $this->actingAs($customer, 'customer');
        $this->record('member1', 'Where is my order?', 'Out for delivery.');

        $this->assertSame($customer->id, AssistantConversation::where('uid', 'member1')->value('customer_id'));
    }
}
