<?php

namespace Tests\Feature;

use App\Jobs\CheckOrderCourier;
use App\Models\CourierCheck;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\BdCourierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automatic BDCourier lookups for brand-new orders (owner, 2026-09-24).
 *
 * The whole point is which orders spend a credit and which do not: a first-time
 * buyer is looked up without being asked, and a returning customer never is —
 * their earlier history is shown instead, however old it has got.
 */
class OrderCourierAutoCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function configure(bool $auto = true, array $extra = []): void
    {
        Setting::put('integrations', array_merge([
            'bdcourier_enabled' => true,
            'bdcourier_api_key' => 'test-key',
            'bdcourier_base_url' => 'https://api.bdcourier.com',
            'bdcourier_auto_check' => $auto,
        ], $extra));
    }

    /** The vendor's success payload, trimmed to one courier. */
    protected function fakeApi(float $ratio = 84.29): void
    {
        Http::fake(['api.bdcourier.com/*' => Http::response([
            'status' => 'success',
            'data' => [
                'pathao' => ['name' => 'Pathao', 'logo' => '', 'total_parcel' => 350,
                    'success_parcel' => 295, 'cancelled_parcel' => 55, 'success_ratio' => $ratio],
                'summary' => ['total_parcel' => 350, 'success_parcel' => 295,
                    'cancelled_parcel' => 55, 'success_ratio' => $ratio],
            ],
            'reports' => [],
        ])]);
    }

    protected function product(): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 1000, 'manage_stock' => true, 'stock_quantity' => 20, 'in_stock' => true,
        ]);
    }

    /** Place a real storefront order, the way a customer does. */
    protected function checkout(string $phone = '01870620635'): Order
    {
        $this->post('/cart/add/'.$this->product()->slug, ['qty' => 1])->assertRedirect();
        $this->post('/checkout', [
            'name' => 'Kazi Rahat', 'phone' => $phone,
            'address' => '123 Road, Dhaka', 'is_inside_dhaka' => 1,
        ])->assertRedirect();

        return Order::where('customer_phone', $phone)->latest('id')->firstOrFail();
    }

    /** An earlier order on the same number, which is what makes a repeat buyer. */
    protected function earlierOrder(string $phone, array $attrs = []): Order
    {
        static $n = 0;
        $n++;

        return Order::create(array_merge([
            'order_number' => '2900'.$n, 'customer_name' => 'Kazi Rahat', 'customer_phone' => $phone,
            'shipping_address' => 'X', 'subtotal' => 1570, 'shipping_cost' => 0, 'discount' => 0,
            'total' => 1570, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'delivered', 'source' => 'web',
        ], $attrs));
    }

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.c'],
            ['name' => 'A', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    // ── Who gets looked up ──────────────────────────────────────────────────

    public function test_a_first_order_from_a_new_number_is_looked_up_without_being_asked(): void
    {
        $this->configure();
        $this->fakeApi();

        $order = $this->checkout();

        Http::assertSentCount(1);
        $this->assertNotNull(
            app(BdCourierService::class)->stored($order->customer_phone),
            'the result should have been stored against the phone',
        );
    }

    public function test_a_repeat_buyers_order_spends_no_credit(): void
    {
        $this->configure();
        $this->fakeApi();
        $this->earlierOrder('01870620635');

        $this->checkout('01870620635');

        Http::assertNothingSent();
        $this->assertDatabaseCount('courier_checks', 0);
    }

    public function test_nothing_is_looked_up_while_the_setting_is_off(): void
    {
        $this->configure(auto: false);
        $this->fakeApi();

        $this->checkout();

        Http::assertNothingSent();
    }

    public function test_a_number_that_was_already_checked_recently_is_not_paid_for_again(): void
    {
        $this->configure();
        $this->fakeApi();

        // A batch run, or the customer page, got there first.
        CourierCheck::create([
            'phone' => '01870620635', 'payload' => ['ok' => true], 'success_ratio' => 90,
            'total_parcel' => 10, 'reports_count' => 0, 'checked_at' => now()->subHour(),
        ]);

        $this->checkout('01870620635');

        Http::assertNothingSent();
    }

    public function test_an_order_taken_by_hand_is_looked_up_too(): void
    {
        $this->configure();
        $this->fakeApi();
        $product = $this->product();

        $this->actingAs($this->admin())->post('/admin/orders/create', [
            'name' => 'Kazi Rahat', 'phone' => '01870620635',
            'address' => '123 Road, Dhaka', 'is_inside_dhaka' => '1',
            'status' => 'processing', 'shipping_cost' => 0, 'discount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'price' => 1000]],
        ])->assertRedirect();

        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
    }

    // ── The decision itself, without going through a checkout ───────────────

    public function test_a_phone_bdcourier_cannot_answer_for_is_skipped(): void
    {
        $this->configure();
        $svc = app(BdCourierService::class);

        $landline = $this->earlierOrder('0299887766', ['customer_phone' => '0299887766']);

        $this->assertSame(
            CheckOrderCourier::SKIP_NO_PHONE,
            CheckOrderCourier::skipReason($landline, $svc),
        );
    }

    public function test_the_order_being_placed_does_not_count_as_its_own_earlier_order(): void
    {
        $this->configure();

        $only = $this->earlierOrder('01870620635');

        $this->assertNull(
            CheckOrderCourier::skipReason($only, app(BdCourierService::class)),
            'the only order on a number must not read as a repeat',
        );
    }

    public function test_a_lookup_that_fails_leaves_the_order_unchecked_rather_than_failing_the_job(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response([], 500)]);

        $order = $this->checkout();

        $this->assertDatabaseCount('courier_checks', 0);
        $this->assertNull(app(BdCourierService::class)->stored($order->customer_phone));
    }

    // ── What the order page says ────────────────────────────────────────────

    public function test_the_order_page_shows_an_earlier_result_however_old_it_has_got(): void
    {
        $this->configure(auto: false);
        $this->fakeApi();

        $order = $this->earlierOrder('01870620635');
        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/courier-check');

        // Long past the 48 hours that decides whether it must be paid for again.
        $this->travel(40)->days();

        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringContainsString('84.29%', $html, 'the earlier figures should still be on the page');
        $this->assertStringContainsString('last check on record', $html, 'and should be marked as old');
    }

    public function test_the_page_tells_the_admin_a_repeat_buyer_was_skipped_on_purpose(): void
    {
        $this->configure();
        $this->fakeApi();
        $this->earlierOrder('01870620635');

        $order = $this->checkout('01870620635');
        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringContainsString('Not checked automatically', $html);
        $this->assertStringContainsString('Delivery reliability', $html);
    }

    public function test_a_first_order_that_was_checked_shows_its_result_not_the_skip_note(): void
    {
        $this->configure();
        $this->fakeApi();

        $order = $this->checkout();
        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringContainsString('84.29%', $html);
        $this->assertStringNotContainsString('Not checked automatically', $html);
    }

    // ── The switch ──────────────────────────────────────────────────────────

    public function test_the_toggle_saves_from_the_integrations_page(): void
    {
        $this->configure(auto: false);

        $this->actingAs($this->admin())->post('/admin/system-config/integrations', [
            'bdcourier_enabled' => '1',
            'bdcourier_api_key' => 'test-key',
            'bdcourier_base_url' => 'https://api.bdcourier.com',
            'bdcourier_auto_check' => '1',
        ])->assertRedirect();

        Setting::flushMemo();
        $this->assertTrue(app(BdCourierService::class)->autoCheckNewOrders());
    }

    public function test_the_toggle_means_nothing_until_bdcourier_itself_is_configured(): void
    {
        Setting::put('integrations', ['bdcourier_enabled' => false, 'bdcourier_auto_check' => true]);

        $this->assertFalse(app(BdCourierService::class)->autoCheckNewOrders());
    }
}
