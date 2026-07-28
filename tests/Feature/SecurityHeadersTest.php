<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HTTPS enforcement and the response security headers.
 *
 * The redirect test that matters most is the Cloudflare one: under Flexible
 * SSL the visitor is on HTTPS but the edge→origin hop is plain HTTP, so a
 * check against the raw connection would bounce every real visitor forever.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** A Cloudflare edge address, from the ranges trusted in bootstrap/app.php. */
    protected const CF_EDGE = '172.64.0.10';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/ping', fn () => 'ok');
        Route::middleware('web')->post('/_test/ping', fn () => 'ok');
    }

    protected function forceHttps(): void
    {
        config(['security.https.redirect' => true]);
    }

    public function test_plain_http_is_redirected_permanently(): void
    {
        $this->forceHttps();

        $target = $this->get('/_test/ping?utm_source=facebook')
            ->assertStatus(301)
            ->headers->get('Location');

        $this->assertStringStartsWith('https://', $target);

        // The campaign tag has to survive: attribution reads utm_* off the
        // landing request, and dropping the query would make every ad click
        // look like direct traffic.
        $this->assertStringEndsWith('/_test/ping?utm_source=facebook', $target);
    }

    public function test_a_visitor_on_https_through_cloudflare_is_not_redirected(): void
    {
        $this->forceHttps();

        // Flexible SSL: the origin connection is plain HTTP, and only
        // X-Forwarded-Proto says the visitor was on HTTPS. Redirecting here
        // would be an infinite loop for every customer on the site.
        $this->withServerVariables(['REMOTE_ADDR' => self::CF_EDGE])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/_test/ping')
            ->assertOk();
    }

    public function test_a_forwarded_scheme_from_an_untrusted_source_is_ignored(): void
    {
        $this->forceHttps();

        // Anyone can send this header straight to the origin IP. Honouring it
        // would let a client opt out of HTTPS enforcement.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/_test/ping')
            ->assertStatus(301);
    }

    public function test_a_post_keeps_its_method_and_body(): void
    {
        $this->forceHttps();

        // 308, not 301: a 301 lets the browser rewrite the request to GET, so a
        // courier webhook or checkout arriving over HTTP would lose its payload.
        $this->post('/_test/ping', ['a' => 'b'])->assertStatus(308);
    }

    public function test_excepted_paths_answer_on_either_scheme(): void
    {
        $this->forceHttps();
        config(['security.https.except' => ['up']]);

        $this->get('/up')->assertOk();
    }

    public function test_nothing_is_redirected_when_enforcement_is_off(): void
    {
        config(['security.https.redirect' => false]);

        $this->get('/_test/ping')->assertOk();
    }

    public function test_the_security_headers_are_present(): void
    {
        $res = $this->get('/_test/ping');

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=()', $res->headers->get('Permissions-Policy'));
        $res->assertHeader('Content-Security-Policy');
    }

    public function test_the_policy_allows_every_third_party_the_site_actually_loads(): void
    {
        $csp = $this->get('/_test/ping')->headers->get('Content-Security-Policy');

        // Each of these is loaded by a view in this repo. If one is dropped from
        // the policy the corresponding feature silently stops working in the
        // browser, which is exactly the failure this test exists to catch.
        foreach ([
            'https://connect.facebook.net',   // Meta Pixel loader
            'https://www.facebook.com',       // Pixel event delivery
            'https://cdn.jsdelivr.net',       // JsBarcode on shipping labels
            'https://fonts.googleapis.com',   // Google Fonts stylesheet
            'https://fonts.gstatic.com',      // Google Fonts files
            'https://www.youtube.com',        // product / home-block video
            'https://player.vimeo.com',
        ] as $host) {
            $this->assertStringContainsString($host, $csp, "CSP is missing {$host}");
        }

        // Alpine evaluates x-* with new Function(), and the Pixel loader is inline.
        $this->assertStringContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("'unsafe-inline'", $csp);

        // The parts that do the real work.
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_extra_hosts_widen_the_policy_without_a_code_change(): void
    {
        config(['security.csp.extra_hosts' => ['https://static.example.test']]);

        $csp = $this->get('/_test/ping')->headers->get('Content-Security-Policy');

        $this->assertSame(
            4,   // script-src, connect-src, frame-src, img-src
            substr_count($csp, 'https://static.example.test'),
        );
    }

    public function test_report_only_mode_does_not_send_the_enforcing_header(): void
    {
        config(['security.csp.report_only' => true]);

        $res = $this->get('/_test/ping');

        $res->assertHeader('Content-Security-Policy-Report-Only');
        $this->assertFalse($res->headers->has('Content-Security-Policy'));
    }
}
