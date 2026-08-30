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
                Log::warning('SMS rejected', ['to' => static::maskRecipients($to), 'response' => $data]);
            }

            return $accepted;
        } catch (\Throwable $e) {
            // Neither the numbers nor the exception message: a QueryException's
            // message is the full SQL with its bindings substituted in, which is
            // how a broadcast to 100 people put 100 phone numbers in the log.
            Log::error('SMS send failed', [
                'to' => static::maskRecipients($to),
                'error' => $e::class.' ('.$e->getCode().')',
            ]);
            $this->log($to, $message, $orderId, status: 'error', accepted: false, response: ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Human-readable explanation of the most recent send() for admin UIs.
     * Adds a plain-language hint for common gateway rejections, then the raw JSON.
     */
    public function explainLast(): string
    {
        $resp = $this->lastResponse ?? [];
        if (! $resp) {
            if (! $this->isEnabled()) {
                return ' — SMS is disabled or missing credentials (Base URL, API key, Secret key). Enable it in Integrations.';
            }
            return '';
        }

        $desc = (string) ($resp['StatusDescription'] ?? $resp['Text'] ?? '');
        $hint = '';
        if (stripos($desc, 'Org Client Not Found') !== false) {
            $hint = ' — KhudeBarta did not recognise your API key/secret on this gateway URL. '
                  . 'This is NOT a masking/sender-ID problem. Verify the Base URL, API key and Secret key match exactly '
                  . 'what KhudeBarta issued for THIS account (no extra spaces) and that API access is activated.';
        } elseif (stripos($desc, 'sender') !== false || stripos($desc, 'mask') !== false || stripos($desc, 'caller') !== false) {
            $hint = ' — The sender/caller ID was rejected. For non-masking, set the Caller ID to your approved numeric sender (e.g. 123); for masking, use your approved brand name.';
        } elseif (stripos($desc, 'balance') !== false || stripos($desc, 'insufficient') !== false) {
            $hint = ' — Your KhudeBarta account has insufficient balance.';
        }

        return $hint.' — gateway response: '.json_encode($resp);
    }

    /** Editable per-status templates (admin) with config fallback. */
    public function template(string $key): ?string
    {
        $saved = Setting::get('sms_templates', []);
        $value = is_array($saved) ? ($saved[$key] ?? null) : null;
        return filled($value) ? $value : config("sms.templates.{$key}");
    }

    /**
     * Send a configured template for an order, replacing {placeholders}.
     *
     * $template overrides the stored/config wording for this one send — used
     * where the caller has to guarantee a placeholder exists even if the owner
     * has rewritten the template without it.
     */
    public function sendTemplate(string $templateKey, Order $order, array $extra = [], ?string $template = null): bool
    {
        $template ??= $this->template($templateKey);
        if (! $template) {
            return false;
        }

        $order->loadMissing('items');

        $replacements = array_merge([
            '{name}' => $order->customer_name,
            '{store}' => store_name(),
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
            ->map(fn ($p) => self::normalizeNumber($p))
            ->filter()
            ->implode(',');
    }

    /**
     * One number in the form the gateway wants.
     *
     * Public because the broadcast needs it before sending: the same customer's
     * number can be stored as `01712345678` on one row and `8801712345678` on
     * another, and a list de-duplicated before normalising still messages them
     * twice — two SMS, two charges, one annoyed customer.
     */
    public static function normalizeNumber(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);

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
    }

    /**
     * "01712345678,0186…" becomes "3 recipient(s), last 4: 8859".
     *
     * Enough to identify a send in support without putting the customer list on
     * disk — the numbers are the most valuable thing this shop holds, and the
     * log file travels differently from the database: support pastes, cPanel
     * File Manager, downloaded backups.
     */
    public static function maskRecipients(string $to): string
    {
        $numbers = array_values(array_filter(array_map('trim', explode(',', $to))));

        if (empty($numbers)) {
            return '(none)';
        }

        $last = substr((string) end($numbers), -4);

        return count($numbers).' recipient(s), last 4: '.$last;
    }

    /**
     * Longest value `sms_logs.phone` can hold. A bulk send is one gateway call
     * with the numbers comma-separated, so the raw string is unbounded — 100
     * recipients is ~1,400 characters into a varchar(255).
     */
    protected const PHONE_COLUMN_LIMIT = 255;

    /**
     * Write the audit row. Never throws.
     *
     * A send that already reached the gateway must not be reported as failed
     * because the *record* of it wouldn't fit, and the admin must not get a 500
     * for a broadcast that went out. This used to happen both ways at once: the
     * INSERT overflowed `phone`, the catch block called this again with the same
     * oversized value, and the second failure escaped as a 500 — after the first
     * hundred customers had already been messaged.
     */
    protected function log(string $phone, string $message, ?int $orderId, string $status, bool $accepted, ?array $response, ?string $messageId = null, ?string $providerStatus = null): void
    {
        $count = self::countRecipients($phone);

        try {
            SmsLog::create([
                'phone' => self::phoneForLog($phone, $count),
                'recipients' => $count,
                'message' => $message,
                'direction' => 'out',
                'status' => $status,
                'provider_status' => $providerStatus,
                'message_id' => $messageId,
                'order_id' => $orderId,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            // Log the failure without the numbers: a QueryException's message is
            // the full SQL with its bindings substituted in, which is how a
            // broadcast to 100 people once put 100 phone numbers on disk.
            Log::error('SMS log write failed', [
                'to' => self::maskRecipients($phone),
                'error' => $e::class.' ('.$e->getCode().')',
            ]);
        }
    }

    /** How many numbers one send covers. */
    public static function countRecipients(string $phone): int
    {
        return count(array_filter(array_map('trim', explode(',', $phone))));
    }

    /**
     * What goes in the `phone` column.
     *
     * One recipient is stored as itself — that is the whole point of the column
     * and of its index. A bulk send stores a summary instead: the full list
     * neither fits nor belongs on disk, and `recipients` carries the count.
     */
    protected static function phoneForLog(string $phone, int $count): string
    {
        if ($count <= 1) {
            return substr(trim($phone), 0, self::PHONE_COLUMN_LIMIT);
        }

        $numbers = array_values(array_filter(array_map('trim', explode(',', $phone))));
        $last = substr((string) end($numbers), -4);

        return $count.' recipients · ends '.$last;
    }
}
