<?php

namespace Tests\Feature;

use App\Actions\CreateManualOrder;
use App\Http\Middleware\TrackVisit;
use App\Jobs\SendOrderPlacedEffects;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\CartService;
use App\Services\Meta\MetaSettings;
use App\Services\Meta\MetaTrackingService;
use App\Services\SmsService;
use App\Support\MetaIdentity;
use App\Support\Storefront\HomePageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The data-quality half of the Conversions API, from the 2026-09-17 read-only
 * production audit.
 *
 * Sending worked — every POST came back 200, every order had its Purchase, the
 * dedup ids lined up — but what was sent was wrong in ways Meta never reports
 * as an error, only as a lower match quality:
 *
 *  - close to half of the server ViewContents were facebookexternalhit, Meta's
 *    own link-preview fetcher, plus another 6% from other bots, none of which
 *    ran the Pixel, so none had a browser twin;
 *  - speculative prefetches (Cloudflare Speed Brain is on) counted a hovered
 *    link as a view;
 *  - an order typed into the admin went out as a website visit from 127.0.0.1
 *    with the user agent "Symfony" — the queue worker's own request;
 *  - "Add selected" on Frequently bought together sent no AddToCart at all;
 *  - the access token rode in the query string, where a timeout's exception
 *    message would carry it into laravel.log.
 *
 * Each test issues at most one storefront request: terminating callbacks are
 * not cleared between requests inside one test (see MetaCapiDeferredTest), so
 * a second request would re-run the first one's deferred send.
 */
class MetaServerEventQualityTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Linux; Android 13; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';

    private const FACEBOOK_PREVIEW = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /** A real handset in the Facebook in-app browser, whose model name contains "bot". */
    private const CUBOT_FACEBOOK = 'Mozilla/5.0 (Linux; Android 11; CUBOT_X30 Build/RP1A.200720.011; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.127 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/482.0.0.40.84;]';

    protected function setUp(): void
    {
        parent::setUp();

        // Same shape as MetaCapiPayloadTest::configureMeta().
        Setting::put('meta_integration', [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => Crypt::encryptString('test-token'),
        ]);
        app()->forgetInstance(MetaSettings::class);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1'], 200),
            // Anything else a placed order touches (alerts, SMS) stays local.
            '*' => Http::response([], 200),
        ]);
    }

    /** @return array<int,ClientRequest> every Conversions API POST so far */
    protected function capiRequests(): array
    {
        return Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), '/events'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    /** @return array<int,array> the events sent so far, optionally one kind */
    protected function sentEvents(?string $name = null): array
    {
        return collect($this->capiRequests())
            ->map(fn (ClientRequest $r) => $r->data()['data'][0])
            ->filter(fn ($e) => $name === null || $e['event_name'] === $name)
            ->values()->all();
    }

    protected function product(array $extra = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => 'Quality Ring '.$n,
            'slug' => 'quality-ring-'.$n,
            'status' => 'published',
            'price' => 1500,
            'manage_stock' => true,
            'stock_quantity' => 10,
            'in_stock' => true,
        ], $extra));
    }

    // ── Crawlers ─────────────────────────────────────────────────────────────

    public function test_meta_s_own_link_preview_fetcher_sends_no_view_content(): void
    {
        $product = $this->product();

        $this->withHeader('User-Agent', self::FACEBOOK_PREVIEW)
            ->get('/product/'.$product->slug)->assertOk();

        $this->assertSame([], $this->sentEvents(), 'a crawler has no Pixel twin and must not reach Meta');
    }

    public function test_a_real_browser_still_sends_its_view_content(): void
    {
        $product = $this->product();

        $this->withHeader('User-Agent', self::CHROME)
            ->get('/product/'.$product->slug)->assertOk();

        $events = $this->sentEvents('ViewContent');
        $this->assertCount(1, $events);
        $this->assertSame(self::CHROME, $events[0]['user_data']['client_user_agent']);
        $this->assertSame('website', $events[0]['action_source']);
    }

    public function test_a_crawler_adding_to_cart_sends_no_add_to_cart(): void
    {
        $product = $this->product();

        $this->withHeader('User-Agent', self::FACEBOOK_PREVIEW)
            ->postJson(route('cart.add', $product), [
                'qty' => 1,
                'event_id' => MetaTrackingService::newEventId('AddToCart'),
            ])->assertOk();

        $this->assertSame([], $this->sentEvents());
    }

    public function test_a_crawler_on_the_checkout_sends_no_initiate_checkout(): void
    {
        app(CartService::class)->add($this->product(), null, 1);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/checkout')->assertOk();

        $this->assertSame([], $this->sentEvents());
    }

    public function test_every_fetcher_the_audit_found_is_recognised_and_a_shopper_is_not(): void
    {
        foreach ([
            self::FACEBOOK_PREVIEW,
            'facebookcatalog/1.0',
            'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
            'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36 (compatible; Google-InspectionTool/1.0;)',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            '',
            // The search and SEO crawlers, which the word-boundary "bot" rule
            // must still catch now that it spares Cubot phones.
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
            'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
            'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
            'Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)',
            'DuckDuckBot-Https/1.1; (+https://duckduckgo.com/duckduckbot)',
            'Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)',
            'Mozilla/5.0 (compatible; Mail.RU_Bot/2.0; +http://go.mail.ru/help/robots)',
            // The chat apps' link-preview fetchers.
            'WhatsApp/2.24.18.80 A',
            'WhatsApp/2.23.20.76 i',
            'TelegramBot (like TwitterBot)',
            'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
            'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
            'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
            'Twitterbot/1.0',
            'Mozilla/5.0 (Windows NT 6.1; WOW64) SkypeUriPreview Preview/0.5 skype-url-preview@microsoft.com',
            'Mozilla/5.0 (compatible; ViberBot/1.0; +https://developers.viber.com)',
            'Viber/8.7.2.190 CFNetwork/897.15 Darwin/17.5.0',
        ] as $agent) {
            $this->assertTrue(MetaTrackingService::isMachineAgent($agent), "not recognised as a machine: {$agent}");
        }

        foreach ([
            self::CHROME,
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.97;FBBV/613425364;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.5;FBSS/3;FBID/phone;FBLC/en_US;FBOP/5]',
            // Cubot phones: "bot" as three letters anywhere took them for
            // crawlers, in exactly the in-app browsers the ads land in.
            self::CUBOT_FACEBOOK,
            'Mozilla/5.0 (Linux; Android 12; CUBOT P60 Build/SP1A.210812.016; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.127 Mobile Safari/537.36 Instagram 348.0.0.40.99 Android',
            // A browser opened from Viber names the app too, but not first.
            'Mozilla/5.0 (Linux; Android 14; SM-S921B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.0.0 Mobile Safari/537.36 Viber/27.7.0.0',
        ] as $agent) {
            $this->assertFalse(MetaTrackingService::isMachineAgent($agent), "a shopper taken for a machine: {$agent}");
        }
    }

    public function test_a_cubot_phone_in_the_facebook_app_is_a_shopper_to_meta_and_to_the_dashboard(): void
    {
        $product = $this->product();

        $this->withHeader('User-Agent', self::CUBOT_FACEBOOK)
            ->get('/product/'.$product->slug)->assertOk();

        $events = $this->sentEvents('ViewContent');
        $this->assertCount(1, $events);
        $this->assertSame(self::CUBOT_FACEBOOK, $events[0]['user_data']['client_user_agent']);

        // One rule for both: the visitor count had the same false positive.
        $this->assertFalse(TrackVisit::isBot(self::CUBOT_FACEBOOK));
        $this->assertSame(1, Visit::where('event', 'product')->count());
    }

    public function test_a_whatsapp_link_preview_sends_no_view_content(): void
    {
        $product = $this->product();

        $this->withHeader('User-Agent', 'WhatsApp/2.24.18.80 A')
            ->get('/product/'.$product->slug)->assertOk();

        $this->assertSame([], $this->sentEvents(), 'the preview card ran no Pixel, so there is no twin');
    }

    // ── Speculative fetches ──────────────────────────────────────────────────

    public function test_a_speculative_prefetch_sends_no_view_content(): void
    {
        $product = $this->product();

        // Exactly what Cloudflare Speed Brain sends.
        $this->withHeaders(['User-Agent' => self::CHROME, 'Sec-Purpose' => 'prefetch'])
            ->get('/product/'.$product->slug)->assertOk();

        $this->assertSame([], $this->sentEvents());
    }

    public function test_every_prefetch_header_is_recognised(): void
    {
        foreach ([['Sec-Purpose', 'prefetch'], ['Sec-Purpose', 'prefetch;prerender'], ['Purpose', 'prefetch'], ['X-Moz', 'prefetch']] as [$header, $value]) {
            $request = Request::create('/product/x');
            $request->headers->set($header, $value);

            $this->assertTrue(MetaTrackingService::isSpeculative($request), "{$header}: {$value} not recognised");
        }

        $this->assertFalse(MetaTrackingService::isSpeculative(Request::create('/product/x')));
    }

    public function test_with_the_pixel_off_a_prefetched_page_still_sends_its_view_content(): void
    {
        // Skipping a prefetch is only safe because the Pixel reports the view
        // when the prefetched copy is opened. With the Pixel off nothing does,
        // so the server event is the only record there will ever be.
        $this->switchPixelOff();
        $product = $this->product();

        $this->withHeaders(['User-Agent' => self::CHROME, 'Sec-Purpose' => 'prefetch'])
            ->get('/product/'.$product->slug)->assertOk();

        $this->assertCount(1, $this->sentEvents('ViewContent'));
    }

    public function test_with_the_pixel_off_a_crawler_is_still_not_a_shopper(): void
    {
        $this->switchPixelOff();

        app(MetaTrackingService::class)->viewContent($this->product(), 'VC.crawler', [], [
            'ip' => '203.0.113.9', 'ua' => self::FACEBOOK_PREVIEW, 'url' => 'https://noychoy.com/product/x',
            'time' => time(), 'external_id' => [], 'prefetch' => false,
        ]);

        $this->assertSame([], $this->sentEvents());
    }

    /** The Pixel switched off in Meta → Tracking, with the Conversions API left on. */
    protected function switchPixelOff(): void
    {
        Setting::put('meta_integration', array_merge(Setting::get('meta_integration', []), ['pixel_enabled' => false]));
        app()->forgetInstance(MetaSettings::class);

        $this->assertFalse(app(MetaTrackingService::class)->pixelEnabled());
        $this->assertTrue(app(MetaTrackingService::class)->enabled());
    }

    public function test_a_purchase_is_never_dropped_by_the_crawler_guard(): void
    {
        // A real order is a real order, whatever software placed the request.
        $product = $this->product();
        $order = Order::create([
            'order_number' => '20001', 'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01712345678',
            'shipping_address' => 'Mirpur', 'subtotal' => 1500, 'total' => 1500, 'status' => 'pending',
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'price' => 1500, 'quantity' => 1, 'subtotal' => 1500]);

        app(MetaTrackingService::class)->purchase($order->fresh('items'), '20001', [
            'ip' => '203.0.113.9', 'ua' => self::FACEBOOK_PREVIEW, 'fbc' => null, 'fbp' => null,
            'url' => 'https://noychoy.com/checkout', 'time' => time(), 'external_id' => [], 'prefetch' => true,
        ]);

        $this->assertCount(1, $this->sentEvents('Purchase'));
    }

    // ── Orders typed into the admin ──────────────────────────────────────────

    public function test_an_order_typed_into_the_admin_is_a_phone_call_with_no_invented_browser(): void
    {
        $product = $this->product(['price' => 2500]);
        // Successes go to Log::channel('meta-debug'); on a bare spy channel()
        // returns null, so hand the spy itself back to record the line.
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();

        // Whatever request is standing — the queue worker's console stub
        // (127.0.0.1, "Symfony"), or on a sync queue the owner's own admin
        // browser, as here — none of it belongs to the customer.
        request()->headers->set('User-Agent', self::CHROME);
        request()->server->set('REMOTE_ADDR', '198.51.100.7');

        $order = app(CreateManualOrder::class)->handle([
            'name' => 'Fatima Rahman',
            'phone' => '01860988859',
            'address' => 'Banani, Dhaka',
            'shipping_cost' => 70,
        ], [['product_id' => $product->id, 'variant_id' => null, 'qty' => 2]]);

        $events = $this->sentEvents('Purchase');
        $this->assertCount(1, $events);
        $event = $events[0];
        $user = $event['user_data'];

        $this->assertSame('phone_call', $event['action_source']);
        $this->assertArrayNotHasKey('event_source_url', $event);
        foreach (['client_ip_address', 'client_user_agent', 'fbp', 'fbc'] as $key) {
            $this->assertArrayNotHasKey($key, $user, "{$key} must not be invented for an order with no browser");
        }

        // What she typed in still matches, hashed.
        $this->assertSame([hash('sha256', '8801860988859')], $user['ph']);
        $this->assertSame([hash('sha256', 'fatima')], $user['fn']);
        $this->assertSame([hash('sha256', 'rahman')], $user['ln']);
        // The customer record's id, the same one her signed-in browsing carries.
        $this->assertSame(
            [MetaIdentity::pseudonym('customer:'.Customer::where('phone', '01860988859')->value('id'))],
            $user['external_id'],
        );
        $this->assertSame($order->order_number, $event['event_id']);
        $this->assertSame(5070.0, (float) $event['custom_data']['value']);

        // No Pixel ever ran for it, so the log must not promise a twin.
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Meta CAPI event sent'
            && ($context['event'] ?? null) === 'Purchase'
            && ($context['dedup_expected'] ?? null) === false
            && ($context['action_source'] ?? null) === 'phone_call');
    }

    public function test_a_job_queued_with_an_empty_context_is_treated_as_no_browser(): void
    {
        // Jobs already sitting in the production queue at deploy time carry [].
        $product = $this->product();
        $order = Order::create([
            'order_number' => '20002', 'customer_name' => 'Rafi', 'customer_phone' => '01712345678',
            'shipping_address' => 'Uttara', 'subtotal' => 1500, 'total' => 1500, 'status' => 'confirmed',
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'price' => 1500, 'quantity' => 1, 'subtotal' => 1500]);

        (new SendOrderPlacedEffects($order, []))->handle(
            app(SmsService::class), app(MetaTrackingService::class),
        );

        $event = $this->sentEvents('Purchase')[0];
        $this->assertSame('phone_call', $event['action_source']);
        $this->assertArrayNotHasKey('client_ip_address', $event['user_data']);
        $this->assertArrayNotHasKey('client_user_agent', $event['user_data']);
    }

    public function test_a_web_checkout_purchase_is_unchanged(): void
    {
        $product = $this->product(['price' => 1000]);
        app(CartService::class)->add($product, null, 2);

        $this->withHeader('User-Agent', self::CHROME)
            ->withUnencryptedCookie(MetaIdentity::FBP_COOKIE, 'fb.1.1700000000000.42')
            ->post('/checkout', [
                'name' => 'Test Buyer',
                'phone' => '01712345678',
                'address' => '123 Road, Dhaka',
                'is_inside_dhaka' => 1,
            ])->assertRedirect();

        $events = $this->sentEvents('Purchase');
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('website', $event['action_source']);
        $this->assertStringContainsString('/checkout', $event['event_source_url']);
        $this->assertSame(self::CHROME, $event['user_data']['client_user_agent']);
        $this->assertArrayHasKey('client_ip_address', $event['user_data']);
        $this->assertSame('fb.1.1700000000000.42', $event['user_data']['fbp']);
    }

    // ── Frequently bought together ───────────────────────────────────────────

    public function test_add_selected_sends_one_add_to_cart_for_the_lines_that_went_in(): void
    {
        $a = $this->product(['price' => 1000]);
        $b = $this->product(['price' => 1500]);
        // Skipped by the bundle — it needs an option chosen — so not reported.
        $variable = $this->product(['price' => 9000, 'has_variants' => true]);
        $eventId = MetaTrackingService::newEventId('AddToCart');

        $this->withHeader('User-Agent', self::CHROME)
            ->post('/cart/add-many', [
                'product_ids' => [$a->id, $b->id, $variable->id],
                'event_id' => $eventId,
            ])->assertRedirect();

        $events = $this->sentEvents('AddToCart');
        $this->assertCount(1, $events, 'one decision, one event — the Pixel fires once with this id');

        $custom = $events[0]['custom_data'];
        $this->assertSame($eventId, $events[0]['event_id']);
        $this->assertEqualsCanonicalizing(["prod-{$a->id}", "prod-{$b->id}"], $custom['content_ids']);
        $this->assertEqualsCanonicalizing([
            ['id' => "prod-{$a->id}", 'quantity' => 1, 'item_price' => 1000],
            ['id' => "prod-{$b->id}", 'quantity' => 1, 'item_price' => 1500],
        ], $custom['contents']);
        $this->assertEquals(2500, $custom['value']);
        $this->assertSame('BDT', $custom['currency']);
        $this->assertSame('website', $events[0]['action_source']);

        // The dashboard still counts the bundle once.
        $this->assertSame(1, Visit::where('event', 'cart_add')->count());
    }

    public function test_add_selected_without_an_event_id_sends_nothing_to_meta(): void
    {
        // No id means no Pixel twin to deduplicate against.
        $a = $this->product();

        $this->withHeader('User-Agent', self::CHROME)
            ->post('/cart/add-many', ['product_ids' => [$a->id]])->assertRedirect();

        $this->assertSame([], $this->sentEvents());
    }

    // ── The homepage buy box ─────────────────────────────────────────────────

    public function test_the_buy_box_tells_the_page_which_products_need_an_option_chosen(): void
    {
        // BuyBoxBlock fires the Pixel AddToCart as it posts the add. The cart
        // refuses a product with options until one is chosen, so without this
        // flag the block fired a Pixel event for an add that never happened;
        // with it, that product gets a link to its page instead.
        $simple = $this->product(['price' => 1200]);
        $variable = $this->product(['price' => 3400, 'has_variants' => true]);

        $data = HomePageData::make(collect(), collect(), collect(), collect(), collect([
            ['type' => 'buy_box', 'title' => 'Pick yours', 'products' => collect([$simple, $variable])],
        ]));

        $items = collect($data['blocks']->first()['items'])->keyBy('id');

        $this->assertFalse($items[$simple->id]['has_variants']);
        $this->assertSame(1200.0, $items[$simple->id]['price']);
        $this->assertTrue($items[$variable->id]['has_variants']);
        $this->assertSame(route('product.show', $variable), $items[$variable->id]['url']);

        // And the refusal the flag stands for, so the two cannot drift apart.
        $this->withHeader('User-Agent', self::CHROME)
            ->postJson(route('cart.add', $variable), ['qty' => 1, 'event_id' => MetaTrackingService::newEventId('AddToCart')]);

        $this->assertSame([], $this->sentEvents());
        $this->assertSame(0, app(CartService::class)->count());
    }

    public function test_add_selected_rejects_an_oversized_event_id(): void
    {
        $a = $this->product();

        $this->post('/cart/add-many', ['product_ids' => [$a->id], 'event_id' => str_repeat('x', 101)])
            ->assertSessionHasErrors('event_id');
    }

    // ── The access token ─────────────────────────────────────────────────────

    public function test_the_access_token_is_never_in_the_request_url(): void
    {
        app(MetaTrackingService::class)->initiateCheckout(['prod-1'], 1500.0, 1, 'IC.token', [], [
            'ip' => '203.0.113.9', 'ua' => self::CHROME, 'url' => 'https://noychoy.com/checkout', 'time' => time(),
        ]);

        $requests = $this->capiRequests();
        $this->assertCount(1, $requests);
        $this->assertStringNotContainsString('access_token', $requests[0]->url());
        $this->assertStringNotContainsString('test-token', $requests[0]->url());
        $this->assertSame('test-token', $requests[0]->data()['access_token']);
    }

    public function test_a_transport_error_cannot_carry_the_token_into_the_log(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Operation timed out for https://graph.facebook.com/v21.0/1234567890/events?access_token=test-token'
            );
        });
        Log::spy();

        $result = app(MetaTrackingService::class)->initiateCheckout(['prod-1'], 1500.0, 1, 'IC.timeout', [], [
            'ip' => '203.0.113.9', 'ua' => self::CHROME, 'url' => 'https://noychoy.com/checkout', 'time' => time(),
        ]);

        $this->assertNull($result, 'initiateCheckout() is fire-and-forget');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'Meta CAPI event failed'
            && ! str_contains((string) ($context['error'] ?? ''), 'test-token')
            && str_contains((string) ($context['error'] ?? ''), '[redacted]'));
    }
}
