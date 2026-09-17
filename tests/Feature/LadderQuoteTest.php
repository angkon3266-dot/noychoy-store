<?php

namespace Tests\Feature;

use App\Http\Middleware\ReadOnlySession;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\CartService;
use App\Services\Meta\MetaSettings;
use App\Support\GiftLadder;
use App\Support\Storefront\LadderQuoteData;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The reward ladder, quoted before anything is added.
 *
 * The owner's call on 17 Sep 2026: the ৳50 off the first piece was told to
 * nobody until the cart, and Frequently bought together priced the bundle as if
 * the ladder did not exist. The product page now carries `ladderQuote` (one row
 * per quantity, per price) and `fbt.ladder` (every combination of ticked
 * tiles), and GET /cart/ladder-quote refreshes both after the cart moves.
 *
 * These pin that the quotes are the cart's own numbers — the real solver on a
 * copy of the cart, stacking rungs, counting what is already in, opening
 * percent, delivery and gift rungs — that quoting never touches the cart, the
 * funnel or Meta, and that the shared ladder prop names each rung's value.
 * Every ladder here is set explicitly: the owner edits the live one in
 * Admin → Offers, so nothing may lean on the shipped defaults.
 */
class LadderQuoteTest extends TestCase
{
    use RefreshDatabase;

    /** The owner's live ladder on the day: seven cumulative flat rungs. */
    protected const FLAT = [
        ['threshold' => 1, 'type' => 'flat', 'value' => 50],
        ['threshold' => 2, 'type' => 'flat', 'value' => 60],
        ['threshold' => 3, 'type' => 'flat', 'value' => 70],
        ['threshold' => 4, 'type' => 'flat', 'value' => 80],
        ['threshold' => 5, 'type' => 'flat', 'value' => 90],
        ['threshold' => 6, 'type' => 'flat', 'value' => 100],
        ['threshold' => 7, 'type' => 'flat', 'value' => 150],
    ];

