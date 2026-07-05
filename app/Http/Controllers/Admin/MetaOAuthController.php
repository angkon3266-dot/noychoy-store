<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Production Mode: "Connect with Facebook" OAuth flow. The vendor's Meta App
 * credentials (config/meta.php → oauth) drive this; each client authenticates
 * with their own Facebook account, grants catalog permission, and picks their
 * Business + Commerce Catalog — no manual token copy/paste.
 *
 * The short-lived code is immediately exchanged for a long-lived token which is
 * stored encrypted. For a never-expiring server-to-server token, generate a
 * System User token in Business Settings and use Development Mode instead (see
 * docs/META_INTEGRATION.md) — this flow targets the WooCommerce-like UX.
 */
class MetaOAuthController extends Controller
{
    public function __construct(private readonly MetaSettings $settings) {}

    private function ensureConfigured(): void
    {
        abort_unless(
            filled(config('meta.oauth.app_id')) && filled(config('meta.oauth.app_secret')),
            422,
            'Production OAuth is not configured. Set META_APP_ID and META_APP_SECRET, or use Development Mode.',
        );
    }

    private function redirectUri(): string
    {
        return route('admin.meta.oauth.callback');
    }

    private function graph(string $path): string
    {
        return rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version').'/'.ltrim($path, '/');
    }

