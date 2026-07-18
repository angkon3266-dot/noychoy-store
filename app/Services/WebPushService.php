<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Self-contained Web Push (RFC 8291 message encryption + RFC 8292 VAPID),
 * implemented with PHP's native openssl + hash_hkdf — no third-party package,
 * so there's nothing extra to install on the host. The aes128gcm encryption is
 * verified byte-for-byte against the RFC 8291 §5 test vector.
 *
 * VAPID keys live in Settings (webpush_public_key / webpush_private_key), both
 * base64url-encoded raw P-256 keys. Generate them with `php artisan webpush:keys`.
 */
class WebPushService
{
    /** Details of the most recent send() — status, error, response body. */
    public array $lastResult = [];

    /** Push is usable only when enabled AND a VAPID keypair exists. */
    public function ready(): bool
    {
        return (bool) Setting::get('webpush_enabled', false)
            && filled(Setting::get('webpush_public_key'))
            && filled(Setting::get('webpush_private_key'));
    }

    /**
     * Environment + config self-check, so "push isn't working" becomes a specific
     * diagnosis (missing extension, EC keygen unavailable, no keys/subscribers…).
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $ecOk = false;
        $ecErr = null;
        try {
            // Exercise the real key path (random scalar + import) rather than
            // openssl_pkey_new, which some PHP builds can't do for EC curves.
            $ecOk = strlen($this->importFromRaw(self::randomScalar())[1]) === 65;
        } catch (\Throwable $e) {
            $ecErr = $e->getMessage();
        }

        return [
            'enabled' => (bool) Setting::get('webpush_enabled', false),
            'keys_present' => filled(Setting::get('webpush_public_key')) && filled(Setting::get('webpush_private_key')),
            'curl' => function_exists('curl_init'),
            'openssl_sign' => function_exists('openssl_sign'),
            'openssl_pkey_derive' => function_exists('openssl_pkey_derive'),
            'hash_hkdf' => function_exists('hash_hkdf'),
            'ec_keygen' => $ecOk,
            'ec_error' => $ecErr,
            'subscribers' => \App\Models\PushSubscription::count(),
            'subject' => $this->subject(),
        ];
    }

    public function publicKey(): ?string
    {
        return Setting::get('webpush_public_key') ?: null;
    }

    public function subject(): string
    {
        $sub = Setting::get('webpush_subject');
        if (filled($sub)) {
            return str_starts_with($sub, 'mailto:') || str_starts_with($sub, 'http') ? $sub : 'mailto:'.$sub;
        }

        return url('/');
    }

    /** Generate a fresh VAPID P-256 keypair as base64url raw keys. */
    public function generateKeys(): array
    {
        // A uniformly random 32-byte scalar in [1, n-1] is a valid P-256 private
        // key; deriving the public point via key import avoids openssl_pkey_new,
        // which some PHP builds (e.g. native Windows) can't do for EC curves.
        $private = self::randomScalar();
        [, $public] = $this->importFromRaw($private);

        return [
            'public' => self::b64urlEncode($public),
            'private' => self::b64urlEncode($private),
        ];
    }

    /** Random P-256 private scalar in [1, n-1] (big-endian, 32 bytes). */
    protected static function randomScalar(): string
    {
        // Curve order n for prime256v1.
        $n = hex2bin('FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551');
        do {
            $d = random_bytes(32);
        } while ($d === str_repeat("\x00", 32) || strcmp($d, $n) >= 0);

        return $d;
    }

