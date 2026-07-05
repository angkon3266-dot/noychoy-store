<?php

namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the plugin architecture: binds the shared ModuleRegistry, then registers
 * every module service provider listed in config/modules.php. Each module
 * registers its own manifest, gates, routes and bindings — this provider owns no
 * module-specific knowledge.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared registry — must exist before module providers register.
        $this->app->singleton(ModuleRegistry::class);

        foreach ((array) config('modules.providers', []) as $provider) {
            $this->app->register($provider);
        }
    }
}
