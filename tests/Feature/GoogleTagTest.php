<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Google\GoogleTagService;
use App\Services\SystemConfig\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Google tag's failure mode is silence. gtag accepts a malformed ID without
 * complaint, renders normally, and records nothing — the only symptom is a
 * conversion column that stays at zero while the money goes out. So the shape
 * checks, and the refusal to emit half a configuration, are the behaviour worth
 * holding in place.
 */
class GoogleTagTest extends TestCase
{
    use RefreshDatabase;

    protected function tag(): GoogleTagService
    {
        return app(GoogleTagService::class);
    }

    protected function configure(array $values): void
    {
        config($values);
    }

    protected function product(string $name = 'Solitaire Ring'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        $product = Product::create([
            'name' => $name, 'slug' => Str::slug($name), 'status' => 'published',
            'price' => 1000, 'category_id' => $category->id, 'stock_quantity' => 5, 'in_stock' => true,
        ]);

        ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'is_primary' => true]);

        return $product->fresh();
    }

    // ── ID validation ────────────────────────────────────────────────────────

    public function test_a_well_formed_pair_of_ids_is_accepted(): void
    {
        $this->configure([
            'google.analytics_id' => 'G-ABC1234567',
            'google.ads_id' => 'AW-123456789',
        ]);

        $this->assertSame('G-ABC1234567', $this->tag()->analyticsId());
        $this->assertSame('AW-123456789', $this->tag()->adsId());
        $this->assertSame(['G-ABC1234567', 'AW-123456789'], $this->tag()->tagIds());
        $this->assertTrue($this->tag()->enabled());
    }

    /**
     * The two prefixes are not interchangeable, and swapping them is the
     * mistake a first-timer actually makes — the IDs sit next to each other in
     * Google's own UI.
     */
    public function test_a_malformed_id_is_dropped_rather_than_emitted(): void
    {
        $this->configure([
            'google.analytics_id' => 'AW-123456789',   // an Ads ID in the GA4 field
            'google.ads_id' => 'G-ABC1234567',         // and the reverse
        ]);

        $this->assertNull($this->tag()->analyticsId());
        $this->assertNull($this->tag()->adsId());
        $this->assertFalse($this->tag()->enabled());
    }

    public function test_blank_and_whitespace_count_as_unset(): void
    {
        $this->configure(['google.analytics_id' => '   ', 'google.ads_id' => '']);

        $this->assertNull($this->tag()->analyticsId());
        $this->assertSame([], $this->tag()->tagIds());
    }

    // ── The conversion pair ──────────────────────────────────────────────────

    public function test_a_purchase_needs_both_the_ads_id_and_its_label(): void
    {
        $this->configure(['google.ads_id' => 'AW-123456789', 'google.ads_purchase_label' => null]);
        $this->assertNull($this->tag()->purchaseSendTo(), 'An ID with no label records nothing.');

        $this->configure(['google.ads_id' => null, 'google.ads_purchase_label' => 'AbC-D_efGhIjKlM']);
        $this->assertNull($this->tag()->purchaseSendTo(), 'A label with no ID has nowhere to send.');

        $this->configure(['google.ads_id' => 'AW-123456789', 'google.ads_purchase_label' => 'AbC-D_efGhIjKlM']);
        $this->assertSame('AW-123456789/AbC-D_efGhIjKlM', $this->tag()->purchaseSendTo());
    }

    // ── Enhanced conversions ─────────────────────────────────────────────────

    public function test_identifiers_are_hashed_and_never_sent_in_the_clear(): void
    {
        $this->configure(['google.enhanced_conversions' => true]);

        $customer = (object) ['email' => '  Angkon@Example.COM ', 'phone' => '01712345678'];

        $data = $this->tag()->userData($customer);

        // Lower-cased and trimmed before hashing, per Google's spec.
        $this->assertSame(hash('sha256', 'angkon@example.com'), $data['sha256_email_address']);
        // Stored locally as 01XXXXXXXXX; Google wants E.164.
        $this->assertSame(hash('sha256', '+8801712345678'), $data['sha256_phone_number']);

        $encoded = json_encode($data);
        $this->assertStringNotContainsString('angkon@example.com', $encoded);
        $this->assertStringNotContainsString('01712345678', $encoded);
    }

    public function test_enhanced_conversions_can_be_switched_off(): void
    {
        $this->configure(['google.enhanced_conversions' => false]);

        $this->assertSame([], $this->tag()->userData((object) ['email' => 'a@b.test']));
    }

    public function test_a_guest_contributes_no_user_data(): void
    {
        $this->configure(['google.enhanced_conversions' => true]);

        $this->assertSame([], $this->tag()->userData(null));
    }

    // ── What reaches the page ────────────────────────────────────────────────

    public function test_no_tag_is_rendered_when_nothing_is_configured(): void
    {
        $this->configure(['google.analytics_id' => null, 'google.ads_id' => null]);
        $this->product();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('gtag(', $html);
    }

    public function test_the_tag_is_rendered_once_configured(): void
    {
        $this->configure(['google.analytics_id' => 'G-ABC1234567', 'google.ads_id' => 'AW-123456789']);
        $this->product();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('googletagmanager.com/gtag/js', $html);
        $this->assertStringContainsString('G-ABC1234567', $html);
        $this->assertStringContainsString('AW-123456789', $html);
    }

    /**
     * Reset URLs carry a token and the customer's email, and the tag reports the
     * full page URL. Same exclusion the Pixel makes.
     */
    public function test_the_tag_stays_off_password_reset_pages(): void
    {
        $this->configure(['google.analytics_id' => 'G-ABC1234567']);

        $html = $this->get('/password/reset')->getContent();

        $this->assertStringNotContainsString('G-ABC1234567', $html);
    }

    // ── Site verification ────────────────────────────────────────────────────

    public function test_the_verification_tag_appears_only_when_a_token_is_set(): void
    {
        $this->product();

        $this->configure(['google.site_verification' => null]);
        $this->assertStringNotContainsString('google-site-verification', $this->get('/')->getContent());

        $this->configure(['google.site_verification' => 'abc123TOKEN']);
        $this->assertStringContainsString(
            '<meta name="google-site-verification" content="abc123TOKEN">',
            $this->get('/')->getContent(),
        );
    }

    // ── Content Security Policy ──────────────────────────────────────────────

    /**
     * The CSP blocked googletagmanager outright, and the tag was written,
     * reviewed and nearly shipped before a browser said so — no PHP test could
     * have caught it, because the server renders the script perfectly and the
     * browser is what refuses to run it.
     *
     * Every host below was observed being hit by a real gtag load. Dropping one
     * does not break a page or raise an error; it silently empties whichever
     * part of the reporting depended on it.
     */
    public function test_the_csp_allows_every_host_the_google_tag_actually_uses(): void
    {
        $this->product();
        $this->configure(['google.analytics_id' => 'G-ABC1234567', 'google.ads_id' => 'AW-123456789']);

        $csp = $this->get('/')->assertOk()->headers->get('Content-Security-Policy')
            ?: $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($csp, 'No CSP header is being sent at all.');

        [$script, $connect] = [
            $this->directive($csp, 'script-src'),
            $this->directive($csp, 'connect-src'),
        ];

        // gtag.js itself, plus the further script an Ads conversion injects.
        foreach (['https://www.googletagmanager.com', 'https://googleads.g.doubleclick.net', 'https://www.googleadservices.com'] as $host) {
            $this->assertStringContainsString($host, $script, "script-src must allow {$host}.");
        }

        // Where fired events are actually delivered — none of it goes back to
        // the host the script came from.
        foreach ([
            'https://www.google-analytics.com',   // GA4 measurement
            'https://www.google.com',             // conversions + remarketing
            'https://www.google.com.bd',          // the visitor's country domain
            'https://ad.doubleclick.net',
            'https://googleads.g.doubleclick.net',
        ] as $host) {
            $this->assertStringContainsString($host, $connect, "connect-src must allow {$host}.");
        }
    }

    private function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if (str_starts_with($part, $name.' ')) {
                return $part;
            }
        }

        return '';
    }

    // ── The admin's connection test ──────────────────────────────────────────

    public function test_the_connection_test_catches_a_half_finished_conversion_setup(): void
    {
        $tester = app(ConnectionTester::class);

        $result = $tester->test('google', [
            'google.ads_id' => 'AW-123456789',
            'google.ads_purchase_label' => '',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('purchase label', $result['message']);
    }

    public function test_the_connection_test_rejects_swapped_ids(): void
    {
        $result = app(ConnectionTester::class)->test('google', ['google.analytics_id' => 'AW-123456789']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('G-XXXXXXXXXX', $result['message']);
    }

    public function test_the_connection_test_confirms_a_complete_setup(): void
    {
        $result = app(ConnectionTester::class)->test('google', [
            'google.analytics_id' => 'G-ABC1234567',
            'google.ads_id' => 'AW-123456789',
            'google.ads_purchase_label' => 'AbC-D_efGhIjKlM',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('GA4', $result['message']);
        $this->assertStringContainsString('Ads conversions', $result['message']);
    }
}
