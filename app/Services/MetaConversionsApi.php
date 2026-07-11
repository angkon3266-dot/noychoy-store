<?php

namespace App\Services;

use App\Models\Order;
use App\Services\Meta\MetaTrackingService;

/**
 * Backwards-compatible facade over {@see MetaTrackingService}.
 *
 * The server-side Conversions API logic (DB-driven credentials, SHA256 hashing,
 * event dedup, transport) now lives in MetaTrackingService — this class remains
 * only so existing callers (e.g. App\Actions\PlaceOrder) keep working unchanged.
 * Prefer injecting MetaTrackingService directly in new code.
 *
 * @deprecated Use App\Services\Meta\MetaTrackingService.
 */
class MetaConversionsApi
{
    public function __construct(private readonly MetaTrackingService $tracking) {}

    public function isEnabled(): bool
    {
        return $this->tracking->enabled();
    }

    public function purchase(Order $order, ?string $eventId = null): void
    {
        $this->tracking->purchase($order, $eventId ?? $order->order_number);
    }

    public function lead(string $phone, ?string $name = null, ?string $eventId = null): void
    {
        $this->tracking->lead($phone, $name, $eventId ?? MetaTrackingService::newEventId('Lead'));
    }
}