    /** Step 1 — send the admin to Facebook's OAuth dialog. */
    public function redirect(Request $request)
    {
        $this->ensureConfigured();

        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        $params = [
            'client_id' => config('meta.oauth.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
        ];

        if ($configId = config('meta.oauth.config_id')) {
            // ── Facebook Login for Business (recommended) ────────────────────
            // Asset permissions (business_management, catalog_management) are
            // defined in the login *configuration*, NOT passed as `scope`.
            // Passing `scope` here is what triggers Meta's "Invalid Scopes"
            // error, so we deliberately omit it.
            $params['config_id'] = $configId;
            $params['override_default_response_type'] = 'true';
        } else {
            // ── Standard Facebook Login (fallback) ───────────────────────────
            // Only request scopes the login dialog universally accepts. We never
            // request Commerce Manager / Marketing permissions here — those must
            // come through a Login-for-Business configuration (config_id above).
            $scopes = array_values(array_filter((array) config('meta.oauth.scopes', ['public_profile'])));
            if ($scopes) {
                $params['scope'] = implode(',', $scopes);
            }
        }

        $dialog = 'https://www.facebook.com/'.config('meta.graph_version').'/dialog/oauth?'.http_build_query($params);

        return redirect()->away($dialog);
    }

    /**
     * Step 2 — the single canonical callback for ALL Meta OAuth flows.
     *
     * The module that started a modular authorization is encoded in `state`;
     * when present, we hand off to the Connection hub. Otherwise this is the
     * legacy Commerce production flow (exchange code, then pick a catalog).
     */
    public function callback(Request $request, \App\Modules\Meta\Services\MetaOAuthService $modular)
    {
        if ($modular->isModularState($request->query('state'))) {
            return app(MetaConnectionController::class)->completeModularCallback($request);
        }

        $this->ensureConfigured();

        if ($request->filled('error')) {
            return redirect()->route('admin.meta.index')
                ->with('error', 'Facebook connection cancelled: '.$request->query('error_description', $request->query('error')));
        }

        // CSRF: state must match what we issued.
        if (! $request->filled('state') || $request->query('state') !== $request->session()->pull('meta_oauth_state')) {
            return redirect()->route('admin.meta.index')->with('error', 'Invalid OAuth state. Please try again.');
        }

        // Exchange code → short-lived token.
        $short = Http::acceptJson()->get($this->graph('oauth/access_token'), [
            'client_id' => config('meta.oauth.app_id'),
            'client_secret' => config('meta.oauth.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $request->query('code'),
        ]);

        if ($short->failed() || ! $short->json('access_token')) {
            return redirect()->route('admin.meta.index')
                ->with('error', 'Failed to obtain access token: '.$short->json('error.message', 'unknown error'));
        }

        // Exchange short-lived → long-lived token.
        $long = Http::acceptJson()->get($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('meta.oauth.app_id'),
            'client_secret' => config('meta.oauth.app_secret'),
            'fb_exchange_token' => $short->json('access_token'),
        ]);

        $token = $long->json('access_token', $short->json('access_token'));
        $expiresIn = (int) $long->json('expires_in', 0);

        $this->settings->setToken($token);
        $this->settings->update([
            'mode' => MetaSettings::MODE_PRODUCTION,
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->toIso8601String() : null,
        ]);

        // Fetch the businesses + catalogs this token can see, for selection.
        $catalogs = $this->discoverCatalogs($token);

        if (empty($catalogs)) {
            return redirect()->route('admin.meta.index')->with('error', config('meta.oauth.config_id')
                ? 'Connected, but no Commerce Catalogs were found. Make sure you granted access to a Business and its Catalog, and that a catalog exists in Commerce Manager, then reconnect.'
                : 'Signed in, but this app can only read your catalogs once Facebook Login for Business is configured. Set META_LOGIN_CONFIG_ID (a Login-for-Business configuration granting business_management + catalog_management), or use Development Mode with a System User token.');
        }

        // Auto-select when there is exactly one; otherwise show the picker.
        if (count($catalogs) === 1) {
            return $this->applyCatalog($catalogs[0]);
        }

        return view('admin.meta.select-catalog', ['catalogs' => $catalogs]);
    }

    /** Step 3 — the admin chose a business + catalog from the picker. */
    public function selectCatalog(Request $request)
    {
        $data = $request->validate([
            'business_id' => ['required', 'string'],
            'business_name' => ['nullable', 'string'],
            'catalog_id' => ['required', 'string'],
            'catalog_name' => ['nullable', 'string'],
        ]);

        return $this->applyCatalog($data);
    }

    /** Persist the chosen business/catalog and enable the integration. */
    private function applyCatalog(array $catalog)
    {
        $this->settings->update([
            'enabled' => true,
            'mode' => MetaSettings::MODE_PRODUCTION,
            'business_id' => $catalog['business_id'] ?? null,
            'catalog_id' => $catalog['catalog_id'] ?? null,
            'connected_business_name' => $catalog['business_name'] ?? null,
            'connected_catalog_name' => $catalog['catalog_name'] ?? null,
            'connected_since' => $this->settings->get('connected_since') ?? now()->toIso8601String(),
            'last_connection_ok' => true,
            'last_connection_at' => now()->toIso8601String(),
        ]);

        return redirect()->route('admin.meta.index')
            ->with('success', 'Connected to “'.($catalog['catalog_name'] ?? 'catalog').'”. Automatic sync is now enabled.');
    }

    /**
     * List every Commerce Catalog reachable by the token, grouped with its
     * owning business.
     *
     * @return array<int, array{business_id:string, business_name:?string, catalog_id:string, catalog_name:?string}>
     */
    private function discoverCatalogs(string $token): array
    {
        $out = [];

        $businesses = Http::acceptJson()->get($this->graph('me/businesses'), [
            'access_token' => $token,
            'fields' => 'id,name',
            'limit' => 50,
        ])->json('data', []);

        foreach ($businesses as $business) {
            $catalogs = Http::acceptJson()->get($this->graph($business['id'].'/owned_product_catalogs'), [
                'access_token' => $token,
                'fields' => 'id,name',
                'limit' => 100,
            ])->json('data', []);

            foreach ($catalogs as $catalog) {
                $out[] = [
                    'business_id' => (string) $business['id'],
                    'business_name' => $business['name'] ?? null,
                    'catalog_id' => (string) $catalog['id'],
                    'catalog_name' => $catalog['name'] ?? null,
                ];
            }
        }

        return $out;
    }
}
