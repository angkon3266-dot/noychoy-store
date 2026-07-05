<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Meta\Services\MetaTokenManager;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;

/**
 * Marketing Center — the hub, now driven entirely by the module registry. Adding
 * a module makes it appear here automatically. The Meta Connection card manages
 * the shared connection + modular authorization.
 */
class MarketingController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly MetaTokenManager $tokens,
    ) {}

    public function index()
    {
        $modules = $this->registry->all()->map(fn (ModuleManifest $m) => [
            'key' => $m->key(),
            'name' => $m->name(),
            'description' => $m->description(),
            'icon' => $m->icon(),
            'available' => $m->isAvailable(),
            'url' => $m->route() ? route($m->route()) : null,
        ])->values();

        return view('admin.marketing.index', [
            'modules' => $modules,
            'connectionHealth' => $this->tokens->health(),
        ]);
    }
}
