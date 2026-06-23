<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KhudeBarta (SoftifyBD) SMS HTTP API client.
 * Send: GET {base}/sendtext?apikey=&secretkey=&callerID=&toUser=&messageContent=
 * Success when JSON Status === "0". Credentials & masking (callerID) are read
 * from the admin Settings panel first, falling back to config/.env.
 */
class SmsService
{
    /** Raw provider response from the most recent send() — for diagnostics. */
    public ?array $lastResponse = null;

    protected function cfg(string $key, $default = null)
    {
        $int = Setting::get('integrations', []);
        $value = is_array($int) ? ($int[$key] ?? null) : null;
        return ($value !== null && $value !== '') ? $value : $default;
    }

    /**
     * Gateway root (host:port), WITHOUT a trailing /sendtext.
     * Tolerates admins pasting the full send endpoint into the Base URL field.
     */
    protected function baseUrl(): string
    {
        $url = rtrim(trim((string) $this->cfg('sms_base_url', config('sms.base_url'))), '/');
        if (str_ends_with(strtolower($url), '/sendtext')) {
            $url = rtrim(substr($url, 0, -strlen('/sendtext')), '/');
        }

        return $url;
    }

    // Trim credentials: copy-pasted keys often carry trailing spaces/newlines,
    // which the gateway rejects with "Org Client Not Found".
    protected function apiKey(): ?string { return trim((string) $this->cfg('sms_api_key', config('sms.api_key'))) ?: null; }
    protected function secretKey(): ?string { return trim((string) $this->cfg('sms_secret_key', config('sms.secret_key'))) ?: null; }
    /** Masking sender ID (e.g. brand name), or a non-masking sender ID (e.g. "123"). Blank ⇒ omitted. */
    protected function callerId(): ?string { return trim((string) $this->cfg('sms_caller_id', config('sms.caller_id'))) ?: null; }

    public function isEnabled(): bool
    {
        $enabled = $this->cfg('sms_enabled', config('sms.enabled'));
        $enabled = filter_var($enabled, FILTER_VALIDATE_BOOL);

        return $enabled && filled($this->baseUrl()) && filled($this->apiKey()) && filled($this->secretKey());
    }

    /**
     * Send an SMS to one number (or comma-separated numbers for bulk).
     * Always writes an SmsLog row. Returns true when accepted (Status "0").
     */
    public function send(string $phone, string $message, ?int $orderId = null): bool
    {
        $to = $this->normalize($phone);

        if (! $this->isEnabled()) {
            $this->log($to, $message, $orderId, status: 'disabled', accepted: false, response: null);
            return false;
        }

        try {
            // callerID (sender ID) is REQUIRED by KhudeBarta — masking name OR a
            // non-masking sender ID. Always send it.
            $params = [
                'apikey' => $this->apiKey(),
                'secretkey' => $this->secretKey(),
                'callerID' => $this->callerId() ?? '',
                'toUser' => $to,
                'messageContent' => $message,
            ];

            $endpoint = $this->baseUrl().'/sendtext';
            $response = Http::timeout(config('sms.timeout', 20))->get($endpoint, $params);

            $this->lastResponse = $response->json() ?? ['raw' => $response->body()];
            // Surface the endpoint + HTTP code (no secrets) to aid diagnosis.
            $this->lastResponse['_endpoint'] = $endpoint;
            $this->lastResponse['_http'] = $response->status();

            $data = $response->json() ?? [];
            // KhudeBarta success = Status "0". Accept string or int just in case.
            $providerStatus = (string) ($data['Status'] ?? '');
            $accepted = in_array($providerStatus, ['0', '00'], true);

            $this->log($to, $message, $orderId,
                status: $data['Text'] ?? ($accepted ? 'ACCEPTD' : 'REJECTD'),
                accepted: $accepted,
                response: $data,
                messageId: $data['Message_ID'] ?? null,
                providerStatus: $providerStatus,
            );

            if (! $accepted) {
                Log::warning('SMS rejected', ['to' => $to, 'response' => $data]);
            }

            return $accepted;
        } catch (\Throwable $e) {
            Log::error('SMS send failed', ['to' => $to, 'error' => $e->getMessage()]);
            $this->log($to, $message, $orderId, status: 'error', accepted: false, response: ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Editable per-status templates (admin) with config fallback. */
    public function template(string $key): ?string
    {
        $saved = Setting::get('sms_templates', []);
        $value = is_array($saved) ? ($saved[$key] ?? null) : null;
        return filled($value) ? $value : config("sms.templates.{$key}");
    }

    /** Send a configured template for an order, replacing {placeholders}. */
    public function sendTemplate(string $templateKey, Order $order, array $extra = []): bool
    {
        $template = $this->template($templateKey);
        if (! $template) {
            return false;
        }

        $order->loadMissing('items');

        $replacements = array_merge([
            '{name}' => $order->customer_name,
            '{order}' => $order->order_number,
            '{total}' => number_format((float) $order->total, 0),
            '{qty}' => (string) $order->total_quantity,
            '{items}' => $order->items->map(fn ($i) => $i->name.' x'.$i->quantity)->implode(', '),
            '{tracking}' => $order->shipment?->tracking_code ?? '',
        ], $extra);

        $message = strtr($template, $replacements);

        return $this->send($order->customer_phone, $message, $order->id);
    }

    public function getBalance(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        // Never let a gateway timeout/connection error 500 the admin page.
        try {
            return Http::timeout(8)
                ->post($this->baseUrl().'/api/v3/balance', [
                    'apikey' => $this->apiKey(),
                    'secretkey' => $this->secretKey(),
                ])->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('SMS balance check failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function normalize(string $phone): string
    {
        // KhudeBarta expects 880XXXXXXXXXX. Supports comma-separated bulk.
        return collect(explode(',', $phone))
            ->map(function ($p) {
                $d = preg_replace('/\D/', '', $p);
                if (str_starts_with($d, '880')) {
                    return $d;
                }
                if (str_starts_with($d, '0')) {
                    return '88'.$d;
                }
                if (strlen($d) === 10 && $d[0] === '1') {
                    return '880'.$d;
                }
                return $d;
            })
            ->filter()
            ->implode(',');
    }

    protected function log(string $phone, string $message, ?int $orderId, string $status, bool $accepted, ?array $response, ?string $messageId = null, ?string $providerStatus = null): void
    {
        SmsLog::create([
            'phone' => $phone,
            'message' => $message,
            'direction' => 'out',
            'status' => $status,
            'provider_status' => $providerStatus,
            'message_id' => $messageId,
            'order_id' => $orderId,
            'response' => $response,
        ]);
    }
}
