<?php

namespace App\Services\Ai;

use App\Models\Setting;
use App\Support\Locale;

/**
 * The doorman on the chat.
 *
 * Most of what reaches the assistant is not a question. Reading the stored
 * transcripts, one session ran to ninety messages of "O", "Ooooo" and
 * "LlLLL"; others are a thumb sliding over a Bangla keyboard ("্্", "।।।",
 * "িগহকহগহও"), a wall of emoji, or someone asking the shop assistant whether
 * it is married. Every one of those was a paid OpenAI call and a row in the
 * owner's chat history.
 *
 * So two things happen here. A message with nothing in it to answer is
 * recognised before any call is made and gets a short nudge instead; and a
 * session that produces nothing else, {@see limit()} times over, is closed —
 * for that browser session only — with the shop's WhatsApp number in hand.
 *
 * Two deliberate softnesses, both taken from the real transcripts:
 *
 * 1. THE COUNTER RESETS on a message that is actually answered. The clearest
 *    shopper in the log — haggling in Bangla over a pink-stone earring under
 *    ৳400 — also sent "হহজ" and "জগককগগজজ" between her questions. Counting
 *    those against her would have thrown out a customer mid-sale, so only an
 *    unbroken run closes a chat.
 * 2. AN ORDER IN PROGRESS IS NEVER CLOSED. Once she has started giving an
 *    address, a mistyped line must cost her a nudge, never the conversation.
 */
class ChatGuard
{
    /** Where the run of unanswerable messages, and the close, are kept. */
    public const KEY = 'chat_guard';

    /**
     * Short words that ARE an answer on their own, so they never count.
     * "Ok" is how the one real chat order in the log was confirmed.
     */
    protected const REAL_WORDS = [
        'ok', 'okk', 'okay', 'oky', 'hi', 'hii', 'hello', 'hey', 'yes', 'yep', 'yeah', 'ya', 'sure',
        'no', 'not', 'nope', 'thanks', 'thanx', 'thank', 'thx', 'ty', 'bye', 'price', 'dam', 'daam',
        'koto', 'kato', 'kobe', 'ache', 'ase', 'achhe', 'nibo', 'chai', 'lagbe', 'ring', 'size',
        'হাই', 'হ্যালো', 'হ্যাঁ', 'হ্যা', 'হা', 'না', 'জি', 'ওকে', 'আচ্ছা', 'ঠিক', 'আছে', 'ধন্যবাদ',
        'দাম', 'কত', 'কবে', 'নিব', 'নিবো', 'চাই', 'লাগবে', 'রিং', 'সাইজ',
    ];

    /** Bengali independent vowels and vowel signs. */
    protected const BN_VOWEL = '\x{0985}-\x{0994}\x{09BE}-\x{09CC}\x{09D7}';

    /** Signs that may follow a Bengali letter but can never open a word. */
    protected const BN_SIGN = '\x{0981}-\x{0983}\x{09BE}-\x{09CC}\x{09CD}\x{09D7}';

    /** How many unanswerable messages in a row close the chat. 0 switches it off. */
    public function limit(): int
    {
        return max(0, (int) config('services.openai.junk_limit', 3));
    }

    public function enabled(): bool
    {
        return $this->limit() > 0;
    }

    // ── The session's standing ──────────────────────────────────────────────

    public function isBlocked(): bool
    {
        return $this->enabled() && (bool) session(self::KEY.'_blocked');
    }

    /** Unanswerable messages in the current run. */
    public function strikes(): int
    {
        return (int) session(self::KEY.'_strikes', 0);
    }

    /**
     * Count one unanswerable message. Returns true when that closed the chat.
     */
    public function strike(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $strikes = $this->strikes() + 1;
        session([self::KEY.'_strikes' => $strikes]);

        // Never shut the door on someone who is part-way through giving us an
        // address: a mistyped line there costs her a nudge, not the order.
        if ($strikes >= $this->limit() && ! $this->ordering()) {
            session([self::KEY.'_blocked' => true]);

            return true;
        }

        return false;
    }

