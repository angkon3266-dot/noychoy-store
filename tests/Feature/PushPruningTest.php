<?php

namespace Tests\Feature;

use App\Services\WebPushService;
use Tests\TestCase;

/**
 * A subscription that can never succeed again must be removed, or it fails on
 * every send forever. 403 is permanent under RFC 8292 — the push service
 * rejected our VAPID signature for that subscription — and each vendor words
 * the body differently, so the status alone has to be enough.
 */
class PushPruningTest extends TestCase
{
    protected function service(string $body = ''): WebPushService
    {
        $svc = app(WebPushService::class);

        // lastResult is what shouldPrune() inspects for the 400 case.
        $ref = new \ReflectionProperty($svc, 'lastResult');
        $ref->setAccessible(true);
        $ref->setValue($svc, ['body' => $body]);

        return $svc;
    }

    public function test_gone_endpoints_are_pruned(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->shouldPrune(404));
        $this->assertTrue($svc->shouldPrune(410));
    }

    public function test_any_403_is_pruned_whatever_the_vendor_wording(): void
    {
        // Google's wording…
        $this->assertTrue($this->service('VapidPkHashMismatch')->shouldPrune(403));

        // …and Apple's, which never contained that token and so used to survive.
        $apple = 'the VAPID credentials in the authorization header do not correspond to the credentials used to create the subscriptions';
        $this->assertTrue($this->service($apple)->shouldPrune(403));
    }

    public function test_400_is_only_pruned_when_the_body_names_a_key_mismatch(): void
    {
        // 400 can mean a malformed request on our side — don't destroy a
        // subscription over our own bug.
        $this->assertFalse($this->service('malformed payload')->shouldPrune(400));
        $this->assertTrue($this->service('VapidPkHashMismatch')->shouldPrune(400));
    }

    public function test_transient_failures_keep_the_subscription(): void
    {
        $svc = $this->service('service unavailable');

        $this->assertFalse($svc->shouldPrune(429));   // rate limited
        $this->assertFalse($svc->shouldPrune(500));
        $this->assertFalse($svc->shouldPrune(503));
        $this->assertFalse($svc->shouldPrune(201));   // success
    }
}
