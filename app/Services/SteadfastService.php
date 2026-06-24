<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Shipment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Steadfast Courier API client (https://portal.packzy.com/api/v1).
 * Auth via Api-Key / Secret-Key headers. Credentials are read from the
 * admin Settings panel first, falling back to config/.env.
 */
class SteadfastService
{
    /** Read an integration setting (admin panel) with config fallback. */
    protected function cfg(string $key, $default = null)
    {
        $int = Setting::get('integrations', []);
        $value = is_array($int) ? ($int[$key] ?? null) : null;
        return filled($value) ? $value : $default;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->cfg('steadfast_base_url', config('steadfast.base_url')))
            ->timeout(config('steadfast.timeout', 30))
            ->acceptJson()
            ->withHeaders([
                'Api-Key' => $this->cfg('steadfast_api_key', config('steadfast.api_key')),
                'Secret-Key' => $this->cfg('steadfast_secret_key', config('steadfast.secret_key')),
            ]);
    }

    public function isConfigured(): bool
    {
        return filled($this->cfg('steadfast_api_key', config('steadfast.api_key')))
            && filled($this->cfg('steadfast_secret_key', config('steadfast.secret_key')));
    }

    /** Raw consignment creation. */
    public function createConsignment(array $payload): array
    {
        return $this->client()->post('/create_order', $payload)->json() ?? [];
    }

    public function bulkCreate(array $orders): array
    {
        return $this->client()->post('/create_order/bulk-order', ['data' => $orders])->json() ?? [];
    }

    public function statusByConsignmentId(string $id): array
    {
        return $this->client()->get("/status_by_cid/{$id}")->json() ?? [];
    }

    public function statusByInvoice(string $invoice): array
    {
        return $this->client()->get("/status_by_invoice/{$invoice}")->json() ?? [];
    }

    public function statusByTrackingCode(string $code): array
    {
        return $this->client()->get("/status_by_trackingcode/{$code}")->json() ?? [];
    }

    /**
     * Live delivery status for a shipment, cached 10 min so page loads stay fast
     * and we don't hammer the courier API. Returns the raw Steadfast status string
     * (e.g. in_review, pending, delivered, partial_delivered, cancelled) or null.
     */
    public function deliveryStatus(?string $consignmentId): ?string
    {
        if (! $consignmentId || ! $this->isConfigured()) {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember(
            "sf_status_{$consignmentId}",
            now()->addMinutes(10),
            function () use ($consignmentId) {
                try {
                    return $this->statusByConsignmentId($consignmentId)['delivery_status'] ?? null;
                } catch (\Throwable $e) {
                    Log::warning('Steadfast status lookup failed', ['cid' => $consignmentId, 'error' => $e->getMessage()]);
                    return null;
                }
            }
        );
    }

    /** Map a raw Steadfast status to [label, step(0-3), tone]. */
    public static function describeStatus(?string $raw): array
    {
        $s = strtolower((string) $raw);
        return match (true) {
            str_contains($s, 'delivered') && ! str_contains($s, 'partial') => ['Delivered', 3, 'green'],
            str_contains($s, 'partial') => ['Partially delivered', 3, 'amber'],
            str_contains($s, 'cancel') => ['Cancelled', 3, 'red'],
            str_contains($s, 'hold') => ['On hold', 2, 'amber'],
            in_array($s, ['in_review', '', 'unknown']) => ['Booked with courier', 1, 'gold'],
            $s === 'pending' => ['Out for delivery', 2, 'gold'],
            default => [ucwords(str_replace('_', ' ', $s)), 2, 'gold'],
        };
    }

    public function getBalance(): array
    {
        return $this->client()->get('/get_balance')->json() ?? [];
    }

    public function createReturnRequest(array $payload): array
    {
        return $this->client()->post('/create_return_request', $payload)->json() ?? [];
    }

    /**
     * Create a consignment for an order and persist a Shipment record.
     * Returns the Shipment on success, or null on failure (logged).
     */
    public function createForOrder(Order $order): ?Shipment
    {
        if (! $this->isConfigured()) {
            Log::warning('Steadfast not configured; skipping consignment', ['order' => $order->order_number]);
            return null;
        }

        $payload = [
            'invoice' => $order->order_number,
            'recipient_name' => $order->customer_name,
            'recipient_phone' => $this->normalizePhone($order->customer_phone),
            'recipient_address' => trim($order->shipping_address.', '.$order->area.', '.$order->district),
            'cod_amount' => (float) ($order->payment_status === 'paid' ? 0 : $order->total),
            'note' => $order->notes ?? '',
            'item_description' => $order->items->map(fn ($i) => "{$i->name} x{$i->quantity}")->implode(', '),
        ];

        $response = $this->createConsignment($payload);
        $consignment = $response['consignment'] ?? null;

        // Success per API doc: top-level status 200 + consignment.consignment_id.
        if (! $consignment || empty($consignment['consignment_id'])) {
            Log::error('Steadfast consignment failed', ['order' => $order->order_number, 'response' => $response]);
            return null;
        }

        return Shipment::create([
            'order_id' => $order->id,
            'courier' => 'steadfast',
            'consignment_id' => $consignment['consignment_id'] ?? null,
            'tracking_code' => $consignment['tracking_code'] ?? null,
            'cod_amount' => $payload['cod_amount'],
            'status' => $consignment['status'] ?? 'in_review',
            'response' => $response,
        ]);
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        // Steadfast expects an 11-digit BD number (01XXXXXXXXX).
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0'.$digits;
        }
        return $digits;
    }
}
