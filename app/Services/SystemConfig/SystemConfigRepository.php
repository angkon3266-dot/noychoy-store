<?php

namespace App\Services\SystemConfig;

use App\Models\SystemConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Data-access layer for the system_configs table. Owns encryption: sensitive
 * fields (per {@see ConfigSchema}) are transparently Crypt-encrypted on write
 * and decrypted on read. Reads are memoised for the request so the applier and
 * UI don't re-query.
 */
class SystemConfigRepository
{
    private ?array $cache = null;

    public function __construct(private readonly ConfigSchema $schema) {}

    /** All stored values keyed by field key, decrypted. */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach (SystemConfig::all() as $row) {
            $out[$row->key] = $this->decode($row->value, $row->is_encrypted);
        }

        return $this->cache = $out;
    }

    public function get(string $key, $default = null)
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** Values for one section only, keyed by field key. */
    public function section(string $section): array
    {
        $keys = collect($this->schema->section($section)['fields'] ?? [])->pluck('key');

        return collect($this->all())->only($keys)->all();
    }

    /** Upsert a single field. Null/'' removes it (falls back to .env). */
    public function put(string $section, string $key, $value): void
    {
        $sensitive = ! empty($this->schema->field($key)['sensitive']);

        if ($value === null || $value === '') {
            SystemConfig::where('key', $key)->delete();
            $this->cache = null;

            return;
        }

        SystemConfig::updateOrCreate(
            ['section' => $section, 'key' => $key],
            [
                'value' => $sensitive ? Crypt::encryptString((string) $value) : (string) $value,
                'is_encrypted' => $sensitive,
            ],
        );

        $this->cache = null;
    }

    /** @param array<string,mixed> $values keyed by field key */
    public function putMany(string $section, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->put($section, $key, $value);
        }
    }

    /** Replace the entire store (used by restore/import). */
    public function replaceAll(array $valuesByKey): void
    {
        foreach ($this->schema->fields() as $key => $field) {
            $section = $field['section'];
            if (array_key_exists($key, $valuesByKey)) {
                $this->put($section, $key, $valuesByKey[$key]);
            }
        }

        $this->cache = null;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    private function decode(?string $value, bool $encrypted): ?string
    {
        if ($value === null || ! $encrypted) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
