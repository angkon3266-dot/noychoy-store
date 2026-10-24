<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Support\MetaIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Where the browser reports the IPv6 address it looked up for itself.
 *
 * Only a landing page's deferred events read this (SendDeferredBrowserEvent).
 * Every later request carries the same address in MetaIdentity::IP6_COOKIE.
 *
 * Nothing here is trusted beyond being a public IPv6 address. A visitor who
 * reports someone else's address only changes how well their own events
 * match, which is no more than editing the cookie would do.
 */
class MetaIpController extends Controller
{
    /** Long enough for the deferred job, short enough not to outlive the visit. */
    private const TTL = 600;

    public function store(Request $request): Response
    {
        $ip = MetaIdentity::publicIpv6($request->input('ip'));
        $key = MetaIdentity::ip6CacheKey($request);

        if ($ip && $key) {
            Cache::put($key, $ip, self::TTL);
        }

        return response()->noContent();
    }
}
