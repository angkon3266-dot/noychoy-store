<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Apply reviewed product copy from a JSON file — the write half of
 * catalog:export-copy. Each entry may carry a new description,
 * short_description, and/or a `specs` map (e.g. {"Metal":"Brass",
 * "Stone":"Cubic Zirconia"}) that is merged into custom_fields: spec labels
 * replace same-named rows (case-insensitively), everything else the product
 * already had is kept. A legacy custom_label matching a spec label is cleared
 * so customFieldList() cannot show the stale value twice.
 *
 * Products are matched by id AND slug together — a payload built against a
 * different database cannot silently rewrite the wrong product. A row without
 * a slug is skipped for the same reason, not trusted on its bare id.
 */
class CatalogApplyCopy extends Command
{
    protected $signature = 'catalog:apply-copy {file : JSON payload path}
        {--dry : report what would change without writing}';

    protected $description = 'Apply product descriptions / spec tables from a JSON payload';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (! is_array($payload)) {
            $this->error('Invalid JSON payload.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $applied = 0;
        $skipped = [];

        foreach ($payload as $row) {
            $product = Product::find($row['id'] ?? 0);

            if (! $product || ($row['slug'] ?? null) !== $product->slug) {
                $skipped[] = ($row['id'] ?? '?').' ('.($row['slug'] ?? 'no slug').')';

                continue;
            }

            $changes = [];

            if (filled($row['description'] ?? null)) {
                $changes['description'] = (string) $row['description'];
            }
            if (filled($row['short_description'] ?? null)) {
                $changes['short_description'] = mb_substr((string) $row['short_description'], 0, 500);
            }

            if (! empty($row['specs']) && is_array($row['specs'])) {
                $specLabels = array_map(fn ($l) => mb_strtolower(trim((string) $l)), array_keys($row['specs']));

                $kept = collect($product->custom_fields ?? [])
                    ->filter(fn ($f) => ! in_array(mb_strtolower(trim((string) ($f['label'] ?? ''))), $specLabels, true))
                    ->values();

                $specRows = collect($row['specs'])
                    ->map(fn ($value, $label) => ['label' => trim((string) $label), 'value' => trim((string) $value), 'show' => true])
                    ->filter(fn ($f) => $f['label'] !== '' && $f['value'] !== '')
                    ->values();

                $changes['custom_fields'] = $specRows->concat($kept)->values()->all();

                // Legacy single field with the same label would render on top
                // of the new row.
                if (in_array(mb_strtolower(trim((string) $product->custom_label)), $specLabels, true)) {
                    $changes['custom_label'] = null;
                    $changes['custom_value'] = null;
                    $changes['custom_show'] = false;
                }
            }

            if ($changes === []) {
                continue;
            }

            $this->line(($dry ? '[dry] ' : '').$product->id.' '.$product->name.' — '.implode(', ', array_keys($changes)));

            if (! $dry) {
                $product->update($changes);
            }
            $applied++;
        }

        $this->info(($dry ? 'Would update ' : 'Updated ').$applied.' product(s).');
        if ($skipped !== []) {
            $this->warn('Skipped (no id+slug match): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }
}
