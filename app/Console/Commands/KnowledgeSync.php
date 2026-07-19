<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Sync knowledge/products/*.md from the database.
 *
 * Each product gets one markdown file named {slug}.md. The YAML front matter
 * is machine-owned and rewritten on every run; the body below it is
 * human/AI-owned and preserved verbatim. New files seed their body from
 * knowledge/_templates/product.md with the name/description filled in.
 */
class KnowledgeSync extends Command
{
    protected $signature = 'knowledge:sync
        {--product= : sync only this product (id or slug)}
        {--prune : list orphan files whose product no longer exists (never deletes)}';

    protected $description = 'Generate/refresh knowledge/products markdown from the product database';

    public function handle(): int
    {
        $dir = base_path('knowledge/products');
        File::ensureDirectoryExists($dir);

        $query = Product::with(['category', 'categories', 'images', 'variants'])->orderBy('id');
        if ($only = $this->option('product')) {
            $query->where(fn ($q) => $q->where('id', $only)->orWhere('slug', $only));
        }
        $products = $query->get();

        $created = 0;
        $updated = 0;
        foreach ($products as $product) {
            $path = $dir.'/'.$product->slug.'.md';
            $front = $this->frontMatter($product);

            if (File::exists($path)) {
                $body = $this->existingBody(File::get($path)) ?? $this->seedBody($product);
                $updated++;
            } else {
                $body = $this->seedBody($product);
                $created++;
            }

            File::put($path, "---\n".$front."---\n\n".ltrim($body));
        }

        $this->info("Synced: {$created} created, {$updated} refreshed (bodies preserved).");

        if ($this->option('prune')) {
            $slugs = Product::pluck('slug')->flip();
            foreach (File::files($dir) as $file) {
                $slug = $file->getFilenameWithoutExtension();
                if (! isset($slugs[$slug])) {
                    $this->warn("Orphan (product gone/renamed, review manually): products/{$slug}.md");
                }
            }
        }

        return self::SUCCESS;
    }

    /** Machine-owned YAML block. json_encode output is valid YAML flow style. */
    protected function frontMatter(Product $p): string
    {
        $y = fn ($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $availability = $p->isPreorder() ? 'preorder' : ($p->isAvailable() ? 'in_stock' : 'out_of_stock');

        $pairs = [
            'type' => '"product"',
            'id' => $p->id,
            'product_id' => $p->serial ?? 'null',
            'sku' => $y((string) $p->sku),
            'name' => $y($p->name),
            'slug' => $y($p->slug),
            'url' => $y(route('product.show', $p)),
            'status' => $y($p->status),
            'category' => $y($p->category?->name ?? ''),
            'categories' => $y($p->categories->pluck('name')->values()->all() ?: array_filter([$p->category?->name])),
            'tags' => $y(array_values($p->tag_list ?? [])),
            'price' => $y((float) $p->price),
            'compare_at_price' => $p->compare_at_price !== null ? $y((float) $p->compare_at_price) : 'null',
            'currency' => $y(config('store.currency', 'BDT')),
            'availability' => $y($availability),
            'stock_quantity' => $p->manage_stock ? (int) $p->stock_quantity : 'null',
            'is_featured' => $p->is_featured ? 'true' : 'false',
            'is_bestseller' => $p->is_bestseller ? 'true' : 'false',
            'weight' => $p->weight !== null ? $y((float) $p->weight) : 'null',
            'variants' => $y($p->variants->map(fn ($v) => [
                'sku' => $v->sku,
                'attributes' => $v->attributes,
                'price' => (float) ($v->effective_price ?? $v->price ?? $p->price),
                'stock' => (int) $v->stock_quantity,
            ])->values()->all()),
            'images' => $y($p->images->map(fn ($i) => $i->url ?? $i->path)->filter()->values()->all()),
            'meta_title' => $y((string) $p->meta_title),
            'meta_description' => $y((string) $p->meta_description),
            'views' => (int) $p->views,
            'loves' => (int) $p->loves_count,
            'created' => $y(optional($p->created_at)->toDateString()),
            'updated' => $y(optional($p->updated_at)->toDateString()),
            'synced_at' => $y(now()->toIso8601String()),
        ];

        $out = '';
        foreach ($pairs as $key => $val) {
            $out .= $key.': '.$val."\n";
        }

        return $out;
    }

    /** Everything after the front-matter block, or null if the file is malformed. */
    protected function existingBody(string $contents): ?string
    {
        // Windows editors save CRLF; without normalizing, "---\r\n" wouldn't
        // match and the old front matter would be duplicated into the body.
        $contents = str_replace("\r\n", "\n", $contents);

        if (! str_starts_with($contents, "---\n")) {
            return $contents; // no front matter — treat whole file as body
        }
        $end = strpos($contents, "\n---", 4);
        if ($end === false) {
            return null;
        }

        return ltrim(substr($contents, $end + 4), "-\n");
    }

    /** First-time body: the product template with name + description filled in. */
    protected function seedBody(Product $p): string
    {
        $template = base_path('knowledge/_templates/product.md');
        $body = File::exists($template)
            ? ($this->existingBody(File::get($template)) ?? '')
            : "# {Product Name}\n";

        $body = str_replace('{Product Name}', $p->name, $body);

        // Replace the template's summary instruction with the real description.
        $summary = trim(strip_tags((string) ($p->short_description ?: $p->description)));
        if ($summary !== '') {
            $body = preg_replace(
                '/^One-paragraph plain-language summary.*?self-contained\./ms',
                $summary,
                $body,
                1
            ) ?? $body;
        }

        return $body;
    }
}
