<?php

namespace App\Services\SystemConfig;

use App\Models\ConfigBackup;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Named full-configuration backups + encrypted import/export. Nothing is ever
 * lost: a backup is auto-created before every restore or import.
 */
class ConfigBackupService
{
    /** Bump when the schema shape changes incompatibly. */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly SystemConfigRepository $repository,
        private readonly ConfigSchema $schema,
        private readonly ConfigApplier $applier,
    ) {}

    /** Snapshot the whole config store into a named backup. */
    public function create(string $name, bool $auto = false): ConfigBackup
    {
        $values = $this->repository->all();
        $payload = $this->envelope($values);
        $json = json_encode($payload);
        $user = auth()->user();

        return ConfigBackup::create([
            'name' => $name,
            'user_id' => $user?->id,
            'creator_name' => $user?->name ?? ($auto ? 'System' : null),
            'payload' => Crypt::encryptString($json),
            'size_bytes' => strlen($json),
            'modules' => $this->modulesOf($values),
            'is_auto' => $auto,
        ]);
    }

    /**
     * Restore a backup. Auto-backs-up the current config first, then replaces
     * values and re-applies. Returns the pre-restore backup for reference.
     */
    public function restore(ConfigBackup $backup): array
    {
        $before = $this->create('Auto-backup before restore #'.$backup->id, true);

        $data = $backup->decodedPayload();
        $values = $data['values'] ?? [];

        $this->repository->replaceAll($values);
        $this->applier->apply();

        return ['before_backup_id' => $before->id, 'restored' => count($values)];
    }

    /** Encrypted export string for download (all or selected sections). */
    public function export(?array $sections = null): string
    {
        $values = $this->repository->all();

        if ($sections) {
            $allowedKeys = collect($this->schema->fields())
                ->filter(fn ($f) => in_array($f['section'], $sections, true))
                ->keys();
            $values = collect($values)->only($allowedKeys)->all();
        }

        return Crypt::encryptString(json_encode($this->envelope($values)));
    }

    /**
     * Decode + validate an uploaded export. Throws on incompatibility.
     *
     * @return array{schema_version:int, app:string, created_at:string, values:array}
     */
    public function decodeImport(string $contents): array
    {
        try {
            $data = json_decode(Crypt::decryptString(trim($contents)), true);
        } catch (DecryptException) {
            throw new \RuntimeException('This backup file could not be decrypted. It must be an export from this installation.');
        }

        if (! is_array($data) || ! isset($data['schema_version'])) {
            throw new \RuntimeException('Unrecognised backup file format.');
        }

        if ((int) $data['schema_version'] !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Incompatible backup version ('.$data['schema_version'].'). This platform expects version '.self::SCHEMA_VERSION.'.');
        }

        return $data;
    }

    /** Preview: which keys an import would change (sensitive masked). */
    public function previewImport(array $data): array
    {
        $incoming = $data['values'] ?? [];
        $current = $this->repository->all();
        $out = [];

        foreach ($incoming as $key => $value) {
            $field = $this->schema->field($key);
            if (! $field) {
                continue; // unknown key — ignored on apply
            }
            $sensitive = ! empty($field['sensitive']);
            $old = $current[$key] ?? null;
            if ((string) $old === (string) $value) {
                continue;
            }
            $out[] = [
                'section' => $field['section'],
                'label' => $field['label'],
                'old' => $old === null || $old === '' ? '—' : ($sensitive ? '••••••••' : $old),
                'new' => $sensitive ? '••••••••' : $value,
            ];
        }

        return $out;
    }

    /** Apply an already-decoded, validated import (auto-backup first). */
    public function applyImport(array $data): int
    {
        $this->create('Auto-backup before import', true);

        $values = collect($data['values'] ?? [])
            ->filter(fn ($v, $k) => $this->schema->field($k) !== null)
            ->all();

        $this->repository->replaceAll($values);
        $this->applier->apply();

        return count($values);
    }

    private function envelope(array $values): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => config('app.name'),
            'created_at' => now()->toIso8601String(),
            'values' => $values,
        ];
    }

    private function modulesOf(array $values): array
    {
        return collect($values)
            ->keys()
            ->map(fn ($k) => $this->schema->field($k)['section'] ?? null)
            ->filter()->unique()->values()->all();
    }
}
