<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send plain-HTTP requests to the HTTPS URL instead of answering them.
 *
 * Cloudflare's "Always Use HTTPS" should catch these at the edge; this is the
 * origin-side backstop for anything that reaches the server another way — a
 * direct hit on the origin IP, a subdomain not proxied through Cloudflare, or
 * the edge setting being switched off by accident.
 *
 * Loop safety rests on $request->isSecure() rather than $_SERVER['HTTPS']:
 * bootstrap/app.php trusts Cloudflare's IP ranges and the X-Forwarded-Proto
 * header, so "secure" means the scheme the *visitor* used. Under Flexible SSL
 * the Cloudflare→origin leg is plain HTTP even though the visitor is on HTTPS,
 * and a check against the raw connection would redirect that request forever.
 *
 * Registered in the global stack *after* TrustProxies for exactly that reason.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.https.redirect') || $request->isSecure()) {
            return $next($request);
        }

        foreach ((array) config('security.https.except', []) as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        // 301 for GET/HEAD: permanent, cacheable, and what search engines want
        // to see. 308 for everything else, because a 301 lets the browser
        // rewrite the request to GET — a webhook or checkout POST arriving over
        // HTTP would otherwise lose its body on the way to the HTTPS URL.
        $status = $request->isMethodCacheable() ? 301 : 308;

        return redirect()->secure($request->getRequestUri(), $status);
    }
}
