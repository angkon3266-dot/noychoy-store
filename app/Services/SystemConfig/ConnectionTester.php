<?php

namespace App\Services\SystemConfig;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Transport;

/**
 * Live connectivity tests for configurable services. Every test is wrapped so
 * it returns a structured result and never throws into the caller. Used by the
 * "Test Connection" buttons and before applying changes.
 *
 * Tests operate on the *submitted* values (merged over current config) so an
 * admin validates a change before it is saved.
 */
class ConnectionTester
{
    /** @return array{ok:bool, message:string} */
    public function test(string $group, array $values): array
    {
        try {
            return match ($group) {
                'smtp' => $this->smtp($values),
                'redis' => $this->redis($values),
                'queue' => $this->queue($values),
                'storage' => $this->storage($values),
                'meta' => $this->meta($values),
                'sms' => $this->sms($values),
                'google' => $this->google($values),
                default => ['ok' => false, 'message' => 'No test available for this section.'],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '❌ '.$e->getMessage()];
        }
    }

    private function val(array $values, string $key, string $configPath = null)
    {
        $v = $values[$key] ?? null;

        return ($v === null || $v === '') && $configPath ? config($configPath) : $v;
    }

    private function smtp(array $v): array
    {
        $host = $this->val($v, 'mail.host', 'mail.mailers.smtp.host');
        $port = $this->val($v, 'mail.port', 'mail.mailers.smtp.port') ?: 587;
        $user = $this->val($v, 'mail.username', 'mail.mailers.smtp.username');
        $pass = $this->val($v, 'mail.password', 'mail.mailers.smtp.password');
        $enc = $this->val($v, 'mail.encryption', 'mail.mailers.smtp.encryption');

        if (! $host) {
            return ['ok' => false, 'message' => '❌ SMTP host is required.'];
        }

        $scheme = $enc === 'ssl' ? 'smtps' : 'smtp';
        $auth = $user ? rawurlencode($user).':'.rawurlencode((string) $pass).'@' : '';
        $dsn = "{$scheme}://{$auth}{$host}:{$port}";

        $transport = Transport::fromDsn($dsn);
        $transport->start(); // opens + authenticates; throws on failure

        return ['ok' => true, 'message' => "✅ Connected to {$host}:{$port}."];
    }

    private function redis(array $v): array
    {
        $host = $this->val($v, 'redis.host', 'database.redis.default.host') ?: '127.0.0.1';
        $port = (int) ($this->val($v, 'redis.port', 'database.redis.default.port') ?: 6379);
        $pass = $this->val($v, 'redis.password', 'database.redis.default.password');

        if (class_exists('Redis')) {
            $r = new \Redis;
            if (! $r->connect($host, $port, 2.0)) {
                return ['ok' => false, 'message' => "❌ Cannot connect to Redis at {$host}:{$port}."];
            }
            if ($pass) {
                $r->auth($pass);
            }
            $pong = $r->ping();
            $r->close();

            return ['ok' => (bool) $pong, 'message' => $pong ? "✅ Redis responded at {$host}:{$port}." : '❌ Redis did not respond.'];
        }

        if (class_exists('Predis\\Client')) {
            $client = new \Predis\Client(array_filter(['host' => $host, 'port' => $port, 'password' => $pass ?: null]));
            $client->ping();

            return ['ok' => true, 'message' => "✅ Redis responded at {$host}:{$port}."];
        }

        return ['ok' => false, 'message' => '❌ No Redis client (phpredis/predis) is installed.'];
    }

    private function queue(array $v): array
    {
        $driver = $this->val($v, 'queue.default', 'queue.default');

        return match ($driver) {
            'sync' => ['ok' => true, 'message' => '✅ Sync driver runs jobs inline (no worker needed).'],
            'redis' => $this->redis($v),
            'database' => \Illuminate\Support\Facades\Schema::hasTable('jobs')
                ? ['ok' => true, 'message' => '✅ Database queue ready (jobs table present).']
                : ['ok' => false, 'message' => '❌ jobs table missing — run migrations.'],
            default => ['ok' => false, 'message' => "❌ Unknown queue driver: {$driver}."],
        };
    }

