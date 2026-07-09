<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Meta\Services\MetaOAuthService;
use App\Modules\Meta\Services\MetaTokenManager;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * The Meta Connection hub — the central Token Manager UI. Shows connection
 * health, discovered assets and, per module, whether its Meta permissions are
 * granted, with a button to authorize only that module (modular OAuth).
 */
class MetaConnectionController extends Controller
{
    public function __construct(
        private readonly MetaTokenManager $tokens,
        private readonly MetaOAuthService $oauth,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * The single canonical Meta OAuth callback — the same URI for every module
     * and every flow, so the Meta App only ever needs one whitelisted redirect.
     * The module is carried in the OAuth `state`, not the URL.
     */
    private function redirectUri(): string
    {
        return route('admin.meta.oauth.callback');
    }

    public function index()
    {
        // Per-module authorization status (only modules that need Meta scopes).
        $modules = $this->registry->all()
            ->filter(fn ($m) => $m->provider() === 'meta' && ! empty($m->requiredScopes()))
            ->map(fn ($m) => [
                'key' => $m->key(),
                'name' => $m->name(),
                'available' => $m->isAvailable(),
                'scopes' => $m->requiredScopes(),
                'missing' => $this->oauth->missingScopesFor($m->key()),
                'authorized' => empty($this->oauth->missingScopesFor($m->key())),
            ])
            ->values();

        return view('admin.meta.connection', [
            'connected' => $this->tokens->isConnected(),
            'health' => $this->tokens->health(),
            'scopes' => $this->tokens->scopes(),
            'businessId' => $this->tokens->businessId(),
            'assets' => [
                'catalog' => $this->tokens->assets('catalog'),
                'page' => $this->tokens->assets('page'),
                'instagram' => $this->tokens->assets('instagram'),
                'ad_account' => $this->tokens->assets('ad_account'),
            ],
            'modules' => $modules,
            'oauthConfigured' => $this->oauth->isConfigured(),
        ]);
    }

    /** Start modular OAuth for a single module. */
    public function authorize(Request $request, string $module)
    {
        abort_unless($this->registry->has($module), 404);

        if (! $this->oauth->isConfigured()) {
            return back()->with('error', 'Set your Meta App ID & Secret first (System Config → Meta).');
        }

        if (empty($this->registry->scopesFor($module))) {
            return back()->with('error', 'This module needs no Meta authorization.');
        }

        // Login for Business is mandatory — asset permissions come from the login
        // config, not raw scopes. Without it we would generate a broken URL.
        if (! $this->oauth->hasLoginConfig()) {
            return back()->with('error', 'Set your Meta Login for Business Config ID first (System Config → Meta).');
        }

        return redirect()->away($this->oauth->authorizeUrl($module, $this->redirectUri(), $request));
    }

    /**
     * Complete a modular authorization. Called by the canonical OAuth callback
     * (MetaOAuthController@callback) when the `state` belongs to a module.
     */
    public function completeModularCallback(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('[meta-oauth] MetaConnectionController::completeModularCallback ENTER', [
            'path' => $request->path(),
            'has_state' => $request->filled('state'),
        ]);

        $result = $this->oauth->handleCallback($request, $this->redirectUri());

        return redirect()->route('admin.meta.connection')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function disconnect()
    {
        $this->tokens->disconnect();

        return back()->with('success', 'Meta connection cleared.');
    }
}
