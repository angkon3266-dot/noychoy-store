<?php

namespace App\Services\Meta;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
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

    // ── Standard commerce events ─────────────────────────────────────────────

    public function viewContent(Product $product, string $eventId, array $user = []): void
    {
        $this->send('ViewContent', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => [$this->contentId($product)],
            'content_name' => $product->name,
            'currency' => $this->currency(),
            'value' => (float) $product->price,
        ], $eventId);
    }

    public function addToCart(Product $product, int $quantity, string $eventId, array $user = [], ?ProductVariant $variant = null): void
    {
        $unit = $variant?->price !== null ? (float) $variant->price : (float) $product->price;

        $this->send('AddToCart', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => [$this->contentId($product, $variant)],
            'content_name' => $product->name,
            'currency' => $this->currency(),
            'value' => $unit * max(1, $quantity),
        ], $eventId);
    }

    /**
     * @param  array<int,string>  $contentIds  retailer_ids ("prod-{id}") in the cart
     */
    public function initiateCheckout(array $contentIds, float $value, int $numItems, string $eventId, array $user = []): void
    {
        $this->send('InitiateCheckout', $this->hashUser($user), [
            'content_type' => 'product',
            'content_ids' => array_values($contentIds),
            'currency' => $this->currency(),
            'value' => $value,
            'num_items' => $numItems,
        ], $eventId);
    }

    /**
     * Snapshot the browser signals Meta uses for ad attribution (IP, user agent,
     * click/browser cookies, page URL). Capture this inside the HTTP request and
     * hand it to queued senders — a queue worker has no request to read from.
     *
     * @return array{ip:?string,ua:?string,fbc:?string,fbp:?string,url:string,time:int}
     */
    public static function captureClientContext(): array
    {
        return [
            'ip' => request()->ip(),
            'ua' => request()->userAgent(),
            'fbc' => request()->cookie('_fbc'),
            'fbp' => request()->cookie('_fbp'),
            'url' => url()->current(),
            'time' => time(),
        ];
    }

    public function purchase(Order $order, string $eventId, ?array $context = null): void
    {
        $order->loadMissing('items');

        $this->send('Purchase', $this->hashUser([
            'em' => $order->customer_email,
            'ph' => $order->customer_phone,
            'fn' => $order->customer_name,
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
        $this->send('Lead', $this->hashUser(['ph' => $phone, 'fn' => $name]), [], $eventId);
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
     * @return array{ok:bool,status:int,body:mixed,error:?string,ms:int}
     */
    protected function send(string $eventName, array $userData, array $customData, string $eventId, bool $test = false, ?array $context = null): array
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
            $payload = [
                'data' => [[
                    'event_name' => $eventName,
                    'event_time' => $context['time'] ?? time(),
                    'event_id' => $eventId,
                    'action_source' => 'website',
                    'event_source_url' => $context['url'] ?? url()->current(),
                    'user_data' => array_merge(array_filter($userData), array_filter([
                        'client_ip_address' => $context['ip'] ?? request()->ip(),
                        'client_user_agent' => $context['ua'] ?? request()->userAgent(),
                        'fbc' => $context['fbc'] ?? request()->cookie('_fbc'),
                        'fbp' => $context['fbp'] ?? request()->cookie('_fbp'),
                    ])),
                    'custom_data' => array_filter($customData, fn ($v) => $v !== null && $v !== []),
                ]],
            ];

            if ($code = $this->testEventCode()) {
                $payload['test_event_code'] = $code;
            }

            $url = sprintf('%s/%s/%s/events',
                rtrim((string) config('meta.graph_url', 'https://graph.facebook.com'), '/'),
                config('meta.graph_version', 'v21.0'),
                $pixelId,
            );

            $res = Http::timeout(10)->post($url.'?access_token='.urlencode($token), $payload);
            $ms = (int) round((microtime(true) - $started) * 1000);

            if ($res->failed()) {
                Log::warning('Meta CAPI event failed', ['event' => $eventName, 'body' => $res->body()]);

                return ['ok' => false, 'status' => $res->status(), 'body' => $res->json() ?? $res->body(),
                    'error' => $res->json('error.message') ?? 'HTTP '.$res->status(), 'ms' => $ms];
            }

            $this->settings->update(['last_event_sent_at' => now()->toIso8601String()]);

            return ['ok' => true, 'status' => $res->status(), 'body' => $res->json(), 'error' => null, 'ms' => $ms];
        } catch (\Throwable $e) {
            Log::error('Meta CAPI error', ['event' => $eventName, 'error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $e->getMessage(),
                'ms' => (int) round((microtime(true) - $started) * 1000)];
        }
    }

    /** Test-event code from the database, falling back to config (env). */
    public function testEventCode(): ?string
    {
        return $this->settings->get('test_event_code') ?: config('meta.test_event_code');
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
     * SHA256-hash the identifiable user fields Meta expects (em/ph/fn/ln),
     * normalising first. Empty fields are dropped. Non-PII fields are ignored.
     *
     * @param  array<string,mixed>  $raw  e.g. ['em'=>email, 'ph'=>phone, 'fn'=>name]
     * @return array<string,array<int,string>>
     */
    protected function hashUser(array $raw): array
    {
        $out = [];

        foreach (['em' => 'email', 'ph' => 'phone', 'fn' => 'name', 'ln' => 'name'] as $key => $type) {
            $value = $raw[$key] ?? null;
            if (! filled($value)) {
                continue;
            }

            $normalised = $type === 'phone' ? $this->normalizePhone((string) $value) : strtolower(trim((string) $value));
            if ($normalised === '') {
                continue;
            }

            $out[$key] = [hash('sha256', $normalised)];
        }

        return $out;
    }

    protected function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '0')) {
            $d = '88'.$d; // local (BD) → E.164 country code, matching existing behaviour
        }

        return $d;
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
