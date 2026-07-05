<?php

namespace App\Support\Modules;

/**
 * Every module ships one manifest describing itself to the platform: its key,
 * the Meta (or other provider) permission scopes it requires, its app-side RBAC
 * abilities, and metadata for the UI. The ModuleRegistry aggregates these so the
 * OAuth flow, permission checks and hub navigation are fully data-driven —
 * adding a module never requires editing OAuth or other modules.
 */
interface ModuleManifest
{
    /** Stable machine key, e.g. "commerce". */
    public function key(): string;

    /** Human name for the UI, e.g. "Commerce". */
    public function name(): string;

    public function description(): string;

    /** Heroicon path data for the nav icon. */
    public function icon(): string;

    /** Provider this module integrates with (meta|google|tiktok|…). */
    public function provider(): string;

    /**
     * Provider permission scopes this module needs (e.g. Meta
     * ['catalog_management','business_management']). Empty = no provider auth.
     *
     * @return array<int,string>
     */
    public function requiredScopes(): array;

    /**
     * Optional per-module Facebook Login-for-Business config id (asset
     * permissions are granted through the configuration, not the scope param).
     */
    public function configId(): ?string;

    /** App-side RBAC ability names this module defines, e.g. ['commerce.access']. */
    public function permissions(): array;

    /** Route to the module's landing page (named route), or null if placeholder. */
    public function route(): ?string;

    /** Whether the module is built & available (false = "coming soon"). */
    public function isAvailable(): bool;
}
