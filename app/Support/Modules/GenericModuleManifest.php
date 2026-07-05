<?php

namespace App\Support\Modules;

/**
 * Array-driven {@see ModuleManifest} so a module's service provider can declare
 * itself in one place without a bespoke class per module.
 */
class GenericModuleManifest implements ModuleManifest
{
    public function __construct(private readonly array $config) {}

    public function key(): string
    {
        return $this->config['key'];
    }

    public function name(): string
    {
        return $this->config['name'];
    }

    public function description(): string
    {
        return $this->config['description'] ?? '';
    }

    public function icon(): string
    {
        return $this->config['icon'] ?? 'M12 6v6h4.5';
    }

    public function provider(): string
    {
        return $this->config['provider'] ?? 'meta';
    }

    public function requiredScopes(): array
    {
        return $this->config['scopes'] ?? [];
    }

    public function configId(): ?string
    {
        return $this->config['config_id'] ?? null;
    }

    public function permissions(): array
    {
        return $this->config['permissions'] ?? [];
    }

    public function route(): ?string
    {
        return $this->config['route'] ?? null;
    }

    public function isAvailable(): bool
    {
        return (bool) ($this->config['available'] ?? false);
    }
}
