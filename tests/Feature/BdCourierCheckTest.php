<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\BdCourierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BDCourier COD risk check on the admin order page.
 *
 * Only POST /courier-check is used. Lookups cost plan quota, so they happen on
 * an explicit click and never on a page view.
 */
class BdCourierCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function configure(array $extra = []): void
    {
        Setting::put('integrations', array_merge([
            'bdcourier_enabled' => true,
            'bdcourier_api_key' => 'test-key',
            'bdcourier_base_url' => 'https://api.bdcourier.com',
        ], $extra));
    }

    protected function order(string $phone = '01870620635'): Order
    {
        return Order::create([
            'order_number' => '30001', 'customer_name' => 'Kazi Rahat', 'customer_phone' => $phone,
            'shipping_address' => 'X', 'subtotal' => 1570, 'shipping_cost' => 0, 'discount' => 0,
            'total' => 1570, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'processing', 'source' => 'web',
        ]);
    }

    protected function admin(): User
    {
        // firstOrCreate: several tests act as the admin twice in one run.
        return User::firstOrCreate(
            ['email' => 'a@b.c'],
            ['name' => 'A', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    /** The documented success payload, trimmed to two couriers plus a report. */
    protected function payload(array $summary = [], array $reports = []): array
    {
        return [
            'status' => 'success',
            'data' => [
                'pathao' => ['name' => 'Pathao', 'logo' => 'https://api.bdcourier.com/c-logo/pathao-logo.png',
                    'total_parcel' => 150, 'success_parcel' => 120, 'cancelled_parcel' => 30, 'success_ratio' => 80],
                'steadfast' => ['name' => 'SteadFast', 'logo' => 'https://api.bdcourier.com/c-logo/steadfast-logo.png',
                    'total_parcel' => 200, 'success_parcel' => 175, 'cancelled_parcel' => 25, 'success_ratio' => 87.5],
                // A courier the customer has never used — should be filtered out.
                'parceldex' => ['name' => 'ParcelDex', 'logo' => '',
                    'total_parcel' => 0, 'success_parcel' => 0, 'cancelled_parcel' => 0, 'success_ratio' => 0],
                'summary' => array_merge([
                    'total_parcel' => 350, 'success_parcel' => 295,
                    'cancelled_parcel' => 55, 'success_ratio' => 84.29,
                ], $summary),
            ],
            'reports' => $reports,
        ];
    }

    // ── The request itself ──────────────────────────────────────────────────

    public function test_it_posts_the_phone_with_a_bearer_token_to_courier_check(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);

        $order = $this->order('01870620635');
        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/courier-check');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.bdcourier.com/courier-check'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['phone'] === '01870620635';
        });
    }

    public function test_opening_the_order_page_never_calls_the_api(): void
    {
        $this->configure();
        Http::fake();

        $order = $this->order();
        $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->assertOk();

        // Quota is only spent on an explicit click.
        Http::assertNothingSent();
    }

    public function test_a_second_page_view_reads_the_cache_without_a_new_call(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);

        $order = $this->order();
        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/courier-check');
        $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->assertOk();

        Http::assertSentCount(1);
    }

    // ── What gets shown ─────────────────────────────────────────────────────

    public function test_the_order_page_shows_the_summary_figures(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);

        $order = $this->order();
        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/courier-check');
        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringContainsString('84.29%', $html);   // success rate
        $this->assertStringContainsString('350', $html);      // total
        $this->assertStringContainsString('295', $html);      // delivered
        $this->assertStringContainsString('55', $html);       // cancelled
        $this->assertStringContainsString('Safe', $html);
    }

    public function test_couriers_with_no_parcels_are_left_out_of_the_breakdown(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);

        $result = app(BdCourierService::class)->check('01870620635');

        $names = array_column($result['couriers'], 'name');
        $this->assertContains('Pathao', $names);
        $this->assertContains('SteadFast', $names);
        $this->assertNotContains('ParcelDex', $names);
        // Sorted by volume, busiest first.
        $this->assertSame('SteadFast', $names[0]);
    }

    public function test_fraud_reports_are_shown_when_present(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload([], [[
            'id' => 'abc123', 'name' => 'John Doe', 'details' => 'Fraud reported by merchant',
            'created_at' => '2024-01-01T00:00:00.000000Z', 'courierName' => 'SteadFast',
        ]]))]);

        $order = $this->order();
        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/courier-check');
        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringContainsString('Fraud reports', $html);
        $this->assertStringContainsString('Fraud reported by merchant', $html);
    }

    public function test_no_fraud_section_when_reports_are_empty(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);

        $order = $this->order();
        $this->actingAs($this->admin())->post('/admin/orders/'.$order->id.'/courier-check');
        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringNotContainsString('Fraud reports', $html);
    }

    // ── Risk banding ────────────────────────────────────────────────────────

    public function test_risk_bands_follow_the_configured_thresholds(): void
    {
        $this->configure();
        $svc = app(BdCourierService::class);

        $this->assertSame('safe', $svc->risk(['total_parcel' => 10, 'success_ratio' => 80])['level']);
        $this->assertSame('warning', $svc->risk(['total_parcel' => 10, 'success_ratio' => 79.9])['level']);
        $this->assertSame('warning', $svc->risk(['total_parcel' => 10, 'success_ratio' => 50])['level']);
        $this->assertSame('risky', $svc->risk(['total_parcel' => 10, 'success_ratio' => 49.9])['level']);
    }

    public function test_a_number_with_no_history_is_unknown_not_risky(): void
    {
        $this->configure();

        // A first-time buyer is not a bad one; calling them Risky would train
        // the shop to ignore the badge.
        $risk = app(BdCourierService::class)->risk(['total_parcel' => 0, 'success_ratio' => 0]);

        $this->assertSame('unknown', $risk['level']);
    }

    public function test_thresholds_are_configurable(): void
    {
        $this->configure(['bdcourier_safe_threshold' => 90, 'bdcourier_warning_threshold' => 70]);
        $svc = app(BdCourierService::class);

        $this->assertSame('warning', $svc->risk(['total_parcel' => 10, 'success_ratio' => 85])['level']);
        $this->assertSame('safe', $svc->risk(['total_parcel' => 10, 'success_ratio' => 90])['level']);
        $this->assertSame('risky', $svc->risk(['total_parcel' => 10, 'success_ratio' => 69])['level']);
    }

    // ── Failure handling ────────────────────────────────────────────────────

    public function test_a_rejected_api_key_reports_a_readable_error(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $order = $this->order();
        $this->actingAs($this->admin())
            ->post('/admin/orders/'.$order->id.'/courier-check')
            ->assertSessionHas('error');

        $this->assertNull(app(BdCourierService::class)->cached($order->customer_phone));
    }

    public function test_a_network_failure_does_not_break_the_page(): void
    {
        $this->configure();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $order = $this->order();
        $this->actingAs($this->admin())
            ->post('/admin/orders/'.$order->id.'/courier-check')
            ->assertSessionHas('error');

        $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->assertOk();
    }

    public function test_the_panel_is_hidden_when_bdcourier_is_not_configured(): void
    {
        Setting::put('integrations', []);

        $order = $this->order();
        $html = $this->actingAs($this->admin())->get('/admin/orders/'.$order->id)->getContent();

        $this->assertStringNotContainsString('Courier history (BDCourier)', $html);
    }
}
