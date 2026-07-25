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
        }

        if ($this->shouldTrack($request)) {
            $product = $request->route('product');

            Visit::record($product ? 'product' : 'page', [
                'visitor_token' => $token,
                'product_id' => is_object($product) ? ($product->id ?? null) : null,
            ]);
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
