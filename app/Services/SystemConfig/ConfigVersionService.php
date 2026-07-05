<?php

namespace App\Services\SystemConfig;

use App\Models\ConfigVersion;
use Illuminate\Support\Facades\Crypt;

/**
 * Creates and compares configuration version snapshots. Previous/new value maps
 * are stored as encrypted JSON so historical secrets are never exposed at rest.
 */
class ConfigVersionService
{
    public function __construct(private readonly ConfigSchema $schema) {}

    /**
     * Record a snapshot taken immediately before a change is applied.
     *
     * @param  array<string,mixed>  $previous  key => value (pre-change)
     * @param  array<string,mixed>  $new       key => value (post-change)
     */
    public function snapshot(string $section, array $previous, array $new, ?string $notes = null): ConfigVersion
    {
        $user = auth()->user();

        return ConfigVersion::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'ip' => request()?->ip(),
            'section' => $section,
            'previous_values' => Crypt::encryptString(json_encode($previous)),
            'new_values' => Crypt::encryptString(json_encode($new)),
            'notes' => $notes,
        ]);
    }

    /**
     * Diff two versions (or a version against another). Returns a per-key list of
     * old vs new with sensitive values masked.
     *
     * @return array<int, array{key:string, label:string, old:string, new:string, changed:bool}>
     */
    public function compare(ConfigVersion $a, ConfigVersion $b): array
    {
        $left = $a->newValues();
        $right = $b->newValues();
        $keys = collect(array_keys($left + $right))->unique();

        return $keys->map(function ($key) use ($left, $right) {
            $field = $this->schema->field($key);
            $sensitive = ! empty($field['sensitive']);
            $old = $left[$key] ?? null;
            $new = $right[$key] ?? null;

            return [
                'key' => $key,
                'label' => $field['label'] ?? $key,
                'old' => $this->display($old, $sensitive),
                'new' => $this->display($new, $sensitive),
                'changed' => (string) $old !== (string) $new,
            ];
        })->values()->all();
    }

    /** Diff a single version's previous vs new (what that change did). */
    public function selfDiff(ConfigVersion $version): array
    {
        $prev = $version->previousValues();
        $new = $version->newValues();
        $keys = collect(array_keys($prev + $new))->unique();

        return $keys->map(function ($key) use ($prev, $new) {
            $field = $this->schema->field($key);
            $sensitive = ! empty($field['sensitive']);

            return [
                'key' => $key,
                'label' => $field['label'] ?? $key,
                'old' => $this->display($prev[$key] ?? null, $sensitive),
                'new' => $this->display($new[$key] ?? null, $sensitive),
                'changed' => (string) ($prev[$key] ?? '') !== (string) ($new[$key] ?? ''),
            ];
        })->filter(fn ($r) => $r['changed'])->values()->all();
    }

    private function display($value, bool $sensitive): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return $sensitive ? '••••••••' : (string) $value;
    }
}
