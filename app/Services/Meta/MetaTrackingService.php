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
    ) {}

    /** Whether server-side CAPI sending is enabled and fully configured (DB). */
    public function enabled(): bool
    {
        return $this->settings->capiEnabled();
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

    public function purchase(Order $order, string $eventId): void
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
        ], $eventId);
    }

    public function lead(string $phone, ?string $name, string $eventId): void
    {
        $this->send('Lead', $this->hashUser(['ph' => $phone, 'fn' => $name]), [], $eventId);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /**
     * POST a single server event to the Graph /events endpoint. Reads the Pixel
     * ID and access token from the database. Never throws into the caller.
     */
    protected function send(string $eventName, array $userData, array $customData, string $eventId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $pixelId = $this->settings->pixelId();
        $token = $this->settings->capiToken();
        if (! $pixelId || ! $token) {
            return;
        }

        try {
            $payload = [
                'data' => [[
                    'event_name' => $eventName,
                    'event_time' => time(),
                    'event_id' => $eventId,
                    'action_source' => 'website',
                    'event_source_url' => url()->current(),
                    'user_data' => array_merge(array_filter($userData), array_filter([
                        'client_ip_address' => request()->ip(),
                        'client_user_agent' => request()->userAgent(),
                        'fbc' => request()->cookie('_fbc'),
                        'fbp' => request()->cookie('_fbp'),
                    ])),
                    'custom_data' => array_filter($customData, fn ($v) => $v !== null && $v !== []),
                ]],
            ];

            if ($code = config('meta.test_event_code')) {
                $payload['test_event_code'] = $code;
            }

            $url = sprintf('%s/%s/%s/events',
                rtrim((string) config('meta.graph_url', 'https://graph.facebook.com'), '/'),
                config('meta.graph_version', 'v21.0'),
                $pixelId,
            );

            $res = Http::timeout(10)->post($url.'?access_token='.urlencode($token), $payload);

            if ($res->failed()) {
                Log::warning('Meta CAPI event failed', ['event' => $eventName, 'body' => $res->body()]);
            }
        } catch (\Throwable $e) {
            Log::error('Meta CAPI error', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
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
