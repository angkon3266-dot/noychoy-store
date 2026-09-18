<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\DashboardAnalytics;
use App\Services\DashboardInsights;
use App\Support\DashboardBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * The ten analytics added to the dashboard on 2026-09-18 (owner: "add other
 * analytical info on the dashboard"), as blocks.
 *
 * DashboardInsightsTest pins what each figure IS; this pins that each block
 * is on the page in its place, says something honest with nothing in the
 * store, shows its key figure with a little data, costs nothing when hidden,
 * and — because analytics are decoration — leaves the rest of the page
 * standing when one of them throws.
 *
 * Time is frozen at 10:00 UTC on 18 Sep 2026 (4 pm in Dhaka), the same
 * instant DashboardInsightsTest uses, so the hour-of-day and age arithmetic
 * is the same on every run. Nothing may leave the box: the courier balance
 * lookup lands on a fake whatever .env holds.
 */
class DashboardNewPanelsTest extends TestCase
{
    use RefreshDatabase;

    /** Every block this change added, with the $deep key it reads. */
    protected const NEW_BLOCKS = [
        'call_list' => 'callList',
        'cash_at_courier' => 'cashAtCourier',
        'delivery_speed' => 'deliverySpeed',
        'stock_health' => 'stockHealth',
        'discount_leakage' => 'discountLeakage',
        'assistant_sms' => 'assistantAndSms',
        'lead_recovery' => 'leadRecovery',
        'order_clock' => 'orderClock',
    ];

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function dashboard(string $period = '30d'): TestResponse
    {
        return $this->actingAs($this->admin())->get('/admin?period='.$period)->assertOk();
    }

    /** Rewrite a row's created_at — the models here have no factory, and the panels read that column. */
    protected function at(Model $model, Carbon $when): void
    {
        DB::table($model->getTable())->where('id', $model->id)->update(['created_at' => $when]);
    }

    protected function order(array $attrs = [], array $items = [], ?Carbon $at = null): Order
    {
        $n = 60000 + (++$this->seq);

        $order = Order::create(array_merge([
            'order_number' => (string) $n, 'customer_name' => 'Buyer '.$n, 'customer_phone' => '01712345678',
            'shipping_address' => 'House 4, Road 2', 'area' => 'Dhanmondi',
            'subtotal' => 1000, 'shipping_cost' => 0, 'discount' => 0, 'total' => 1000,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ], $attrs));

        foreach ($items as $item) {
            $order->items()->create(array_merge(['name' => 'Ring', 'price' => 1000, 'quantity' => 1, 'subtotal' => 1000], $item));
        }

        if ($at) {
            $this->at($order, $at);
        }

        return $order->fresh();
    }

    protected function history(Order $order, string $status, Carbon $at): void
    {
        DB::table('order_status_history')->insert([
            'order_id' => $order->id, 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    protected function shipment(Order $order, array $attrs, Carbon $at): Shipment
    {
        $shipment = Shipment::create(array_merge([
            'order_id' => $order->id, 'courier' => 'steadfast', 'consignment_id' => 'C'.(++$this->seq),
            'cod_amount' => $order->total, 'status' => 'pending',
        ], $attrs));

        $this->at($shipment, $at);

        return $shipment->fresh();
    }

    protected function product(array $attrs = []): Product
    {
        $n = ++$this->seq;

        return Product::create(array_merge([
            'name' => 'Piece '.$n, 'slug' => 'piece-'.$n, 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 5, 'status' => 'published',
        ], $attrs));
    }

    /** The data-block keys rendered as a card (neither hidden nor empty), in page order. */
    protected function cards(TestResponse $response): array
    {
        preg_match_all('/<section data-block="([a-z_]+)" data-span="[a-z_]+"\s+class="/', $response->getContent(), $m);

        return $m[1];
    }

    // ── On the page ──────────────────────────────────────────────────────────

    public function test_every_new_block_is_registered_with_a_partial_and_renders_with_its_title(): void
    {
        $response = $this->dashboard();
        $sequence = $this->cards($response);

        foreach (self::NEW_BLOCKS as $key => $need) {
            $block = DashboardBlocks::find($key);
            $this->assertNotNull($block, "block '{$key}' is not registered");
            $this->assertSame($need, $block['needs'], "block '{$key}' must read \$deep['{$need}']");
            $this->assertFileExists(resource_path("views/admin/dashboard/blocks/{$key}.blade.php"));
            $this->assertContains($key, $sequence, "block '{$key}' did not render as a card");
            $response->assertSee($block['title']);
        }

        // "Top products" became "Top earners" under the same key, so a saved
        // layout keeps the card where the admin put it.
        $this->assertSame('Top earners', DashboardBlocks::find('top_products')['title']);
        $this->assertSame(['topEarners', 'earnersByCategory'], DashboardBlocks::needsOf(['top_products']));
        $this->assertContains('channelEconomics', DashboardBlocks::needsOf(['sources']));
        $this->assertContains('top_products', $sequence);
        $response->assertSee('Top earners ·');
    }

    public function test_the_new_blocks_sit_where_a_never_arranged_dashboard_reads_best(): void
    {
        $keys = DashboardBlocks::keys();
        $after = fn (string $a, string $b) => array_search($b, $keys, true) === array_search($a, $keys, true) + 1;

        // The morning's work is the first full row under the chart; the three
        // money-and-stock cards follow it as one row of thirds.
        $this->assertTrue($after('orders_by_status', 'call_list'));
        $this->assertTrue($after('call_list', 'cash_at_courier'));
        $this->assertTrue($after('cash_at_courier', 'delivery_speed'));
        $this->assertTrue($after('delivery_speed', 'stock_health'));
        // Beside the figure they take apart, and with the customer cards.
        $this->assertTrue($after('profit', 'discount_leakage'));
        $this->assertTrue($after('discount_leakage', 'assistant_sms'));
        $this->assertTrue($after('retention', 'lead_recovery'));
        $this->assertTrue($after('lead_recovery', 'order_clock'));

        foreach (self::NEW_BLOCKS as $key => $need) {
            $this->assertArrayNotHasKey('legacy_panel', DashboardBlocks::find($key), "'{$key}' must not be hidden by the old ⚙ setting");
        }
    }

    public function test_each_new_block_says_something_honest_on_an_empty_store(): void
    {
        $response = $this->dashboard();

        $response
            ->assertSee('Nothing is out with the courier right now.')
            ->assertSee('No deliveries completed in this window.')
            ->assertSee('No discounts given in this window.')
            ->assertSee('No carts captured in this window.')
            ->assertSee('No orders in this window.')
            ->assertSee('No sales in this window — try a longer period.')
            ->assertSee('No conversations in this window.')
            ->assertSee('No SMS sent in this window.')
            // The call list is five sections, each with its own grey line — never a blank card.
            ->assertSee('Nothing to confirm')
            ->assertSee('No calls scheduled for today.')
            ->assertSee('No open carts from the last week.')
            ->assertSee('No birthdays or anniversaries recorded yet')
            ->assertSee('Nobody is slipping away just now.')
            // Cash in stock is always a valid card: nothing sold means no cover figure, not no card.
            ->assertSee('Cash in stock')
            ->assertSee('Weeks of cover');

        // Every new block rendered as a card, not as an empty placeholder.
        $cards = $this->cards($response);
        foreach (array_keys(self::NEW_BLOCKS) as $key) {
            $this->assertContains($key, $cards);
        }
    }

    // ── One fixture per block ────────────────────────────────────────────────

    public function test_an_unsettled_shipment_shows_as_cash_with_the_courier(): void
    {
        $order = $this->order(['status' => 'shipped', 'total' => 2500]);
        $this->shipment($order, ['status' => 'pending', 'cod_amount' => 2500], now()->subDays(2));

        $this->dashboard()
            ->assertSee('Cash with the courier')
            ->assertSee('1 parcel out, COD not yet settled')
            ->assertSee('Out for delivery')
            ->assertSee(money(2500))
            ->assertDontSee('Nothing is out with the courier right now.')
            // No Steadfast in the test box: the balance line says so rather than showing ৳0.
            ->assertSee('not connected');
    }

    public function test_delivered_orders_with_history_fill_the_speed_card(): void
    {
        Setting::put('theme', ['show_delivery_estimate' => false]);
        $t0 = Carbon::parse('2026-09-10 04:00:00', 'UTC');

        // Three inside-Dhaka orders, each booked 12 h after placing and
        // delivered two days later — enough rows for a zone median.
        foreach (range(1, 3) as $i) {
            $o = $this->order(['status' => 'delivered', 'is_inside_dhaka' => true], [], $t0);
            $this->shipment($o, ['status' => 'delivered'], $t0->copy()->addHours(12));
            $this->history($o, 'delivered', $t0->copy()->addHours(12 + 48));
        }

        $this->dashboard()
            ->assertSee('Delivery speed')
            ->assertSee('3 delivered')
            ->assertSee('median 12 h')
            ->assertSee('2 d · p90 2 d')
            ->assertSee('too few to say')     // outside Dhaka: nothing delivered there
            ->assertSee('On time')
            ->assertSee('(3 resolved)')
            ->assertDontSee('No deliveries completed in this window.');
    }

    public function test_a_ladder_order_shows_where_the_discount_went(): void
    {
        $this->order([
            'subtotal' => 2000, 'discount' => 50, 'ladder_tier' => 1,
            'ladder_rewards' => [['n' => 1, 'label' => '৳50 off', 'amount' => 50]],
            'shipping_cost' => 70, 'is_inside_dhaka' => true, 'total' => 2020,
        ], [['quantity' => 1, 'subtotal' => 2000]]);

        $this->dashboard()
            ->assertSee('Where the discounts go')
            ->assertSee('given away')
            ->assertSee('Reward ladder')
            ->assertSee(money(50))
            ->assertSee('2.5%')               // ৳50 of ৳2,000
            ->assertSee('Did the ladder pay?')
            ->assertSee('Orders per rung')
            ->assertSee('No ladder')
            ->assertDontSee('No discounts given in this window.');
    }

    public function test_an_unbooked_order_is_on_the_call_list_with_a_number_to_dial(): void
    {
        $order = $this->order(['customer_name' => 'Nusrat Jahan', 'customer_phone' => '01711000001', 'total' => 1800], [], now()->subHours(3));

        $html = $this->dashboard()->getContent();

        $this->assertStringContainsString('Who to call today', $html);
        $this->assertStringContainsString('#'.$order->order_number, $html);
        $this->assertStringContainsString('Nusrat Jahan', $html);
        $this->assertStringContainsString('href="tel:+8801711000001"', $html);
        $this->assertStringContainsString(route('admin.orders.show', $order), $html);
        // No courier check on file reads "unchecked" — the prompt to look, never a lookup from here.
        $this->assertStringContainsString('Unchecked', $html);
        Http::assertNothingSent();
        $this->assertStringContainsString(route('admin.orders.index', ['status' => 'pending,processing']), $html);
        $this->assertStringNotContainsString('Nothing to confirm', $html);
    }

    public function test_an_abandoned_cart_shows_in_leads_and_recovery(): void
    {
        $cart = AbandonedCart::create([
            'phone' => '01811111111', 'name' => 'Lead One', 'subtotal' => 3200, 'item_count' => 2,
            'last_step' => 'checkout', 'items' => [['name' => 'Ring', 'qty' => 2]],
        ]);
        $this->at($cart, now()->subDays(2));

        $this->dashboard()
            ->assertSee('Leads &amp; recovery', false)
            ->assertSee('Captured')
            ->assertSee('1 · '.money(3200))
            ->assertSee('Recovered value')
            ->assertDontSee('No carts captured in this window.');
    }

    public function test_an_sms_log_row_counts_as_sent_and_asks_for_the_rate(): void
    {
        $log = SmsLog::create([
            'phone' => '8801712345678', 'recipients' => 1, 'message' => 'Your order is on its way',
            'direction' => 'out', 'status' => 'ACCEPTD', 'provider_status' => '0',
        ]);
        $this->at($log, now()->subDay());

        $response = $this->dashboard()
            ->assertSee('Chat &amp; SMS', false)
            ->assertSee('Segments')
            ->assertSee('Broadcasts')
            ->assertDontSee('No SMS sent in this window.');

        // No per-segment rate typed yet: the card points at where to type it
        // instead of pricing the text at zero.
        $response->assertSee('set the per-SMS cost');
        $response->assertSee(route('admin.notifications.index'));

        // The panel is cached for the window; the rate is read inside it, so
        // the next page load after typing it is one cache expiry away.
        Setting::put('sms_cost_per_segment', 2);
        Cache::flush();
        $this->dashboard()->assertSee('Cost')->assertSee(money(2))->assertDontSee('set the per-SMS cost');
    }

    public function test_an_order_at_half_past_seven_utc_lights_the_one_pm_cell(): void
    {
        $this->order([], [], Carbon::parse('2026-09-18 07:30:00', 'UTC'));

        $html = $this->dashboard('7d')->getContent();

        $this->assertStringContainsString('When customers buy', $html);
        $this->assertMatchesRegularExpression('/data-strip="orders">.*?data-hour="13" data-n="1"/s', $html);
        $this->assertMatchesRegularExpression('/data-hour="12" data-n="0"/', $html);
        $this->assertStringContainsString('Best hours:</span> 1 pm', $html);
        $this->assertStringContainsString('n = 1 orders · 0 checkouts · Dhaka time', $html);
        $this->assertStringNotContainsString('No orders in this window.', $html);
    }

    public function test_a_product_with_a_cost_is_a_top_earner_with_its_profit_and_category(): void
    {
        $rings = Category::create(['name' => 'Rings', 'slug' => 'rings']);
        $ring = $this->product(['name' => 'Opal Ring', 'slug' => 'opal-ring', 'price' => 1000, 'cost_price' => 400, 'stock_quantity' => 4, 'category_id' => $rings->id]);
        $this->order([], [['product_id' => $ring->id, 'quantity' => 2, 'price' => 1000, 'subtotal' => 2000, 'cost_price' => 400]]);

        $html = $this->dashboard()->getContent();

        $this->assertStringContainsString('Top earners', $html);
        $this->assertStringContainsString('Opal Ring', $html);
        $this->assertStringContainsString(route('admin.products.edit', 'opal-ring'), $html);
        $this->assertStringContainsString(money(1200), $html);           // 2 × (1000 − 400)
        $this->assertStringContainsString('· 60%', $html);
        $this->assertStringContainsString('4 left · 60 d left', $html);   // 4 on the shelf at 2 per 30 days
        $this->assertStringContainsString('2 sold · '.money(2000).' revenue', $html);
        $this->assertStringNotContainsString('No sales in this window', $html);

        // The Products / By category toggle, and the category ranking behind it.
        $this->assertStringContainsString('>Products</button>', $html);
        $this->assertStringContainsString('>By category</button>', $html);
        $this->assertStringContainsString('Rings', $html);
        $this->assertStringContainsString(money(1600).' on the shelf', $html);   // 4 × ৳400 at cost
    }

    public function test_the_sources_row_carries_profit_per_order_and_delivery(): void
    {
        $this->order(['source_channel' => 'facebook_ads', 'status' => 'delivered', 'total' => 1000], [['subtotal' => 1000, 'cost_price' => 400]], now()->subDays(3));

        $html = $this->dashboard()->getContent();

        $this->assertStringContainsString('Where visitors come from', $html);
        $this->assertStringContainsString('Facebook Ads', $html);
        $this->assertStringContainsString('Profit/order', $html);
        $this->assertStringContainsString(money(600), $html);
        // One resolved order is too few for a delivered rate: a dash and the n, not 100%.
        $this->assertMatchesRegularExpression('/Delivered <span class="font-medium">—<\/span> <span class="text-ink-700\/40">\(1 resolved\)/', $html);
        $this->assertStringContainsString('First-time buyers', $html);
        $this->assertStringContainsString('Margin <span class="font-medium">60%', $html);
    }

    // ── Cost and resilience ──────────────────────────────────────────────────

    public function test_hidden_new_blocks_are_not_computed(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['dashboard_layout' => [
            'order' => DashboardBlocks::keys(),
            'hidden' => array_merge(array_keys(self::NEW_BLOCKS), ['top_products', 'sources']),
        ]])->save();

        $this->partialMock(DashboardInsights::class, function ($mock) {
            foreach ([
                'callList', 'cashAtCourier', 'deliverySpeed', 'stockHealth', 'discountLeakage',
                'channelEconomics', 'topEarners', 'earnersByCategory', 'leadRecovery', 'assistantAndSms', 'orderClock',
            ] as $method) {
                $mock->shouldNotReceive($method);
            }
        });

        $response = $this->actingAs($admin)->get('/admin?period=30d')->assertOk();

        $deep = $response->viewData('deep');
        foreach (array_merge(array_values(self::NEW_BLOCKS), ['channelEconomics', 'topEarners', 'earnersByCategory']) as $key) {
            $this->assertArrayHasKey($key, $deep);
            $this->assertNull($deep[$key], "\$deep['{$key}'] was computed for a hidden block");
        }

        // The card headings are gone; the block TITLES remain on the
        // title-only placeholders arrange mode uses to put a block back.
        $response->assertDontSee('Cash with the courier</h2>', false);
        $response->assertDontSee('tap a number to dial', false);
        $response->assertDontSee('Nothing to confirm', false);
        $response->assertSee('Revenue &amp; profit</h2>', false);
    }

    public function test_one_insight_throwing_costs_that_block_and_nothing_else(): void
    {
        $this->order(['status' => 'shipped']);

        $insights = Mockery::mock(DashboardInsights::class, [app(DashboardAnalytics::class)])->makePartial();
        $insights->shouldReceive('cashAtCourier')->andThrow(new \RuntimeException('courier table is having a day'));
        $this->instance(DashboardInsights::class, $insights);

        $response = $this->dashboard();

        // The failed block is an empty placeholder; every other card, new and old, is there.
        // A failed panel says so in one line rather than vanishing as though
        // there were nothing to show (2026-09-18 review).
        $this->assertMatchesRegularExpression('/<section data-block="cash_at_courier" data-span="third"\s+class="[^"]*lg:col-span-4[^"]*"/', $response->getContent());
        $this->assertStringContainsString('Couldn’t be computed right now', $response->getContent());
        $response->assertSee('Cash with the courier</h2>', false);
        $this->assertFalse($response->viewData('deep')['cashAtCourier']);

        foreach (['delivery_speed', 'stock_health', 'call_list', 'discount_leakage', 'assistant_sms', 'lead_recovery', 'order_clock', 'top_products', 'sources', 'profit', 'recent_orders'] as $key) {
            $this->assertContains($key, $this->cards($response), "'{$key}' should still render");
        }
        $response->assertSee('Delivery speed');
        $response->assertSee('Who to call today');
    }

    // ── The one setting this needed ──────────────────────────────────────────

    public function test_the_per_segment_sms_cost_is_typed_on_the_notifications_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('name="sms_cost_per_segment"', false);

        $this->actingAs($this->admin())->post(route('admin.notifications.abandoned-sms'), [
            'abandoned_sms_delay_minutes' => 60, 'abandoned_sms_max_hours' => 48, 'abandoned_sms_per_run' => 50,
            'sms_cost_per_segment' => '0.25',
        ])->assertRedirect();
        $this->assertSame(0.25, (float) Setting::get('sms_cost_per_segment'));

        // Blank clears it, so a rate typed by mistake does not keep pricing texts.
        $this->actingAs($this->admin())->post(route('admin.notifications.abandoned-sms'), [
            'abandoned_sms_delay_minutes' => 60, 'abandoned_sms_max_hours' => 48, 'abandoned_sms_per_run' => 50,
            'sms_cost_per_segment' => '',
        ])->assertRedirect();
        $this->assertNull(Setting::get('sms_cost_per_segment'));

        $this->actingAs($this->admin())->post(route('admin.notifications.abandoned-sms'), [
            'abandoned_sms_delay_minutes' => 60, 'abandoned_sms_max_hours' => 48, 'abandoned_sms_per_run' => 50,
            'sms_cost_per_segment' => 'lots',
        ])->assertSessionHasErrors('sms_cost_per_segment');
    }
}
