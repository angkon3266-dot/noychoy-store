<?php

namespace App\Modules\Meta\Services;

use App\Services\Meta\MetaSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Meta Integration Debug Mode.
 *
 * A reusable, self-contained diagnostics service for the Meta Graph API. When
 * enabled (local/development, or META_DEBUG=true) it:
 *   - performs Graph requests via {@see graph()} and records EVERYTHING
 *     (method, endpoint, query, status, duration, headers, raw JSON, error
 *     object, exception, retry) to a dedicated `meta-debug` log channel
 *     (storage/logs/meta-debug.log) AND to an in-memory ring buffer the admin
 *     debug page reads;
 *   - stamps every request with a unique Request ID + a rich context block.
 *
 * It never changes integration behaviour — it only observes. Access tokens are
 * redacted from the buffer/logs (only presence + expiry are kept).
 *
 * This is a temporary diagnostic tool; remove it once the Commerce Catalog issue
 * is resolved (search for MetaDebug references).
 */
class MetaDebug
{
    /** Ring-buffer cache key + cap. */
    private const BUFFER = 'meta_debug.entries';
    private const BUFFER_MAX = 120;

    private ?string $requestId = null;

    public function __construct(
        private readonly MetaSettings $legacy,
        private readonly MetaTokenManager $tokens,
    ) {}

    /** Debug Mode is on locally/dev, or anywhere META_DEBUG=true. */
    public function enabled(): bool
    {
        return (bool) config('meta.debug') || app()->environment(['local', 'development']);
    }

    /** One stable id per request/worker lifecycle. */
    public function requestId(): string
    {
        return $this->requestId ??= (string) Str::uuid();
    }

    // ── Token (prefer the modular connection, fall back to legacy Commerce) ────

    /**
     * Token precedence: the modular OAuth connection ALWAYS wins when a
     * meta_connections row exists — we only fall back to legacy when there is
     * genuinely no modular connection at all. This prevents a silent downgrade
     * to the legacy MetaSettings token when the modular token is momentarily
     * empty/unreadable (which just hides the real modular problem).
     */
    public function token(): ?string
    {
        if ($this->tokens->existing() !== null) {
            return $this->modularToken();
        }

        return $this->legacy->token();
    }

    public function tokenSource(): ?string
    {
        if ($this->tokens->existing() !== null) {
            return $this->modularToken()
                ? 'modular (MetaTokenManager / meta_connections)'
                : 'modular connection present but token missing/unreadable — reconnect via Marketing → Meta Connection';
        }
        if ($this->legacy->token()) {
            return 'legacy (MetaSettings / meta_integration)';
        }

        return null;
    }

    /** The legacy MetaSettings token (for the diagnostic comparison panel). */
    public function legacyToken(): ?string
    {
        return $this->legacy->token();
    }

