<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach the response security headers described in config/security.php.
 *
 * Everything is read from config rather than written inline so a store can
 * widen the policy for its own integrations (CSP_EXTRA_HOSTS) or trial a
 * change without a deploy (CSP_REPORT_ONLY) — and so no host is baked into
 * code that ships to more than one storefront.
 *
 * A header the response already carries is never overwritten: a controller
 * that sets its own CSP, or an edge that already sends HSTS, wins.
 */
class SecurityHeaders
{
    /** CSP directives that CSP_EXTRA_HOSTS widens. */
    protected const WIDENABLE = ['script-src', 'connect-src', 'frame-src', 'img-src'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Advertises the exact PHP build for no benefit.
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        foreach ((array) config('security.headers', []) as $name => $value) {
            if (filled($value) && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($policy = $this->policy()) {
            $header = config('security.csp.report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            if (! $response->headers->has($header)) {
                $response->headers->set($header, $policy);
            }
        }

        return $response;
    }

    /** Build the policy string, or null when CSP is switched off. */
    protected function policy(): ?string
    {
        if (! config('security.csp.enabled', true)) {
            return null;
        }

        $extra = (array) config('security.csp.extra_hosts', []);
        $parts = [];

        foreach ((array) config('security.csp.directives', []) as $directive => $sources) {
            $sources = (array) $sources;

            if ($extra && in_array($directive, self::WIDENABLE, true)) {
                $sources = array_merge($sources, $extra);
            }

            $sources = array_values(array_unique(array_filter($sources)));

            if ($sources) {
                $parts[] = $directive.' '.implode(' ', $sources);
            }
        }

        if (! $parts) {
            return null;
        }

        if (config('security.csp.upgrade_insecure_requests', true)) {
            $parts[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $parts);
    }
}
