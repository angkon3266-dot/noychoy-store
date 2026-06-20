<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta Conversions API (server-side events), deduplicated with the browser
 * Pixel via a shared event_id. Used for high-value events like Purchase.
 */
class MetaConversionsApi
{
    public function isEnabled(): bool
    {
        return (bool) config('meta.capi_enabled')
            && filled(meta_pixel_id())
            && filled(config('meta.access_token'));
    }

    public function purchase(Order $order, ?string $eventId = null): void
    {
        $this->send('Purchase', [
            'em' => $order->customer_email ? [$this->hash($order->customer_email)] : [],
            'ph' => [$this->hash($this->normalizePhone($order->customer_phone))],
            'fn' => [$this->hash($order->customer_name)],
        ], [
            'currency' => config('store.currency'),
            'value' => (float) $order->total,
            'content_type' => 'product',
            'contents' => $order->items->map(fn ($i) => [
                'id' => (string) $i->product_id,
                'quantity' => $i->quantity,
                'item_price' => (float) $i->price,
            ])->all(),
            'content_ids' => $order->items->pluck('product_id')->map(fn ($id) => (string) $id)->all(),
            'num_items' => (int) $order->items->sum('quantity'),
        ], $eventId ?? $order->order_number);
    }

    public function lead(string $phone, ?string $name = null, ?string $eventId = null): void
    {
        $this->send('Lead', [
            'ph' => [$this->hash($this->normalizePhone($phone))],
            'fn' => $name ? [$this->hash($name)] : [],
        ], [], $eventId);
    }

    protected function send(string $eventName, array $userData, array $customData, ?string $eventId): void
    {
        if (! $this->isEnabled()) {
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
                    'user_data' => array_merge(array_filter($userData), [
                        'client_ip_address' => request()->ip(),
                        'client_user_agent' => request()->userAgent(),
                        'fbc' => request()->cookie('_fbc'),
                        'fbp' => request()->cookie('_fbp'),
                    ]),
                    'custom_data' => $customData,
                ]],
            ];

            if ($code = config('meta.test_event_code')) {
                $payload['test_event_code'] = $code;
            }

            $url = sprintf('https://graph.facebook.com/%s/%s/events?access_token=%s',
                config('meta.graph_version'), meta_pixel_id(), config('meta.access_token'));

            $res = Http::timeout(10)->post($url, $payload);

            if ($res->failed()) {
                Log::warning('Meta CAPI event failed', ['event' => $eventName, 'body' => $res->body()]);
            }
        } catch (\Throwable $e) {
            Log::error('Meta CAPI error', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    protected function hash(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    protected function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        if (str_starts_with($d, '0')) {
            $d = '88'.$d;
        }
        return $d;
    }
}
