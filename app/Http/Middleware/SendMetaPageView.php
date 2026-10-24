<?php

namespace App\Http\Middleware;

use App\Jobs\Meta\SendDeferredBrowserEvent;
use App\Services\Meta\MetaTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server copy of the Pixel's PageView, under the same event id.
 *
 * Meta's "Improve your rate of Meta Pixel events covered by Conversions API"
 * (PageView, first shown 2026-09-29) was because the server had never sent
 * PageView. This middleware gives every page an id before it renders. The
 * Pixel fires PageView with that id: partials/meta-pixel on a full load, and
 * resources/js/inertia.jsx from the `metaPageView` prop on a React
 * navigation. Once the response is out, the same event goes over the
 * Conversions API and Meta merges the pair.
 *
 * A server copy goes only where the browser really fires one: a full page
 * whose Pixel snippet printed PageView (it marks the request), or a whole
 * Inertia page. Redirects, JSON and partial reloads never fire a Pixel
 * PageView, so a server copy there would have nothing to merge with.
 */
class SendMetaPageView
{
    /** The id this page hands the Pixel. Read by the snippet and the Inertia share. */
    public const ATTRIBUTE = 'meta.pageview_id';

    /** Set by partials/meta-pixel once it has printed fbq('track', 'PageView'). */
    public const PRINTED = 'meta.pageview_printed';

    public function __construct(private readonly MetaTrackingService $tracking) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPage($request)) {
            return $next($request);
        }

        $eventId = MetaTrackingService::newEventId('PageView');
        $request->attributes->set(self::ATTRIBUTE, $eventId);

        $response = $next($request);

        $browserFired = $request->attributes->get(self::PRINTED)
            || ($request->header('X-Inertia') && $response->headers->get('X-Inertia'));

        if ($response->getStatusCode() === 200 && $browserFired) {
            SendDeferredBrowserEvent::sendOrDefer(
                'PageView',
                $eventId,
                $this->tracking->customerMatchData(auth('customer')->user()),
                MetaTrackingService::captureClientContext(),
            );
        }

        return $response;
    }

    /**
     * A GET for a whole storefront page, with the Pixel on to fire PageView.
     * A partial reload (a filter change, a refreshed total) keeps the page it
     * is on, so the browser fires nothing for it and it gets no id.
     */
    private function isPage(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->header('X-Inertia-Partial-Component')
            && ! $request->is('admin', 'admin/*')
            && $this->tracking->pixelEnabled()
            && $this->tracking->eventEnabled('PageView');
    }
}
