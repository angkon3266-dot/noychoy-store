<?php

namespace App\Http\Middleware;

use App\Models\Visit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * First-party traffic tracking for the storefront: gives every browser a
 * long-lived visitor_token cookie and logs one row per pageview. Deliberately
 * stores no IP address — the token is an anonymous id, nothing more.
 */
class TrackVisit
{
    /** Crawlers we never want inflating the visitor count. */
    protected const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|headless|lighthouse|pingdom|uptime/i';

    public function handle(Request $request, Closure $next)
    {
        $token = $request->cookie('visitor_token');
        $fresh = blank($token);
        if ($fresh) {
            $token = Str::random(40);
            // 2-year cookie so returning visitors are recognised as one person.
            Cookie::queue(cookie('visitor_token', $token, 60 * 24 * 730));

            // The queued cookie isn't readable until the next request, so
            // publish it on this one too. Meta's external_id is derived from
            // this token, and a landing page is exactly where an ad click ends
            // up — without this the first (most attributable) event of the
            // journey would be the one event carrying no external_id.
            $request->cookies->set('visitor_token', $token);
        }

        if ($this->shouldTrack($request)) {
            $product = $request->route('product');
            $payload = [
                'visitor_token' => $token,
                'product_id' => is_object($product) ? ($product->id ?? null) : null,
            ];
            $type = $product ? 'product' : 'page';

            // After the response is flushed, not before the controller runs:
            // this INSERT used to sit on the critical path of every pageview,
            // ahead of the HTML, for a number only the dashboard ever reads.
            app()->terminating(fn () => Visit::record($type, $payload));
        }

        return $next($request);
    }

    protected function shouldTrack(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return false;
        }
        // Admin, auth flows, and machine endpoints aren't customer traffic.
        if ($request->is('admin', 'admin/*', 'login', 'register', 'webhooks/*', 'feed/*', 'sitemap.xml', 'robots.txt', 'up', 'site.webmanifest')) {
            return false;
        }
        if (auth('web')->check()) {
            return false;   // the shop owner browsing their own store
        }

        return ! preg_match(self::BOT_PATTERN, (string) $request->userAgent());
    }
}