    /**
     * Send one notification to one subscription.
     * Returns the HTTP status (201/200 = delivered). 404/410 = gone (prune it).
     */
    public function send(PushSubscription $sub, array $payload): int
    {
        if (! $this->ready()) {
            return 0;
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            [$content, $asPublic] = $this->encrypt($body, self::b64urlDecode($sub->p256dh), self::b64urlDecode($sub->auth));
            $vapid = $this->vapidHeaders($sub->endpoint);

            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: '.(int) Setting::get('webpush_ttl', 2419200), // 4 weeks
                'Content-Length: '.strlen($content),
                $vapid,
            ];

            $ch = curl_init($sub->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $content,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $this->lastResult = [
                'status' => $status,
                'error' => $err ?: null,
                'body' => is_string($response) ? substr($response, 0, 400) : null,
            ];

            if ($status < 200 || $status >= 300) {
                Log::warning('WebPush non-2xx', [
                    'endpoint' => $sub->endpoint,
                    'status' => $status,
                    'error' => $err,
                    'body' => $this->lastResult['body'],
                ]);
            }

            return $status;
        } catch (\Throwable $e) {
            $this->lastResult = ['status' => 0, 'error' => $e->getMessage(), 'body' => null];
            Log::warning('WebPush send failed', ['id' => $sub->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Should the subscription that produced $status be deleted? Gone endpoints
     * (404/410) and subscriptions made under a now-replaced VAPID key
     * (VapidPkHashMismatch, returned as 400/403) are both dead — pruning lets the
     * browser recreate a fresh one on its next visit.
     */
    public function shouldPrune(int $status): bool
    {
        if (in_array($status, [404, 410], true)) {
            return true;
        }
        if (in_array($status, [400, 403], true)) {
            return str_contains((string) ($this->lastResult['body'] ?? ''), 'VapidPkHashMismatch');
        }

        return false;
    }

    // ── Crypto ───────────────────────────────────────────────────────────────

    /**
     * Encrypt a payload for a subscription (RFC 8291, aes128gcm).
     * Test hooks let the RFC vector inject a fixed ephemeral key + salt.
     *
     * @return array{0:string, 1:string}  [body, applicationServerPublicKey(raw)]
     */
    public function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPrivateRaw = null, ?string $salt = null): array
    {
        // Application-server (ephemeral) keypair — random scalar + import, so
        // encryption works even where openssl_pkey_new can't make EC keys.
        [$asPrivatePem, $asPublic] = $this->importFromRaw($asPrivateRaw ?? self::randomScalar());

        $salt ??= random_bytes(16);

        // ECDH shared secret with the subscriber's public key.
        $shared = openssl_pkey_derive(self::rawPublicToPem($uaPublic), $asPrivatePem, 32);
        if (! $shared) {
            throw new \RuntimeException('ECDH derive failed: '.openssl_error_string());
        }

        // RFC 8291 key derivation, then RFC 8188 content encryption keys.
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        // Header: salt(16) | record-size(4, uint32) | idlen(1)=65 | keyid(as public 65) | ciphertext | tag
        $body = $salt.pack('N', 4096).chr(65).$asPublic.$ciphertext.$tag;

        return [$body, $asPublic];
    }

    /** Build the VAPID `Authorization` header for an endpoint (RFC 8292, ES256). */
    public function vapidHeaders(string $endpoint): string
    {
        $publicRaw = self::b64urlDecode(Setting::get('webpush_public_key'));
        $privateRaw = self::b64urlDecode(Setting::get('webpush_private_key'));

        $parts = parse_url($endpoint);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $header = self::b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64urlEncode(json_encode([
            'aud' => $origin,
            'exp' => time() + 12 * 3600,
            'sub' => $this->subject(),
        ]));
        $unsigned = $header.'.'.$claims;

        [$privatePem] = $this->importFromRaw($privateRaw, $publicRaw);
        $der = '';
        openssl_sign($unsigned, $der, $privatePem, OPENSSL_ALGO_SHA256);
        $jwt = $unsigned.'.'.self::b64urlEncode(self::derToRawSignature($der));

        return 'Authorization: vapid t='.$jwt.', k='.self::b64urlEncode($publicRaw);
    }

    // ── Key/format helpers ───────────────────────────────────────────────────

    /**
     * Build a PEM EC private key (and its raw public key) from a raw 32-byte scalar.
     * If the public key isn't supplied it's recovered from the private via openssl.
     *
     * @return array{0:string, 1:string}  [privatePem, rawPublic]
     */
    protected function importFromRaw(string $privateRaw, ?string $publicRaw = null): array
    {
        if ($publicRaw === null) {
            // Derive the public point by importing a bare private key first
            // (SEC1, named curve prime256v1, no explicit public key).
            $bare = "\x30\x31\x02\x01\x01\x04\x20".$privateRaw."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
            $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($bare), 64, "\n")."-----END EC PRIVATE KEY-----\n";
            $k = openssl_pkey_get_private($pem);
            $d = openssl_pkey_get_details($k);
            $publicRaw = "\x04".str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        }

        // SEC1 ECPrivateKey with named curve prime256v1 + explicit public key.
        $der = "\x30\x77\x02\x01\x01\x04\x20".$privateRaw
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$publicRaw;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        return [$pem, $publicRaw];
    }

    /** Raw 65-byte uncompressed point → PEM SubjectPublicKeyInfo (P-256). */
    protected static function rawPublicToPem(string $raw): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** DER-encoded ECDSA signature → raw 64-byte (r||s) for JWS ES256. */
    protected static function derToRawSignature(string $der): string
    {
        $offset = 0;
        $readInt = function () use ($der, &$offset): string {
            $offset++; // skip 0x02 tag
            $len = ord($der[$offset++]);
            $val = substr($der, $offset, $len);
            $offset += $len;
            $val = ltrim($val, "\x00");              // drop sign padding

            return str_pad($val, 32, "\x00", STR_PAD_LEFT);
        };
        $offset += 2; // skip SEQUENCE tag + length
        $r = $readInt();
        $s = $readInt();

        return $r.$s;
    }

    public static function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