    /** Read the modular token, swallowing a decrypt failure (APP_KEY mismatch). */
    private function modularToken(): ?string
    {
        try {
            return $this->tokens->token() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Raw dump of every meta_connections row for diagnosis — reads the stored
     * (encrypted) column WITHOUT decrypting so a key mismatch can't throw, then
     * reports separately whether it decrypts. Answers "does the modular
     * connection still exist and does its token work?".
     */
    public function connectionDump(): array
    {
        return \App\Models\MetaConnection::query()->orderBy('id')->get()->map(function ($c) {
            $rawToken = $c->getRawOriginal('access_token');
            $rawRefresh = $c->getRawOriginal('refresh_token');

            try {
                $decrypted = $c->access_token; // triggers the `encrypted` cast
                $decryptState = filled($decrypted) ? 'decrypts OK (non-empty)' : 'decrypts to empty';
            } catch (\Throwable $e) {
                $decryptState = 'DECRYPT FAILED — '.$e->getMessage().' (APP_KEY changed since the token was stored?)';
            }

            $selectedCatalog = $c->assets()->where('type', 'catalog')->where('is_selected', true)->first();

            return [
                'connection_id' => $c->id,
                'provider' => $c->provider,
                // No user_id column: one connection per install, keyed by unique provider.
                'user_id' => 'n/a — meta_connections is per-install (no user_id column)',
                // No "active" column either; derived: has a token AND not disconnected.
                'active' => filled($rawToken) && $c->health_status !== 'disconnected',
                'access_token_exists' => (bool) filled($rawToken),
                'access_token_decrypt' => $decryptState,
                'access_token_column' => $rawToken ? 'present ('.strlen((string) $rawToken).' chars, encrypted)' : 'NULL / empty',
                'refresh_token_exists' => (bool) filled($rawRefresh),
                'expires_at' => optional($c->token_expires_at)->toIso8601String(),
                'scopes' => $c->granted_scopes ?? [],
                'business_id' => $c->business_id,
                // business_id IS the selected business in this schema (single connection).
                'selected_business_id' => $c->business_id,
                'business_name' => $c->business_name,
                'selected_catalog_id' => $selectedCatalog?->external_id,
                'selected_catalog_name' => $selectedCatalog?->name,
                'health_status' => $c->health_status,
                'created_at' => optional($c->created_at)->toIso8601String(),
                'updated_at' => optional($c->updated_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * The exact query used to resolve the active modular connection
     * (MetaTokenManager::existing()), so the lookup logic can be verified.
     */
    public function lookupQuery(): array
    {
        $query = \App\Models\MetaConnection::query()->where('provider', 'meta');

        return [
            'method' => 'MetaTokenManager::existing()',
            'eloquent' => "MetaConnection::where('provider', 'meta')->first()",
            'sql' => $query->toSql().' limit 1',
            'bindings' => $query->getBindings(),
            'note' => 'No "active"/"user_id" filter — `provider` is UNIQUE, so this returns the single install connection (or null).',
        ];
    }

    public function businessId(): ?string
    {
        return $this->tokens->businessId() ?: $this->legacy->businessId();
    }

    public function catalogId(): ?string
    {
        $selected = $this->tokens->selectedAsset('catalog');

        return ($selected['id'] ?? null) ?: $this->legacy->catalogId();
    }

    /**
     * Readiness flags — every boolean the debug page / a caller might gate on.
     * NOTE: the debug testers do NOT gate on any of these; they only need a
     * token. This is surfaced so it's obvious there is no hidden gating and no
     * circular "discover-before-you-can-discover" dependency.
     */
    public function readiness(): array
    {
        $conn = $this->tokens->existing();

        return [
            'debug_enabled' => $this->enabled(),
            'hasConnection' => $conn !== null,
            'hasToken' => filled($this->token()),
            'tokenSource' => $this->tokenSource(),
            'hasBusinessId' => filled($this->businessId()),
            'hasCatalog' => filled($this->catalogId()),
            'hasSelectedBusiness' => filled($this->tokens->businessId()),
            'hasSelectedCatalog' => $this->tokens->selectedAsset('catalog') !== null,
            'hasLoginConfigId' => filled(config('meta.oauth.config_id')),
            'loginConfigId' => config('meta.oauth.config_id'),
            'grantedScopes' => $this->tokens->scopes(),
            'tokenExpiration' => optional($conn?->token_expires_at)->toIso8601String()
                ?: $this->legacy->get('token_expires_at'),
            // The ONLY thing that disables a debug button is an in-flight request
            // (client-side `running`). No readiness/business/catalog gate exists.
            'buttons_gated_by_readiness' => false,
        ];
    }

    /** The full per-request context block the spec asks to log on every request. */
    public function context(): array
    {
        $conn = $this->tokens->existing();

        return [
            'timestamp' => now()->toIso8601String(),
            'request_id' => $this->requestId(),
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email,
            'token_source' => $this->tokenSource(),
            'connected' => filled($this->token()),
            'business_portfolio_id' => $this->businessId(),
            'business_name' => $conn?->business_name ?: $this->legacy->get('connected_business_name'),
            'selected_catalog_id' => $this->catalogId(),
            'selected_catalog_name' => $this->legacy->get('connected_catalog_name'),
            'granted_scopes' => $this->tokens->scopes(),
            'app_id' => config('meta.oauth.app_id'),
            'login_config_id' => config('meta.oauth.config_id'),
            'graph_version' => config('meta.graph_version'),
            'graph_url' => config('meta.graph_url'),
            'token_expires_at' => optional($conn?->token_expires_at)->toIso8601String()
                ?: $this->legacy->get('token_expires_at'),
        ];
    }

    // ── Instrumented Graph request ─────────────────────────────────────────────

    /**
     * Perform a Graph request and record the full request/response. Returns a
     * structured result: ok, status, duration_ms, headers, body (raw JSON),
     * error (Graph error object), exception, and the stored `record`.
     *
     * @param  string  $method  GET|POST
     */
    public function graph(string $method, string $path, array $params = [], ?string $token = null, int $retry = 0): array
    {
        $token ??= $this->token();
        $method = strtoupper($method);
        $base = rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version');
        $url = $base.'/'.ltrim($path, '/');

        // Query actually sent to Meta (token added), and a redacted copy to store.
        $sent = $params;
        if ($token) {
            $sent['access_token'] = $token;
            if ($proof = $this->appSecretProof($token)) {
                $sent['appsecret_proof'] = $proof;
            }
        }

        $started = microtime(true);
        $status = 0;
        $headers = [];
        $body = null;
        $graphError = null;
        $exception = null;
        $ok = false;

        try {
            $http = Http::timeout(30)->acceptJson();
            $response = $method === 'GET' ? $http->get($url, $sent) : $http->asForm()->post($url, $sent);

            $status = $response->status();
            $headers = $response->headers();
            $body = $response->json() ?? ['_raw_body' => $response->body()];
            $graphError = is_array($body) ? ($body['error'] ?? null) : null;
            $ok = ! $response->failed() && $graphError === null;
        } catch (\Throwable $e) {
            $exception = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
            $graphError = ['message' => $e->getMessage(), 'type' => 'exception'];
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $record = [
            'id' => (string) Str::uuid(),
            'request_id' => $this->requestId(),
            'at' => now()->toIso8601String(),
            'type' => 'graph',
            'method' => $method,
            'endpoint' => $path,
            'url' => $url,
            'query' => $this->redact($params),
            'scopes' => $this->tokens->scopes(),
            'business_id' => $this->businessId(),
            'catalog_id' => $this->catalogId(),
            'token_present' => filled($token),
            'token_expires_at' => $this->context()['token_expires_at'],
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'response_headers' => $headers,
            'response' => $body,
            'graph_error' => $graphError,
            'exception' => $exception,
            'retry' => $retry,
            'is_error' => ! $ok,
        ];

        $this->record($record);

        return [
            'ok' => $ok,
            'status' => $status,
            'duration_ms' => $durationMs,
            'headers' => $headers,
            'body' => $body,
            'error' => $graphError,
            'exception' => $exception,
            'record' => $record,
        ];
    }

    /** App-secret-proof (defends the token), matching MetaGraphClient. */
    private function appSecretProof(string $token): ?string
    {
        $secret = config('meta.oauth.app_secret');

        return $secret ? hash_hmac('sha256', $token, $secret) : null;
    }

    private function redact(array $params): array
    {
        foreach (['access_token', 'appsecret_proof', 'client_secret', 'fb_exchange_token'] as $k) {
            if (isset($params[$k])) {
                $params[$k] = '***redacted***';
            }
        }

        return $params;
    }

    // ── Structured logging + ring buffer ──────────────────────────────────────

    /** Log a free-form event (OAuth step, discovery note, …) + buffer it. */
    public function event(string $type, string $message, array $data = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::channel('meta-debug')->debug("[{$type}] {$message}", ['request_id' => $this->requestId()] + $data);

        $this->push([
            'id' => (string) Str::uuid(),
            'request_id' => $this->requestId(),
            'at' => now()->toIso8601String(),
            'type' => $type,
            'endpoint' => $message,
            'response' => $data,
            'is_error' => false,
        ]);
    }

    /** Store a full graph record in the log channel + ring buffer. */
    public function record(array $record): void
    {
        if (! $this->enabled()) {
            return;
        }

        $summary = sprintf(
            '%s %s -> HTTP %s (%dms)%s',
            $record['method'] ?? '?',
            $record['endpoint'] ?? '?',
            $record['http_status'] ?? '?',
            $record['duration_ms'] ?? 0,
            ($record['is_error'] ?? false) ? ' [ERROR]' : '',
        );

        Log::channel('meta-debug')->{($record['is_error'] ?? false) ? 'error' : 'debug'}($summary, $record);

        $this->push($record);
    }

    private function push(array $record): void
    {
        $buffer = Cache::get(self::BUFFER, []);
        array_unshift($buffer, $record);
        Cache::put(self::BUFFER, array_slice($buffer, 0, self::BUFFER_MAX), now()->addDay());
    }

    /** Recent buffered entries, optionally only errors. */
    public function recent(?string $only = null, int $limit = 60): array
    {
        $buffer = collect(Cache::get(self::BUFFER, []));

        if ($only === 'error') {
            $buffer = $buffer->where('is_error', true);
        } elseif ($only === 'graph') {
            $buffer = $buffer->where('type', 'graph');
        }

        return $buffer->take($limit)->values()->all();
    }

    public function clear(): void
    {
        Cache::forget(self::BUFFER);
    }
}
