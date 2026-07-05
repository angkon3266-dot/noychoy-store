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

    private function redirectUri(): string
    {
        return route('admin.meta.connection.callback');
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

        return redirect()->away($this->oauth->authorizeUrl($module, $this->redirectUri(), $request));
    }

    public function callback(Request $request)
    {
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