    private function storage(array $v): array
    {
        $disk = $this->val($v, 'storage.default', 'filesystems.default') ?: 'public';

        // For S3/R2, apply the submitted credentials to a throwaway disk config.
        if ($disk === 's3') {
            config([
                'filesystems.disks.s3.key' => $this->val($v, 'storage.s3_key', 'filesystems.disks.s3.key'),
                'filesystems.disks.s3.secret' => $this->val($v, 'storage.s3_secret', 'filesystems.disks.s3.secret'),
                'filesystems.disks.s3.region' => $this->val($v, 'storage.s3_region', 'filesystems.disks.s3.region'),
                'filesystems.disks.s3.bucket' => $this->val($v, 'storage.s3_bucket', 'filesystems.disks.s3.bucket'),
                'filesystems.disks.s3.endpoint' => $this->val($v, 'storage.s3_endpoint', 'filesystems.disks.s3.endpoint'),
            ]);
            Storage::forgetDisk('s3');
        }

        $probe = '.system-config-healthcheck-'.uniqid().'.txt';
        Storage::disk($disk)->put($probe, 'ok');
        $ok = Storage::disk($disk)->get($probe) === 'ok';
        Storage::disk($disk)->delete($probe);

        return ['ok' => $ok, 'message' => $ok ? "✅ Storage disk '{$disk}' is writable." : "❌ Could not write to disk '{$disk}'."];
    }

    private function meta(array $v): array
    {
        $appId = $this->val($v, 'meta.app_id', 'meta.oauth.app_id');
        $secret = $this->val($v, 'meta.app_secret', 'meta.oauth.app_secret');

        if (! $appId || ! $secret) {
            return ['ok' => false, 'message' => '❌ App ID and App Secret are required.'];
        }

        $base = rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version');
        $res = Http::timeout(15)->acceptJson()->get("{$base}/oauth/access_token", [
            'client_id' => $appId,
            'client_secret' => $secret,
            'grant_type' => 'client_credentials',
        ]);

        if ($res->successful() && $res->json('access_token')) {
            return ['ok' => true, 'message' => '✅ Meta App credentials are valid.'];
        }

        return ['ok' => false, 'message' => '❌ '.$res->json('error.message', 'Invalid Meta App credentials.')];
    }

    private function sms(array $v): array
    {
        $base = $this->val($v, 'sms.base_url', 'sms.base_url');
        $key = $this->val($v, 'sms.api_key', 'sms.api_key');
        $secret = $this->val($v, 'sms.secret_key', 'sms.secret_key');

        if (! $base || ! $key) {
            return ['ok' => false, 'message' => '❌ Base URL and API key are required.'];
        }

        $url = rtrim(preg_replace('#/sendtext/?$#', '', $base), '/').'/api/v3/balance';
        $res = Http::timeout(15)->asForm()->post($url, ['apikey' => $key, 'secretkey' => $secret]);

        // KhudeBarta returns Status:"ERROR" for bad/whitelisted-IP creds.
        $status = (string) $res->json('Status', $res->json('status', ''));
        $ok = $res->successful() && ! in_array(strtoupper($status), ['ERROR', '-1'], true);

        return ['ok' => $ok, 'message' => $ok ? '✅ SMS gateway reachable.' : '❌ SMS gateway rejected the credentials (check IP whitelist).'];
    }

    private function google(array $v): array
    {
        $ga = $this->val($v, 'google.analytics_id', 'services.google.analytics_id');

        // No server-side handshake without a full OAuth flow — validate presence/shape.
        if ($ga && ! preg_match('/^G-[A-Z0-9]+$/i', (string) $ga)) {
            return ['ok' => false, 'message' => '❌ GA4 Measurement ID should look like "G-XXXXXXX".'];
        }

        return ['ok' => true, 'message' => '✅ Google settings look valid (no live handshake available).'];
    }
}
