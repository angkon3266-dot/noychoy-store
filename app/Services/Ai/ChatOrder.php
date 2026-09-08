<?php

namespace App\Services\Ai;

use App\Actions\PlaceOrder;
use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\CartService;
use App\Support\Locale;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * An order taken in the chat, held server-side until it is confirmed.
 *
 * The assistant is a salesperson, not a cashier: it may say what the customer
 * wants, but every number on the order is computed here, from the catalogue and
 * the same pricing cascade the website uses. The model can name a product, a
 * variant, a quantity and the answers the customer gives to the checkout
 * questions — it can never name a price, a discount or a total, because no tool
 * accepts one.
 *
 * Three deliberate guards, in order of how much they matter:
 *
 * 1. THE BASKET IS NOT THE SHOPPER'S. Lines live in a second cart
 *    ({@see CartService::scoped()}) under its own session keys, so an order
 *    taken in the chat can never sweep up items she was still deciding about,
 *    and taking one cannot disturb her real basket.
 * 2. NOTHING IS PLACED THAT WAS NOT QUOTED. quote() returns a `quote_id` that
 *    hashes the exact basket, the delivery zone and the money. place() refuses
 *    any id that is not the current one, so an order can only be created after
 *    the customer has been shown, in writing, the total she is agreeing to.
 * 3. ONE INTENT, ONE ORDER. The order number is written into the draft, so a
 *    model that calls place() twice — or a customer who re-sends the transcript,
 *    which lives in her browser and is fully replayable — gets the same order
 *    back rather than a second parcel.
 */
class ChatOrder
{
    /** Where the answers to the checkout questions live between messages. */
    public const KEY = 'chat_order';

    /** The scope name for this session's second cart. */
    public const SCOPE = 'chat';

    /** A draft this old is abandoned: a stale address is worse than no address. */
    public const TTL_MINUTES = 120;

    /**
     * Which customer message we are on. A quote is stamped with this, and
     * place() refuses one stamped with the message it is currently answering —
     * so the summary must have been sent to the customer, and she must have
     * written back, before anything can be ordered. Without it a model could
     * quote and place inside a single turn, and the first the customer would
     * know of the total is the confirmation.
     */
    public function turn(): int
    {
        return (int) session(self::KEY.'_turn', 0);
    }

    public function nextTurn(): void
    {
        session([self::KEY.'_turn' => $this->turn() + 1]);
    }

    /**
     * The checkout questions, in the order the assistant should ask them, with
     * the SAME rules CheckoutController@store applies. Mirrored rather than
     * shared because the controller validates an HTTP request and this
     * validates one answer at a time — but if the form changes, change both.
     */
    public function fields(): array
    {
        return [
            'name' => ['rules' => ['required', 'string', 'max:120'], 'ask_en' => 'Your full name', 'ask_bn' => 'আপনার নাম'],
            'phone' => ['rules' => ['required', 'string', new \App\Rules\BdPhone], 'ask_en' => 'Mobile number (01XXXXXXXXX)', 'ask_bn' => 'মোবাইল নম্বর (01XXXXXXXXX)'],
            'address' => ['rules' => ['required', 'string', 'min:10', 'max:500'], 'ask_en' => 'Full delivery address — house, road, area', 'ask_bn' => 'সম্পূর্ণ ঠিকানা — বাসা, রোড, এলাকা'],
            'area' => ['rules' => ['nullable', 'string', 'max:120'], 'ask_en' => 'Area or thana', 'ask_bn' => 'এলাকা বা থানা'],
            'district' => ['rules' => ['nullable', 'string', 'max:120'], 'ask_en' => 'District', 'ask_bn' => 'জেলা'],
            'is_inside_dhaka' => ['rules' => ['required', 'boolean'], 'ask_en' => 'Is the address inside Dhaka city?', 'ask_bn' => 'ঠিকানাটি কি ঢাকার ভিতরে?'],
            // Deliberately no email. The checkout page offers one, but an
            // assistant that accepts an arbitrary address is a way to point
            // the shop's mail server at a stranger; the SMS and the tracking
            // link do the same job for a chat order.
            'notes' => ['rules' => ['nullable', 'string', 'max:500'], 'ask_en' => 'Anything we should know (optional)', 'ask_bn' => 'কিছু জানানোর থাকলে (ঐচ্ছিক)'],
            // Gifts: without these a piece ordered as a present ships with the
            // price slip in the box and no card, which is the one thing the
            // gift note on the checkout page promises will not happen.
            'is_gift' => ['rules' => ['nullable', 'boolean'], 'ask_en' => 'Is it a gift?', 'ask_bn' => 'এটি কি উপহার?'],
            'card_message' => ['rules' => ['nullable', 'string', 'max:200'], 'ask_en' => 'Message for the gift card', 'ask_bn' => 'গিফট কার্ডে কী লিখব?'],
        ];
    }

