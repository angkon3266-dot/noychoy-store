<?php

namespace App\Http\Middleware;

use App\Support\MetaIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turn a ?fbclid= on the landing page into a durable _fbc cookie.
 *
 * Meta's Pixel does this itself, but only if it loads — and the visitors most
 * worth attributing are often the ones running a content blocker. Doing it
 * server-side means the click survives to the Purchase event either way, which
 * is the whole point of the Conversions API.
 *
 * The cookie is deliberately readable by JavaScript: the Pixel looks for _fbc
 * and will mint its own if it doesn't find one, and two different values for
 * the same click is worse than none.
 */
class CaptureMetaClickId
{
    public function handle(Request $request, Closure $next): Response
    {
        [$fbc, $isNew] = MetaIdentity::resolveFbc($request);

        if ($fbc !== null) {
            // Read back by MetaIdentity::fbc() later in the same request — the
            // cookie queued below isn't visible until the next one.
            $request->attributes->set(MetaIdentity::FBC_ATTRIBUTE, $fbc);
        }

        if ($isNew && $fbc !== null) {
            Cookie::queue(Cookie::make(
                name: MetaIdentity::FBC_COOKIE,
                value: $fbc,
                minutes: MetaIdentity::FBC_DAYS * 24 * 60,
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: false,   // the Pixel has to be able to read it
                raw: false,
                sameSite: 'lax',   // must survive the cross-site arrival from Facebook
            ));
        }

        return $next($request);
    }
}
