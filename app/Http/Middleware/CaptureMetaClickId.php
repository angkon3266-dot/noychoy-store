<?php

namespace App\Http\Middleware;

use App\Services\Meta\MetaTrackingService;
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

        $this->mintBrowserId($request);

        return $next($request);
    }

    /**
     * Give a first-time shopper's browser its _fbp now, rather than waiting
     * for the Pixel to load a second after the page (or never, behind an ad
     * blocker). The Pixel adopts the cookie as its own, so both copies of
     * every event carry the same id from the first page on. This is what
     * Meta's Parameter Builder does on the server.
     *
     * Only for a shopper's page request: a crawler keeps no cookies, so each
     * of its hits would be a new id for Meta to count as a new person.
     */
    private function mintBrowserId(Request $request): void
    {
        if (MetaIdentity::fbp($request) !== null
            || ! $request->isMethod('GET')
            || $request->is('admin', 'admin/*')
            || MetaTrackingService::isMachineAgent($request->userAgent())) {
            return;
        }

        $tracking = app(MetaTrackingService::class);
        if (! $tracking->enabled() && ! $tracking->pixelEnabled()) {
            return;
        }

        $fbp = MetaIdentity::buildFbp();
        $request->attributes->set(MetaIdentity::FBP_ATTRIBUTE, $fbp);

        Cookie::queue(Cookie::make(
            name: MetaIdentity::FBP_COOKIE,
            value: $fbp,
            minutes: MetaIdentity::FBP_DAYS * 24 * 60,
            path: '/',
            domain: MetaIdentity::cookieDomain($request),   // where the Pixel keeps it
            secure: $request->isSecure(),
            httpOnly: false,   // the Pixel reads it
            raw: false,
            sameSite: 'lax',
        ));
    }
}
