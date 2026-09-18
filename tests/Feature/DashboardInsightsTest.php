<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\AbandonedCartContact;
use App\Models\AssistantConversation;
use App\Models\CallReminder;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CourierCheck;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\SmsLog;
use App\Services\DashboardAnalytics;
use App\Services\DashboardInsights;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The second layer of dashboard analytics (owner, 2026-09-18: "add other
 * analytical info on the dashboard"). Each panel's definition is pinned here
 * with a small, hand-built store, so the view can code against the shapes
 * and a later change to a definition has to say so in a test.
 *
 * Time is frozen at 10:00 UTC on 18 Sep 2026 — 4 pm in Dhaka — so windows,
 * ages and the Dhaka-day arithmetic are the same on every run.
 */
class DashboardInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));
        Setting::flushMemo();

        // The suite reads .env, where the courier and SMS keys may well be
        // real. Nothing here may leave the box: a configured Steadfast makes
        // cashAtCourier() ask for the balance, and that must land on a fake.
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function insights(): DashboardInsights
    {
        return app(DashboardInsights::class);
    }

    /** What the service wrote to the cache for a given key. */
    protected function cachedPayload(string $key): mixed
    {
        return Cache::get('dash.ins.v1.'.$key, 'ABSENT');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** Rewrite a row's created_at — the factories here have none, and the panels read that column. */
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

    protected function product(array $attrs = [], ?Carbon $at = null): Product
    {
        $n = ++$this->seq;

        $product = Product::create(array_merge([
            'name' => 'Piece '.$n, 'slug' => 'piece-'.$n, 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 0, 'status' => 'published',
        ], $attrs));

        if ($at) {
            $this->at($product, $at);
        }

        return $product->fresh();
    }

    protected function customer(array $attrs = []): Customer
    {
        $n = ++$this->seq;

        // forceCreate: the occasion stamps (*_wished_at) are not fillable.
        return Customer::query()->forceCreate(array_merge([
            'name' => 'Customer '.$n,
            'phone' => '0171'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        ], $attrs));
    }

    protected function cart(array $attrs = [], ?Carbon $at = null): AbandonedCart
    {
        $n = ++$this->seq;

        $cart = AbandonedCart::create(array_merge([
            'phone' => '0181'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'name' => 'Lead '.$n,
            'subtotal' => 1000, 'item_count' => 1, 'last_step' => 'checkout',
            'items' => [['name' => 'Ring', 'qty' => 1]],
        ], $attrs));

        $this->at($cart, $at ?? now()->subDays(3));

        return $cart->fresh();
    }

    protected function check(string $phone, float $ratio, int $parcels, ?Carbon $checkedAt = null): CourierCheck
    {
        return CourierCheck::create([
            'phone' => $phone, 'payload' => ['ok' => true],
            'success_ratio' => $ratio, 'total_parcel' => $parcels,
            'checked_at' => $checkedAt ?? now()->subDay(),
        ]);
    }

    protected function sms(array $attrs, ?Carbon $at = null): SmsLog
    {
        $log = SmsLog::create(array_merge([
            'phone' => '8801712345678', 'recipients' => 1, 'message' => 'Hello there',
            'direction' => 'out', 'status' => 'ACCEPTD', 'provider_status' => '0',
        ], $attrs));

        $this->at($log, $at ?? now()->subDay());

        return $log;
    }

    // ── 1. Cash at courier ───────────────────────────────────────────────────

    public function test_cash_at_courier_splits_live_stale_rebooked_and_collected(): void
    {
        $range = DateRange::preset('30d');

        // Booked, still in review after a day: live, waiting for pickup, ≤3 d.
        $a = $this->order(['status' => 'booked', 'total' => 1000]);
        $this->shipment($a, ['status' => 'in_review', 'cod_amount' => 1000], now()->subDay());

        // Out for delivery for ten days: live, on the road, over 7 d. Its
        // earlier consignment was replaced yesterday and still delivered —
        // that is a re-booking with a parcel the owner must cancel.
        $b = $this->order(['status' => 'shipped', 'total' => 2000]);
        $this->shipment($b, ['status' => 'pending', 'cod_amount' => 2000], now()->subDays(10));
        $this->shipment($b, [
            'status' => 'delivered', 'cod_amount' => 2000,
            'superseded_at' => now()->subDay(), 'delivered_after_superseded_at' => now()->subHours(2),
        ], now()->subDays(12));

        // The rider proposed a delivery the courier has not approved: unsettled, 5 d out.
        $f = $this->order(['status' => 'shipped', 'total' => 300]);
        $this->shipment($f, ['status' => 'delivered_approval_pending', 'cod_amount' => 300], now()->subDays(5));

        // Delivered by hand while the consignment is still open at Steadfast: stale.
        $c = $this->order(['status' => 'delivered', 'total' => 500]);
        $this->shipment($c, ['status' => 'pending', 'cod_amount' => 500], now()->subDays(4));

        // Delivered and settled eight days ago (a repeat report must not count twice).
        $d = $this->order(['status' => 'delivered', 'total' => 1500]);
        $this->shipment($d, ['status' => 'delivered', 'cod_amount' => 1500], now()->subDays(9));
        $this->history($d, 'delivered', now()->subDays(8));
        $this->history($d, 'delivered', now()->subDays(2));

        // Delivered before the window: not collected in it.
        $e = $this->order(['status' => 'delivered', 'total' => 999]);
        $this->history($e, 'delivered', now()->subDays(40));

        // A deleted order's consignment is nobody's money.
        $g = $this->order(['status' => 'shipped', 'total' => 4000]);
        $this->shipment($g, ['status' => 'pending', 'cod_amount' => 4000], now()->subDays(2));
        $g->delete();

        $cash = $this->insights()->cashAtCourier($range);

        $this->assertSame(3, $cash['live']['count']);
        $this->assertSame(3300.0, $cash['live']['amount']);
        $this->assertSame(['count' => 1, 'amount' => 1000.0], $cash['live']['stages']['booked_waiting']);
        $this->assertSame(['count' => 2, 'amount' => 2300.0], $cash['live']['stages']['out_for_delivery']);
        $this->assertSame(['count' => 1, 'amount' => 1000.0], $cash['live']['ages']['le3']);
        $this->assertSame(['count' => 1, 'amount' => 300.0], $cash['live']['ages']['d4_7']);
        $this->assertSame(['count' => 1, 'amount' => 2000.0], $cash['live']['ages']['over7']);

        $this->assertSame(['count' => 1, 'amount' => 500.0], $cash['stale']);
        $this->assertSame(['count' => 1, 'delivered_after_replacement' => 1], $cash['rebooked']);
        $this->assertNull($cash['balance'], 'no Steadfast, or a Steadfast that answered nothing');
        $this->assertSame(['count' => 1, 'amount' => 1500.0], $cash['collected']);
    }

    public function test_cash_at_courier_reads_as_nothing_out_on_an_empty_store(): void
    {
        $cash = $this->insights()->cashAtCourier(DateRange::preset('all'));

        $this->assertSame(0, $cash['live']['count']);
        $this->assertSame(0.0, $cash['live']['amount']);
        $this->assertSame(0, $cash['stale']['count']);
        $this->assertSame(0, $cash['collected']['count']);
    }

    // ── 2. Delivery speed ────────────────────────────────────────────────────

    public function test_delivery_speed_measures_shop_and_courier_time_by_zone(): void
    {
        // Plain calendar-day promises (2 inside, 4 outside) keep the on-time
        // rule independent of which weekday the fixture lands on.
        Setting::put('theme', ['show_delivery_estimate' => false]);

        $range = DateRange::preset('30d');
        $t0 = Carbon::parse('2026-09-10 04:00:00', 'UTC');   // 10 am in Dhaka

        // Booked 12 h after placing, delivered two days later: on time.
        $o1 = $this->order(['status' => 'delivered', 'is_inside_dhaka' => true], [], $t0);
        $this->shipment($o1, ['status' => 'delivered'], $t0->copy()->addHours(12));
        $this->history($o1, 'delivered', $t0->copy()->addHours(12 + 48));

        // Booked a day later, three days in transit: late.
        $o2 = $this->order(['status' => 'delivered', 'is_inside_dhaka' => true], [], $t0);
        $this->shipment($o2, ['status' => 'delivered'], $t0->copy()->addHours(24));
        $this->history($o2, 'delivered', $t0->copy()->addHours(24 + 72));

        // No shipment row (booked before the table existed): the 'booked' history row stands in.
        $o3 = $this->order(['status' => 'delivered', 'is_inside_dhaka' => true], [], $t0);
        $this->history($o3, 'booked', $t0->copy()->addHours(6));
        $this->history($o3, 'delivered', $t0->copy()->addHours(6 + 24));

        // Outside Dhaka, five days in transit: late, and alone in its zone.
        $o4 = $this->order(['status' => 'delivered', 'is_inside_dhaka' => false], [], $t0);
        $this->shipment($o4, ['status' => 'delivered'], $t0->copy()->addHours(2));
        $this->history($o4, 'delivered', $t0->copy()->addHours(2 + 120));

        // Delivered by hand — no booking time at all — is not a transit measurement.
        $o5 = $this->order(['status' => 'delivered', 'is_inside_dhaka' => true], [], $t0);
        $this->history($o5, 'delivered', $t0->copy()->addHours(50));

        // Delivered before it was booked is a data error, not a negative transit.
        $o6 = $this->order(['status' => 'delivered', 'is_inside_dhaka' => true], [], $t0);
        $this->shipment($o6, ['status' => 'delivered'], $t0->copy()->addHours(80));
        $this->history($o6, 'delivered', $t0->copy()->addHours(40));

        // Cancelled at the courier: counts against the zone and in the RTO rate.
        $o7 = $this->order(['status' => 'cancelled', 'is_inside_dhaka' => true], [], $t0);
        $this->shipment($o7, ['status' => 'cancelled'], $t0->copy()->addHour());

        $speed = $this->insights()->deliverySpeed($range);

        $this->assertSame(4, $speed['n']);
        $this->assertSame(['median_hours' => 9.0, 'n' => 4], $speed['shop_to_courier']);

        $this->assertSame(['median_days' => 2.5, 'p90_days' => 5.0, 'n' => 4], $speed['courier_to_door']['overall']);
        $this->assertSame(['median_days' => 2.0, 'p90_days' => 3.0, 'n' => 3], $speed['courier_to_door']['inside']);
        $this->assertSame(['median_days' => null, 'p90_days' => null, 'n' => 1], $speed['courier_to_door']['outside'], 'one parcel is too few to say');

        $this->assertSame(['pct' => 50.0, 'n' => 4], $speed['on_time']);
        $this->assertSame(['le1' => 1, 'd2' => 1, 'd3' => 1, 'd4_6' => 1, 'over7' => 0], $speed['distribution']);

        $inside = $speed['zones']['inside'];
        $this->assertSame(5, $inside['orders']);
        $this->assertSame(1000.0, $inside['aov']);
        $this->assertSame(5, $inside['delivered']);
        $this->assertSame(6, $inside['resolved']);
        $this->assertSame(83.3, $inside['delivered_pct']);

        $outside = $speed['zones']['outside'];
        $this->assertSame(1, $outside['orders']);
        $this->assertSame(1, $outside['resolved']);
        $this->assertNull($outside['delivered_pct'], 'one resolved order is too few for a rate');

        $this->assertSame(['pct' => 20.0, 'settled_n' => 5, 'came_back' => 1], $speed['rto']);
    }

    public function test_delivery_speed_is_honest_when_nothing_was_delivered(): void
    {
        $speed = $this->insights()->deliverySpeed(DateRange::preset('7d'));

        $this->assertSame(0, $speed['n']);
        $this->assertNull($speed['shop_to_courier']['median_hours']);
        $this->assertNull($speed['courier_to_door']['overall']['median_days']);
        $this->assertNull($speed['on_time']['pct']);
        $this->assertNull($speed['zones']['inside']['aov']);
        $this->assertNull($speed['rto']['pct']);
        $this->assertSame(0, array_sum($speed['distribution']));
    }

    // ── 3. Discount leakage ──────────────────────────────────────────────────

    public function test_discount_leakage_decomposes_the_discount_and_values_free_delivery(): void
    {
        $range = DateRange::preset('30d');

        // ৳50 ladder + ৳40 member pricing, paid delivery.
        $this->order([
            'subtotal' => 2000, 'discount' => 90, 'member_discount' => 40, 'ladder_tier' => 1,
            'ladder_rewards' => [['n' => 1, 'label' => '৳50 off', 'amount' => 50]],
            'shipping_cost' => 70, 'is_inside_dhaka' => true, 'total' => 1980,
        ], [['quantity' => 1, 'subtotal' => 2000]]);

        // Three rungs (৳110), 100 points (৳5), a coupon (the ৳50 residual), free delivery inside Dhaka.
        $this->order([
            'subtotal' => 3000, 'discount' => 165, 'ladder_tier' => 3,
            'ladder_rewards' => [
                ['n' => 1, 'label' => '৳50 off', 'amount' => 50],
                ['n' => 2, 'label' => '2% off', 'amount' => 60],
                ['n' => 3, 'label' => 'Free delivery', 'amount' => 0],
            ],
            'points_redeemed' => 100, 'points_discount' => 5, 'coupon_code' => 'save50',
            'shipping_cost' => 0, 'is_inside_dhaka' => true, 'total' => 2835,
        ], [['quantity' => 3, 'subtotal' => 3000]]);

        // A web order that reached no rung: the "without" baseline.
        $this->order(['subtotal' => 500, 'shipping_cost' => 70, 'total' => 570], [['quantity' => 1, 'subtotal' => 500]]);

        // Staff-entered: never climbs the ladder, so it stays out of the pay-off line. Earned 100 points on delivery.
        $this->order([
            'subtotal' => 1000, 'shipping_cost' => 130, 'is_inside_dhaka' => false, 'total' => 1130,
            'source' => 'admin', 'source_channel' => 'admin', 'points_earned' => 100, 'status' => 'delivered',
        ], [['quantity' => 1]]);

        // A chat order is a real sale but not a ladder comparison.
        $this->order(['subtotal' => 400, 'shipping_cost' => 70, 'total' => 470, 'source' => 'chat'], [['quantity' => 1, 'subtotal' => 400]]);

        // Cancelled: not a sale, whatever it was discounted.
        $this->order(['subtotal' => 5000, 'discount' => 999, 'status' => 'cancelled', 'coupon_code' => 'BIG']);

        Coupon::create(['code' => 'SAVE50', 'type' => 'fixed', 'value' => 50, 'is_active' => true]);
        Coupon::create(['code' => 'NEWBIE', 'type' => 'fixed', 'value' => 100, 'is_active' => true]);
        Coupon::create(['code' => 'OLD', 'type' => 'fixed', 'value' => 100, 'is_active' => false]);
        Coupon::create(['code' => 'GONE', 'type' => 'fixed', 'value' => 100, 'is_active' => true, 'expires_at' => now()->subDay()]);

        $leak = $this->insights()->discountLeakage($range);

        $this->assertSame(6900.0, $leak['revenue']);
        $this->assertSame(5, $leak['orders']);

        $rows = $leak['rows'];
        $this->assertSame(['label' => 'Reward ladder', 'amount' => 160.0, 'pct_of_revenue' => 2.3, 'orders' => 2], $rows['ladder']);
        $this->assertSame(['label' => 'Member pricing', 'amount' => 40.0, 'pct_of_revenue' => 0.6, 'orders' => 1], $rows['member']);
        $this->assertSame(['label' => 'Points redeemed', 'amount' => 5.0, 'pct_of_revenue' => 0.1, 'orders' => 1], $rows['points']);
        $this->assertSame(['label' => 'Coupons & offers', 'amount' => 50.0, 'pct_of_revenue' => 0.7, 'orders' => 1], $rows['coupons_offers']);
        $this->assertSame(['label' => 'Free delivery', 'amount' => 70.0, 'pct_of_revenue' => 1.0, 'orders' => 1], $rows['free_delivery']);

        $this->assertSame(['amount' => 325.0, 'pct' => 4.7], $leak['given_away']);
        $this->assertSame(['points' => 100, 'value' => 5.0], $leak['points_issued']);

        $rungs = collect($leak['ladder_rungs'])->keyBy('n');
        $this->assertCount(11, $rungs, 'tier 0 plus the ten configured rungs');
        $this->assertSame(['n' => 0, 'label' => 'No ladder', 'count' => 3], $rungs[0]);
        $this->assertSame(['n' => 1, 'label' => '৳50 off', 'count' => 1], $rungs[1]);
        $this->assertSame(['n' => 3, 'label' => 'Free delivery', 'count' => 1], $rungs[3]);
        $this->assertSame(0, $rungs[2]['count']);

        $this->assertSame(['aov' => 2408.0, 'items_per_order' => 2.0, 'n' => 2], $leak['ladder_payoff']['with']);
        $this->assertSame(['aov' => 570.0, 'items_per_order' => 1.0, 'n' => 1], $leak['ladder_payoff']['without']);

        $this->assertSame(
            [['code' => 'save50', 'uses' => 1, 'revenue' => 2835.0, 'discount_on_orders' => 165.0]],
            $leak['coupons']['codes'],
        );
        $this->assertSame(1, $leak['coupons']['active_unused_count'], 'NEWBIE is live and unused; OLD and GONE are not live');
    }

    public function test_discount_leakage_has_no_percentages_without_revenue(): void
    {
        $leak = $this->insights()->discountLeakage(DateRange::preset('today'));

        $this->assertSame(0.0, $leak['revenue']);
        $this->assertNull($leak['given_away']['pct']);
        $this->assertNull($leak['rows']['ladder']['pct_of_revenue']);
        $this->assertNull($leak['ladder_payoff']['with']['aov']);
        $this->assertSame(0, $leak['ladder_rungs'][0]['count']);
    }

    // ── 4. Channel economics ─────────────────────────────────────────────────

    public function test_channel_economics_keys_rows_like_traffic_sources_and_marks_first_timers(): void
    {
        $range = DateRange::preset('30d');

        // First-time buyer from an ad, delivered, ৳600 profit on the line.
        $this->order([
            'customer_phone' => '01711000001', 'source_channel' => 'facebook_ads', 'status' => 'delivered', 'total' => 1000,
        ], [['subtotal' => 1000, 'cost_price' => 400]], now()->subDays(10));

        // The same buyer back five days later with no channel — that is 'direct', and not a first order.
        $this->order([
            'customer_phone' => '01711000001', 'source_channel' => '', 'total' => 500,
        ], [['price' => 500, 'subtotal' => 500, 'cost_price' => 200]], now()->subDays(5));

        // A second ad buyer whose only earlier order (before the window) was cancelled: still a first-timer.
        $this->order(['customer_phone' => '01711000002', 'source_channel' => 'facebook_ads', 'status' => 'cancelled'], [], now()->subDays(40));
        $this->order([
            'customer_phone' => '01711000002', 'source_channel' => 'facebook_ads', 'total' => 800,
        ], [['price' => 800, 'subtotal' => 800]], now()->subDays(3));

        // A cancelled ad order in the window: no sale, but a resolved outcome.
        $this->order(['customer_phone' => '01711000003', 'source_channel' => 'facebook_ads', 'status' => 'cancelled'], [], now()->subDays(2));

        $rows = $this->insights()->channelEconomics($range);

        $this->assertSame(['facebook_ads', 'direct'], array_keys($rows), 'sorted by profit');

        $ads = $rows['facebook_ads'];
        $this->assertSame('Facebook Ads', $ads['label']);
        $this->assertSame(2, $ads['orders']);
        $this->assertSame(1800.0, $ads['revenue']);
        $this->assertSame(900.0, $ads['aov']);
        $this->assertSame(1400.0, $ads['profit']);
        $this->assertSame(700.0, $ads['profit_per_order']);
        $this->assertSame(77.8, $ads['margin']);
        $this->assertSame(1, $ads['delivered']);
        $this->assertSame(1, $ads['cancelled']);
        $this->assertSame(2, $ads['resolved']);
        $this->assertNull($ads['delivered_pct'], 'two resolved orders are too few for a rate');
        $this->assertSame(100.0, $ads['first_time_pct']);
        $this->assertSame(2, $ads['first_time_n']);

        $direct = $rows['direct'];
        $this->assertSame(1, $direct['orders']);
        $this->assertSame(300.0, $direct['profit']);
        $this->assertSame(60.0, $direct['margin']);
        $this->assertSame(0.0, $direct['first_time_pct']);
        $this->assertSame(0, $direct['resolved']);

        // The same channel keys "Where visitors come from" prints, so the view can merge them.
        $sources = app(DashboardAnalytics::class)->trafficSources($range)->pluck('channel');
        $this->assertEmpty($sources->diff(array_keys($rows))->all());
    }

    // ── 5. Earners & reorder ─────────────────────────────────────────────────

    public function test_top_earners_rank_by_profit_and_badge_reorders_and_slow_movers(): void
    {
        $range = DateRange::preset('30d');
        $rings = Category::create(['name' => 'Rings', 'slug' => 'rings']);

        // One piece left, three sold this month at 60 % margin: reorder.
        $a = $this->product(['name' => 'Opal Ring', 'price' => 1000, 'cost_price' => 400, 'stock_quantity' => 1, 'has_variants' => true, 'category_id' => $rings->id], now()->subDays(100));
        ProductVariant::create(['product_id' => $a->id, 'attributes' => ['size' => '6'], 'stock_quantity' => 0, 'is_active' => true]);
        ProductVariant::create(['product_id' => $a->id, 'attributes' => ['size' => '7'], 'stock_quantity' => 3, 'is_active' => true]);
        ProductVariant::create(['product_id' => $a->id, 'attributes' => ['size' => '8'], 'stock_quantity' => 0, 'is_active' => false]);
        $this->order([], [['product_id' => $a->id, 'quantity' => 3, 'price' => 1000, 'subtotal' => 3000, 'cost_price' => 400]]);
        // An older sale counts towards sell-through since arrival, not the window.
        $this->order([], [['product_id' => $a->id, 'quantity' => 1, 'price' => 1000, 'subtotal' => 1000, 'cost_price' => 400]], now()->subDays(60));

        // A hundred on the shelf, five sold at a thin margin, listed 100 days: slow.
        $b = $this->product(['name' => 'Plain Band', 'price' => 500, 'cost_price' => 400, 'stock_quantity' => 100, 'category_id' => $rings->id], now()->subDays(100));
        $this->order([], [['product_id' => $b->id, 'quantity' => 5, 'price' => 500, 'subtotal' => 2500, 'cost_price' => 400]]);

        // Twenty pieces imported on one day: "listed 90 days ago" would be the import, so it is withheld.
        $batchDay = now()->subDays(90);
        $c = $this->product(['name' => 'Imported Chain', 'price' => 200, 'stock_quantity' => 10], $batchDay);
        foreach (range(1, 19) as $i) {
            $this->product(['stock_quantity' => 1], $batchDay);
        }
        $this->order([], [['product_id' => $c->id, 'quantity' => 1, 'price' => 200, 'subtotal' => 200, 'cost_price' => null]]);

        $earners = $this->insights()->topEarners($range);

        $this->assertSame(['Opal Ring', 'Plain Band', 'Imported Chain'], array_column($earners['rows'], 'name'));

        $ring = $earners['rows'][0];
        $this->assertSame($a->slug, $ring['slug']);
        $this->assertSame(3, $ring['units']);
        $this->assertSame(3000.0, $ring['revenue']);
        $this->assertSame(1800.0, $ring['profit']);
        $this->assertSame(60.0, $ring['margin']);
        $this->assertFalse($ring['cost_missing']);
        $this->assertSame(1, $ring['stock_left']);
        $this->assertSame(10, $ring['days_left'], '1 left at 3 per 30 days');
        $this->assertSame(80.0, $ring['sell_through_pct'], '4 ever sold against 1 on the shelf');
        $this->assertSame(100, $ring['listed_days']);
        $this->assertSame(['out' => 1, 'active' => 2], $ring['variants']);
        $this->assertSame(['reorder' => true, 'slow' => false], $ring['badges']);

        $band = $earners['rows'][1];
        $this->assertSame(500.0, $band['profit']);
        $this->assertSame(20.0, $band['margin']);
        $this->assertSame(600, $band['days_left']);
        $this->assertSame(4.8, $band['sell_through_pct']);
        $this->assertNull($band['variants']);
        $this->assertSame(['reorder' => false, 'slow' => true], $band['badges']);

        $chain = $earners['rows'][2];
        $this->assertTrue($chain['cost_missing']);
        $this->assertNull($chain['listed_days'], 'an import batch date is not an arrival');
        $this->assertSame(['reorder' => false, 'slow' => false], $chain['badges']);

        $this->assertSame(43.9, $earners['overall']['margin']);
        $this->assertSame(1, $earners['overall']['cost_missing_items']);
        $this->assertSame(3, $earners['overall']['products']);

        $this->assertCount(2, $this->insights()->topEarners($range, 2)['rows']);
    }

    public function test_earners_by_category_puts_stock_at_cost_beside_profit(): void
    {
        $range = DateRange::preset('30d');
        $rings = Category::create(['name' => 'Rings', 'slug' => 'rings']);
        $necklaces = Category::create(['name' => 'Necklaces', 'slug' => 'necklaces']);

        $a = $this->product(['price' => 1000, 'cost_price' => 400, 'stock_quantity' => 1, 'category_id' => $rings->id]);
        $b = $this->product(['price' => 500, 'cost_price' => 400, 'stock_quantity' => 100, 'category_id' => $rings->id]);
        $c = $this->product(['price' => 200, 'stock_quantity' => 10, 'category_id' => $necklaces->id]);
        $d = $this->product(['price' => 300, 'cost_price' => 150, 'stock_quantity' => 2]);

        $this->order([], [
            ['product_id' => $a->id, 'quantity' => 3, 'subtotal' => 3000, 'cost_price' => 400],
            ['product_id' => $b->id, 'quantity' => 5, 'subtotal' => 2500, 'cost_price' => 400],
            ['product_id' => $c->id, 'quantity' => 1, 'subtotal' => 200],
            ['product_id' => $d->id, 'quantity' => 1, 'subtotal' => 300, 'cost_price' => 150],
        ]);

        $rows = $this->insights()->earnersByCategory($range);

        $this->assertSame(['Rings', 'Necklaces', 'Uncategorised'], array_column($rows, 'name'));
        $this->assertSame([
            'id' => $rings->id, 'name' => 'Rings', 'units' => 8, 'revenue' => 5500.0, 'profit' => 2300.0, 'margin' => 41.8,
            'stock_at_cost' => 40400.0,
        ], $rows[0]);
        $this->assertSame(0.0, $rows[1]['stock_at_cost'], 'a blank cost is worth nothing on the shelf');
        $this->assertNull($rows[2]['id']);
        $this->assertSame(150.0, $rows[2]['profit']);
        $this->assertSame(300.0, $rows[2]['stock_at_cost']);
    }

    // ── 6. Who to call today ─────────────────────────────────────────────────

    public function test_call_list_never_calls_bdcourier_and_ranks_the_riskiest_first(): void
    {
        Http::fake();
        Setting::put('integrations', ['bdcourier_enabled' => true, 'bdcourier_api_key' => 'k']);

        // Unbooked orders, all older than two hours unless said otherwise.
        $r1 = $this->order(['customer_phone' => '01711000001', 'total' => 1000], [], now()->subHours(3));
        $this->check('01711000001', 30, 10);
        $r2 = $this->order(['customer_phone' => '01711000002', 'status' => 'pending', 'total' => 900], [], now()->subHours(3));
        $this->check('01711000002', 90, 10);
        $r3 = $this->order(['customer_phone' => '01711000003', 'status' => 'pending', 'total' => 800], [], now()->subHours(5));
        $r4 = $this->order(['customer_phone' => '01711000004', 'status' => 'pending', 'total' => 700], [], now()->subHours(3));
        $this->check('01711000004', 0, 0);
        $r5 = $this->order(['customer_phone' => '01711000005', 'status' => 'pending', 'total' => 600], [], now()->subHours(3));
        $this->customer(['phone' => '01711000005', 'blacklisted' => true]);
        $this->check('01711000005', 95, 20);
        $this->order(['customer_phone' => '01711000006', 'status' => 'pending'], [], now()->subHour());          // too fresh
        $r7 = $this->order(['customer_phone' => '01711000007'], [], now()->subHours(3));
        $this->shipment($r7, ['status' => 'in_review'], now()->subHours(2));                                        // already booked
        $r8 = $this->order(['customer_phone' => '01711000008', 'status' => 'pending', 'total' => 500], [], now()->subHours(4));
        $this->check('01711000008', 95, 10, now()->subDays(100));                                                    // stale check

        // Reminders: one overdue, one later today (10 pm Dhaka), one tomorrow, one done this week that became an order.
        $overdue = CallReminder::create(['phone' => '01722000001', 'name' => 'Rima', 'due_at' => now()->subHours(3), 'items' => [['name' => 'Ring'], ['name' => 'Band'], ['name' => 'Chain']]]);
        CallReminder::create(['phone' => '01722000002', 'name' => 'Sadia', 'due_at' => now()->addHours(6)]);
        CallReminder::create(['phone' => '01722000003', 'name' => 'Tomorrow', 'due_at' => now()->addDay()]);
        CallReminder::create(['phone' => '01722000004', 'due_at' => now()->subDays(3), 'done_at' => now()->subDays(2), 'order_id' => $r2->id]);
        CallReminder::create(['phone' => '01722000005', 'due_at' => now()->subDays(12), 'done_at' => now()->subDays(10)]);

        // Carts: one open this week (texted), one open but older, one contacted, one recovered.
        $c1 = $this->cart(['subtotal' => 3000, 'item_count' => 2, 'sms_reminded_at' => now()->subDay()], now()->subDays(2));
        $this->cart(['subtotal' => 5000], now()->subDays(10));
        $this->cart(['subtotal' => 9000, 'contacted' => true], now()->subDay());
        $this->cart(['subtotal' => 9000, 'recovered' => true], now()->subDay());

        // Occasions in the next seven Dhaka days, one of them already wished.
        $birthday = $this->customer(['name' => 'Nusrat', 'birthday_day' => 21, 'birthday_month' => 9]);
        $this->customer(['name' => 'Wished', 'anniversary_day' => 20, 'anniversary_month' => 9, 'anniversary_wished_at' => now()->subDay()]);
        $this->customer(['name' => 'Later', 'birthday_day' => 30, 'birthday_month' => 9]);

        // Buyers going quiet: a repeat buyer and a one-time buyer 3–6 months out; not the blacklisted, the recent or the lost.
        $s1 = $this->customer(['name' => 'Repeat', 'total_orders' => 2, 'total_spent' => 5000, 'last_order_at' => now()->subDays(100)]);
        $s2 = $this->customer(['name' => 'Once', 'total_orders' => 1, 'total_spent' => 7000, 'last_order_at' => now()->subDays(120)]);
        $this->customer(['name' => 'Banned', 'total_orders' => 3, 'total_spent' => 9000, 'last_order_at' => now()->subDays(100), 'blacklisted' => true]);
        $this->customer(['name' => 'Recent', 'total_orders' => 2, 'total_spent' => 9000, 'last_order_at' => now()->subDays(20)]);
        $this->customer(['name' => 'Lost', 'total_orders' => 1, 'total_spent' => 9000, 'last_order_at' => now()->subDays(200)]);

        $list = $this->insights()->callList();

        Http::assertNothingSent();

        $confirm = $list['confirm'];
        $this->assertSame(6, $confirm['count']);
        $this->assertSame(
            [[$r5->id, 'blacklisted'], [$r1->id, 'risky'], [$r3->id, 'unchecked']],
            array_map(fn ($r) => [$r['order_id'], $r['risk']], $confirm['rows']),
        );
        $this->assertSame(3, $confirm['rows'][0]['hours_waiting']);
        $this->assertSame('01711000005', $confirm['rows'][0]['phone']);
        $this->assertSame(1600.0, $confirm['extra']['cod_at_risk']);
        $this->assertSame('admin.orders.index', $confirm['link']['route']);

        $reminders = $list['reminders'];
        $this->assertSame(2, $reminders['count']);
        $this->assertSame(['id' => $overdue->id, 'name' => 'Rima', 'phone' => '01722000001', 'items' => ['Ring', 'Band'], 'overdue' => true], $reminders['rows'][0]);
        $this->assertSame('Sadia', $reminders['rows'][1]['name']);
        $this->assertFalse($reminders['rows'][1]['overdue']);
        $this->assertSame(['done_week' => 1, 'orders_from_calls' => 1], $reminders['extra']);

        $carts = $list['carts'];
        $this->assertSame(1, $carts['count']);
        $this->assertSame($c1->id, $carts['rows'][0]['id']);
        $this->assertSame(3000.0, $carts['rows'][0]['subtotal']);
        $this->assertSame(48, $carts['rows'][0]['hours_ago']);
        $this->assertTrue($carts['rows'][0]['sms_sent']);
        $this->assertSame('Checkout', $carts['rows'][0]['last_step']);
        $this->assertSame(['count' => 2, 'amount' => 8000.0, 'oldest_days' => 10], $carts['extra']['open_now']);

        $occasions = $list['occasions'];
        $this->assertSame(1, $occasions['count']);
        $this->assertSame(['customer_id' => $birthday->id, 'name' => 'Nusrat', 'phone' => $birthday->phone, 'type' => 'birthday', 'days_until' => 3, 'date' => '2026-09-21'], $occasions['rows'][0]);
        $this->assertSame(2, $occasions['extra']['coverage']['birthday']);

        $slipping = $list['slipping'];
        $this->assertSame(2, $slipping['count']);
        $this->assertSame([$s2->id, $s1->id], array_column($slipping['rows'], 'id'), 'biggest spender first');
        $this->assertSame(120, $slipping['rows'][0]['days_since']);
        $this->assertSame(7000.0, $slipping['rows'][0]['total_spent']);

        $this->assertSame(12, $list['total']);
    }

    public function test_call_list_is_a_to_do_list_and_only_stays_cached_for_a_minute(): void
    {
        $this->assertSame(0, $this->insights()->callList()['total']);

        $this->order(['status' => 'pending'], [], now()->subHours(3));

        $this->assertSame(0, $this->insights()->callList()['total'], 'served from the 60-second cache');

        Cache::forget('dash.ins.v1.calls');

        $this->assertSame(1, $this->insights()->callList()['confirm']['count']);
    }

    // ── 7. Abandoned carts & recovery ────────────────────────────────────────

    public function test_lead_recovery_counts_the_chase_and_flags_cart_value_fallbacks(): void
    {
        $range = DateRange::preset('30d');

        // Converted by hand into a ৳1,800 order after a call.
        $c1 = $this->cart(['subtotal' => 1500, 'contacted' => true, 'recovered' => true]);
        $this->order(['abandoned_cart_id' => $c1->id, 'total' => 1800]);
        AbandonedCartContact::create(['abandoned_cart_id' => $c1->id, 'channel' => 'call', 'outcome' => 'will_order']);

        // Came back on their own: only the cart's own value is known.
        $this->cart(['subtotal' => 1200, 'recovered' => true]);

        // Converted by hand, but the order was cancelled: counts as by hand, worth nothing.
        $c8 = $this->cart(['subtotal' => 900, 'contacted' => true, 'recovered' => true]);
        $this->order(['abandoned_cart_id' => $c8->id, 'total' => 900, 'status' => 'cancelled']);

        // Texted, then chased on WhatsApp (no note) and by SMS (no answer).
        $c3 = $this->cart(['subtotal' => 800, 'contacted' => true, 'sms_reminded_at' => now()->subDays(2)]);
        AbandonedCartContact::create(['abandoned_cart_id' => $c3->id, 'channel' => 'whatsapp']);
        AbandonedCartContact::create(['abandoned_cart_id' => $c3->id, 'channel' => 'sms', 'outcome' => 'no_answer']);

        // A free push reminder, and two nobody has touched.
        $this->cart(['subtotal' => 700, 'push_reminded_at' => now()->subDays(2)]);
        $this->cart(['subtotal' => 600]);
        $this->cart(['subtotal' => 500]);

        // Outside the window.
        $this->cart(['subtotal' => 9999, 'recovered' => true], now()->subDays(40));

        $leads = $this->insights()->leadRecovery($range);

        $this->assertSame(['count' => 7, 'amount' => 6200.0], $leads['captured']);
        $this->assertSame(['count' => 3, 'pct' => 42.9], $leads['contacted']);
        $this->assertSame(1, $leads['attempts']['call']['count']);
        $this->assertSame(1, $leads['attempts']['whatsapp']['count']);
        $this->assertSame(1, $leads['attempts']['sms']['count']);
        $this->assertSame(['label' => 'Note', 'count' => 0], $leads['attempts']['note']);
        $this->assertSame(['sms' => 1, 'push' => 1], $leads['reminders']);
        $this->assertSame(['count' => 3, 'pct' => 42.9, 'by_hand' => 2, 'on_their_own' => 1], $leads['recovered']);
        $this->assertSame(['amount' => 3000.0, 'includes_cart_values' => true], $leads['recovered_value']);
        $this->assertSame(1, $leads['outcomes']['will_order']['count']);
        $this->assertSame(1, $leads['outcomes']['no_answer']['count']);
        $this->assertSame(['label' => 'No note', 'count' => 1], $leads['outcomes']['no_note']);
        $this->assertSame(0, $leads['outcomes']['not_interested']['count']);
    }

    public function test_lead_recovery_hides_percentages_under_five_captured_carts(): void
    {
        $this->cart(['contacted' => true, 'recovered' => true]);
        $this->cart();
        $this->cart();

        $leads = $this->insights()->leadRecovery(DateRange::preset('30d'));

        $this->assertSame(3, $leads['captured']['count']);
        $this->assertSame(1, $leads['contacted']['count']);
        $this->assertNull($leads['contacted']['pct']);
        $this->assertNull($leads['recovered']['pct']);
    }

    // ── 8. Assistant & SMS ───────────────────────────────────────────────────

    public function test_assistant_and_sms_read_the_tables_that_own_each_outcome(): void
    {
        $range = DateRange::preset('30d');
        $two = now()->subDays(2);

        // Chats: one with a product card, one that never got a question, one failed and closed, one guest, one member.
        $conv = fn (array $attrs) => tap(AssistantConversation::create(array_merge(['uid' => 'u'.(++$this->seq)], $attrs)), fn ($c) => $this->at($c, $two));
        $c1 = $conv([]);
        $c1->messages()->create(['role' => 'user', 'content' => 'Any rings?', 'created_at' => $two]);
        $c1->messages()->create(['role' => 'assistant', 'content' => 'Yes', 'products' => [1], 'created_at' => $two]);
        $c2 = $conv([]);
        $c2->messages()->create(['role' => 'assistant', 'content' => 'Hello', 'created_at' => $two]);
        $c3 = $conv(['had_failure' => true, 'blocked_at' => $two]);
        $c3->messages()->create(['role' => 'user', 'content' => 'asdf', 'created_at' => $two]);
        $c3->messages()->create(['role' => 'assistant', 'content' => 'Sorry', 'ok' => false, 'created_at' => $two]);
        $c4 = $conv(['visitor_token' => 'v-guest']);
        $c4->messages()->create(['role' => 'user', 'content' => 'price?', 'created_at' => $two]);
        $member = $this->customer();
        $c5 = $conv(['customer_id' => $member->id, 'last_message_at' => $two]);
        $c5->messages()->create(['role' => 'user', 'content' => 'size 7?', 'created_at' => $two]);

        // The guest's cart became an order; the member ordered the day after chatting; one order came through chat itself.
        $guestCart = $this->cart(['visitor_token' => 'v-guest']);
        $this->order(['abandoned_cart_id' => $guestCart->id, 'total' => 1100], [], now()->subDay());
        $this->order(['customer_id' => $member->id, 'customer_phone' => $member->phone, 'total' => 1300], [], now()->subDay());
        $this->order(['source' => 'chat', 'total' => 900], [], now()->subDay());

        // SMS: three accepted sends (one a three-recipient Bangla broadcast), a rejection, a disabled-gateway row.
        $this->sms(['order_id' => Order::first()->id]);
        $this->sms(['provider_status' => '00', 'recipients' => 3, 'phone' => '3 recipients · ends 5678', 'message' => 'হ্যালো']);
        $this->sms(['provider_status' => '-55', 'status' => 'Invalid number', 'recipients' => 2]);
        $this->sms(['provider_status' => null, 'status' => 'disabled']);
        $this->sms(['message' => str_repeat('a', 161)]);
        Setting::put('sms_cost_per_segment', 0.25);
        Cache::put('admin.alerts.sms_balance', ['statusInfo' => ['availablebalance' => '123.5']], 1800);

        // Cart reminders: two texted, one of which became a ৳1,500 order.
        $k1 = $this->cart(['sms_reminded_at' => $two, 'recovered' => true]);
        $this->order(['abandoned_cart_id' => $k1->id, 'total' => 1500], [], now()->subDay());
        $this->cart(['sms_reminded_at' => $two]);

        // Review requests: two asked, one answered by a review on that phone after the ask.
        $product = $this->product();
        $q1 = $this->order(['customer_phone' => '01799000001', 'status' => 'delivered']);
        $q1->forceFill(['review_request_sent_at' => now()->subDays(3)])->saveQuietly();
        $q2 = $this->order(['customer_phone' => '01799000002', 'status' => 'delivered']);
        $q2->forceFill(['review_request_sent_at' => now()->subDays(3)])->saveQuietly();
        $review = Review::create(['product_id' => $product->id, 'author_name' => 'Anika', 'phone' => '01799000001', 'rating' => 5, 'status' => 'approved']);
        $this->at($review, now()->subDay());

        // Occasion texts: one wished four days ago and ordered since; one wished before the window.
        $wished = $this->customer(['birthday_wished_at' => now()->subDays(4)]);
        $this->order(['customer_id' => $wished->id, 'customer_phone' => $wished->phone], [], now()->subDays(2));
        $this->customer(['anniversary_wished_at' => now()->subDays(40)]);

        $panel = $this->insights()->assistantAndSms($range);

        $assistant = $panel['assistant'];
        $this->assertIsBool($assistant['enabled']);           // whatever .env says — read, never assumed
        $this->assertIsBool($assistant['ordering_enabled']);
        $this->assertSame(4, $assistant['conversations'], 'a chat with no question from the shopper is not a conversation');
        $this->assertSame(1, $assistant['failed']);
        $this->assertSame(1, $assistant['blocked']);
        $this->assertSame(1, $assistant['product_replies']);
        $this->assertSame(['count' => 1, 'revenue' => 900.0], $assistant['chat_orders']);
        $this->assertSame(['count' => 1, 'note' => 'known customers only'], $assistant['assisted_orders']);
        $this->assertSame(1, $assistant['guest_assisted_at_least']);
        $this->assertFalse($assistant['cost_tracked']);

        $sms = $panel['sms'];
        $this->assertIsBool($sms['enabled']);
        $this->assertSame(5, $sms['sent']);
        $this->assertSame(3, $sms['rejected']);
        $this->assertSame('Invalid number', $sms['top_rejection_text']);
        $this->assertSame(6, $sms['segments'], '1 + 3 (Bangla to three people) + 2 (161 GSM characters)');
        $this->assertSame(1.5, $sms['cost']);
        $this->assertSame(123.5, $sms['balance']);

        $purpose = $panel['by_purpose'];
        $this->assertSame(['sent' => 2, 'recovered' => 1, 'recovered_pct' => null, 'revenue' => 1500.0], $purpose['cart_reminders']);
        $this->assertSame(['sent' => 2, 'replied' => 1, 'pct' => null], $purpose['review_requests']);
        $this->assertSame(['sent' => 1, 'ordered_within_7d' => 1], $purpose['occasion_texts']);
        $this->assertSame(['sent' => 1], $purpose['order_texts']);
        $this->assertSame(['sent' => 3], $purpose['broadcasts']);
    }

    public function test_sms_cost_and_balance_are_null_until_the_rate_is_typed_and_the_bell_has_looked(): void
    {
        $this->sms([]);

        $sms = $this->insights()->assistantAndSms(DateRange::preset('30d'))['sms'];

        $this->assertSame(1, $sms['sent']);
        $this->assertSame(1, $sms['segments']);
        $this->assertNull($sms['cost']);
        $this->assertNull($sms['balance']);
        $this->assertNull($sms['top_rejection_text']);
    }

    // ── 9. Order clock ───────────────────────────────────────────────────────

    public function test_order_clock_buckets_orders_and_checkouts_in_dhaka_time(): void
    {
        // 07:30 UTC is 1:30 pm in Dhaka; 20:30 UTC the evening before is 2:30 am on the 18th.
        $this->order([], [], Carbon::parse('2026-09-18 07:30:00', 'UTC'));
        $this->order([], [], Carbon::parse('2026-09-18 07:30:00', 'UTC'));
        $this->order([], [], Carbon::parse('2026-09-17 20:30:00', 'UTC'));
        $this->order(['status' => 'cancelled'], [], Carbon::parse('2026-09-18 07:30:00', 'UTC'));

        DB::table('visits')->insert([
            ['visitor_token' => 'v1', 'event' => 'checkout_start', 'created_at' => '2026-09-17 20:30:00'],
            ['visitor_token' => 'v2', 'event' => 'page', 'created_at' => '2026-09-18 07:30:00'],
        ]);

        $clock = $this->insights()->orderClock(DateRange::preset('7d'));

        $this->assertCount(24, $clock['hours']);
        $this->assertSame(2, $clock['hours'][13]);
        $this->assertSame(1, $clock['hours'][2]);
        $this->assertSame(3, array_sum($clock['hours']));

        $this->assertCount(24, $clock['checkouts']);
        $this->assertSame(1, $clock['checkouts'][2]);
        $this->assertSame(1, array_sum($clock['checkouts']));

        // All three land on the same Dhaka day — a Friday — Sunday-first.
        $friday = Carbon::parse('2026-09-18', 'Asia/Dhaka')->dayOfWeek;
        $this->assertCount(7, $clock['weekdays']);
        $this->assertSame(3, $clock['weekdays'][$friday]);
        $this->assertSame('sunday', $clock['weekday_first']);

        $this->assertSame(['1 pm', '2 am'], $clock['best_hours']);
        $this->assertSame(['orders' => 3, 'checkouts' => 1], $clock['n']);
        $this->assertSame('Asia/Dhaka', $clock['timezone']);
        $this->assertTrue($clock['tracking']);
    }

    // ── 10. Stock health ─────────────────────────────────────────────────────

    public function test_stock_health_prices_the_shelf_and_what_has_not_moved(): void
    {
        $range = DateRange::preset('30d');
        $rings = Category::create(['name' => 'Rings', 'slug' => 'rings']);
        $necklaces = Category::create(['name' => 'Necklaces', 'slug' => 'necklaces']);

        $a = $this->product(['price' => 300, 'cost_price' => 100, 'transport_cost' => 20, 'stock_quantity' => 10, 'category_id' => $rings->id]);
        $this->product(['price' => 200, 'cost_price' => 50, 'stock_quantity' => 5, 'category_id' => $necklaces->id]);
        $this->product(['price' => 100, 'stock_quantity' => 3, 'manage_stock' => false]);        // not tracked
        $this->product(['price' => 100, 'stock_quantity' => 2]);                                  // no cost on file
        $this->product(['price' => 100, 'cost_price' => 99, 'stock_quantity' => 9])->delete();    // gone

        $this->order([], [['product_id' => $a->id, 'quantity' => 4, 'subtotal' => 1200]]);

        $stock = $this->insights()->stockHealth($range);

        $this->assertSame(1450.0, $stock['stock_at_cost']);
        $this->assertSame(4200.0, $stock['stock_at_sell']);
        $this->assertSame(17, $stock['units_on_hand']);
        $this->assertSame(4, $stock['units_sold']);
        $this->assertSame(19.0, $stock['sell_through_pct']);
        $this->assertSame(18.2, $stock['weeks_of_cover']);
        $this->assertSame(30, $stock['span_days']);
        $this->assertSame(['amount' => 250.0, 'pct_of_cost' => 17.2, 'units' => 7], $stock['sitting_still']);
        $this->assertSame([['name' => 'Necklaces', 'amount' => 250.0], ['name' => 'Uncategorised', 'amount' => 0.0]], $stock['sitting_still_by_category']);
        $this->assertSame(1, $stock['cost_gap_count']);
    }

    public function test_stock_health_has_no_cover_figure_when_nothing_sold(): void
    {
        $this->product(['price' => 300, 'cost_price' => 100, 'stock_quantity' => 10]);

        $stock = $this->insights()->stockHealth(DateRange::preset('today'));

        $this->assertSame(1000.0, $stock['stock_at_cost']);
        $this->assertSame(0.0, $stock['sell_through_pct']);
        $this->assertNull($stock['weeks_of_cover']);
        $this->assertSame(100.0, $stock['sitting_still']['pct_of_cost']);
    }

    // ── Cache contract ───────────────────────────────────────────────────────

    /** Every window preset, including the unbounded one, on a store with nothing in it. */
    public function test_every_insight_runs_on_an_empty_store_for_every_window(): void
    {
        foreach (array_keys(DateRange::PRESETS) as $preset) {
            $range = DateRange::preset($preset);
            $i = $this->insights();

            $this->assertIsArray($i->cashAtCourier($range));
            $this->assertIsArray($i->deliverySpeed($range));
            $this->assertIsArray($i->discountLeakage($range));
            $this->assertSame([], $i->channelEconomics($range));
            $this->assertSame([], $i->topEarners($range)['rows']);
            $this->assertSame([], $i->earnersByCategory($range));
            $this->assertIsArray($i->leadRecovery($range));
            $this->assertIsArray($i->assistantAndSms($range));
            $this->assertSame(0, $i->orderClock($range)['n']['orders']);
            $this->assertIsArray($i->stockHealth($range));
        }

        $this->assertSame(0, $this->insights()->callList()['total']);
    }

    public function test_no_insight_caches_an_object(): void
    {
        $range = DateRange::preset('30d');
        $i = $this->insights();

        $i->cashAtCourier($range);
        $i->deliverySpeed($range);
        $i->discountLeakage($range);
        $i->channelEconomics($range);
        $i->topEarners($range);
        $i->earnersByCategory($range);
        $i->callList();
        $i->leadRecovery($range);
        $i->assistantAndSms($range);
        $i->orderClock($range);
        $i->stockHealth($range);

        $keys = ['cash.30d', 'speed.30d', 'leak.30d', 'chan.30d', 'earn.30d.12', 'earncat.30d', 'calls', 'leads.30d', 'aisms.30d', 'clock.30d', 'stock.30d'];

        foreach ($keys as $key) {
            $payload = $this->cachedPayload($key);
            $this->assertNotSame('ABSENT', $payload, "nothing cached for {$key}");
            $this->assertTrue($this->isPlain($payload), "dash.ins.v1.{$key} contains an object — it will come back as __PHP_Incomplete_Class");
        }
    }

    public function test_a_poisoned_cache_entry_is_discarded_not_served(): void
    {
        Cache::put('dash.ins.v1.clock.30d', new \stdClass, 300);
        Cache::put('dash.ins.v1.calls', new \stdClass, 60);

        $clock = $this->insights()->orderClock(DateRange::preset('30d'));
        $list = $this->insights()->callList();

        $this->assertCount(24, $clock['hours']);
        $this->assertSame(0, $list['total']);
        $this->assertTrue($this->isPlain($this->cachedPayload('clock.30d')));
        $this->assertTrue($this->isPlain($this->cachedPayload('calls')));
    }

    protected function isPlain(mixed $v, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        if (is_object($v)) {
            return false;
        }
        if (is_array($v)) {
            foreach ($v as $i) {
                if (! $this->isPlain($i, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }
}
