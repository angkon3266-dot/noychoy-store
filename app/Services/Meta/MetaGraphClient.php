<?php

namespace App\Services\Meta;

use App\Services\Meta\Exceptions\MetaApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin, stateless wrapper around the Meta Graph API. Knows nothing about our
 * domain models — it only speaks HTTP + Graph error semantics and normalises
 * every failure into a {@see MetaApiException}. Retries are the caller's job
 * (queue jobs), so this client fails fast.
 */
class MetaGraphClient
{
    public function __construct(private readonly MetaSettings $settings) {}

    private function base(): string
    {
        return rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version');
    }

    private function token(): string
    {
        $token = $this->settings->token();
        if (! $token) {
            throw new MetaApiException('No access token configured.', MetaApiException::TOKEN_INVALID);
        }

        return $token;
    }

    /** App-secret-proof appsecret_proof when we hold the app secret (defends token). */
    private function appSecretProof(string $token): ?string
    {
        $secret = config('meta.oauth.app_secret');

        return $secret ? hash_hmac('sha256', $token, $secret) : null;
    }

    /**
     * Perform a Graph request. $method is GET|POST. Returns the decoded body.
     *
     * @throws MetaApiException
     */
    public function request(string $method, string $path, array $params = [], ?string $token = null): array
    {
        $token ??= $this->token();
        $params['access_token'] = $token;
        if ($proof = $this->appSecretProof($token)) {
            $params['appsecret_proof'] = $proof;
        }

        $url = $this->base().'/'.ltrim($path, '/');

        $started = microtime(true);
        try {
            $http = Http::timeout(30)->acceptJson();

            $response = strtoupper($method) === 'GET'
                ? $http->get($url, $params)
                : $http->asForm()->post($url, $params);
        } catch (ConnectionException $e) {
            $this->debugRecord($method, $path, $url, $params, null, $started, $e);
            throw new MetaApiException(
                'Could not reach the Meta Graph API: '.$e->getMessage(),
                MetaApiException::NETWORK,
                previous: $e,
            );
        }

        // Meta Debug Mode: record every Graph call (no-op unless debug is enabled).
        $this->debugRecord($method, $path, $url, $params, $response, $started);

        return $this->decode($response);
    }

    /** Feed a Graph call into the Meta Debug buffer/log (no-op when disabled). */
    private function debugRecord(string $method, string $path, string $url, array $params, ?Response $response, float $started, ?\Throwable $e = null): void
    {
        $debug = app(\App\Modules\Meta\Services\MetaDebug::class);
        if (! $debug->enabled()) {
            return;
        }

        $params = collect($params)->except(['access_token', 'appsecret_proof'])->all();
        $body = $response?->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        $debug->record([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'request_id' => $debug->requestId(),
            'at' => now()->toIso8601String(),
            'type' => 'graph',
            'source' => 'MetaGraphClient (sync path)',
            'method' => strtoupper($method),
            'endpoint' => $path,
            'url' => $url,
            'query' => $params,
            'http_status' => $response?->status() ?? 0,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'response_headers' => $response?->headers() ?? [],
            'response' => $body,
            'graph_error' => $e ? ['message' => $e->getMessage(), 'type' => 'exception'] : $error,
            'exception' => $e ? ['class' => $e::class, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()] : null,
            'retry' => 0,
            'is_error' => $e !== null || ($response?->failed() ?? true) || $error !== null,
        ]);
    }

    /** @throws MetaApiException */
    private function decode(Response $response): array
    {
        $body = $response->json() ?? [];

        if ($response->failed() || isset($body['error'])) {
            $error = $body['error'] ?? ['message' => 'HTTP '.$response->status(), 'code' => 0];
            throw MetaApiException::fromGraphError($error, $response->status());
        }

        return is_array($body) ? $body : [];
    }

    // ── Connection / auth introspection ────────────────────────────────────

    /**
     * Inspect a token: validity, expiry, granted scopes, owning app/user.
     * Uses appsecret when available (more reliable), else the token itself.
     */
    public function debugToken(?string $token = null): array
    {
        $token ??= $this->token();
        $appId = config('meta.oauth.app_id');
        $appSecret = config('meta.oauth.app_secret');
        $inspector = ($appId && $appSecret) ? "{$appId}|{$appSecret}" : $token;

        $data = $this->request('GET', 'debug_token', [
            'input_token' => $token,
        ], $inspector);

        return $data['data'] ?? [];
    }

    public function business(string $businessId, ?string $token = null): array
    {
        return $this->request('GET', $businessId, [
            'fields' => 'id,name,verification_status',
        ], $token);
    }

    public function catalog(string $catalogId, ?string $token = null): array
    {
        return $this->request('GET', $catalogId, [
            'fields' => 'id,name,product_count,vertical',
        ], $token);
    }

    /** Long-lived / never-expiring system-user tokens report expires_at = 0. */
    public function grantedScopes(?string $token = null): array
    {
        $data = $this->debugToken($token);

        return $data['scopes'] ?? [];
    }

    // ── Catalog item batch (create / update / delete) ──────────────────────

    /**
     * Send a batch of catalog item mutations.
     *
     * @param  array<int, array{method:string, retailer_id:string, data?:array}>  $requests
     * @return array Meta batch handles/validation status.
     * @throws MetaApiException
     */
    public function itemsBatch(string $catalogId, array $requests): array
    {
        return $this->request('POST', "{$catalogId}/items_batch", [
            'item_type' => 'PRODUCT_ITEM',
            'requests' => json_encode(array_values($requests)),
        ]);
    }
}
