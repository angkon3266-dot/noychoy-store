<?php

namespace App\Services\SystemConfig;

use Illuminate\Support\Facades\Log;

/**
 * Applies DB-stored configuration as runtime overrides on top of the
 * .env-loaded config. Called once during application boot (after config is
 * loaded, even when config is cached). Fails safe: any error leaves the app
 * running on its .env/config defaults.
 *
 * The database section is intentionally NOT applied here — a DB connection
 * cannot be bootstrapped from the DB, so it is persisted to .env via the
 * DatabaseConfigManager wizard instead.
 */
class ConfigApplier
{
    public function __construct(
        private readonly SystemConfigRepository $repository,
        private readonly ConfigSchema $schema,
    ) {}

    public function apply(): void
    {
        // Never bake DB-stored (decrypted) values into the config cache file.
        // config:cache / optimize boot providers, then serialise config()->all()
        // to plaintext — applying here would leak secrets to disk and freeze
        // them. HTTP requests and queue workers still apply normally.
        if ($this->isCachingConfig()) {
            return;
        }

        try {
            $values = $this->repository->all();
        } catch (\Throwable $e) {
            // DB unavailable / not migrated yet — run on .env defaults.
            return;
        }

        if (empty($values)) {
            return;
        }

        $overrides = [];
        foreach ($values as $key => $value) {
            $field = $this->schema->field($key);
            if (! $field || empty($field['config'])) {
                continue; // no runtime target (e.g. db.* lives in .env)
            }
            $overrides[$field['config']] = $this->coerce($field, $key, $value);
        }

        if ($overrides) {
            try {
                config($overrides);
            } catch (\Throwable $e) {
                Log::warning('ConfigApplier failed to apply overrides', ['error' => $e->getMessage()]);
            }
        }
    }

    /** True while `config:cache` / `optimize` is (re)building the config cache. */
    private function isCachingConfig(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? '';

        return in_array($command, ['config:cache', 'optimize', 'config:clear'], true);
    }

    private function coerce(array $field, string $key, $value)
    {
        return match (true) {
            $field['type'] === 'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            $field['type'] === 'number' => is_numeric($value) ? $value + 0 : $value,
            $key === 'mail.encryption' && $value === 'none' => null,
            default => $value,
        };
    }
}