    /** The answers that must be present before an order can be quoted. */
    public function requiredFields(): array
    {
        return collect($this->fields())
            ->filter(fn ($f) => in_array('required', $f['rules'], true))
            ->keys()->all();
    }

    // ── Switches and limits ─────────────────────────────────────────────────

    /** Whether the assistant may take orders at all right now. */
    public function enabled(): bool
    {
        return app(AssistantService::class)->enabled()
            && (bool) config('services.openai.orders_enabled', true);
    }

    /** The largest total the assistant may place unaided. */
    public function maxTotal(): float
    {
        return (float) (config('services.openai.orders_max_total') ?: 20000);
    }

    /** How many chat orders one customer may place in a day. */
    public function dailyLimit(): int
    {
        return max(1, (int) (config('services.openai.orders_per_day') ?: 3));
    }

    /**
     * Chat orders already placed today by this phone or this browser.
     *
     * Counted both ways on purpose: the phone stops one customer ordering the
     * same thing five times over after a misunderstanding, and the session
     * stops a single visitor cycling through invented numbers. Neither is
     * proof of identity on a cash-on-delivery store — the IP-level assistant
     * limiter is what bounds a script.
     */
    public function placedToday(?string $phone = null): int
    {
        $since = store_time(now())->startOfDay()->utc();

        $byPhone = $phone
            ? Order::where('source', 'chat')->where('customer_phone', bd_phone($phone))->where('created_at', '>=', $since)->count()
            : 0;

        $bySession = count(array_filter(
            (array) session(self::KEY.'_placed', []),
            fn ($at) => is_numeric($at) && $at >= $since->getTimestamp(),
        ));

        return max($byPhone, $bySession);
    }

    // ── The draft ───────────────────────────────────────────────────────────

    public function cart(): CartService
    {
        return CartService::scoped(self::SCOPE);
    }

    /** @return array{details:array, order_number:?string, updated_at:?int} */
    public function state(): array
    {
        $state = (array) session(self::KEY, []);

        // An abandoned draft is dropped rather than resumed: a customer who
        // comes back tomorrow should be asked again, not delivered to the
        // address she gave for a gift last week.
        $updated = (int) ($state['updated_at'] ?? 0);
        if ($updated > 0 && $updated < now()->subMinutes(self::TTL_MINUTES)->getTimestamp()) {
            $this->clear();

            return ['details' => [], 'order_number' => null, 'updated_at' => null];
        }

        return [
            'details' => (array) ($state['details'] ?? []),
            'order_number' => $state['order_number'] ?? null,
            'updated_at' => $updated ?: null,
        ];
    }

    protected function write(array $patch): void
    {
        session([self::KEY => array_merge(session(self::KEY, []), $patch, ['updated_at' => now()->getTimestamp()])]);
    }

    public function clear(): void
    {
        session()->forget([self::KEY, self::KEY.'_quoted']);
        $this->cart()->clear();
    }

    // ── Choosing the piece ──────────────────────────────────────────────────

