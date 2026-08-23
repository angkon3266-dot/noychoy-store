<?php

namespace App\Services\Meta;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\MetaIdentity;
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

    // ── Standard commerce events ─────────────────────────────────────────────

    public function viewContent(Product $product, string $eventId, array $user = [], ?array $context = null): void
    {
        $this->send('ViewContent', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => [$this->contentId($product)],
            'content_name' => $product->name,
            'currency' => $this->currency(),
            'value' => (float) $product->price,
        ], $eventId, context: $context, timeout: self::STOREFRONT_TIMEOUT);
    }

    public function addToCart(Product $product, int $quantity, string $eventId, array $user = [], ?ProductVariant $variant = null, ?array $context = null): void
    {
        $unit = $variant?->price !== null ? (float) $variant->price : (float) $product->price;

        $this->send('AddToCart', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => [$this->contentId($product, $variant)],
            'content_name' => $product->name,
            'currency' => $this->currency(),
            'value' => $unit * max(1, $quantity),
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
     * @return array{ip:?string,ua:?string,fbc:?string,fbp:?string,url:string,time:int,external_id:array<int,string>}
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
        ];
    }

    /**
     * @return array{ok:bool,status:int,body:mixed,error:?string,ms:int}
     */
    public function purchase(Order $order, string $eventId, ?array $context = null): array
    {
        $order->loadMissing('items');

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
     * @return array{ok:bool,status:int,body:mixed,error:?string,ms:int}
     */
    protected function send(string $eventName, array $userData, array $customData, string $eventId, bool $test = false, ?array $context = null, ?int $timeout = null): array
    {
        $skip = ['ok' => false, 'status' => 0, 'body' => null, 'error' => null, 'ms' => 0];

        if (! $test) {
            if (! $this->eventEnabled($eventName) || ! $this->enabled()) {
                return $skip;
            }
        }

        $pixelId = $this->settings->pixelId();
        $token = $this->settings->capiToken();
        if (! $pixelId || ! $token) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Pixel ID or CAPI token is not configured.', 'ms' => 0];
        }

        $started = microtime(true);
        try {
            // Queued senders pass a $context snapshot taken during the request;
            // synchronous ones fall back to the live request. fbp/fbc/IP/UA are
            // sent as-is — Meta rejects hashed values for all four.
            $userData = array_merge(array_filter($userData), array_filter([
                'client_ip_address' => $context['ip'] ?? request()->ip(),
                'client_user_agent' => $context['ua'] ?? request()->userAgent(),
                'fbc' => $context['fbc'] ?? MetaIdentity::fbc(),
                'fbp' => $context['fbp'] ?? MetaIdentity::fbp(),
                'external_id' => $context['external_id'] ?? MetaIdentity::externalIds(),
            ]));

            $payload = [
                'data' => [[
                    'event_name' => $eventName,
                    'event_time' => $context['time'] ?? time(),
                    'event_id' => $eventId,
                    'action_source' => 'website',
                    'event_source_url' => $context['url'] ?? url()->current(),
                    'user_data' => $userData,
                    'custom_data' => array_filter($customData, fn ($v) => $v !== null && $v !== []),
                ]],
            ];

            if ($code = $this->testEventCode($test)) {
                $payload['test_event_code'] = $code;
            }

            $url = sprintf('%s/%s/%s/events',
                rtrim((string) config('meta.graph_url', 'https://graph.facebook.com'), '/'),
                config('meta.graph_version', 'v21.0'),
                $pixelId,
            );

            $res = Http::connectTimeout($timeout ? 3 : 10)
                ->timeout($timeout ?? 10)
                ->post($url.'?access_token='.urlencode($token), $payload);
            $ms = (int) round((microtime(true) - $started) * 1000);

            if ($res->failed()) {
                $this->logEvent($eventName, $eventId, $userData, $res->status(), $ms,
                    $res->json('error.message') ?? 'HTTP '.$res->status(),
                    $res->json('error.fbtrace_id'));

                return ['ok' => false, 'status' => $res->status(), 'body' => $res->json() ?? $res->body(),
                    'error' => $res->json('error.message') ?? 'HTTP '.$res->status(), 'ms' => $ms];
            }

            $this->logEvent($eventName, $eventId, $userData, $res->status(), $ms, null, $res->json('fbtrace_id'));
            $this->settings->update(['last_event_sent_at' => now()->toIso8601String()]);

            return ['ok' => true, 'status' => $res->status(), 'body' => $res->json(), 'error' => null, 'ms' => $ms];
        } catch (\Throwable $e) {
            $this->logEvent($eventName, $eventId, [], 0, (int) round((microtime(true) - $started) * 1000), $e->getMessage());

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $e->getMessage(),
                'ms' => (int) round((microtime(true) - $started) * 1000)];
        }
    }

    /**
     * One line per CAPI call, with enough to diagnose match quality and
     * deduplication and nothing that identifies a customer.
     *
     * Only the *names* of the user_data keys are recorded — never their values,
     * hashed or otherwise. A SHA-256 of an email is still a stable identifier
     * for that person, so a log full of them is a log full of PII.
     */
    protected function logEvent(string $event, string $eventId, array $userData, int $status, int $ms, ?string $error = null, ?string $trace = null): void
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
            // The browser Pixel fires the same four events with the same
            // event_id; anything else has no browser copy to collapse with.
            'dedup_expected' => $this->pixelEnabled()
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

        $custom = match ($event) {
            'ViewContent', 'AddToCart' => ['content_type' => 'product', 'content_ids' => ['prod-test'], 'content_name' => 'Test product', 'currency' => $this->currency(), 'value' => 1.0],
            'InitiateCheckout', 'Purchase' => ['content_type' => 'product', 'content_ids' => ['prod-test'], 'currency' => $this->currency(), 'value' => 1.0, 'num_items' => 1],
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
