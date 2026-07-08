<?php

namespace App\Modules\Meta\Services;

use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Modular, incremental Meta OAuth. A module is authorized only when the user
 * opens it and its scopes are missing — we then request the UNION of already
 * granted scopes plus the module's, so Meta only prompts for what's new and we
 * never lose prior grants ("never ask twice").
 *
 * Asset permissions (catalog/pages/IG/ads) are granted through a per-module
 * Facebook Login-for-Business configuration (config_id), not the `scope` param
 * (which would return "Invalid Scopes"). After login we use the Graph API to
 * discover the assets relevant to that module.
 */
class MetaOAuthService
{
    public function __construct(
        private readonly MetaTokenManager $tokens,
        private readonly ModuleRegistry $registry,
    ) {}

    private function graph(string $path): string
    {
        return rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version').'/'.ltrim($path, '/');
    }

    /** Meta Debug logger (no-op unless META_DEBUG / local). */
    private function debug(): MetaDebug
    {
        return app(MetaDebug::class);
    }

    public function isConfigured(): bool
    {
        return filled(config('meta.oauth.app_id')) && filled(config('meta.oauth.app_secret'));
    }

    /**
     * The Facebook Login-for-Business configuration id to use for a module.
     *
     * Resolved LIVE from config at call time — NOT from the module manifest.
     * Manifests are built during the service-provider register() phase, before
     * the System Config runtime overrides are applied in boot(), so a manifest
     * can freeze a stale/null config_id. A module may still declare its own id;
     * otherwise we use the shared `meta.oauth.config_id` (env META_LOGIN_CONFIG_ID
     * or the System Config override).
     */
    public function loginConfigId(?ModuleManifest $module = null): ?string
    {
        $fromModule = $module?->configId();

        return filled($fromModule) ? $fromModule : config('meta.oauth.config_id');
    }

    /** True when a Login-for-Business config id is available (any module). */
    public function hasLoginConfig(): bool
    {
        return filled($this->loginConfigId());
    }

    /** Scopes a module still needs (empty = already satisfied). */
    public function missingScopesFor(string $moduleKey): array
    {
        return array_values(array_diff($this->registry->scopesFor($moduleKey), $this->tokens->scopes()));
    }

    /** Marker separating the module key from the CSRF nonce inside `state`. */
    private const STATE_SEP = '~';

    /** True when an OAuth `state` belongs to the modular flow (encodes a module). */
    public function isModularState(?string $state): bool
    {
        return is_string($state) && str_contains($state, self::STATE_SEP);
    }

    /** Extract the module key encoded in a modular OAuth `state`. */
    public function moduleFromState(?string $state): ?string
    {
        return $this->isModularState($state) ? explode(self::STATE_SEP, $state, 2)[0] : null;
    }