    /**
     * Resolve what the customer pointed at: a product link, an exact name, or
     * words to search for. Never a price — the catalogue decides that.
     *
     * @return array{ok:bool, ...}
     */
    public function chooseItem(string $reference, ?string $variant = null, int $qty = 1): array
    {
        $product = $this->findProduct($reference);

        if (! $product) {
            return ['ok' => false, 'reason' => 'no_match',
                'message' => 'No published piece matches "'.$reference.'". Use search_products to show the customer what is close, and let them pick one.'];
        }

        if (! $product->isAvailable() && ! $product->isPreorder()) {
            return ['ok' => false, 'reason' => 'sold_out',
                'message' => $product->name.' is sold out. Offer something similar with search_products.'];
        }

        $chosen = null;
        if ($product->has_variants) {
            $variants = $product->variants()->where('is_active', true)->get();
            $chosen = $this->matchVariant($variants, $variant);

            if (! $chosen) {
                return ['ok' => false, 'reason' => filled($variant) ? 'variant_unavailable' : 'variant_needed',
                    'product' => $product->name,
                    'asked_for' => filled($variant) ? $variant : null,
                    'options' => $variants->map(fn ($v) => [
                        'variant' => $v->label,
                        'price' => money($v->effective_price),
                        'in_stock' => (int) $v->stock_quantity > 0,
                    ])->values()->all(),
                    'message' => filled($variant)
                        ? 'We do not have that one, or the answer matched more than one. Tell the customer plainly which options we DO have, in their language, and let them pick — never substitute a different size or colour for them.'
                        : 'Ask the customer which one they want, in their own language, then call this tool again with their answer.'];
            }

            if ((int) $chosen->stock_quantity <= 0) {
                return ['ok' => false, 'reason' => 'variant_sold_out', 'variant' => $chosen->label,
                    'message' => 'That option is sold out. Offer the ones that are still in stock.'];
            }
        }

        // A variant with a stored 0.00 reads as a real price rather than
        // inheriting the parent's. The product page hides those; without this
        // the chat would happily sell one for nothing.
        $unit = $chosen?->effective_price ?? (float) $product->price;
        if ($unit <= 0) {
            return ['ok' => false, 'reason' => 'no_price',
                'message' => 'That one has no price set, so I cannot sell it here. Give the customer the WhatsApp link and let a person quote it.'];
        }

        // A chat order is a conversation, not a wholesale channel. Two of a
        // piece is a plausible "one for my sister too"; ten is either a
        // misunderstanding or someone tying up the shelf.
        $qty = max(1, min(2, $qty));

        if ($product->manage_stock && $chosen === null && $qty > max(1, (int) $product->stock_quantity)) {
            return ['ok' => false, 'reason' => 'not_enough_stock',
                'message' => 'There are not that many left. Tell the customer how many we have and ask if that is alright.'];
        }

        // One piece per chat order, replaced rather than added to: an assistant
        // that silently accumulates lines across a long conversation is how a
        // customer ends up agreeing to one ring and receiving three.
        $cart = $this->cart();
        $cart->clear();
        $cart->add($product, $chosen, $qty);

        // Starting a new piece after one has been ordered begins a genuinely
        // clean draft rather than quietly clearing the anti-duplicate flag —
        // a replayed transcript must not be able to reopen a placed order and
        // send the parcel twice. The content guard in place() is the backstop.
        $state = $this->state();
        if ($state['order_number']) {
            session()->forget([self::KEY, self::KEY.'_quoted']);
            $state['details'] = [];
        }

        $this->write(['details' => $state['details'], 'order_number' => null]);

        return ['ok' => true] + $this->summary();
    }

    /** Change just the quantity of the piece already chosen. */
    public function setQuantity(int $qty): array
    {
        $cart = $this->cart();
        $line = $cart->items()->first();

        if (! $line) {
            return ['ok' => false, 'reason' => 'nothing_chosen', 'message' => 'No piece has been chosen yet — call choose_item first.'];
        }

        $cart->update($line['key'], max(1, min(10, $qty)));

        return ['ok' => true] + $this->summary();
    }

