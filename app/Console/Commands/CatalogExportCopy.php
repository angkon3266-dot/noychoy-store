<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Dump every published product's copy-relevant fields as JSON, so new
 * descriptions and spec tables can be written offline and applied back with
 * catalog:apply-copy. Read-only.
 */
class CatalogExportCopy extends Command
{
    protected $signature = 'catalog:export-copy {--all : include drafts and archived products}';

    protected $description = 'Export product copy (name, description, tags, specs) as JSON to stdout';

    public function handle(): int
    {
        $query = $this->option('all') ? Product::query() : Product::published();

        $rows = $query->with('category:id,name')->orderBy('id')->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'serial' => $p->serial,
            'name' => $p->name,
            'slug' => $p->slug,
            'price' => (float) $p->price,
            'category' => $p->category?->name,
            'tags' => $p->tags,
            'colors' => $p->colors,
            'short_description' => $p->short_description,
            'description' => $p->description,
            'custom_fields' => $p->customFieldList(),
        ]);

        $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