    /** A message that was actually answered — the run starts again. */
    public function pass(): void
    {
        if ($this->strikes() !== 0) {
            session()->forget(self::KEY.'_strikes');
        }
    }

    /** Let the chat back in (admin/test use; the storefront never calls it). */
    public function reopen(): void
    {
        session()->forget([self::KEY.'_strikes', self::KEY.'_blocked']);
    }

    protected function ordering(): bool
    {
        $orders = app(ChatOrder::class);

        return $orders->enabled() && $orders->state()['details'] !== [];
    }

    // ── Is there a question in here at all? ─────────────────────────────────

    /**
     * True when the message carries nothing the assistant could answer.
     *
     * This runs BEFORE OpenAI is called, so every message it catches is a
     * call the store does not pay for. It is deliberately conservative:
     * anything that might be a real question — however oddly spelled — is
     * passed through and answered.
     *
     * @param  array<int, array{role:string, content:string}>  $transcript  the turns sent with this message
     */
    public function looksJunk(string $text, array $transcript = []): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        // A pasted link is the strongest thing a customer can send: the one
        // real order in the stored transcripts opened with a product URL,
        // and stripped of its punctuation that URL reads exactly like mash.
        if (preg_match('~https?://~i', $text)) {
            return false;
        }

        // Letters, digits and the marks that belong to them. Punctuation,
        // emoji and whitespace go: "।।।", "🖕", ",😄:-[" and "্্" are left
        // with nothing at all.
        $word = mb_strtolower((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $text));
        $solid = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $word);

        if ($solid === '') {
            return true;
        }
        if (in_array($word, self::REAL_WORDS, true)) {
            return false;
        }

        // A number on its own is always an answer to something — a quantity,
        // a budget, an order number, a phone. "01711111111" is a real mobile
        // and must never read as someone leaning on the 1 key.
        if (preg_match('/^\p{N}+$/u', $word)) {
            return false;
        }

        $len = mb_strlen($word);

        // A single letter is not a question: "L", "M", "শ", "ঞ", "K".
        if ($len <= 2) {
            return true;
        }

        // Repetition is judged on the letters alone, for the same reason:
        // digits repeat in perfectly ordinary numbers.
        $letters = (string) preg_replace('/[^\p{L}\p{M}]+/u', '', $word);
        if (mb_strlen($letters) >= 3) {
            // The same letter four times over: "Xxxxxxx", "Ooooo", "আআআআ".
            if (preg_match('/(.)\1{3,}/u', $letters)) {
                return true;
            }
            // Barely any different letters in it: "LlLLL", "Ooo", "জগককগগজজ".
            // Three is safe — at this ratio it takes a word of one repeated
            // letter to trip, and no word is built that way.
            if ($this->variety($letters) <= 0.4) {
                return true;
            }
        }
        if ($this->saidBefore($word, $transcript)) {
            return true;
        }

        // Nothing a mouth could say. Whole-message, so one vowel anywhere is
        // enough to pass: "Wmwswcwrr", "BmmvhBjhMmbm", "Lll".
        if (! Locale::looksBangla($word) && ! preg_match('/[aeiou\p{N}]/u', $word)) {
            return true;
        }

        return $this->wordJunk($text) || (Locale::looksBangla($word) && $this->banglaJunk($text));
    }

    /**
     * Tests that belong to one word at a time. Run per word rather than over
     * the whole message because "Korean দুল" and "pink stone ring" are both
     * ordinary things to type, and joining them up would make either look
     * like mash.
     */
    protected function wordJunk(string $text): bool
    {
        foreach (preg_split('/\s+/u', mb_strtolower($text)) ?: [] as $chunk) {
            $chunk = (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $chunk);
            if ($chunk === '') {
                continue;
            }
            // Two scripts inside ONE word is a keyboard slipping, not a
            // sentence — "rxxrtrccbxdক্সd".
            if (preg_match('/\p{Latin}\p{Bengali}|\p{Bengali}\p{Latin}/u', $chunk)) {
                return true;
            }
            // A consonant run no word in either language has — "Hmmbhug",
            // "iklwklw". Five, because Banglish is full of bh, ch, dh, kh
            // and th, and "chhoto" must survive.
            if (preg_match('/[b-df-hj-np-tv-xz]{5,}/u', $chunk)) {
                return true;
            }
            // A whole word of consonants — "Nbbn". Four, since "sms" and
            // "pls" are things people really write.
            if (preg_match('/^[\p{Latin}]{4,}$/u', $chunk) && ! preg_match('/[aeiouy]/u', $chunk)) {
                return true;
            }
        }

        return false;
    }

    /** Different characters over total length — 1.0 is a real word. */
    protected function variety(string $word): float
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $chars === [] ? 1.0 : count(array_unique($chars)) / count($chars);
    }

    protected function banglaJunk(string $text): bool
    {
        foreach (preg_split('/\s+/u', $text) ?: [] as $chunk) {
            $chunk = (string) preg_replace('/[^\p{L}\p{M}]+/u', '', $chunk);
            if ($chunk === '') {
                continue;
            }
            // A vowel sign, hasant or chandrabindu cannot open a word:
            // "িগহকহগহও" is a thumb, not a syllable.
            if (preg_match('/^['.self::BN_SIGN.']/u', $chunk)) {
                return true;
            }
            // One unbroken run far longer than any Bangla word.
            if (mb_strlen((string) preg_replace('/\p{M}+/u', '', $chunk)) >= 20) {
                return true;
            }
        }

        // Not one vowel in the whole message — "হহজ", "রং্্্". Bangla does
        // not work that way.
        return ! preg_match('/['.self::BN_VOWEL.']/u', $text);
    }

    /**
     * The same words for the third time. The transcript comes from the
     * browser, so this is a nudge and not a gate — the run counter above is
     * what actually closes a chat.
     */
    protected function saidBefore(string $word, array $transcript): bool
    {
        $seen = 0;
        foreach (array_slice($transcript, 0, -1) as $turn) {
            if (($turn['role'] ?? '') !== 'user') {
                continue;
            }
            $earlier = mb_strtolower((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', (string) ($turn['content'] ?? '')));
            if ($earlier === $word) {
                $seen++;
            }
        }

        return $seen >= 2;
    }

    // ── What the customer is told ───────────────────────────────────────────

    /** After one unanswerable message: what she could ask instead. */
    public function nudgeText(): string
    {
        return Locale::isBangla()
            ? 'দুঃখিত, বুঝতে পারলাম না। গহনা, গিফট, ডেলিভারি, পেমেন্ট বা আপনার অর্ডার নিয়ে জিজ্ঞেস করুন — আমি সাহায্য করব।'
            : 'Sorry, I didn\'t catch that. Ask me about a piece, a gift, delivery, payment or your order and I\'ll help.';
    }

    /**
     * The last thing said in a closed chat. It always hands over a way to
     * reach a person: the chat shutting is not the shop shutting.
     */
    public function blockedText(): string
    {
        $store = store_name();
        $phone = Setting::get('store_phone', config('store.phone'));
        $wa = app(AssistantService::class)->whatsappLink();

        if (Locale::isBangla()) {
            return 'আমি শুধু '.$store.'-এর গহনা, ডেলিভারি, পেমেন্ট আর আপনার অর্ডার নিয়ে সাহায্য করতে পারি, তাই এই চ্যাটটি এখানেই বন্ধ করছি।'
                .($wa ? ' কারও সাথে কথা বলতে চাইলে WhatsApp করুন: '.$wa.'।' : '')
                .($phone ? ' অথবা কল করুন '.$phone.'।' : '');
        }

        return 'I can only help with '.$store.' — our jewelry, delivery, payment and your order — so I\'ll close this chat here.'
            .($wa ? ' If you need a person, WhatsApp us: '.$wa.'.' : '')
            .($phone ? ' Or call '.$phone.'.' : '');
    }
}
