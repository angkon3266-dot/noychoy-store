<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Order;
use App\Models\Product;
use App\Support\MetaIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Events Manager, 2026-10-31: AddToCart 6.6/10, InitiateCheckout 6.1/10, and
 * on ViewContent fbp on 57% of events, phone and name on 5%. Three fixes:
 * the server mints _fbp on a shopper's first page, a returning guest buyer's
 * details ride on server events, and the store's country goes on every one.
 */
class MetaMatchQualityTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $this->withHeader('User-Agent', self::AGENT);
    }

    private function configureMeta(array $extra = []): void
    {
        \App\Models\Setting::put('meta_integration', array_merge([
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('test-token'),
        ], $extra));
        app()->forgetInstance(\App\Services\Meta\MetaSettings::class);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Match Ring', 'slug' => 'match-ring', 'status' => 'published',
            'price' => 4200, 'manage_stock' => false,
        ]);
    }

    /** user_data of the last event of that name. */
    private function userData(string $event): array
    {
        $found = [];
        Http::recorded(function ($request) use (&$found, $event) {
            $data = $request->data()['data'][0] ?? [];
            if (($data['event_name'] ?? null) === $event) {
                $found = $data['user_data'];
            }

            return true;
        });

        return $found;
    }

    private function viewProduct(): \Illuminate\Testing\TestResponse
    {
        // "-": the IPv6 lookup has run, so the events go at once.
        return $this->withUnencryptedCookie(MetaIdentity::IP6_COOKIE, '-')
            ->get('/product/'.$this->product()->slug)->assertOk();
    }

    // ── _fbp ─────────────────────────────────────────────────────────────────

    public function test_a_first_page_mints_the_browser_id_and_reports_it(): void
    {
        $res = $this->viewProduct();

        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === MetaIdentity::FBP_COOKIE);
        $this->assertNotNull($cookie, 'no _fbp cookie set');
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.\d{10}$/', $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly(), 'the Pixel must be able to read it');
        $this->assertGreaterThan(now()->addDays(89)->timestamp, $cookie->getExpiresTime());

        // The same id on this very page's events, and on the Pixel's.
        $this->assertSame($cookie->getValue(), $this->userData('ViewContent')['fbp']);
        $this->assertSame($cookie->getValue(), $this->userData('PageView')['fbp']);
    }

    public function test_an_existing_browser_id_is_kept(): void
    {
        $res = $this->withUnencryptedCookie(MetaIdentity::FBP_COOKIE, 'fb.1.1700000000000.1234567890')->viewProduct();

        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === MetaIdentity::FBP_COOKIE));
        $this->assertSame('fb.1.1700000000000.1234567890', $this->userData('ViewContent')['fbp']);
    }

    public function test_a_crawler_gets_no_browser_id(): void
    {
        $res = $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->viewProduct();

        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === MetaIdentity::FBP_COOKIE));
    }

    public function test_the_cookie_is_scoped_like_the_pixels(): void
    {
        $make = fn (string $host) => MetaIdentity::cookieDomain(\Illuminate\Http\Request::create("https://{$host}/"));

        $this->assertSame('.noychoy.com', $make('noychoy.com'));
        $this->assertSame('.noychoy.com', $make('www.noychoy.com'));
        $this->assertNull($make('localhost'));
        $this->assertNull($make('127.0.0.1'));
    }

    // ── Returning guest buyers ───────────────────────────────────────────────

    private function pastBuyer(bool $recovered = true): void
    {
        AbandonedCart::create([
            'visitor_token' => 'buyer-browser', 'phone' => '01712345678', 'name' => 'Fatima Rahman',
            'recovered' => $recovered,
        ]);
        Order::create([
            'order_number' => '10001', 'customer_name' => 'Fatima Rahman', 'customer_phone' => '01712345678',
            'shipping_address' => '12 Road 5', 'city' => 'Mirpur', 'district' => 'Dhaka',
            'subtotal' => 550, 'total' => 550, 'status' => 'delivered',
        ]);
    }

    public function test_a_returning_guest_buyers_details_go_on_server_events(): void
    {
        $this->pastBuyer();

        $html = $this->withCookie('visitor_token', 'buyer-browser')->viewProduct()->getContent();

        $user = $this->userData('ViewContent');
        $this->assertSame([hash('sha256', MetaIdentity::normalize('ph', '01712345678'))], $user['ph']);
        $this->assertSame([hash('sha256', 'fatima')], $user['fn']);
        $this->assertSame([hash('sha256', 'rahman')], $user['ln']);
        $this->assertSame([hash('sha256', 'mirpur')], $user['ct']);
        $this->assertArrayHasKey('ph', $this->userData('PageView'));

        // Server events only: nothing of it is printed into the page.
        $this->assertStringNotContainsString('01712345678', $html);
        $this->assertStringNotContainsString(hash('sha256', 'fatima'), $html);
    }

    public function test_a_lead_that_never_ordered_adds_nothing(): void
    {
        $this->pastBuyer(recovered: false);

        $this->withCookie('visitor_token', 'buyer-browser')->viewProduct();

        $this->assertArrayNotHasKey('ph', $this->userData('ViewContent'));
    }

    public function test_another_browser_gets_nothing(): void
    {
        $this->pastBuyer();

        $this->withCookie('visitor_token', 'someone-else')->viewProduct();

        $this->assertArrayNotHasKey('ph', $this->userData('ViewContent'));
    }

    // ── Country ──────────────────────────────────────────────────────────────

    public function test_a_guests_events_carry_the_stores_country(): void
    {
        $this->configureMeta(['country' => 'bd']);

        $this->viewProduct();

        $this->assertSame([hash('sha256', 'bd')], $this->userData('ViewContent')['country']);
        $this->assertSame([hash('sha256', 'bd')], $this->userData('PageView')['country']);
    }

    public function test_no_country_when_the_merchant_chose_not_to_send_one(): void
    {
        $this->viewProduct();

        $this->assertArrayNotHasKey('country', $this->userData('ViewContent'));
    }
}
