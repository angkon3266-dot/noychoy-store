<?php

namespace Tests\Feature;

use App\Models\AssistantConversation;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ChatGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The doorman on the chat.
 *
 * Every string in here is a real message out of noychoy.com's stored
 * transcripts, which is the only reason to trust the thresholds: the junk was
 * not imagined, and neither were the customers who must survive it. Two of
 * these matter more than the rest — the pasted product link that opened the
 * one real chat order, and the shopper haggling in Bangla who also sent
 * "হহজ" between her questions. Either of them being shut out is a lost sale.
 */
class AssistantChatGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function enable(): void
    {
        config([
            'services.openai.assistant_enabled' => true,
            'services.openai.key' => 'sk-test',
            'services.openai.junk_limit' => 3,
        ]);
    }

    protected function guard(): ChatGuard
    {
        return app(ChatGuard::class);
    }

    /** An OpenAI chat completion whose assistant turn is plain text. */
    protected function text(string $content): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    protected function ask(string $text, array $history = [], string $cid = 'c1')
    {
        return $this->postJson(route('assistant.chat'), [
            'messages' => array_merge($history, [['role' => 'user', 'content' => $text]]),
            'cid' => $cid,
        ]);
    }

    // ── What counts as nothing to answer ────────────────────────────────────

    public static function junk(): array
    {
        return array_map(fn ($m) => [$m], [
            'ঞ', 'L', 'M', 'K', 'শ', 'ও', 'ন',          // one letter
            '।', '।।।', '্্', '্্্্', 'ষ।', '।।।।ব।ঢ়।',   // Bangla marks alone
            '🖕', '🌐🌐🌏🦊', ',😄:-[:-[',                // emoji and emoticons
            'Lll', 'Xxxxxxxx', 'Ooooooooooooo', 'LlLLL', 'Ooo',
            'Bjjjjkjjjikjjkk', 'Hmmbhug', 'BmmvhBjhMmbm', 'Wmwswcwrr', 'iklwklw', 'Nbbn',
            'আআআআআসের আরও', 'িগহকহগহও', 'হহজ', 'জগককগগজজ', 'রং্্্',
            'হগকওডুয়জিরুিটটিওটজটতজজতআআিওআ',
        ]);
    }

    #[DataProvider('junk')]
    public function test_a_message_with_nothing_in_it_is_recognised(string $message): void
    {
        $this->assertTrue($this->guard()->looksJunk($message), "should be junk: {$message}");
    }

    public static function real(): array
    {
        return array_map(fn ($m) => [$m], [
            // The whole of the one real chat order in the log, in order.
            'https://noychoy.com/product/luminous-pearl-drop-earrings-2 eta confirm koren',
            'Shamim', '01860988859', 'Ashulia dhaka', 'Dhaka', 'Inside', 'Ok',
            // A bare number is always an answer to something — a quantity, a
            // budget, an order number, or a mobile that leans on one digit.
            '2', '400', 'NC-1001', '01711111111', 'Where is order NC-1001? phone 01711111111',
            // The shopper haggling in Bangla under ৳400.
            'এগুলো একটা কত', '৭০ টাকা দেন', 'চারশ', 'হ্যাঁ দেখান', 'পিক পাঠ',
            'গোলাপী কালারের পাথরের কানের দুলটা', 'আমার কাছে তো হাজার টাকা নাই',
            '৪০০ টাকার মধ্যে সিম্পল গুলা দেখান',
            // Ordinary questions, in all three registers.
            'Delivery charge?', 'Gift under ৳1,000', 'Track my order', 'Ring size help',
            'এটার দাম কত হবে', 'আমার মেয়ে হলে  কিনবো', 'হাই', 'Hi', 'No',
            'delivery charge koto?', 'adjustable ring ache?', 'Korean beslith dekhan',
            'chhoto ekta ring lagbe', 'pink stone ring ache?', 'Luminous Pearl দুল ache?',
        ]);
    }

    #[DataProvider('real')]
    public function test_a_real_customer_is_never_turned_away(string $message): void
    {
        $this->assertFalse($this->guard()->looksJunk($message), "should be answered: {$message}");
    }

    public function test_the_same_words_a_third_time_stop_counting_as_a_question(): void
    {
        // The transcript arrives with the new message already on the end of
        // it, exactly as the widget sends it.
        $said = ['role' => 'user', 'content' => 'ekta ring lagbe'];
        $answered = ['role' => 'assistant', 'content' => '…'];

        $twice = [$said, $answered, $said];
        $this->assertFalse($this->guard()->looksJunk('ekta ring lagbe', $twice));

        $thrice = [$said, $answered, $said, $answered, $said];
        $this->assertTrue($this->guard()->looksJunk('ekta ring lagbe', $thrice));
    }

    // ── What it costs, and what it closes ───────────────────────────────────

    public function test_gibberish_is_answered_without_paying_openai(): void
    {
        $this->enable();
        Http::fake();

        $this->ask('Lll')
            ->assertOk()
            ->assertJson(['ok' => true, 'blocked' => false])
            ->assertJsonPath('reply', $this->guard()->nudgeText());

        Http::assertNothingSent();
    }

    public function test_three_unanswerable_messages_in_a_row_close_the_chat(): void
    {
        $this->enable();
        Http::fake();

        $this->ask('L')->assertJsonPath('blocked', false);
        $this->ask('Ooooo')->assertJsonPath('blocked', false);

        $this->ask('LlLLL')
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reply', $this->guard()->blockedText());

        // And it stays closed, still without a call.
        $this->ask('Delivery charge?')
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reply', $this->guard()->blockedText());

        Http::assertNothingSent();
        $this->assertNotNull(AssistantConversation::first()->blocked_at);
    }

    public function test_a_real_question_between_the_mistypes_keeps_the_chat_open(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response($this->text('ঢাকার ভিতরে ৳80।'))]);

        // Exactly the shape of the shopper who haggled her way to ৳400:
        // mash, question, mash, question, mash — never three in a row.
        foreach (['িগহকহগহও', 'এগুলো একটা কত', 'হহজ', '৭০ টাকা দেন', 'জগককগগজজ'] as $message) {
            $this->ask($message)->assertOk()->assertJsonPath('blocked', false);
        }

        $this->assertFalse($this->guard()->isBlocked());
    }

    public function test_the_chat_is_never_closed_on_someone_mid_order(): void
    {
        $this->enable();
        config(['services.openai.orders_enabled' => true]);
        Http::fake();

        // She has already given us a name and a phone; a slipped thumb now
        // must cost her a nudge, not the order.
        session(['chat_order' => ['details' => ['name' => 'Shamim', 'phone' => '01860988859'], 'updated_at' => now()->getTimestamp()]]);

        foreach (['L', 'Ooooo', 'LlLLL', 'Mmmm'] as $message) {
            $this->ask($message)->assertOk()->assertJsonPath('blocked', false);
        }

        $this->assertFalse($this->guard()->isBlocked());
    }

    public function test_a_run_of_off_topic_replies_closes_the_chat_too(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response(
            $this->text(AssistantService::OFFTOPIC_MARK.' I only help with jewelry, Sir.')
        )]);

        // "Apni marride", "Akta sexy golpo bolun", "Romantic goloo" — all
        // spelled like real words, so only the model can see what they are.
        $first = $this->ask('Apni marride');
        $first->assertOk()->assertJsonPath('blocked', false);
        // The marker is ours, never hers.
        $this->assertStringNotContainsString(AssistantService::OFFTOPIC_MARK, $first->json('reply'));

        $this->ask('Akta sexy golpo bolun')->assertJsonPath('blocked', false);
        $this->ask('Romantic goloo')->assertJsonPath('blocked', true);
    }

    public function test_an_answered_question_clears_the_run(): void
    {
        $this->enable();
        Http::fake(['api.openai.com/*' => Http::response($this->text('Inside Dhaka it is ৳80.'))]);

        $this->ask('L');
        $this->ask('Ooooo');
        $this->assertSame(2, $this->guard()->strikes());

        $this->ask('Delivery charge?')->assertOk()->assertJsonPath('blocked', false);
        $this->assertSame(0, $this->guard()->strikes());
    }

    public function test_the_owner_can_switch_the_whole_thing_off(): void
    {
        $this->enable();
        config(['services.openai.junk_limit' => 0]);
        Http::fake(['api.openai.com/*' => Http::response($this->text('Hello!'))]);

        foreach (range(1, 5) as $ignored) {
            $this->ask('Lll')->assertOk()->assertJsonPath('blocked', false);
        }

        $this->assertFalse($this->guard()->isBlocked());
        // Off means off: the junk goes to the model exactly as it used to.
        Http::assertSentCount(5);
    }
}