    /** A product from a storefront URL, an exact name, a slug, or a search. */
    protected function findProduct(string $reference): ?Product
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        // A link the customer pasted: /product/{slug}, with or without the host,
        // query string or trailing slash.
        if (preg_match('~/product/([A-Za-z0-9\-_]+)~', $reference, $m)) {
            if ($bySlug = Product::published()->where('slug', $m[1])->first()) {
                return $bySlug;
            }
        }

        $slugish = Str::slug($reference);
        if ($slugish !== '' && ($bySlug = Product::published()->where('slug', $slugish)->first())) {
            return $bySlug;
        }

        if ($byName = Product::published()->whereRaw('LOWER(name) = ?', [mb_strtolower($reference)])->first()) {
            return $byName;
        }

        // Fall back to the catalogue's own search, but only accept it when it
        // is unambiguous: guessing between two similar necklaces is how the
        // wrong parcel gets delivered.
        $found = Product::published()->search($reference)->take(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    /** Match "gold, 18", "Size: 18" or an exact label against the live variants. */
    protected function matchVariant($variants, ?string $wanted): ?ProductVariant
    {
        if ($variants->isEmpty()) {
            return null;
        }
        if (blank($wanted)) {
            // The single-option shortcut lives BELOW this on purpose. Above it,
            // a customer who says "size 18 lagbe" on a piece whose only live
            // option is 16 was silently handed the 16.
            return $variants->count() === 1 ? $variants->first() : null;
        }

        $needle = mb_strtolower(trim($wanted));

        foreach ($variants as $v) {
            if (mb_strtolower($v->label) === $needle || mb_strtolower((string) $v->sku) === $needle) {
                return $v;
            }
        }

        // Every word the customer gave must appear in the variant's values, so
        // "gold 18" matches {colour: Gold, size: 18} but "gold" alone does not
        // pick between two golds.
        $words = collect(preg_split('/[\s,;:]+/u', $needle))->filter()->values();
        $matches = $variants->filter(function ($v) use ($words) {
            $hay = mb_strtolower(collect($v->getAttribute('attributes') ?? [])->values()->implode(' '));

            return $words->every(fn ($w) => str_contains($hay, $w));
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    // ── The checkout answers ────────────────────────────────────────────────

    /**
     * Store whatever the customer has answered so far, one field at a time.
     *
     * Every value is validated by the same rules the checkout form uses, and a
     * value that fails is NOT stored — the tool returns the error so the
     * assistant asks again rather than shipping to an address that is half a
     * sentence.
     */
    public function setDetails(array $input): array
    {
        $fields = $this->fields();
        $details = $this->state()['details'];
        $errors = [];

        foreach ($input as $key => $value) {
            if (! isset($fields[$key]) || $value === null || $value === '') {
                continue;
            }

            if ($key === 'is_gift') {
                $details[$key] = (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            if ($key === 'is_inside_dhaka') {
                // Only a real yes or no counts. Reading anything else as "no"
                // would quote the outside-Dhaka rate and call the question
                // answered, when the honest thing is to ask again.
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    $errors[$key] = 'Ask the customer plainly whether the address is inside Dhaka city — yes or no.';
                } else {
                    $details[$key] = $parsed;
                }

                continue;
            }

            $value = trim((string) $value);
            $check = Validator::make([$key => $value], [$key => $fields[$key]['rules']]);

            if ($check->fails()) {
                $errors[$key] = $check->errors()->first($key);

                continue;
            }

            $details[$key] = $key === 'phone' ? bd_phone($value) : $value;
        }

        // The phone prices the order — assigned coupons are matched on it — so
        // the scoped cart is told once we have one AND the customer has given
        // us the rest of her details. Doing it for any number the model relays
        // would turn the chat into an oracle: type a stranger's number, watch
        // which private offer appears in the summary.
        if (filled($details['phone'] ?? null) && filled($details['name'] ?? null) && filled($details['address'] ?? null)) {
            $this->cart()->rememberCheckoutPhone($details['phone']);
        }

        $this->write(['details' => $details]);

        return ['ok' => $errors === [], 'errors' => $errors] + $this->summary();
    }

    /** Which required answers are still missing, in the order to ask them. */
    public function missing(): array
    {
        $details = $this->state()['details'];

        return array_values(array_filter(
            $this->requiredFields(),
            fn ($key) => ! isset($details[$key]) || $details[$key] === '' || $details[$key] === null,
        ));
    }

    // ── Quote and confirmation ──────────────────────────────────────────────

    /**
     * What the customer is being asked to agree to. Every figure comes from the
     * same cascade the cart page uses, so a chat order and a website order for
     * the same basket cost exactly the same.
     */
    /**
     * @param  bool  $forReading  true only when this is the summary the
     *                            assistant is about to read to the customer —
     *                            see the turn stamp below.
     */
    public function quote(bool $forReading = false): array
    {
        $cart = $this->cart();
        if ($cart->isEmpty()) {
            return ['ready' => false, 'reason' => 'nothing_chosen'];
        }

        $details = $this->state()['details'];
        $inside = (bool) ($details['is_inside_dhaka'] ?? false);
        $missing = $this->missing();

        $subtotal = $cart->subtotal();
        $discount = $cart->discount();
        $shipping = $cart->shipping($inside);
        $total = max(0, $subtotal - $discount + $shipping);

        $quote = [
            'ready' => $missing === [],
            'missing' => $missing,
            'items' => $cart->items()->map(fn ($i) => [
                'name' => $i['name'].($i['attributes'] ? ' ('.collect($i['attributes'])->map(fn ($v, $k) => "$k: $v")->implode(', ').')' : ''),
                'qty' => (int) $i['qty'],
                'unit_price' => money($i['price']),
                'line_total' => money($i['price'] * $i['qty']),
            ])->values()->all(),
            'subtotal' => money($subtotal),
            // Labels with the coupon code stripped out: the model repeats what
            // it is given, and a private code read aloud in chat is a code
            // posted publicly.
            'savings' => collect($cart->discountLines())->map(fn ($l) => [
                'label' => preg_replace('/\bCoupon\s+\S+/i', 'Coupon', (string) $l['label']),
                'amount' => money($l['amount']),
            ])->values()->all(),
            'delivery' => $cart->hasFreeShipping() ? 'Free' : money($shipping),
            'delivery_zone' => $inside ? 'inside Dhaka' : 'outside Dhaka',
            'total' => money($total),
            'total_raw' => round($total, 2),
            'payment' => 'Cash on delivery',
            'deliver_to' => array_filter([
                'name' => $details['name'] ?? null,
                'phone' => $details['phone'] ?? null,
                'address' => $details['address'] ?? null,
                'area' => $details['area'] ?? null,
                'district' => $details['district'] ?? null,
            ]),
        ];

        $quote['quote_id'] = $this->quoteId($quote);

        // Only the explicit read-back counts as putting a total in front of
        // the customer. Every other caller — recording an answer, choosing a
        // piece — needs the figures too, but stamping them there would mean an
        // order could be placed off a total nobody ever asked to be read out.
        if ($forReading) {
            $seen = (array) session(self::KEY.'_quoted', []);
            if (! isset($seen[$quote['quote_id']])) {
                $seen[$quote['quote_id']] = $this->turn();
                session([self::KEY.'_quoted' => array_slice($seen, -10, null, true)]);
            }
        }

        return $quote;
    }

    /**
     * A fingerprint of everything the customer is agreeing to. place() will not
     * accept a stale one, so changing the basket, the address zone or the
     * quantity after the customer said yes invalidates the yes.
     */
    protected function quoteId(array $quote): string
    {
        return substr(hash('sha256', json_encode([
            $this->cart()->items()->map(fn ($i) => [$i['key'], $i['qty'], $i['price']])->values(),
            $quote['total_raw'],
            $quote['delivery_zone'],
            // The whole delivery block, not just the phone: she agreed to a
            // parcel going to one address, and editing it afterwards has to
            // cost a fresh confirmation like everything else does.
            $quote['deliver_to'],
        ])), 0, 16);
    }

    /** A short summary for the model after every change to the draft. */
    protected function summary(): array
    {
        $quote = $this->quote();

        return [
            'order' => $quote,
            'still_needed' => $quote['missing'] ?? [],
            'note' => ($quote['ready'] ?? false)
                ? 'Everything is answered. Call review_order now, in THIS message, and read the whole summary out — pieces, savings, delivery charge, total, cash on delivery and the address — then ask them to confirm. Never say you will send a summary later; send it now.'
                : 'Ask the customer for the next missing answer, one or two at a time, in their language. Do not call place_order yet.',
        ];
    }

    // ── Placing it ──────────────────────────────────────────────────────────

    /**
     * Create the order. Refuses unless the customer has just been quoted this
     * exact basket and total, and returns the existing order rather than a
     * second one if it has already been placed.
     */
    public function place(string $quoteId): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'reason' => 'disabled', 'message' => 'Order taking is switched off. Give the customer the WhatsApp link and let a person take it.'];
        }

        $state = $this->state();

        // Already placed: hand back the same order. A model that calls this
        // twice, or a replayed transcript, must not produce a second parcel.
        if ($existing = $state['order_number']) {
            $order = Order::where('order_number', $existing)->first();

            return $order
                ? ['ok' => true, 'already_placed' => true] + $this->placedPayload($order)
                : ['ok' => false, 'reason' => 'unknown', 'message' => 'Tell the customer to call us — the order could not be read back.'];
        }

        $cart = $this->cart();
        if ($cart->isEmpty()) {
            return ['ok' => false, 'reason' => 'nothing_chosen', 'message' => 'No piece has been chosen — start again with choose_item.'];
        }

        if ($missing = $this->missing()) {
            return ['ok' => false, 'reason' => 'incomplete', 'missing' => $missing,
                'message' => 'Still missing: '.implode(', ', $missing).'. Ask for those first.'];
        }

        $quote = $this->quote();

        if ($quoteId !== ($quote['quote_id'] ?? null)) {
            return ['ok' => false, 'reason' => 'stale_quote', 'order' => $quote,
                'message' => 'Something changed since that total was quoted. Read the customer the summary above again and ask them to confirm before placing it.'];
        }

        // The customer must have been shown this total and replied to it. A
        // quote worked out while answering the current message has not been
        // seen by anyone yet, whatever the model believes it was told.
        $quotedOnTurn = ((array) session(self::KEY.'_quoted', []))[$quoteId] ?? null;
        if ($quotedOnTurn === null || $quotedOnTurn >= $this->turn()) {
            return ['ok' => false, 'reason' => 'not_confirmed_yet', 'order' => $quote,
                'message' => 'Read this summary to the customer — pieces, delivery charge, total, cash on delivery and the address — and wait for them to agree. Place the order on their NEXT message, once they have said yes.'];
        }

        if ($quote['total_raw'] <= 0) {
            return ['ok' => false, 'reason' => 'no_price',
                'message' => 'This order comes to nothing, which cannot be right. Give the customer the WhatsApp link so a person can check it.'];
        }

        if ($quote['total_raw'] > $this->maxTotal()) {
            return ['ok' => false, 'reason' => 'too_large',
                'message' => 'This order is above the amount I may place on my own ('.money($this->maxTotal()).'). Tell the customer a person will confirm it and give the WhatsApp link.'];
        }

        $details = $state['details'];

        if ($this->placedToday($details['phone'] ?? null) >= $this->dailyLimit()) {
            return ['ok' => false, 'reason' => 'daily_limit',
                'message' => 'That is as many orders as I can take for one customer today. Give the WhatsApp link so a person can help with another.'];
        }

        // The cap above is keyed on things the customer controls — a phone she
        // types and a session she can drop. This one is keyed on the
        // connection, which is the only thing an attacker has to spend to get
        // more of, and it is what actually bounds a script.
        if (! \Illuminate\Support\Facades\RateLimiter::attempt(
            'chat-order-ip:'.request()->ip(),
            $this->dailyLimit() + 2,
            fn () => true,
            (int) now()->diffInSeconds(now()->addDay()),
        )) {
            return ['ok' => false, 'reason' => 'daily_limit',
                'message' => 'I cannot take another order from here today. Give the customer the WhatsApp link so a person can take it.'];
        }

        // Content-based idempotency, independent of the draft: the same
        // customer, the same money, a minute ago is the same intent — a
        // replayed transcript or a retried request, not a second parcel.
        if ($twin = $this->recentTwin($details['phone'] ?? null, $quote['total_raw'])) {
            $this->write(['order_number' => $twin->order_number]);

            return ['ok' => true, 'already_placed' => true] + $this->placedPayload($twin);
        }

        try {
            $order = (new PlaceOrder($cart))->handle($details + [
                'source' => 'chat',
                // The total she agreed to, made binding inside the transaction:
                // validateLines() can reprice a line, and an order written at a
                // number she never saw is exactly what a rider argues about.
                'expected_total' => $quote['total_raw'],
            ]);
        } catch (CheckoutException $e) {
            // Stock ran out or a price moved while they were answering.
            return ['ok' => false, 'reason' => 'checkout_failed', 'message' => $e->getMessage().' Tell the customer plainly and offer to start again.'];
        } catch (\Throwable $e) {
            // The class only. An exception message here can carry the address
            // and the phone straight into laravel.log, which is the one place
            // this feature promises they never go.
            \Illuminate\Support\Facades\Log::error('[assistant] chat order failed', ['error' => $e::class]);

            return ['ok' => false, 'reason' => 'error', 'message' => 'The order could not be saved. Apologise and give the customer the phone and WhatsApp link so a person can take it.'];
        }

        // Keep the order number (that is what stops a second parcel) and drop
        // her name, number and address: the order has them now, and a home
        // address does not need to sit in a browser session, or go to OpenAI
        // in the next message, for two more hours.
        $this->write(['order_number' => $order->order_number, 'details' => []]);
        session()->forget(self::KEY.'_quoted');
        $this->cart()->clear();
        session([self::KEY.'_placed' => array_slice(
            array_merge((array) session(self::KEY.'_placed', []), [now()->getTimestamp()]), -10,
        )]);

        // Let this browser see the confirmation page, exactly as the checkout
        // page does after placing an order.
        session()->put('placed_orders', array_slice(array_unique(array_merge(
            (array) session('placed_orders', []), [$order->order_number],
        )), -5));

        return ['ok' => true] + $this->placedPayload($order);
    }

    /**
     * A chat order for the same phone and the same money, moments ago. Almost
     * always the same intent arriving twice — a resent message, a retried
     * request, a transcript replayed after a dropped connection.
     */
    protected function recentTwin(?string $phone, float $total): ?Order
    {
        if (blank($phone)) {
            return null;
        }

        return Order::where('source', 'chat')
            ->where('customer_phone', bd_phone($phone))
            ->whereBetween('total', [$total - 0.01, $total + 0.01])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()->first();
    }

    protected function placedPayload(Order $order): array
    {
        $phone = Setting::get('store_phone', config('store.phone'));

        return [
            'order_number' => $order->order_number,
            'total' => money($order->total),
            'payment' => 'Cash on delivery — pay the rider when it arrives',
            // This browser was just authorised for the confirmation page, so
            // she can open it without typing anything.
            'order_url' => route('order.confirmation', $order->order_number),
            'track_url' => route('track').'?order_number='.$order->order_number,
            'message' => Locale::isBangla()
                ? 'অর্ডারটি নেওয়া হয়েছে। গ্রাহককে অর্ডার নম্বরটি বলুন, মোট টাকা আর ক্যাশ অন ডেলিভারির কথা মনে করিয়ে দিন, এবং জানান যে টিম শীঘ্রই ফোনে কনফার্ম করবে'.($phone ? ' ('.$phone.')' : '').'।'
                : 'The order is placed. Give the customer the order number, remind them of the total and that it is cash on delivery, and tell them our team will confirm by phone shortly'.($phone ? ' ('.$phone.')' : '').'.',
        ];
    }
}
