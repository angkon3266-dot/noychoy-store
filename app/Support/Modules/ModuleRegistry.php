<?php

namespace App\Support\Modules;

use App\Models\MetaModuleState;
use Illuminate\Support\Collection;

/**
 * Central registry of all installed modules and the Permission Registry.
 *
 * Modules register their manifest from their own service provider; nothing else
 * in the platform hard-codes the module list. This is the single source of truth
 * consumed by the modular OAuth flow, the hub UI and authorization.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    public function register(ModuleManifest $manifest): void
    {
        $this->modules[$manifest->key()] = $manifest;
    }

    /** @return Collection<string, ModuleManifest> */
    public function all(): Collection
    {
        return collect($this->modules);
    }

    public function get(string $key): ?ModuleManifest
    {
        return $this->modules[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    /** Modules the admin has enabled (default: available modules are enabled). */
    public function enabled(): Collection
    {
        $states = MetaModuleState::pluck('enabled', 'module');

        return $this->all()->filter(fn (ModuleManifest $m) => (bool) ($states[$m->key()] ?? $m->isAvailable()));
    }

    // ── Permission Registry ────────────────────────────────────────────────

    /** Scopes required by a module. */
    public function scopesFor(string $key): array
    {
        return $this->get($key)?->requiredScopes() ?? [];
    }

    /** Union of every module's required scopes. */
    public function allRequiredScopes(): array
    {
        return $this->all()
            ->flatMap(fn (ModuleManifest $m) => $m->requiredScopes())
            ->unique()->values()->all();
    }

    /** Which modules require a given scope. */
    public function modulesRequiringScope(string $scope): array
    {
        return $this->all()
            ->filter(fn (ModuleManifest $m) => in_array($scope, $m->requiredScopes(), true))
            ->keys()->all();
    }
}
