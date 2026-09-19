<?php

namespace App\Services\Meta;

use App\Http\Middleware\TrackVisit;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\MetaIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enterprise-grade Meta tracking: the single server-side entry point for the
 * Conversions API, deduplicated with the browser Pixel via a shared event_id.
 *
 * All credentials come from the database (MetaSettings) — Pixel ID and CAPI
 * access token — never from .env. Customer PII is SHA256-hashed before it ever
 * leaves the server. Content ids come from MetaProductMapper so Pixel, CAPI and
 * the product catalog all speak the same retailer_id ("prod-{id}").
 *
 * The four standard commerce events are supported: ViewContent, AddToCart,
 * InitiateCheckout, Purchase. Each takes an $eventId — generate it once (see
 * {@see newEventId()}), fire the browser Pixel with the same id, and Meta will
 * collapse the two into one event.
 */
class MetaTrackingService
{
    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaProductMapper $mapper,
        private readonly MetaGraphClient $client,
    ) {}

    /** Whether server-side CAPI sending is enabled and fully configured (DB). */
    public function enabled(): bool
    {
        return $this->settings->capiEnabled();
    }

    /** Whether the browser Pixel is enabled and has an id. */
    public function pixelEnabled(): bool
    {
        return (bool) $this->settings->get('pixel_enabled', true) && filled($this->settings->pixelId());
    }

    public function advancedMatching(): bool
    {
        return (bool) $this->settings->get('advanced_matching', true);
    }

    /** Whether a given standard event is enabled by the per-event toggles. */
    public function eventEnabled(string $event): bool
    {
        return (bool) match ($event) {
            'PageView' => $this->settings->get('track_pageview', true),
            'ViewContent' => $this->settings->get('track_viewcontent', true),
            'Search' => $this->settings->get('track_search', true),
            'AddToCart' => $this->settings->get('track_addtocart', true),
            'InitiateCheckout' => $this->settings->get('track_initiatecheckout', true),
            'Purchase' => $this->settings->get('track_purchase', true),
            default => true,
        };
    }

    /** Per-event enabled map for the browser Pixel (window.META_TRACK.events). */
    public function enabledEventsMap(): array
    {
        return [
            'PageView' => $this->eventEnabled('PageView'),
            'ViewContent' => $this->eventEnabled('ViewContent'),
            'Search' => $this->eventEnabled('Search'),
            'AddToCart' => $this->eventEnabled('AddToCart'),
            'InitiateCheckout' => $this->eventEnabled('InitiateCheckout'),
            'Purchase' => $this->eventEnabled('Purchase'),
        ];
    }

    /**
     * A fresh, unique event id for one user action. Use the SAME value for the
     * browser Pixel (fbq(..., { eventID })) and the matching CAPI call below.
     */
    public static function newEventId(string $event): string
    {
        return $event.'.'.Str::uuid()->toString();
    }

    // ── Content-id helpers (must match the catalog retailer_id) ──────────────

    public function contentId(Product $product, ?ProductVariant $variant = null): string
    {
        return $this->mapper->retailerId($product, $variant);
    }

    /**
     * Seconds allowed for a storefront event that has already been deferred past
     * the response. Meta normally answers in 100-200ms; the only thing a longer
     * window buys is a stuck connection holding an lsphp process for longer.
     */
    private const STOREFRONT_TIMEOUT = 5;

    /**
     * The events a shopper's own browser also fires through the Pixel, and so
     * the only ones a crawler or a speculative prefetch can fake. Purchase is
     * deliberately not here: a real order is a real order, whoever's software
     * happened to place the request.
     */
    private const BROWSER_EVENTS = ['ViewContent', 'AddToCart', 'InitiateCheckout'];

    /**
     * Meta's and Google's own fetchers, on top of TrackVisit's crawler list.
     *
     * The 2026-09-17 production audit found 46% of server ViewContents came
     * from facebookexternalhit — Meta's own link-preview and catalogue checker
     * — and another 6% from other bots. None of them runs the Pixel, so each
     * one was a server event with no browser twin, dragging the dataset's match
     * quality down on the very traffic Meta itself generated. facebookexternalhit
     * and HeadlessChrome are already caught by TrackVisit's list; they are named
     * again so this line reads as the audit found it, and survives someone
     * trimming that list for the dashboard's sake.
     *
     * The chat apps' link-preview fetchers are named here for the same reason.
     * A product link shared in WhatsApp — Meta's own app — is fetched by
     * "WhatsApp/2.24.18.80 A" to draw the preview card, and that fetch runs
     * no Pixel, so each share was a server ViewContent with no twin. The rest
     * are also caught by TrackVisit's "bot" and "preview" words, but narrowing
     * "bot" to spare Cubot phones already showed how easily that list moves.
     * WhatsApp and Viber are anchored: a browser opened from either sends
     * Mozilla/5.0 first.
     */
    private const MACHINE_AGENT_PATTERN = '/facebookexternalhit|facebookcatalog|meta-externalagent|Google-InspectionTool|HeadlessChrome|^WhatsApp\/|^Viber\/|TelegramBot|Slackbot-LinkExpanding|Discordbot|LinkedInBot|Twitterbot|SkypeUriPreview/i';

    /** The `origin` of a client context with no browser behind it at all. */
    private const NO_BROWSER = 'no_browser';

    // ── Standard commerce events ─────────────────────────────────────────────

    public function viewContent(Product $product, string $eventId, array $user = [], ?array $context = null): void
    {
        $this->send('ViewContent', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => [$this->contentId($product)],
            'content_name' => $product->name,
            'currency' => $this->currency(),
            // What the page lists it at, live offer off — the value the
            // browser Pixel sends with this same event id.
            'value' => offer_pricing()->priceFor($product),
        ], $eventId, context: $context, timeout: self::STOREFRONT_TIMEOUT);
    }

    public function addToCart(Product $product, int $quantity, string $eventId, array $user = [], ?ProductVariant $variant = null, ?array $context = null): void
    {
        $unit = offer_pricing()->priceFor($product, $variant);

        $this->send('AddToCart', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => [$this->contentId($product, $variant)],
            'content_name' => $product->name,
            'currency' => $this->currency(),
            'value' => $unit * max(1, $quantity),
        ], $eventId, context: $context, timeout: self::STOREFRONT_TIMEOUT);
    }

    /**
     * One AddToCart for several lines added in a single tap — the "frequently
     * bought together" bundle.
     *
     * One event, not one per piece, for the same reason the dashboard funnel
     * records the bundle once: the shopper made one decision, and the browser
     * Pixel fires once with this same event id. Three server events against one
     * browser event would leave two with nothing to deduplicate against. Pass
     * only the lines that actually went into the cart — a variant product the
     * bundle skipped was never added, and reporting it would overstate the
     * value Meta optimises towards.
     *
     * @param  array<int,array{product:Product,variant?:?ProductVariant,quantity?:int}>  $lines
     */
    public function addBundleToCart(array $lines, string $eventId, array $user = [], ?array $context = null): void
    {
        $contents = [];
        $value = 0.0;

        foreach ($lines as $line) {
            $variant = $line['variant'] ?? null;
            $unit = offer_pricing()->priceFor($line['product'], $variant);
            $qty = max(1, (int) ($line['quantity'] ?? 1));

            $contents[] = [
                'id' => $this->contentId($line['product'], $variant),
                'quantity' => $qty,
                'item_price' => $unit,
            ];
            $value += $unit * $qty;
        }

        if ($contents === []) {
            return;
        }

        $this->send('AddToCart', $this->hashUser($user), [
            'content_type' => 'product',
            'contents' => $contents,
            'content_ids' => array_column($contents, 'id'),
            'currency' => $this->currency(),
            'value' => $value,
        ], $eventId, context: $context, timeout: self::STOREFRONT_TIMEOUT);
    }

    /**
     * @param  array<int,string>  $contentIds  retailer_ids ("prod-{id}") in the cart
     */
    public function initiateCheckout(array $contentIds, float $value, int $numItems, string $eventId, array $user = [], ?array $context = null): void
    {
        $this->send('InitiateCheckout', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => array_values($contentIds),
            'currency' => $this->currency(),
            'value' => $value,
            'num_items' => $numItems,
        ], $eventId, context: $context, timeout: self::STOREFRONT_TIMEOUT);
    }

    /**
     * Snapshot the browser signals Meta uses for ad attribution (IP, user agent,
     * click/browser cookies, page URL, external ids). Capture this inside the
     * HTTP request and hand it to queued senders — a queue worker has no
     * request to read from, and reconstructing any of this later would be
     * guesswork.
     *
     * `prefetch` is recorded here, while the headers still exist, so the
     * deferred sender can tell a speculative fetch from a visit.
     *
     * @return array{ip:?string,ua:?string,fbc:?string,fbp:?string,url:string,time:int,external_id:array<int,string>,prefetch:bool}
     */
    public static function captureClientContext(): array
    {
        return [
            'ip' => request()->ip(),
            'ua' => request()->userAgent(),
            'fbc' => MetaIdentity::fbc(),
            'fbp' => MetaIdentity::fbp(),
            'url' => url()->current(),
            'time' => time(),
            'external_id' => MetaIdentity::externalIds(),
            'prefetch' => self::isSpeculative(request()),
        ];
    }

    /**
     * The context for an event with no browser behind it: an order the owner
     * typed into the admin after a phone call or a Messenger chat.
     *
     * Explicit rather than an empty array that send() has to guess about. The
     * 2026-09-17 audit found every hand-typed order reaching Meta as a website
     * Purchase from IP 127.0.0.1 with the user agent "Symfony" — send() had
     * fallen back to request(), which inside a queue worker is a console stub.
     * An order that came down a phone line has no IP, no browser, no Pixel
     * cookie and no page, and saying so honestly is worth more to Meta than a
     * fabricated visitor it can never match.
     *
     * @return array{origin:string,time:int}
     */
    public static function noBrowserContext(?int $time = null): array
    {
        return ['origin' => self::NO_BROWSER, 'time' => $time ?? time()];
    }

    /**
     * Whether a context says there is no browser to read from. An empty array
     * counts: it is what a caller hands over when it had nothing to capture,
     * which is different from null ("no snapshot, read the live request").
     */
    private static function isNoBrowser(?array $context): bool
    {
        return is_array($context)
            && ($context === [] || ($context['origin'] ?? null) === self::NO_BROWSER);
    }

    /**
     * Whether a request is a speculative fetch rather than a visit.
     *
     * Cloudflare Speed Brain (on for this zone) and Chrome's speculation rules
     * send Sec-Purpose, Inertia's link prefetch sends Purpose, and Firefox sends
     * X-Moz. Each loads a page the shopper might open next; most are never
     * opened. When one is, the browser shows the prefetched copy and runs the
     * Pixel then — with the event id baked into that copy — so skipping the
     * server twin loses no view, while sending it counted every hovered link
     * as one. That holds only while the Pixel is on; notAShopper() checks.
     */
    public static function isSpeculative(?Request $request): bool
    {
        if (! $request) {
            return false;
        }

        foreach (['Sec-Purpose', 'Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->header($header)), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a user agent belongs to software rather than a shopper: the
     * crawlers the visitor count already ignores (TrackVisit, the one list)
     * plus Meta's and Google's own fetchers and the chat apps' link previews.
     *
     * A missing user agent counts too. Meta requires client_user_agent on every
     * website event, so one without it is rejected as malformed — and no
     * browser that runs the Pixel omits the header.
     */
    public static function isMachineAgent(?string $userAgent): bool
    {
        return blank($userAgent)
            || TrackVisit::isBot($userAgent)
            || (bool) preg_match(self::MACHINE_AGENT_PATTERN, (string) $userAgent);
    }

    /**
     * @return array{ok:bool,status:int,body:mixed,error:?string,ms:int}
     */
    public function purchase(Order $order, string $eventId, ?array $context = null): array
    {
        $order->loadMissing('items');

        // No browser, so no visitor cookie to derive an external_id from — but
        // the customer record is the same id a signed-in customer's browser
        // events carry (MetaIdentity::externalIds), so a DM buyer who also
        // shops the site signed in still joins up with herself.
        if (self::isNoBrowser($context) && $order->customer_id && empty($context['external_id'])) {
            $context = array_merge(self::noBrowserContext(), $context, [
                'external_id' => [MetaIdentity::pseudonym('customer:'.$order->customer_id)],
            ]);
        }

        // Split the single stored name: Meta matches first and last separately,
        // and a full name in `fn` matches neither.
        [$first, $last] = $this->splitName($order->customer_name);

        return $this->send('Purchase', $this->hashUser([
            'em' => $order->customer_email,
            'ph' => $order->customer_phone,
            'fn' => $first,
            'ln' => $last,
            'ct' => $order->city,
            'st' => $order->district,
            'country' => $this->country(),
        ]), [
            'content_type' => 'product',
            'contents' => $order->items->map(fn ($i) => [
                'id' => $this->retailerForOrderItem($i),
                'quantity' => (int) $i->quantity,
                'item_price' => (float) $i->price,
            ])->all(),
            'content_ids' => $order->items->map(fn ($i) => $this->retailerForOrderItem($i))->values()->all(),
            'currency' => $this->currency(),
            'value' => (float) $order->total,
            'num_items' => (int) $order->items->sum('quantity'),
        ], $eventId, context: $context);
    }

    public function lead(string $phone, ?string $name, string $eventId): void
    {
        [$first, $last] = $this->splitName($name);

        $this->send('Lead', $this->hashUser(['ph' => $phone, 'fn' => $first, 'ln' => $last]), [], $eventId);
    }

    /**
     * The matching fields a signed-in customer contributes to any event.
     *
     * One place rather than three: the product page, the cart and checkout all
     * used to build this inline, which meant a full name landed in `fn` (where
     * it matches nobody) on every one of them. Values are raw here — hashUser()
     * normalises and hashes on the way out.
     *
     * Guests return [] and that is fine: IP, user agent, fbp/fbc and
     * external_id still carry the event.
     *
     * @return array<string,?string>
     */
    public function customerMatchData(?object $customer): array
    {
        if (! $customer) {
            return [];
        }

        [$first, $last] = $this->splitName($customer->name ?? null);

        return array_filter([
            'em' => $customer->email ?? null,
            'ph' => $customer->phone ?? null,
            'fn' => $first,
            'ln' => $last,
            'country' => $this->country(),
        ]);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /**
     * POST a single server event to the Graph /events endpoint. Reads the Pixel
     * ID and access token from the database. Never throws into the caller.
     *
     * Real events are gated by the per-event toggle + the CAPI enable flag; a
     * test send ($test = true) bypasses those flags but still needs the Pixel ID
     * and a token to be configured. Returns a structured result for the Test
     * panel / Event debugger.
     *
     * $timeout is opt-in and deliberately NOT the default. The storefront
     * events are fire-and-forget after the response, so they should fail fast
     * rather than pin a PHP worker on a shared host. Purchase keeps the full
     * ten seconds: it is one POST per order, it carries the revenue, and
     * send() never throws — so a timeout there is silent, permanent data loss,
     * not a retry.
     *
     * Two kinds of caller are told apart by $context:
     *  - a browser event (context captured from the request, or null to read
     *    the live one) is sent as action_source "website" with the visitor's
     *    IP, user agent, Pixel cookies and page — unless the "visitor" is a
     *    crawler, or a speculative prefetch the Pixel will report if it is
     *    ever opened, in which case nothing is sent;
     *  - a no-browser event ({@see noBrowserContext()}, or an empty array) is
     *    an order typed into the admin, sent as "phone_call" with the customer
     *    fields alone and never a value read from whatever request is standing.
     *
     * @return array{ok:bool,status:int,body:mixed,error:?string,ms:int}
     */
    protected function send(string $eventName, array $userData, array $customData, string $eventId, bool $test = false, ?array $context = null, ?int $timeout = null): array
    {
        $skip = ['ok' => false, 'status' => 0, 'body' => null, 'error' => null, 'ms' => 0];

        if (! $test) {
            if (! $this->eventEnabled($eventName) || ! $this->enabled()) {
                return $skip;
            }

            // The one place every storefront sender passes through, so a new
            // caller cannot forget it. Deliberately silent: at close to half of
            // all product views, even a debug line per skip would be the
            // noisiest thing in the Meta log.
            if (in_array($eventName, self::BROWSER_EVENTS, true) && $this->notAShopper($context)) {
                return $skip;
            }
        }

        $pixelId = $this->settings->pixelId();
        $token = $this->settings->capiToken();
        if (! $pixelId || ! $token) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Pixel ID or CAPI token is not configured.', 'ms' => 0];
        }

        $browser = ! self::isNoBrowser($context);
        // The owner's call (2026-09-17): hand-typed orders arrive by phone or
        // Messenger, and "phone_call" is the closest source Meta offers that
        // does not claim a website visit. It also lifts Meta's website-only
        // requirements for a user agent and a page URL, neither of which such
        // an order has.
        $actionSource = $browser ? 'website' : 'phone_call';
        $started = microtime(true);
        try {
            if ($browser) {
                // Queued senders pass a $context snapshot taken during the
                // request; synchronous ones fall back to the live request.
                // fbp/fbc/IP/UA are sent as-is — Meta rejects hashed values for
                // all four.
                $userData = array_merge(array_filter($userData), array_filter([
                    'client_ip_address' => $context['ip'] ?? request()->ip(),
                    'client_user_agent' => $context['ua'] ?? request()->userAgent(),
                    'fbc' => $context['fbc'] ?? MetaIdentity::fbc(),
                    'fbp' => $context['fbp'] ?? MetaIdentity::fbp(),
                    'external_id' => $context['external_id'] ?? MetaIdentity::externalIds(),
                ]));
            } else {
                // No request() fallback here, ever: in a queue worker it is a
                // console stub (127.0.0.1, "Symfony"), and in a synchronous
                // dispatch it is the owner's own browser — both of which the
                // audit found being reported as the customer.
                $userData = array_merge(array_filter($userData), array_filter([
                    'external_id' => $context['external_id'] ?? null,
                ]));
            }

            // Meta wants `value` as a number above zero; a 0, a string or a
            // long float is a data-quality warning against the whole dataset
            // rather than a rejected event. Two decimals, or no value at all.
            if (array_key_exists('value', $customData)) {
                $value = round((float) $customData['value'], 2);
                $customData['value'] = $value > 0 ? $value : null;
            }

            $event = [
                'event_name' => $eventName,
                'event_time' => $context['time'] ?? time(),
                'event_id' => $eventId,
                'action_source' => $actionSource,
            ];
            if ($browser) {
                $event['event_source_url'] = $context['url'] ?? url()->current();
            }

            $payload = [
                'data' => [$event + [
                    'user_data' => $userData,
                    'custom_data' => array_filter($customData, fn ($v) => $v !== null && $v !== []),
                ]],
            ];

            if ($code = $this->testEventCode($test)) {
                $payload['test_event_code'] = $code;
            }

            // The token travels in the body, never the query string. Guzzle
            // puts the full request URL into a timeout's exception message,
            // and that message is logged below — a token in the URL was one
            // slow Graph response away from sitting in laravel.log.
            $payload['access_token'] = $token;

            $url = sprintf('%s/%s/%s/events',
                rtrim((string) config('meta.graph_url', 'https://graph.facebook.com'), '/'),
                config('meta.graph_version', 'v21.0'),
                $pixelId,
            );

            $res = Http::connectTimeout($timeout ? 3 : 10)
                ->timeout($timeout ?? 10)
                ->post($url, $payload);
            $ms = (int) round((microtime(true) - $started) * 1000);

            if ($res->failed()) {
                $error = $this->redact($res->json('error.message') ?? 'HTTP '.$res->status(), $token);

                $this->logEvent($eventName, $eventId, $userData, $res->status(), $ms,
                    $error, $res->json('error.fbtrace_id'), $actionSource);

                return ['ok' => false, 'status' => $res->status(), 'body' => $res->json() ?? $res->body(),
                    'error' => $error, 'ms' => $ms];
            }

            $this->logEvent($eventName, $eventId, $userData, $res->status(), $ms, null, $res->json('fbtrace_id'), $actionSource);
            $this->settings->update(['last_event_sent_at' => now()->toIso8601String()]);

            return ['ok' => true, 'status' => $res->status(), 'body' => $res->json(), 'error' => null, 'ms' => $ms];
        } catch (\Throwable $e) {
            // Scrubbed as well as kept out of the URL: the message is written
            // to laravel.log and shown on the admin Test panel, and neither is
            // a place a live access token belongs.
            $error = $this->redact($e->getMessage(), $token);

            $this->logEvent($eventName, $eventId, [], 0, (int) round((microtime(true) - $started) * 1000), $error, null, $actionSource);

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $error,
                'ms' => (int) round((microtime(true) - $started) * 1000)];
        }
    }

    /**
     * Whether a browser event came from software rather than a person: a
     * crawler's user agent, or — while the Pixel is on to report the real
     * view — a page fetched speculatively in case it was wanted. Reads the
     * captured snapshot when there is one — a deferred or queued sender has no
     * headers left to look at — and the live request otherwise.
     */
    protected function notAShopper(?array $context): bool
    {
        $live = $context === null;

        if (self::isMachineAgent($live ? request()->userAgent() : ($context['ua'] ?? null))) {
            return true;
        }

        // A prefetch is only safe to drop when the browser Pixel is on. The
        // reason for dropping it is that the page, once actually opened, fires
        // its own Pixel ViewContent under the id baked into it — so the view is
        // counted once either way. With the Pixel switched off nothing in the
        // browser ever reports it, so skipping here meant every page the
        // shopper opened from a prefetched copy reached Meta as nothing at
        // all. A hovered link counted as a view is the lesser loss for a store
        // running on server events alone.
        $speculative = $live ? self::isSpeculative(request()) : (bool) ($context['prefetch'] ?? false);

        return $speculative && $this->pixelEnabled();
    }

    /** Take the access token out of a message bound for a log or the screen. */
    private function redact(string $message, string $token): string
    {
        return str_replace(array_unique([$token, urlencode($token), rawurlencode($token)]), '[redacted]', $message);
    }

    /**
     * One line per CAPI call, with enough to diagnose match quality and
     * deduplication and nothing that identifies a customer.
     *
     * Only the *names* of the user_data keys are recorded — never their values,
     * hashed or otherwise. A SHA-256 of an email is still a stable identifier
     * for that person, so a log full of them is a log full of PII.
     */
    protected function logEvent(string $event, string $eventId, array $userData, int $status, int $ms, ?string $error = null, ?string $trace = null, string $actionSource = 'website'): void
    {
        $matchKeys = array_values(array_diff(
            array_keys($userData),
            ['client_ip_address', 'client_user_agent', 'fbc', 'fbp'],
        ));

        $line = [
            'event' => $event,
            'event_id' => $eventId,
            'status' => $status,
            'ms' => $ms,
            'fbp' => isset($userData['fbp']),
            'fbc' => isset($userData['fbc']),
            'ip' => isset($userData['client_ip_address']),
            'ua' => isset($userData['client_user_agent']),
            'match_keys' => $matchKeys,
            'action_source' => $actionSource,
            // The browser Pixel fires the same four events with the same
            // event_id; anything else has no browser copy to collapse with —
            // and neither does an order typed into the admin, where no Pixel
            // ever ran. Claiming otherwise sent the audit looking for a twin
            // that could not exist.
            'dedup_expected' => $actionSource === 'website'
                && $this->pixelEnabled()
                && in_array($event, ['ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase'], true),
        ];

        if ($trace) {
            $line['fbtrace_id'] = $trace;
        }

        if ($error !== null) {
            Log::warning('Meta CAPI event failed', $line + ['error' => $error]);

            return;
        }

        // Successes are the noisy case — one per page view. Keep them out of
        // laravel.log and in the Meta channel, which rotates on a short window.
        Log::channel('meta-debug')->info('Meta CAPI event sent', $line);
    }

    /**
     * Test-event code from the database, falling back to config (env).
     *
     * Real production events deliberately ignore it. A code left behind in the
     * settings after a debugging session would otherwise keep diverting live
     * traffic into Events Manager's Test Events tab, where it does not feed
     * attribution or optimisation — a silent failure that looks like working
     * tracking. The admin Test panel ($test) always gets the code, and outside
     * production so does everything else.
     */
    public function testEventCode(bool $test = true): ?string
    {
        $code = $this->settings->get('test_event_code') ?: config('meta.test_event_code');

        if (! $code) {
            return null;
        }

        if ($test || ! app()->isProduction() || config('meta.test_events_in_production')) {
            return $code;
        }

        return null;
    }

    // ── Diagnostics / test panel support ─────────────────────────────────────

    /**
     * Validate the CAPI access token via Graph debug_token.
     *
     * @return array{valid:bool,expires_at:?int,scopes:array,error:?string}
     */
    public function validateToken(): array
    {
        if (! filled($this->settings->capiToken())) {
            return ['valid' => false, 'expires_at' => null, 'scopes' => [], 'error' => 'No token configured.'];
        }

        try {
            $data = $this->client->debugToken($this->settings->capiToken());

            return [
                'valid' => ($data['is_valid'] ?? false) === true,
                'expires_at' => isset($data['expires_at']) ? (int) $data['expires_at'] : null,
                'scopes' => $data['scopes'] ?? [],
                'error' => $data['is_valid'] ?? false ? null : 'Token reported invalid.',
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'expires_at' => null, 'scopes' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Fire one real test event through the CAPI with a sample payload and the
     * configured Test Event Code. The browser fires the matching Pixel event with
     * the SAME $eventId (passed from JS) so Events Manager shows them deduplicated.
     *
     * @return array{ok:bool,status:int,body:mixed,error:?string,ms:int,event_id:string,event:string,test_event_code:?string}
     */
    public function sendTest(string $event, ?string $eventId = null): array
    {
        $eventId ??= self::newEventId($event);

        // Never without a test event code. Without one the sample goes into
        // the real dataset: Events Manager then reports "all Purchase events
        // send the same price" (every sample carried the same value) and the
        // made-up content id drags the catalogue match rate down — both of
        // which happened here, and both of which cost ad delivery.
        if (! $this->testEventCode()) {
            return [
                'ok' => false, 'status' => 0, 'body' => null, 'ms' => 0, 'event_id' => $eventId, 'event' => $event, 'test_event_code' => null,
                'error' => 'Set a Test Event Code first (Events Manager → Test events → copy the code into Meta → Tracking). Without it a sample would be recorded as a real '.$this->currency().' 1 purchase against a product that does not exist.',
            ];
        }

        $custom = match ($event) {
            'ViewContent', 'AddToCart' => ['content_type' => 'product', 'content_ids' => ['prod-test'], 'content_name' => 'Test product', 'currency' => $this->currency(), 'value' => 1250.0],
            'InitiateCheckout', 'Purchase' => ['content_type' => 'product', 'content_ids' => ['prod-test'], 'currency' => $this->currency(), 'value' => 1250.0, 'num_items' => 1],
            default => [], // PageView / Search
        };

        $result = $this->send($event, [], $custom, $eventId, test: true);
        $result['event_id'] = $eventId;
        $result['event'] = $event;
        $result['test_event_code'] = $this->testEventCode();

        return $result;
    }

    // ── Hashing / normalisation ──────────────────────────────────────────────

    /**
     * Normalise then SHA256-hash the identifiable user fields Meta expects.
     * Empty fields are dropped — a blank hash matches nobody and only makes the
     * payload look better than it is.
     *
     * Normalisation rules live in MetaIdentity because getting them wrong fails
     * silently: Meta reports a lower match rate rather than an error.
     *
     * @param  array<string,mixed>  $raw  e.g. ['em'=>email, 'ph'=>phone, 'fn'=>first]
     * @return array<string,array<int,string>>
     */
    protected function hashUser(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (! MetaIdentity::mustHash((string) $key) || ! filled($value)) {
                continue;
            }

            foreach ((array) $value as $single) {
                // Something upstream may already have hashed this; hashing a
                // digest again produces a value that matches nothing.
                if (is_string($single) && MetaIdentity::isHashed($single)) {
                    $out[$key][] = mb_strtolower($single);

                    continue;
                }

                if ($normalised = MetaIdentity::normalize((string) $key, $single)) {
                    $out[$key][] = hash('sha256', $normalised);
                }
            }
        }

        return array_map(fn ($v) => array_values(array_unique($v)), $out);
    }

    /**
     * Best-effort split of one stored name into first and last.
     *
     * The app only ever collects a single "name" at checkout, so this is a
     * guess — but "first word / rest" is the convention Meta's own examples
     * use, and a first name alone still matches better than a full name in the
     * fn field, which matches nothing.
     *
     * @return array{0:?string,1:?string}
     */
    protected function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return [null, null];
        }

        $first = array_shift($parts);

        return [$first, $parts ? implode(' ', $parts) : null];
    }

    /**
     * Country for user_data, as chosen by the merchant in Meta → Tracking.
     *
     * Never guessed from the currency or the phone format, and deliberately not
     * a config default: this codebase runs more than one store, and a wrong
     * country is a hash that matches nobody — worse than omitting the field.
     */
    public function country(): ?string
    {
        return MetaIdentity::normalize('country', $this->settings->get('country'));
    }

    /** retailer_id for an order line, mirroring MetaProductMapper's format. */
    private function retailerForOrderItem($item): string
    {
        return $item->variant_id
            ? "prod-{$item->product_id}-var-{$item->variant_id}"
            : "prod-{$item->product_id}";
    }

    private function currency(): string
    {
        return (string) (config('store.currency') ?: config('meta.defaults.currency', 'BDT'));
    }
}
