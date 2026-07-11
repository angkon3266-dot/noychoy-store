<?php

namespace App\Services\FraudChecker;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin-managed courier portal logins for the fraud-checker package, stored in
 * the existing settings table (key "fraud_checker") — never in .env. Passwords
 * are encrypted at rest. A ServiceProvider maps credentials() onto the package's
 * config('fraud-checker-bd-courier.*') keys at runtime.
 *
 * The package logs into each courier's merchant portal, so these are login
 * credentials (not API keys): Steadfast user+password, Pathao user+password,
 * RedX phone+password.
 */
class FraudCheckerSettings
{
    private const KEY = 'fraud_checker';

    /** Field layout per courier, matching the package config keys. */
    private const FIELDS = [
        'steadfast' => ['user', 'password'],
        'pathao' => ['user', 'password'],
        'redx' => ['phone', 'password'],
    ];

    private function all(): array
    {
        $stored = Setting::get(self::KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /** At least one courier has both fields set. */
    public function isConfigured(): bool
    {
        $creds = $this->credentials();
        foreach (self::FIELDS as $courier => $fields) {
            $present = array_filter(array_map(fn ($f) => $creds[$courier][$f] ?? null, $fields), 'filled');
            if (count($present) === count($fields)) {
                return true;
            }
        }

        return false;
    }

    /** Decrypted credentials keyed for config injection. */
    public function credentials(): array
    {
        $raw = $this->all();
        $out = [];

        foreach (self::FIELDS as $courier => $fields) {
            foreach ($fields as $field) {
                $value = $raw[$courier][$field] ?? null;
                $out[$courier][$field] = $field === 'password' ? $this->decrypt($value) : $value;
            }
        }

        return $out;
    }

    /**
     * Persist the settings form. Passwords are only overwritten when a new value
     * is typed (blank leaves the stored one intact). Input keys are like
     * "steadfast_user", "steadfast_password", "redx_phone", …
     */
    public function save(array $input): void
    {
        $current = $this->all();

        foreach (self::FIELDS as $courier => $fields) {
            foreach ($fields as $field) {
                $value = $input["{$courier}_{$field}"] ?? null;

                if ($field === 'password') {
                    if (filled($value)) {
                        $current[$courier][$field] = Crypt::encryptString($value);
                    }
                    continue; // blank → keep existing
                }

                $current[$courier][$field] = $value ?: null;
            }
        }

        Setting::put(self::KEY, $current);
    }

    /** Non-secret values + has-password flags for rendering the settings form. */
    public function formData(): array
    {
        $raw = $this->all();

        return [
            'steadfast_user' => $raw['steadfast']['user'] ?? '',
            'steadfast_has_pw' => filled($raw['steadfast']['password'] ?? null),
            'pathao_user' => $raw['pathao']['user'] ?? '',
            'pathao_has_pw' => filled($raw['pathao']['password'] ?? null),
            'redx_phone' => $raw['redx']['phone'] ?? '',
            'redx_has_pw' => filled($raw['redx']['password'] ?? null),
        ];
    }

    private function decrypt(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null; // legacy/plain value or key rotation — treat as unset
        }
    }
}
