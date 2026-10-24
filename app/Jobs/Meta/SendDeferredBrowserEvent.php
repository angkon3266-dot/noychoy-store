<?php

namespace App\Jobs\Meta;

use App\Models\Product;
use App\Services\Meta\MetaTrackingService;
use App\Support\MetaIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A landing page's server PageView or ViewContent, sent a little after the
 * page instead of straight after it.
 *
 * The browser looks up its own IPv6 address once it has the page
 * (partials/meta-pixel) and reports it to MetaIpController. A landing page's
 * events would leave before that report arrives, carrying the carrier's
 * shared IPv4 address while the Pixel's copy carries the phone's IPv6 one.
 * That is the mismatch Meta flagged on 2026-10-11. Waiting gives the report
 * time to land. The first page of a visit is the one an ad click lands on, so
 * it is the event most worth matching.
 *
 * Every later page reads the address from the cookie and goes out at once.
 * If no report ever arrives (no IPv6, script blocked, tab closed), the event
 * still goes, with the address the request came from, which is what it carried
 * before. event_time stays the moment of the visit.
 */
class SendDeferredBrowserEvent implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait for the browser's report. The lookup takes well under one. */
    public const DELAY = 20;

    /**
     * @param  array<string,mixed>  $user  raw match fields; hashed on send
     * @param  array<string,mixed>  $context  MetaTrackingService::captureClientContext()
     */
    public function __construct(
        public string $event,
        public string $eventId,
        public array $user,
        public array $context,
        public ?int $productId = null,
    ) {
        $this->onQueue(config('meta.sync.queue'));
        $this->delay(self::DELAY);
    }

    /** One try: send() never throws, and a second copy would be a duplicate. */
    public function tries(): int
    {
        return 1;
    }

    public function handle(MetaTrackingService $tracking): void
    {
        $context = self::withReportedIp($this->context);

        if ($this->event === 'PageView') {
            $tracking->pageView($this->eventId, $this->user, $context);

            return;
        }

        if ($this->event === 'ViewContent' && ($product = Product::find($this->productId))) {
            $tracking->viewContent($product, $this->eventId, $this->user, $context);
        }
    }

    /**
     * The context with the browser's reported IPv6 address, when there is one.
     * A context whose request already came over IPv6 keeps that address.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function withReportedIp(array $context): array
    {
        if (str_contains((string) ($context['ip'] ?? ''), ':') || empty($context['ip6_key'])) {
            return $context;
        }

        if ($ip = MetaIdentity::publicIpv6(Cache::get($context['ip6_key']))) {
            $context['ip'] = $ip;
        }

        return $context;
    }

    /**
     * Send now, or hold it for the browser's report when this is the visit's
     * first page. The one decision both landing-page senders share.
     *
     * @param  array<string,mixed>  $user
     * @param  array<string,mixed>  $context
     */
    public static function sendOrDefer(string $event, string $eventId, array $user, array $context, ?Product $product = null): void
    {
        $tracking = app(MetaTrackingService::class);

        // Only when there is a report to wait for: the lookup runs inside the
        // Pixel snippet, so with the Pixel off no browser ever sends one. A
        // crawler or a prefetch is never sent at all, and is not worth a row
        // in the jobs table on the way to being dropped — about half of all
        // product-page requests are crawlers, and none of them keeps cookies.
        $wait = ! empty($context['ip6_pending'])
            && ! empty($context['ip6_key'])
            && $tracking->enabled()
            && $tracking->pixelEnabled()
            && $tracking->eventEnabled($event)
            && ! MetaTrackingService::isMachineAgent($context['ua'] ?? null)
            && empty($context['prefetch']);

        if ($wait) {
            // Queued after the response too: the jobs-table INSERT has no
            // business ahead of the page.
            $job = new self($event, $eventId, $user, $context, $product?->id);
            app()->terminating(fn () => dispatch($job));

            return;
        }

        app()->terminating(fn () => $event === 'PageView'
            ? $tracking->pageView($eventId, $user, $context)
            : $tracking->viewContent($product, $eventId, $user, $context));
    }
}
