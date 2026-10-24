<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\Meta\SendDeferredBrowserEvent;
use App\Models\Product;
use App\Services\Meta\MetaTrackingService;
use App\Support\MetaIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Meta's diagnostics of 2026-10-11 ("send client IP addresses that match the
 * IP addresses from your Pixel", 55% of ViewContents) and 2026-09-29 ("improve
 * your rate of Pixel events covered by Conversions API", PageView).
 *
 * The browser looks up its own IPv6 address (partials/meta-pixel) and server
 * events prefer it over a carrier's shared IPv4. A landing page, whose lookup
 * has not run yet, holds its events in the queue for the browser's report. And
 * every page now sends a server PageView under the id the Pixel fires with.
 */
class MetaClientIpAndPageViewTest extends TestCase
{
    use RefreshDatabase;

    private const V4 = '103.181.163.108';

    private const V6 = '2400:c600:3361:9a2e:1d4c:77b0:12ab:9f01';

    private const AGENT = 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Setting::put('meta_integration', [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('test-token'),
        ]);
        app()->forgetInstance(\App\Services\Meta\MetaSettings::class);

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $this->withServerVariables(['REMOTE_ADDR' => self::V4])
            ->withHeader('User-Agent', self::AGENT);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Matching Ring', 'slug' => 'matching-ring', 'status' => 'published',
            'price' => 4200, 'manage_stock' => false,
        ]);
    }

    /** @return array<int,array<string,mixed>> every event sent, in order */
    private function sent(?string $name = null): array
    {
        $events = [];
        Http::recorded(function ($request) use (&$events, $name) {
            $event = $request->data()['data'][0] ?? null;
            if ($event && ($name === null || $event['event_name'] === $name)) {
                $events[] = $event;
            }

            return true;
        });

        return $events;
    }

    // ── Which address goes out ───────────────────────────────────────────────

    public function test_a_request_over_ipv4_reports_the_ipv6_address_the_browser_found(): void
    {
        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, self::V6)
            ->get('/product/'.$this->product()->slug)->assertOk();

        $this->assertSame(self::V6, $this->sent('ViewContent')[0]['user_data']['client_ip_address']);
        $this->assertSame(self::V6, $this->sent('PageView')[0]['user_data']['client_ip_address']);
    }

    public function test_a_request_already_over_ipv6_keeps_its_own_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '2400:c600:aaaa:bbbb::7'])
            ->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, self::V6)
            ->get('/product/'.$this->product()->slug)->assertOk();

        $this->assertSame('2400:c600:aaaa:bbbb::7', $this->sent('ViewContent')[0]['user_data']['client_ip_address']);
    }

    public function test_a_browser_with_no_ipv6_keeps_the_request_address_and_sends_at_once(): void
    {
        Queue::fake();

        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')
            ->get('/product/'.$this->product()->slug)->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(self::V4, $this->sent('ViewContent')[0]['user_data']['client_ip_address']);
    }

    public function test_an_address_that_is_not_a_public_ipv6_one_is_ignored(): void
    {
        foreach (['fe80::1', '::1', 'fc00::5', '10.0.0.1', '<script>', str_repeat('a:', 30)] as $bad) {
            $this->assertNull(MetaIdentity::publicIpv6($bad), $bad);
        }
        $this->assertSame(self::V6, MetaIdentity::publicIpv6(' '.strtoupper(self::V6).' '));

        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, 'fe80::1')
            ->get('/product/'.$this->product()->slug)->assertOk();

        $this->assertSame(self::V4, $this->sent('ViewContent')[0]['user_data']['client_ip_address']);
    }

    // ── A landing page waits for the browser's report ───────────────────────

    public function test_a_landing_page_holds_its_events_for_the_browsers_report(): void
    {
        Queue::fake();

        $this->get('/product/'.$this->product()->slug)->assertOk();

        $this->assertSame([], $this->sent(), 'nothing should leave before the browser reports');
        Queue::assertPushed(SendDeferredBrowserEvent::class, 2);
        Queue::assertPushed(SendDeferredBrowserEvent::class, fn ($job) => $job->event === 'ViewContent'
            && $job->delay === SendDeferredBrowserEvent::DELAY
            && $job->context['ip6_pending'] === true
            && filled($job->context['ip6_key']));
        Queue::assertPushed(SendDeferredBrowserEvent::class, fn ($job) => $job->event === 'PageView');
    }

    public function test_the_held_event_goes_out_with_the_reported_address(): void
    {
        Queue::fake();
        $this->withCookie('visitor_token', 'visitor-abc')
            ->get('/product/'.$this->product()->slug)->assertOk();

        // The browser's report, through the real endpoint.
        $this->withCookie('visitor_token', 'visitor-abc')
            ->withCredentials()->postJson(route('meta.ip6'), ['ip' => self::V6])->assertNoContent();

        $jobs = Queue::pushed(SendDeferredBrowserEvent::class);
        foreach ($jobs as $job) {
            $job->handle(app(MetaTrackingService::class));
        }

        $view = $this->sent('ViewContent')[0];
        $this->assertSame(self::V6, $view['user_data']['client_ip_address']);
        $this->assertStringContainsString('/product/matching-ring', $view['event_source_url']);
        $this->assertSame(self::V6, $this->sent('PageView')[0]['user_data']['client_ip_address']);
    }

    public function test_a_held_event_with_no_report_still_goes_out_with_the_request_address(): void
    {
        Queue::fake();
        $this->get('/product/'.$this->product()->slug)->assertOk();

        foreach (Queue::pushed(SendDeferredBrowserEvent::class) as $job) {
            $job->handle(app(MetaTrackingService::class));
        }

        $this->assertSame(self::V4, $this->sent('ViewContent')[0]['user_data']['client_ip_address']);
    }

    public function test_a_crawler_landing_is_not_queued(): void
    {
        Queue::fake();

        $this->withHeader('User-Agent', 'facebookexternalhit/1.1')
            ->get('/product/'.$this->product()->slug)->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame([], $this->sent());
    }

    public function test_nothing_waits_when_the_pixel_is_off_since_no_browser_will_report(): void
    {
        Queue::fake();
        \App\Models\Setting::put('meta_integration', array_merge(\App\Models\Setting::get('meta_integration'), ['pixel_enabled' => false]));
        app()->forgetInstance(\App\Services\Meta\MetaSettings::class);

        $this->get('/product/'.$this->product()->slug)->assertOk();

        Queue::assertNothingPushed();
        $this->assertCount(1, $this->sent('ViewContent'));
        $this->assertSame([], $this->sent('PageView'), 'no Pixel PageView, so no server twin');
    }

    public function test_the_report_endpoint_keeps_only_a_public_ipv6_address(): void
    {
        $key = 'meta.ip6.'.MetaIdentity::pseudonym('visitor:visitor-xyz');

        $this->withCookie('visitor_token', 'visitor-xyz')
            ->withCredentials()->postJson(route('meta.ip6'), ['ip' => '10.1.2.3'])->assertNoContent();
        $this->assertNull(Cache::get($key));

        $this->withCookie('visitor_token', 'visitor-xyz')
            ->withCredentials()->postJson(route('meta.ip6'), ['ip' => self::V6])->assertNoContent();
        $this->assertSame(self::V6, Cache::get($key));
    }

    // ── The browser side ────────────────────────────────────────────────────

    public function test_the_page_carries_the_lookup_and_the_csp_allows_it(): void
    {
        $res = $this->get('/product/'.$this->product()->slug)->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('ipv6.icanhazip.com', $html);
        $this->assertStringContainsString(MetaIdentity::IP6_COOKIE.'=', $html);
        $this->assertStringContainsString("referrerPolicy: 'no-referrer'", $html);
        $this->assertStringContainsString('\/meta\/ip6', $html);

        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('/connect-src[^;]*https:\/\/ipv6\.icanhazip\.com/', $csp);
    }

    // ── PageView ─────────────────────────────────────────────────────────────

    public function test_a_full_page_load_sends_a_server_pageview_under_the_pixels_id(): void
    {
        $html = $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')
            ->get('/product/'.$this->product()->slug)->assertOk()->getContent();

        $pageView = $this->sent('PageView');
        $this->assertCount(1, $pageView);
        $this->assertStringStartsWith('PageView.', $pageView[0]['event_id']);
        $this->assertSame('website', $pageView[0]['action_source']);
        $this->assertArrayNotHasKey('custom_data', array_filter($pageView[0]));

        // The Pixel's PageView carries the very same id.
        $this->assertMatchesRegularExpression(
            '/fbq\(\'track\', \'PageView\', \{\}, [^;]*'.preg_quote($pageView[0]['event_id'], '/').'/',
            $html,
        );
    }

    public function test_an_inertia_navigation_hands_react_the_id_it_sent(): void
    {
        $product = $this->product();
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $page = $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/product/'.$product->slug)->assertOk()->json();

        $pageView = $this->sent('PageView');
        $this->assertCount(1, $pageView);
        $this->assertSame($pageView[0]['event_id'], $page['props']['metaPageView']);
    }

    public function test_a_partial_reload_is_not_a_page_view(): void
    {
        $product = $this->product();
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')
            ->withHeaders([
                'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest',
                'X-Inertia-Partial-Component' => 'Product', 'X-Inertia-Partial-Data' => 'product',
            ])
            ->get('/product/'.$product->slug)->assertOk();

        $this->assertSame([], $this->sent('PageView'));
    }

    public function test_json_redirects_and_posts_send_no_pageview(): void
    {
        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')->getJson('/search/suggest?q=ring')->assertOk();
        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')->postJson(route('meta.ip6'), ['ip' => self::V6]);

        $this->assertSame([], $this->sent('PageView'));
    }

    public function test_the_pageview_toggle_turns_both_copies_off(): void
    {
        \App\Models\Setting::put('meta_integration', array_merge(\App\Models\Setting::get('meta_integration'), ['track_pageview' => false]));
        app()->forgetInstance(\App\Services\Meta\MetaSettings::class);

        $html = $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')
            ->get('/product/'.$this->product()->slug)->assertOk()->getContent();

        $this->assertSame([], $this->sent('PageView'));
        $this->assertStringNotContainsString("fbq('track', 'PageView'", $html);
    }

    public function test_the_meta_log_line_says_which_kind_of_address_went_out(): void
    {
        $handler = new \Monolog\Handler\TestHandler;
        \Illuminate\Support\Facades\Log::channel('meta-debug')->getLogger()->pushHandler($handler);

        $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, self::V6)
            ->get('/product/'.$this->product()->slug)->assertOk();

        $lines = array_filter($handler->getRecords(), fn ($r) => ($r['context']['event'] ?? null) === 'ViewContent');
        $this->assertNotEmpty($lines);
        $line = array_values($lines)[0]['context'];
        $this->assertSame(6, $line['ip_v']);
        $this->assertStringNotContainsString(self::V6, json_encode($line), 'the address itself is never logged');
    }
}
