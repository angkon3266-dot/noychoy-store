<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Base service provider for a module. Handles the boilerplate every module
 * shares — registering its manifest with the ModuleRegistry, its permission
 * gates, routes and views — but only wires routes/views when the module is
 * enabled. Concrete modules extend this and implement manifest()/bindings().
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function manifest(): ModuleManifest;

    /** Path to the module's routes file (loaded inside the admin group), or null. */
    protected function routesPath(): ?string
    {
        return null;
    }

    /** Directory of the module's views, or null. */
    protected function viewsPath(): ?string
    {
        return null;
    }

    /** Container bindings (e.g. bind a contract to this module's implementation). */
    protected function bindings(): void {}

    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register($this->manifest());
        $this->bindings();
    }

    public function boot(): void
    {
        $manifest = $this->manifest();

        // App-side abilities default to "admin only" unless the module overrides.
        foreach ($manifest->permissions() as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user) => $user->role === 'admin');
            }
        }

        if ($this->viewsPath() && is_dir($this->viewsPath())) {
            $this->loadViewsFrom($this->viewsPath(), $manifest->key());
        }
    }
}
