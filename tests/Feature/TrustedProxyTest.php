<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The production site sits behind Cloudflare. These tests pin the trusted-proxy
 * behaviour: a request arriving FROM a Cloudflare edge IP must resolve ip() to
 * the forwarded visitor address (otherwise every visitor shares one rate-limit
 * bucket and checkout 429s), while X-Forwarded-For from anywhere else must be
 * ignored (otherwise rate limits are spoofable).
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function ipEchoRoute(): void
    {
        Route::middleware('web')->get('/_test/ip', fn (Request $r) => $r->ip());
    }

    public function test_visitor_ip_is_read_through_cloudflare(): void
    {
        $this->ipEchoRoute();

        $res = $this->withServerVariables(['REMOTE_ADDR' => '172.64.0.10']) // in CF range 172.64.0.0/13
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/_test/ip');

        $this->assertSame('203.0.113.7', $res->getContent());
    }

    public function test_forwarded_header_from_untrusted_source_is_ignored(): void
    {
        $this->ipEchoRoute();

        $res = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']) // not a CF range
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/_test/ip');

        $this->assertSame('198.51.100.9', $res->getContent());
    }
}