    /** Build the Meta dialog URL to authorize a module (incrementally). */
    public function authorizeUrl(string $moduleKey, string $redirectUri, Request $request): string
    {
        $module = $this->registry->get($moduleKey);
        // The module is carried IN the state param (not a per-module callback URL),
        // so every flow can share one canonical redirect URI. The random suffix is
        // the CSRF nonce we verify on return.
        $state = $moduleKey.self::STATE_SEP.Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        $params = [
            'client_id' => config('meta.oauth.app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
        ];

        // Facebook Login for Business: the requested permissions come from the
        // login *configuration* (config_id), never the `scope` param. Passing
        // `scope=public_profile` here is exactly what makes Meta reject the app
        // with "This app needs at least one supported permission".
        $configId = $this->loginConfigId($module);
        if (! filled($configId)) {
            throw new \RuntimeException(
                'Meta Login for Business is not configured. Set the Login Config ID '.
                '(META_LOGIN_CONFIG_ID or System Config → Meta) before authorizing.'
            );
        }

        $params['config_id'] = $configId;
        $params['override_default_response_type'] = 'true';

        $url = 'https://www.facebook.com/'.config('meta.graph_version').'/dialog/oauth?'.http_build_query($params);

        // Requirement: log the final authorization URL before redirecting.
        Log::info('Meta OAuth authorize URL generated', [
            'module' => $moduleKey,
            'config_id' => $configId,
            'redirect_uri' => $redirectUri,
            'url' => $url,
        ]);

        return $url;
    }

    /**
     * Handle the OAuth callback: verify state, exchange the code for a
     * long-lived token, record the module's scopes as granted, and discover the
     * relevant assets.
     *
     * @return array{ok:bool, module:?string, message:string}
     */
    public function handleCallback(Request $request, string $redirectUri): array
    {
        if ($request->filled('error')) {
            return ['ok' => false, 'module' => null, 'message' => 'Authorization cancelled: '.$request->query('error_description', $request->query('error'))];
        }

        $state = (string) $request->query('state');
        if (! $request->filled('state') || $state !== $request->session()->pull('meta_oauth_state')) {
            return ['ok' => false, 'module' => null, 'message' => 'Invalid OAuth state. Please try again.'];
        }

        // Which module initiated the flow is read from the state parameter.
        $moduleKey = $this->moduleFromState($state);
        if (! $moduleKey || ! $this->registry->has($moduleKey)) {
            return ['ok' => false, 'module' => null, 'message' => 'Unknown module in OAuth state.'];
        }

        // Exchange code → short-lived → long-lived token.
        $short = Http::acceptJson()->get($this->graph('oauth/access_token'), [
            'client_id' => config('meta.oauth.app_id'),
            'client_secret' => config('meta.oauth.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $request->query('code'),
        ]);

        if ($short->failed() || ! $short->json('access_token')) {
            return ['ok' => false, 'module' => $moduleKey, 'message' => 'Failed to obtain access token: '.$short->json('error.message', 'unknown error')];
        }

        $long = Http::acceptJson()->get($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('meta.oauth.app_id'),
            'client_secret' => config('meta.oauth.app_secret'),
            'fb_exchange_token' => $short->json('access_token'),
        ]);

        $token = $long->json('access_token', $short->json('access_token'));
        $expiresIn = (int) $long->json('expires_in', 0);

        $this->tokens->setToken($token, $expiresIn > 0 ? now()->addSeconds($expiresIn) : null);
        $this->tokens->grantScopes($this->registry->scopesFor($moduleKey));
        $this->tokens->setHealth('ok');

        // TEMP TRACE (unconditional → laravel.log; independent of META_DEBUG/cache).
        Log::info('[meta-oauth] BEFORE discoverAssets', ['module' => $moduleKey, 'has_token' => filled($token)]);
        try {
            $this->discoverAssets($moduleKey, $token);
        } catch (\Throwable $e) {
            Log::error('[meta-oauth] discoverAssets THREW before saving', [
                'error' => $e->getMessage(),
                'class' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
        Log::info('[meta-oauth] AFTER discoverAssets', [
            'module' => $moduleKey,
            'business_id' => $this->tokens->businessId(),
            'catalogs' => count($this->tokens->assets('catalog')),
        ]);

        $name = $this->registry->get($moduleKey)?->name() ?? 'Module';

        return ['ok' => true, 'module' => $moduleKey, 'message' => $name.' authorized with Meta.'];
    }

    /** Pull the assets relevant to a module into the Token Manager. */
    private function discoverAssets(?string $moduleKey, string $token): void
    {
        // TEMP TRACE: proves entry + which switch branch runs.
        Log::info('[meta-oauth] discoverAssets ENTER', ['module' => $moduleKey]);

        switch ($moduleKey) {
            case 'commerce':
                // (1) Enumerate businesses. business_id is ONLY ever written inside
                // this loop (setBusiness). If /me/businesses returns no data, the
                // loop body never runs and business_id stays NULL — this is the
                // first point at which discovery silently produces nothing.
                $bizResp = Http::acceptJson()->get($this->graph('me/businesses'), [
                    'access_token' => $token, 'fields' => 'id,name,verification_status', 'limit' => 50,
                ]);
                $businesses = $bizResp->json('data', []);
                Log::info('[meta-oauth] GET /me/businesses', [
                    'url' => $this->graph('me/businesses'),
                    'http_status' => $bizResp->status(),
                    'count' => count($businesses),
                    'raw' => $bizResp->body(),
                ]);
                $this->debug()->event('discovery', 'GET /me/businesses', [
                    'status' => $bizResp->status(),
                    'count' => count($businesses),
                    'error' => $bizResp->json('error'),
                    'response' => $bizResp->json(),
                ]);

                if (empty($businesses)) {
                    $this->debug()->event('discovery', 'business_id LEFT NULL — /me/businesses returned 0 businesses, so setBusiness() is never called', [
                        'status' => $bizResp->status(),
                        'error' => $bizResp->json('error'),
                    ]);
                }

                foreach ($businesses as $business) {
                    // (2) THE update() that writes business_id.
                    $this->tokens->setBusiness((string) $business['id'], $business['name'] ?? null);
                    $this->debug()->event('discovery', 'setBusiness() wrote business_id', [
                        'business_id' => $business['id'] ?? null,
                        'business_name' => $business['name'] ?? null,
                    ]);

                    $ownedResp = Http::acceptJson()->get($this->graph($business['id'].'/owned_product_catalogs'), [
                        'access_token' => $token, 'fields' => 'id,name,product_count', 'limit' => 100,
                    ]);
                    $catalogs = $ownedResp->json('data', []);
                    Log::info("[meta-oauth] GET /{$business['id']}/owned_product_catalogs", [
                        'url' => $this->graph($business['id'].'/owned_product_catalogs'),
                        'http_status' => $ownedResp->status(),
                        'count' => count($catalogs),
                        'raw' => $ownedResp->body(),
                    ]);
                    $this->debug()->event('discovery', "GET /{$business['id']}/owned_product_catalogs", [
                        'status' => $ownedResp->status(),
                        'count' => count($catalogs),
                        'error' => $ownedResp->json('error'),
                        'response' => $ownedResp->json(),
                    ]);

                    // Diagnostic only (NOT persisted): catalogs the business can access
                    // but does not own (client/shared) — surfaces the owned-vs-shared case.
                    $accResp = Http::acceptJson()->get($this->graph($business['id'].'/product_catalogs'), [
                        'access_token' => $token, 'fields' => 'id,name,product_count', 'limit' => 100,
                    ]);
                    Log::info("[meta-oauth] GET /{$business['id']}/product_catalogs", [
                        'url' => $this->graph($business['id'].'/product_catalogs'),
                        'http_status' => $accResp->status(),
                        'count' => count($accResp->json('data', [])),
                        'raw' => $accResp->body(),
                    ]);
                    $this->debug()->event('discovery', "GET /{$business['id']}/product_catalogs (diagnostic, not persisted)", [
                        'status' => $accResp->status(),
                        'count' => count($accResp->json('data', [])),
                        'error' => $accResp->json('error'),
                        'response' => $accResp->json(),
                    ]);

                    foreach ($catalogs as $c) {
                        $this->tokens->putAsset('catalog', (string) $c['id'], $c['name'] ?? null);
                    }
                }
                break;

            case 'publishing':
            case 'inbox':
                $pages = Http::acceptJson()->get($this->graph('me/accounts'), [
                    'access_token' => $token, 'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100,
                ])->json('data', []);
                foreach ($pages as $p) {
                    $this->tokens->putAsset('page', (string) $p['id'], $p['name'] ?? null, false, $p['access_token'] ?? null);
                    if (! empty($p['instagram_business_account']['id'])) {
                        $ig = $p['instagram_business_account'];
                        $this->tokens->putAsset('instagram', (string) $ig['id'], $ig['username'] ?? null);
                    }
                }
                break;

            case 'analytics':
                $accounts = Http::acceptJson()->get($this->graph('me/adaccounts'), [
                    'access_token' => $token, 'fields' => 'id,name', 'limit' => 100,
                ])->json('data', []);
                foreach ($accounts as $a) {
                    $this->tokens->putAsset('ad_account', (string) $a['id'], $a['name'] ?? null);
                }
                break;
        }
    }

}
