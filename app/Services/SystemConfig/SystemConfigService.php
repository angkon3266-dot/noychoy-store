<?php

namespace App\Services\SystemConfig;

use App\Events\ConfigurationChanged;

/**
 * Facade for the System Configuration module. Coordinates the schema,
 * repository, runtime applier, versioning and the database wizard, exposing a
 * small surface to controllers. Reading never returns secrets in the clear to
 * the UI — use {@see uiValues()}.
 */
class SystemConfigService
{
    public function __construct(
        private readonly ConfigSchema $schema,
        private readonly SystemConfigRepository $repository,
        private readonly ConfigApplier $applier,
        private readonly ConfigVersionService $versions,
        private readonly ConfigBackupService $backups,
        private readonly DatabaseConfigManager $database,
    ) {}

    public function schema(): ConfigSchema
    {
        return $this->schema;
    }

    /** Effective (real) value for a field: DB override → config() → env fallback. */
    public function effectiveValue(array $field)
    {
        $key = $field['key'];

        if ($this->repository->has($key)) {
            return $this->repository->get($key);
        }

        // Database section is read from the live connection config.
        if ($field['section'] === 'database') {
            $conn = config('database.default');
            $suffix = str_replace('db.', '', $key);

            return config("database.connections.{$conn}.{$suffix}");
        }

        if (! empty($field['config'])) {
            return config($field['config']);
        }

        return $field['env'] ? env($field['env']) : null;
    }

    /** Real effective values for a section, keyed by field key. */
    public function sectionCurrent(string $section): array
    {
        $out = [];
        foreach ($this->schema->section($section)['fields'] ?? [] as $field) {
            $field['section'] = $section;
            $out[$field['key']] = $this->effectiveValue($field);
        }

        return $out;
    }

    /**
     * UI-safe values for a section: sensitive fields never leak — they expose
     * only whether a value is saved.
     *
     * @return array<int, array{field:array, value:mixed, has_saved:bool}>
     */
    public function uiValues(string $section): array
    {
        $out = [];
        foreach ($this->schema->section($section)['fields'] ?? [] as $field) {
            $field['section'] = $section;
            $value = $this->effectiveValue($field);
            $sensitive = ! empty($field['sensitive']);

            $out[] = [
                'field' => $field,
                'value' => $sensitive ? '' : $value,
                'has_saved' => $sensitive ? filled($value) : false,
            ];
        }

        return $out;
    }

    /**
     * Save a section. Sensitive fields left blank keep their stored value.
     * The database section goes through the Test→Apply→Rollback wizard.
     *
     * @return array{ok:bool, message:string, changed:array}
     */
    public function save(string $section, array $input, ?string $notes = null): array
    {
        $sectionDef = $this->schema->section($section);
        if (! $sectionDef) {
            return ['ok' => false, 'message' => 'Unknown section.', 'changed' => []];
        }

        $previous = $this->sectionCurrent($section);
        $final = $this->resolveInput($section, $input, $previous);

        if ($errors = $this->validate($section, $final)) {
            return ['ok' => false, 'message' => $errors[0], 'changed' => []];
        }

        // ── Database: bootstrap-level, handled by the safe wizard (.env) ──────
        if (! empty($sectionDef['env_managed'])) {
            $this->backups->create('Auto-backup before database change', true);
            $result = $this->database->testAndApply($final);

            if ($result['ok']) {
                $this->versions->snapshot($section, $previous, $final, $notes);
                event(new ConfigurationChanged($section, array_keys($final)));
            }

            return ['ok' => $result['ok'], 'message' => $result['message'], 'changed' => array_keys($final)];
        }

        // ── Everything else: DB-stored runtime overrides ─────────────────────
        $this->backups->create('Auto-backup before saving '.$sectionDef['label'], true);
        $this->versions->snapshot($section, $previous, $final, $notes);
        $this->repository->putMany($section, $final);
        $this->applier->apply();
        event(new ConfigurationChanged($section, array_keys($final)));

        return ['ok' => true, 'message' => $sectionDef['label'].' settings saved.', 'changed' => array_keys($final)];
    }

    /**
     * Schema-driven field validation. Returns a list of human error messages
     * (empty = valid).
     *
     * @return array<int,string>
     */
    public function validate(string $section, array $values): array
    {
        $errors = [];
        foreach ($this->schema->section($section)['fields'] ?? [] as $field) {
            $value = $values[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue; // blank clears / keeps — allowed
            }

            $ok = match ($field['type']) {
                'email' => (bool) filter_var($value, FILTER_VALIDATE_EMAIL),
                'number' => is_numeric($value),
                'select' => in_array($value, $field['options'] ?? [], true),
                default => true,
            };

            if (! $ok) {
                $errors[] = "“{$field['label']}” has an invalid value.";
            }
        }

        return $errors;
    }

    /** Merge submitted input with existing values, honouring blank-secret rule. */
    private function resolveInput(string $section, array $input, array $previous): array
    {
        $final = [];
        foreach ($this->schema->section($section)['fields'] ?? [] as $field) {
            $key = $field['key'];
            $submitted = $input[$key] ?? null;

            if (($field['type'] === 'bool')) {
                $final[$key] = ! empty($submitted) ? '1' : '0';

                continue;
            }

            // Blank sensitive field → keep whatever is already stored.
            if (! empty($field['sensitive']) && ($submitted === null || $submitted === '')) {
                $final[$key] = $previous[$key] ?? null;

                continue;
            }

            $final[$key] = $submitted;
        }

        return $final;
    }
}
