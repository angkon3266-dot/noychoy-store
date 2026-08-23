<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\CartService;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Conversions API POST used to run on the render path: a product page or a
 * checkout blocked on graph.facebook.com before a byte of HTML was written.
 * The three storefront events now fire from a terminating callback instead.
 *
 * These assertions are only possible because Application::terminate() runs its
 * callbacks FIFO, and the test client terminates the kernel inside the request
 * helper — so a probe registered BEFORE the request runs before the
 * controller's own callback, at the moment the response has been built.
 *
 * Nothing else in the suite would catch a regression here: every existing CAPI
 * assertion passes whether the send is inline or deferred, because by the time
 * the test reads them the kernel has already terminated. Each test issues
 * exactly one request — terminating callbacks are not cleared between requests
 * within a process, so a second request would re-run the probe.
 */
class MetaCapiDeferredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same shape as MetaCapiPayloadTest::configureMeta().
        \App\Models\Setting::put('meta_integration', [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('test-token'),
        ]);
        app()->forgetInstance(\App\Services\Meta\MetaSettings::class);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);
    }

    protected function product(): Product
    {
        return Product::create([
            'name' => 'Deferred Ring',
            'slug' => 'deferred-ring',
            'status' => 'published',
            'price' => 4200,
            'manage_stock' => false,
        ]);
    }

    /** How many CAPI POSTs have gone out so far. */
    protected function sentCount(): int
    {
        $n = 0;
        Http::recorded(function () use (&$n) {
            $n++;

            return true;
        });

        return $n;
    }

    public function test_view_content_is_sent_after_the_response_not_on_the_render_path(): void
    {
        $product = $this->product();
        $beforeOurs = null;

        // Registered first, so it runs first — the response is built and
        // flushed at this point, and nothing should have gone to Graph yet.
        $this->app->terminating(function () use (&$beforeOurs) {
            $beforeOurs = $this->sentCount();
        });

        $this->get('/product/'.$product->slug)->assertOk();

        $this->assertSame(0, $beforeOurs, 'ViewContent was POSTed before the HTML was written');
        $this->assertSame(1, $this->sentCount(), 'ViewContent was deferred but never sent');
    }

    public function test_initiate_checkout_is_sent_after_the_response(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product, null, 1);
        $beforeOurs = null;

        $this->app->terminating(function () use (&$beforeOurs) {
            $beforeOurs = $this->sentCount();
        });

        $this->get('/checkout')->assertOk();

        $this->assertSame(0, $beforeOurs, 'InitiateCheckout was POSTed before the checkout rendered');
        $this->assertSame(1, $this->sentCount(), 'InitiateCheckout was deferred but never sent');
    }

    public function test_add_to_cart_is_sent_after_the_mini_cart_json(): void
    {
        $product = $this->product();
        $beforeOurs = null;

        $this->app->terminating(function () use (&$beforeOurs) {
            $beforeOurs = $this->sentCount();
        });

        // The controller only sends AddToCart when the browser supplies the
        // event id it used for the Pixel, so the two can be deduplicated.
        $this->postJson(route('cart.add', $product), [
            'qty' => 1,
            'event_id' => MetaTrackingService::newEventId('AddToCart'),
        ])->assertOk();

        $this->assertSame(0, $beforeOurs, 'AddToCart was POSTed before the mini-cart JSON was returned');
        $this->assertSame(1, $this->sentCount(), 'AddToCart was deferred but never sent');
    }

    public function test_the_deferred_events_fail_fast_but_purchase_keeps_its_full_window(): void
    {
        // A deferred send still holds a PHP worker on shared hosting, so the
        // storefront events must not wait ten seconds on a stuck connection.
        // Purchase is the exception: it is queued, it carries the revenue, and
        // send() never throws — so a timeout there is silent, permanent loss.
        $reflection = new \ReflectionClass(MetaTrackingService::class);

        $this->assertSame(5, $reflection->getConstant('STOREFRONT_TIMEOUT'));

        $send = $reflection->getMethod('send');
        $timeout = collect($send->getParameters())->firstWhere('name', 'timeout');

        $this->assertNotNull($timeout, 'send() must take an opt-in timeout');
        $this->assertTrue(
            $timeout->isDefaultValueAvailable() && $timeout->getDefaultValue() === null,
            'the short timeout must be opt-in, so Purchase is not shortened by accident'
        );
    }
}
