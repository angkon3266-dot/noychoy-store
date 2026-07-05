<?php

namespace App\Modules\Meta\Services;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

    public function isConfigured(): bool
    {
        return filled(config('meta.oauth.app_id')) && filled(config('meta.oauth.app_secret'));
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

        $configId = $module?->configId();
        if ($configId) {
            $params['config_id'] = $configId;
            $params['override_default_response_type'] = 'true';
        } else {
            // No Login-for-Business config → only standard scopes are valid here.
            $params['scope'] = 'public_profile';
        }

        return 'https://www.facebook.com/'.config('meta.graph_version').'/dialog/oauth?'.http_build_query($params);
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

        $this->discoverAssets($moduleKey, $token);

        $name = $this->registry->get($moduleKey)?->name() ?? 'Module';

        return ['ok' => true, 'module' => $moduleKey, 'message' => $name.' authorized with Meta.'];
    }

    /** Pull the assets relevant to a module into the Token Manager. */
    private function discoverAssets(?string $moduleKey, string $token): void
    {
        switch ($moduleKey) {
            case 'commerce':
                foreach ($this->businesses($token) as $business) {
                    $this->tokens->setBusiness((string) $business['id'], $business['name'] ?? null);
                    $catalogs = Http::acceptJson()->get($this->graph($business['id'].'/owned_product_catalogs'), [
                        'access_token' => $token, 'fields' => 'id,name', 'limit' => 100,
                    ])->json('data', []);
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

    private function businesses(string $token): array
    {
        return Http::acceptJson()->get($this->graph('me/businesses'), [
            'access_token' => $token, 'fields' => 'id,name', 'limit' => 50,
        ])->json('data', []);
    }
}
