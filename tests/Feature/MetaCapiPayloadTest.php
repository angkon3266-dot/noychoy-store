<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Meta\MetaTrackingService;
use App\Support\MetaIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What actually leaves the server for the Conversions API.
 *
 * Asserted against the intercepted HTTP request rather than the service's
 * return value, because Meta's match quality is decided entirely by the shape
 * of this payload — and a field that is missing, double-hashed or hashed when
 * it shouldn't be produces no error anywhere, just a lower score days later.
 */
class MetaCapiPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMeta();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1'], 200),
        ]);
    }

    /** The user_data block of the single event in the last CAPI request. */
    protected function lastUserData(): array
    {
        $sent = null;
        Http::recorded(function ($request) use (&$sent) {
            $sent = $request;

            return true;
        });

        $this->assertNotNull($sent, 'no CAPI request was sent');

        return $sent->data()['data'][0]['user_data'] ?? [];
    }

    protected function lastEvent(): array
    {
        $sent = null;
        Http::recorded(function ($request) use (&$sent) {
            $sent = $request;

            return true;
        });

        return $sent->data()['data'][0] ?? [];
    }

    protected function product(): Product
    {
        return Product::create([
            'name' => 'Gold Ring',
            'slug' => 'gold-ring',
            'status' => 'published',
            'price' => 550,
            'category_id' => Category::create(['name' => 'Rings', 'slug' => 'rings'])->id,
        ]);
    }

    // ── The anonymous case ───────────────────────────────────────────────────

    public function test_an_anonymous_visitor_from_an_ad_sends_ip_ua_and_fbc(): void
    {
        $product = $this->product();

        $this->withUnencryptedCookie(MetaIdentity::FBP_COOKIE, 'fb.1.1700000000000.99')
            ->get('/product/'.$product->slug.'?fbclid=IwAR0testclick')
            ->assertOk();

        $user = $this->lastUserData();

        $this->assertArrayHasKey('client_ip_address', $user);
        $this->assertArrayHasKey('client_user_agent', $user);
        $this->assertSame('fb.1.1700000000000.99', $user['fbp']);
        $this->assertStringEndsWith('.IwAR0testclick', $user['fbc']);

        // Anonymous, so no email/phone/name — that is expected and correct.
        $this->assertArrayNotHasKey('em', $user);
        $this->assertArrayNotHasKey('ph', $user);

        // …but the visitor is still identifiable to this store alone.
        $this->assertNotEmpty($user['external_id']);
    }

    public function test_a_direct_visitor_sends_no_fbc(): void
    {
        $product = $this->product();

        $this->get('/product/'.$product->slug)->assertOk();

        $this->assertArrayNotHasKey('fbc', $this->lastUserData());
    }

    public function test_the_four_unhashable_parameters_are_sent_in_the_clear(): void
    {
        $product = $this->product();

        $this->withUnencryptedCookie(MetaIdentity::FBP_COOKIE, 'fb.1.1700000000000.99')
            ->get('/product/'.$product->slug.'?fbclid=IwAR0testclick')->assertOk();

        $user = $this->lastUserData();

        foreach (['fbp', 'fbc', 'client_ip_address', 'client_user_agent'] as $key) {
            $this->assertFalse(
                MetaIdentity::isHashed((string) $user[$key]),
                "{$key} must not be hashed — Meta discards it if it is",
            );
        }
    }

    // ── The identified case ──────────────────────────────────────────────────

    public function test_a_purchase_sends_the_customer_fields_hashed(): void
    {
        $order = $this->order();

        app(MetaTrackingService::class)->purchase($order, $order->order_number);

        $user = $this->lastUserData();

        foreach (['em', 'ph', 'fn', 'ln', 'ct'] as $key) {
            $this->assertArrayHasKey($key, $user, "{$key} missing from Purchase");
            $this->assertTrue(MetaIdentity::isHashed($user[$key][0]), "{$key} was not hashed");
        }

        // The exact digests Meta will compare against.
        $this->assertSame([hash('sha256', 'buyer@example.com')], $user['em']);
        $this->assertSame([hash('sha256', '8801712345678')], $user['ph']);
        $this->assertSame([hash('sha256', 'fatima')], $user['fn']);
        $this->assertSame([hash('sha256', 'rahman')], $user['ln']);
    }

    public function test_a_full_name_is_never_sent_as_the_first_name(): void
    {
        // "fatima rahman" hashed into fn matches nobody in Meta's graph.
        app(MetaTrackingService::class)->purchase($this->order(), '10001');

        $this->assertNotSame(
            [hash('sha256', 'fatimarahman')],
            $this->lastUserData()['fn'],
        );
    }

    // ── Event shape ──────────────────────────────────────────────────────────

    public function test_website_events_declare_their_source(): void
    {
        $product = $this->product();
        $this->get('/product/'.$product->slug)->assertOk();

        $event = $this->lastEvent();

        $this->assertSame('website', $event['action_source']);
        $this->assertStringContainsString('/product/'.$product->slug, $event['event_source_url']);
        $this->assertSame('ViewContent', $event['event_name']);
    }

    public function test_the_browser_and_the_server_agree_on_the_view_content_event_id(): void
    {
        $product = $this->product();

        $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

        $serverEventId = $this->lastEvent()['event_id'];

        // The Blade prints the same id into fbq(..., { eventID }); if these ever
        // drift apart Meta counts one action twice.
        $this->assertStringContainsString($serverEventId, $html);
        $this->assertStringContainsString('eventID', $html);
    }

    public function test_the_purchase_event_id_is_the_order_number(): void
    {
        // Deterministic on purpose: a retry re-sends the same logical event
        // rather than inventing a second Purchase for one order.
        $order = $this->order();

        app(MetaTrackingService::class)->purchase($order, $order->order_number);

        $this->assertSame($order->order_number, $this->lastEvent()['event_id']);
    }

    // ── Test event code ──────────────────────────────────────────────────────

    public function test_a_leftover_test_code_does_not_divert_live_traffic(): void
    {
        $this->configureMeta(['test_event_code' => 'TEST1234']);
        app()->detectEnvironment(fn () => 'production');

        app(MetaTrackingService::class)->purchase($this->order(), '10001');

        $sent = null;
        Http::recorded(function ($request) use (&$sent) {
            $sent = $request;

            return true;
        });

        $this->assertArrayNotHasKey('test_event_code', $sent->data());
    }

    public function test_the_admin_test_panel_still_uses_the_code(): void
    {
        $this->configureMeta(['test_event_code' => 'TEST1234']);
        app()->detectEnvironment(fn () => 'production');

        app(MetaTrackingService::class)->sendTest('ViewContent');

        $sent = null;
        Http::recorded(function ($request) use (&$sent) {
            $sent = $request;

            return true;
        });

        $this->assertSame('TEST1234', $sent->data()['test_event_code']);
    }

    protected function order(): Order
    {
        $order = Order::create([
            'order_number' => '10001',
            'customer_name' => 'Fatima Rahman',
            'customer_phone' => '01712345678',
            'customer_email' => 'Buyer@Example.com',
            'shipping_address' => '12 Road 5',
            'city' => 'Dhaka',
            'district' => 'Dhaka',
            'subtotal' => 550,
            'total' => 550,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'product_id' => $this->product()->id,
            'name' => 'Gold Ring',
            'price' => 550,
            'quantity' => 1,
            'subtotal' => 550,
        ]);

        return $order->fresh('items');
    }

    /** Credentials live encrypted in the settings table, never in .env. */
    protected function configureMeta(array $extra = []): void
    {
        Setting::put('meta_integration', array_merge([
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('test-token'),
        ], $extra));

        // MetaSettings memoises on first read; drop any instance built earlier.
        app()->forgetInstance(\App\Services\Meta\MetaSettings::class);
    }
}