    protected function product(string $name, float $price, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'status' => 'published',
            'price' => $price,
            'manage_stock' => false,
            'in_stock' => true,
        ], $extra));
    }

    protected function collection(string $name, array $products): Collection
    {
        $c = Collection::create(['name' => $name, 'type' => 'manual', 'is_active' => true]);
        foreach (array_values($products) as $i => $p) {
            $c->products()->attach($p->id, ['position' => $i]);
        }

        return $c;
    }

    protected function ladder(array $tiers, ?Collection $gifts = null): void
    {
        Setting::put('gift_ladder_enabled', true);
        Setting::put('gift_ladder_tiers', $tiers);
        Setting::put('gift_ladder_gifts_collection_id', $gifts?->id ?? 0);
        app()->forgetInstance(GiftLadder::class);
    }

    protected function cart(): CartService
    {
        return app(CartService::class);
    }

    /** The quote endpoint, fetched the way the page fetches it. */
    protected function quote(Product $product, array $fbt = [])
    {
        return $this->getJson(route('cart.ladder-quote', ['product' => $product->id, 'fbt' => array_map(fn ($p) => $p->id, $fbt)]));
    }

    public function test_an_empty_cart_is_quoted_the_first_piece_and_the_rungs_stacked_on_it(): void
    {
        $this->ladder(self::FLAT);
        $tile = $this->product('Pearl studs', 900);
        $earrings = $this->product('Turquoise earrings', 1450, ['cross_sell_ids' => [$tile->id]]);

        $response = $this->get(route('product.show', $earrings))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ladderQuote.for_units', 0)
                // Enough rows to climb from an empty cart to the top rung.
                ->where('ladderQuote.max_qty', 7)
                ->where('ladderQuote.default_price_key', '1450.00')
                ->has('ladderQuote.by_price', 1)
                ->where('fbt.ladder.for_units', 0)
                ->has('fbt.ladder.subsets', 3));

        // Props travel as JSON, so a whole ৳50.0 arrives as 50.
        $rows = $response->inertiaProps('ladderQuote')['by_price']['1450.00'];
        $this->assertCount(7, $rows);

        $this->assertSame([
            'qty' => 1, 'saving' => 50, 'saving_text' => '৳50', 'first_piece' => 1,
            'opened' => ['৳50 off'], 'free_delivery_unlocked' => false, 'gift_unlocked' => false,
        ], $rows[0]);

        // Two pieces hold rungs 1 and 2 at once: ৳50 + ৳60.
        $this->assertSame(2, $rows[1]['qty']);
        $this->assertSame(110, $rows[1]['saving']);
        $this->assertSame('৳110', $rows[1]['saving_text']);
        $this->assertSame(1, $rows[1]['first_piece']);
        $this->assertSame(['৳50 off', '৳60 off'], $rows[1]['opened']);

        // The whole ladder at seven pieces.
        $this->assertSame(600, $rows[6]['saving']);

        // Frequently bought together: each tile alone is the first piece, and
        // ticked together the page's own piece (shown first) takes rung 1
        // while the tile beside it takes rung 2.
        $subsets = $response->inertiaProps('fbt.ladder.subsets');
        $both = $earrings->id.'-'.$tile->id;
        $this->assertSame([(string) $earrings->id, (string) $tile->id, $both], array_map('strval', array_keys($subsets)));
        $this->assertSame(50, $subsets[$tile->id]['saving']);
        $this->assertSame([$tile->id => 50], $subsets[$tile->id]['per_item']);
        $this->assertSame([
            'saving' => 110, 'saving_text' => '৳110',
            'per_item' => [$earrings->id => 50, $tile->id => 60],
            'free_delivery_unlocked' => false, 'gift_unlocked' => false,
        ], $subsets[$both]);
    }

    public function test_a_piece_already_in_the_cart_moves_every_quote_up_a_rung(): void
    {
        $this->ladder(self::FLAT);
        $tile = $this->product('Pearl studs', 900);
        $earrings = $this->product('Turquoise earrings', 1450, ['cross_sell_ids' => [$tile->id]]);
        $this->cart()->add($earrings, null, 1);

        $response = $this->get(route('product.show', $earrings))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ladderQuote.for_units', 1)
                ->where('ladderQuote.max_qty', 6)
                ->where('fbt.ladder.for_units', 1));

        $rows = $response->inertiaProps('ladderQuote')['by_price']['1450.00'];
        $this->assertSame(60, $rows[0]['saving']);
        $this->assertSame(2, $rows[0]['first_piece']);
        $this->assertSame(['৳60 off'], $rows[0]['opened']);
        $this->assertSame(130, $rows[1]['saving']);

        $both = $response->inertiaProps('fbt.ladder.subsets')[$earrings->id.'-'.$tile->id];
        $this->assertSame(130, $both['saving']);
        $this->assertSame([$earrings->id => 60, $tile->id => 70], $both['per_item']);
    }

    public function test_a_percent_rung_counts_what_is_already_in_the_cart_and_each_variant_price(): void
    {
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 2, 'type' => 'percent', 'value' => 10],
            ['threshold' => 3, 'type' => 'flat', 'value' => 70],
        ]);
        $this->cart()->add($this->product('Gold ring', 1000, ['status' => 'draft']), null, 1);

        $bangle = $this->product('Bangle', 500, ['has_variants' => true]);
        ProductVariant::create(['product_id' => $bangle->id, 'sku' => 'B-L', 'attributes' => ['Size' => 'L'], 'price' => 800, 'is_active' => true]);
        ProductVariant::create(['product_id' => $bangle->id, 'sku' => 'B-M', 'attributes' => ['Size' => 'M'], 'price' => null, 'is_active' => true]);
        ProductVariant::create(['product_id' => $bangle->id, 'sku' => 'B-S', 'attributes' => ['Size' => 'S'], 'price' => 900, 'is_active' => false]);

        $quote = $this->get(route('product.show', $bangle))->assertOk()->inertiaProps('ladderQuote');

        // The product's own price, and the one active variant priced apart.
        // The unpriced variant is the product's price; the inactive one is not
        // for sale.
        $this->assertSame('500.00', $quote['default_price_key']);
        $this->assertSame(['500.00', '800.00'], array_keys($quote['by_price']));
        $this->assertSame(2, $quote['max_qty']);

        // The 10% opens on the second piece and takes its share of the whole
        // paid cart — the ৳1,000 ring already in it as well as the new piece.
        $this->assertSame(150, $quote['by_price']['500.00'][0]['saving']);            // 10% of 1,500
        $this->assertSame(['10% off'], $quote['by_price']['500.00'][0]['opened']);
        $this->assertSame(180, $quote['by_price']['800.00'][0]['saving']);            // 10% of 1,800
        $this->assertSame(270, $quote['by_price']['500.00'][1]['saving']);            // 10% of 2,000 + ৳70

        // A flat ladder saves the same on any price, so there is one list.
        $this->ladder(self::FLAT);
        $this->assertSame(['500.00'], array_keys(LadderQuoteData::product($bangle->fresh())['by_price']));
    }

    public function test_the_quote_says_which_piece_opens_free_delivery(): void
    {
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 2, 'type' => 'free_delivery', 'value' => null],
            ['threshold' => 3, 'type' => 'flat', 'value' => 70],
        ]);
        $ring = $this->product('Ring', 1000);

        $rows = $this->quote($ring)->assertOk()->json('ladderQuote.by_price')['1000.00'];
        $this->assertFalse($rows[0]['free_delivery_unlocked']);
        $this->assertTrue($rows[1]['free_delivery_unlocked']);
        $this->assertSame(['৳50 off', 'Free delivery'], $rows[1]['opened']);
        // Delivery is not money off the price.
        $this->assertEquals(50, $rows[1]['saving']);
        $this->assertTrue($rows[2]['free_delivery_unlocked']);

        // Once the cart already ships free, no quote claims to open it again.
        $this->cart()->add($ring, null, 2);
        $rows = $this->quote($ring)->json('ladderQuote.by_price')['1000.00'];
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['free_delivery_unlocked']);
        $this->assertEquals(70, $rows[0]['saving']);
    }

    public function test_a_gift_piece_is_quoted_free_and_does_not_climb_the_ladder(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 2, 'type' => 'free_gift', 'value' => null],
            ['threshold' => 3, 'type' => 'flat', 'value' => 70],
        ], $this->collection('Free gifts', [$gift]));
        $this->cart()->add($this->product('Ring', 1000, ['status' => 'draft']), null, 2);

        $quote = $this->get(route('product.show', $gift))->assertOk()->inertiaProps('ladderQuote');

        // Two paid pieces reach the gift rung; the top rung is one PAID piece
        // away, but the first stud goes free and stops counting, so it takes
        // two studs to get there.
        $this->assertSame(2, $quote['for_units']);
        $this->assertSame(2, $quote['max_qty']);

        [$one, $two] = $quote['by_price']['500.00'];
        $this->assertEquals(500, $one['saving']);                 // the stud itself, free
        $this->assertSame([], $one['opened']);                     // …and no rung climbed
        $this->assertFalse($one['gift_unlocked']);                 // the rung was already open
        $this->assertSame(3, $one['first_piece']);
        $this->assertEquals(570, $two['saving']);                  // the second stud is paid: rung 3
        $this->assertSame(['৳70 off'], $two['opened']);
    }

    public function test_with_the_ladder_off_nothing_is_quoted_anywhere(): void
    {
        $tile = $this->product('Pearl studs', 900);
        $earrings = $this->product('Turquoise earrings', 1450, ['cross_sell_ids' => [$tile->id]]);

        $this->get(route('product.show', $earrings))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ladderQuote', null)
                ->has('fbt.items', 2)
                ->where('fbt.ladder', null));

        $this->quote($earrings, [$earrings, $tile])->assertOk()
            ->assertExactJson(['ladderQuote' => null, 'fbtLadder' => null]);
    }

    public function test_a_piece_that_cannot_be_bought_carries_no_quote(): void
    {
        $this->ladder(self::FLAT);
        $sold = $this->product('Sold out ring', 1000, ['in_stock' => false]);

        $this->get(route('product.show', $sold))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ladderQuote', null));

        // A pre-order can be bought, so it is quoted.
        $sold->update(['is_preorder' => true]);
        $this->get(route('product.show', $sold))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ladderQuote.for_units', 0));
    }

    public function test_quoting_leaves_the_cart_exactly_as_it_was(): void
    {
        $this->ladder(self::FLAT);
        $tile = $this->product('Pearl studs', 900);
        $earrings = $this->product('Turquoise earrings', 1450, ['cross_sell_ids' => [$tile->id]]);
        $cart = $this->cart();
        $cart->add($tile, null, 2);

        $items = $cart->items()->all();
        $discount = $cart->discount();

        $page = route('product.show', $earrings);
        $this->get($page)->assertOk();
        $this->quote($earrings, [$earrings, $tile])->assertOk();
        // Fetched without the XHR header, as a careless client would.
        $this->get(route('cart.ladder-quote', ['product' => $earrings->id]))->assertOk();

        $cart = $this->cart();
        $this->assertSame($items, $cart->items()->all());
        $this->assertSame(2, $cart->count());
        $this->assertSame($discount, $cart->discount());
        $this->assertSame(110.0, $discount);

        // A quote is never somewhere back() should return to.
        $this->assertSame($page, session()->previousUrl());
    }

    public function test_the_endpoint_answers_in_the_page_shape_and_tracks_nothing(): void
    {
        Setting::put('meta_integration', [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => Crypt::encryptString('test-token'),
        ]);
        app()->forgetInstance(MetaSettings::class);
        Http::fake();

        $this->ladder(self::FLAT);
        $tile = $this->product('Pearl studs', 900);
        $earrings = $this->product('Turquoise earrings', 1450, ['cross_sell_ids' => [$tile->id]]);
        $this->cart()->add($tile, null, 1);

        // A plain GET, which the pageview middleware would otherwise count.
        $json = $this->get(route('cart.ladder-quote', ['product' => $earrings->id, 'fbt' => [$earrings->id, $tile->id]]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('ladderQuote.for_units', 1)
            ->assertJsonPath('fbtLadder.for_units', 1)
            ->json();

        Http::assertNothingSent();
        $this->assertSame(0, Visit::count());

        // The same numbers the page itself was given. Asked after the quote:
        // the page's own tracking runs when its request ends, and would
        // otherwise be counted against the quote.
        $props = $this->get(route('product.show', $earrings))->assertOk()->inertiaProps();
        $this->assertSame($props['ladderQuote'], $json['ladderQuote']);
        $this->assertSame($props['fbt']['ladder'], $json['fbtLadder']);
    }

    public function test_the_endpoint_handles_a_missing_or_unpublished_product(): void
    {
        $this->ladder(self::FLAT);
        $tile = $this->product('Pearl studs', 900);
        $draft = $this->product('Withdrawn ring', 1000, ['status' => 'draft']);

        // Gone since the page loaded: no promise for it, but the other tiles
        // can still be bought and are still quoted. A draft tile is skipped.
        $this->quote($draft, [$draft, $tile])->assertOk()
            ->assertJsonPath('ladderQuote', null)
            ->assertJsonPath('fbtLadder.subsets.'.$tile->id.'.saving', 50)
            ->assertJsonCount(1, 'fbtLadder.subsets');

        $this->getJson(route('cart.ladder-quote', ['product' => 999999]))->assertOk()
            ->assertExactJson(['ladderQuote' => null, 'fbtLadder' => null]);

        $this->getJson(route('cart.ladder-quote'))->assertStatus(422);
        $this->getJson(route('cart.ladder-quote', ['product' => $tile->id, 'fbt' => [1, 2, 3, 4, 5]]))->assertStatus(422);
    }

    /**
     * The one case where a piece lowers the ladder's total: a cheaper gift
     * takes the free slot from a dearer one. No tile may then show a price
     * above its own, and the tiles must still add up to the bundle.
     */
    public function test_a_tile_never_shows_a_negative_saving_and_the_tiles_add_up_to_the_bundle(): void
    {
        $dear = $this->product('Dear gift', 900);
        $cheap = $this->product('Cheap gift', 500);
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 3, 'type' => 'free_gift', 'value' => null],
            ['threshold' => 4, 'type' => 'flat', 'value' => 100],
        ], $this->collection('Free gifts', [$dear, $cheap]));
        $this->cart()->add($this->product('Ring', 1000, ['status' => 'draft']), null, 3);

        $subsets = $this->quote($dear, [$dear, $cheap])->assertOk()->json('fbtLadder.subsets');

        // Alone, the dear gift goes free (৳900) and the cheap one too (৳500).
        $this->assertEquals(900, $subsets[$dear->id]['saving']);
        $this->assertEquals(500, $subsets[$cheap->id]['saving']);

        // Together the cheap one is the free unit and the dear one is paid,
        // opening rung 4: ৳500 + ৳100. The cheap tile's own share would be
        // −৳300; it shows nothing, and the dear tile carries the rest.
        $both = $subsets[$dear->id.'-'.$cheap->id];
        $this->assertEquals(600, $both['saving']);
        $this->assertEquals([$dear->id => 600, $cheap->id => 0], $both['per_item']);
        $this->assertEquals($both['saving'], array_sum($both['per_item']));
    }

    public function test_the_shared_ladder_and_the_cart_json_name_each_rungs_value(): void
    {
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 2, 'type' => 'percent', 'value' => 2.5],
            ['threshold' => 3, 'type' => 'free_delivery', 'value' => null],
        ]);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('ladder.units', 0)
            ->where('ladder.tiers.0.value', 50)
            ->where('ladder.tiers.0.label', '৳50 off')
            ->where('ladder.tiers.1.value', 2.5)
            ->where('ladder.tiers.2.value', null)
            ->has('ladder.tiers.2.short'));

        $ring = $this->product('Ring', 1000);
        $this->postJson(route('cart.add', $ring), ['qty' => 1])->assertOk()
            ->assertJsonPath('gift.units', 1)
            ->assertJsonPath('gift.tiers.0.value', 50)
            ->assertJsonPath('gift.tiers.0.unlocked', true)
            ->assertJsonPath('gift.tiers.1.threshold', 2);
    }

    public function test_a_quote_reads_the_database_per_price_not_per_row(): void
    {
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 20, 'type' => 'flat', 'value' => 500],
        ]);
        $this->cart()->add($this->product('Ring', 1000), null, 1);
        $product = Product::find($this->product('Bangle', 700)->id);
        app(GiftLadder::class)->enabled();   // settings are read once per request anyway

        DB::enableQueryLog();
        $quote = LadderQuoteData::product($product);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(19, $quote['max_qty']);
        $this->assertEquals(500, $quote['by_price']['700.00'][18]['saving']);
        $this->assertLessThan(5, $queries, "quote queried per row: {$queries} queries");
    }

    /**
     * Past `max_qty` the page reuses the last row. That is right for a flat
     * rung, paid once, but a percent rung takes its share of every piece — so
     * the quote names the open percent and the page adds it per extra piece.
     */
    public function test_past_the_last_row_a_percent_rung_keeps_taking_its_share_of_each_piece(): void
    {
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 2, 'type' => 'percent', 'value' => 10],
        ]);
        $ring = $this->product('Ring', 1000);

        $quote = $this->quote($ring)->assertOk()->json('ladderQuote');
        $this->assertSame(2, $quote['max_qty']);
        $this->assertEquals(10, $quote['percent']);
        $last = $quote['by_price']['1000.00'][1];
        $this->assertEquals(250, $last['saving']);                 // ৳50 + 10% of 2,000

        // Three pieces, worked out as ladderPriceLine() does: the last row,
        // plus the open percent of the one piece beyond it. It used to show
        // the last row's ৳250 alone.
        $page = $last['saving'] + (3 - $quote['max_qty']) * 1000 * $quote['percent'] / 100;
        $this->assertEquals(350, $page);

        // …and that is what the cart takes once the three are in.
        $cart = $this->cart();
        $cart->add($ring, null, 3);
        $this->assertSame(350.0, $cart->giftDiscount());

        // A percent rung the rows never reach — MAX_QTY cuts a tall ladder
        // short — is not promised to the pieces beyond them.
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 30, 'type' => 'percent', 'value' => 10],
        ]);
        $cart->clear();
        $quote = LadderQuoteData::product($ring->fresh());
        $this->assertSame(LadderQuoteData::MAX_QTY, $quote['max_qty']);
        $this->assertSame(0.0, $quote['percent']);
    }

    /**
     * The page keeps its quote in step by comparing the cart the quote was
     * made for with the cart it has now. The paid-piece count could not tell a
     * free gift going in or out, so the comparison is by signature — and the
     * shared ladder, the cart JSON and both quotes must stamp the same one.
     */
    public function test_the_quotes_and_the_cart_share_one_signature_and_a_free_gift_moves_it(): void
    {
        $gift = $this->product('Gift stud', 500);
        $this->ladder([
            ['threshold' => 1, 'type' => 'flat', 'value' => 50],
            ['threshold' => 2, 'type' => 'free_gift', 'value' => null],
            ['threshold' => 3, 'type' => 'flat', 'value' => 70],
        ], $this->collection('Free gifts', [$gift]));
        $ring = $this->product('Ring', 1000, ['cross_sell_ids' => [$gift->id]]);
        $this->cart()->add($ring, null, 2);

        $props = $this->get(route('product.show', $ring))->assertOk()->inertiaProps();
        $before = $props['ladder']['signature'];
        $this->assertIsString($before);
        $this->assertSame($before, $props['ladderQuote']['for_signature']);
        $this->assertSame($before, $props['fbt']['ladder']['for_signature']);

        // The stud goes in free: the paid count stands still, the signature
        // does not.
        $added = $this->postJson(route('cart.add', $gift), ['qty' => 1])->assertOk()
            ->assertJsonPath('gift.units', 2)
            ->json('gift.signature');
        $this->assertNotSame($before, $added);

        $this->quote($ring, [$ring, $gift])->assertOk()
            ->assertJsonPath('ladderQuote.for_units', 2)
            ->assertJsonPath('ladderQuote.for_signature', $added)
            ->assertJsonPath('fbtLadder.for_signature', $added);

        // Out again, and the cart — and its signature — are as they were.
        $this->deleteJson(route('cart.remove'), ['key' => CartService::lineFor($gift, null, 1)['key']])->assertOk()
            ->assertJsonPath('gift.units', 2)
            ->assertJsonPath('gift.signature', $before);
    }

    /**
     * Laravel saves the whole session at the end of a request, and the
     * database driver rewrites the payload it read at the start. The quote is
     * fetched right after a cart change, so a remove saved while it was being
     * worked out would be undone by the quote's older copy. It must not write.
     */
    public function test_a_quote_never_saves_its_copy_of_the_session_over_a_remove_made_meanwhile(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        $this->ladder(self::FLAT);
        $ring = $this->product('Ring', 1000);
        $bangle = $this->product('Bangle', 700);

        $this->postJson(route('cart.add', $ring), ['qty' => 1])->assertOk();
        $session = DB::table('sessions')->sole();
        $ringOnly = $session->payload;
        // One browser from here on: JSON requests carry her session cookie.
        $this->withCredentials()->withCookie(config('session.cookie'), $session->id);

        $this->postJson(route('cart.add', $bangle), ['qty' => 1])->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(1, DB::table('sessions')->count());
        $session = DB::table('sessions')->sole();

        // Her remove of the bangle lands while the quote is at work: saved to
        // the row after the quote has read the session, before it answers.
        $removed = false;
        DB::listen(function ($query) use (&$removed, $session, $ringOnly) {
            if (! $removed && str_contains($query->sql, 'products')) {
                $removed = true;
                DB::table('sessions')->where('id', $session->id)->update(['payload' => $ringOnly]);
            }
        });

        $this->travel(5)->minutes();
        $this->quote($ring)->assertOk()->assertJsonPath('ladderQuote.for_units', 2);
        $this->assertTrue($removed);

        $row = DB::table('sessions')->where('id', $session->id)->first();
        $this->assertSame($ringOnly, $row->payload);
        $this->assertEquals($session->last_activity, $row->last_activity);

        // The next request sees one piece, and an ordinary request does save.
        $this->getJson(route('cart.mini'))->assertOk()->assertJsonPath('count', 1);
        $this->assertGreaterThan($session->last_activity, DB::table('sessions')->where('id', $session->id)->value('last_activity'));
    }

    /**
     * The read-only switch has to come after the session is started and before
     * the throttle: a 429 is saved like any other response. Laravel sorts the
     * throttle ahead of route-binding middleware, which would carry it past
     * the switch, so the route drops SubstituteBindings — this pins the order.
     */
    public function test_the_quote_route_goes_read_only_after_the_session_starts_and_before_the_throttle(): void
    {
        app(HttpKernel::class);   // puts the middleware groups on the router
        $router = app('router');
        $stack = array_map(
            fn ($m) => is_string($m) ? explode(':', $m)[0] : $m,
            $router->gatherRouteMiddleware($router->getRoutes()->getByName('cart.ladder-quote')),
        );

        $session = array_search(StartSession::class, $stack, true);
        $readOnly = array_search(ReadOnlySession::class, $stack, true);
        $throttle = array_search(ThrottleRequests::class, $stack, true);

        $this->assertNotFalse($session);
        $this->assertNotFalse($readOnly);
        $this->assertNotFalse($throttle);
        $this->assertLessThan($readOnly, $session);
        $this->assertLessThan($throttle, $readOnly);
    }
}
