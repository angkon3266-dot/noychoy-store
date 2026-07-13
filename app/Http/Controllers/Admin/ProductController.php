<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with('primaryImage', 'category', 'categories')
            ->search($request->query('q'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type') === 'simple', fn ($q) => $q->where('has_variants', false))
            ->when($request->query('type') === 'variable', fn ($q) => $q->where('has_variants', true))
            // Filter by category — matches the primary category or any pivot category.
            ->when($request->query('category'), fn ($q, $cid) => $q->where(fn ($w) => $w
                ->where('category_id', (int) $cid)
                ->orWhereHas('categories', fn ($c) => $c->where('categories.id', (int) $cid))))
            ->when($request->query('tag'), fn ($q, $tag) => $q->where('tags', 'like', "%{$tag}%"))
            ->when($request->query('custom'), fn ($q, $c) => $q->where(fn ($w) => $w
                ->where('custom_value', 'like', "%{$c}%")
                ->orWhere('custom_label', 'like', "%{$c}%")
                ->orWhere('custom_fields', 'like', "%{$c}%")))
            ->latest();

        // Per-page: lets the admin show more rows to select many at once.
        $perPage = $request->query('per_page', 20);
        $perPage = $perPage === 'all'
            ? max(1, (clone $products)->count())
            : (in_array((int) $perPage, [20, 50, 100, 200], true) ? (int) $perPage : 20);

        $products = $products->paginate($perPage)->withQueryString();

        // Tag suggestions for the filter dropdown.
        $allTags = Product::whereNotNull('tags')->where('tags', '!=', '')->pluck('tags')
            ->flatMap(fn ($t) => array_map('trim', explode(',', $t)))->filter()->unique()->sort()->values();

        $bulkCategories = Category::orderBy('name')->get(['id', 'name']);

        // For the quick-edit "related products" picker (name + thumbnail).
        $allProducts = Product::with('primaryImage')->orderBy('name')->get(['id', 'name']);

        return view('admin.products.index', compact('products', 'allTags', 'bulkCategories', 'allProducts'));
    }

    public function importForm()
    {
        return view('admin.products.import');
    }

    /**
     * Bulk-create products from an uploaded CSV.
     * Header row expected: name, price, sku, category, short_description, description,
     * meta_description, stock, status, tags
     */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'extensions:csv,txt', 'max:5120']]);

        // Bulk imports can be large; don't let a slow shared host abort the request.
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (! $handle) {
            return back()->with('error', 'Could not read the file.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            return back()->with('error', 'The file appears to be empty.');
        }
        // Strip a UTF-8 BOM from the first header cell so "name" matches.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $cols = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $colCount = count($cols);

        $categories = Category::all()->keyBy(fn ($c) => strtolower($c->name));
        $created = 0;
        $skipped = 0;
        $errors = [];   // human-readable reasons, capped so the flash stays small
        $row = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;

            // Skip fully blank lines and normalise the row to exactly the header
            // width (CSV rows with extra/missing commas would otherwise crash
            // array_combine with a count mismatch).
            if ($line === [null] || $line === [false]) {
                continue;
            }
            $line = array_slice(array_pad($line, $colCount, null), 0, $colCount);

            // Isolate every row: a bad row (duplicate SKU, over-long field, bad
            // encoding, …) is counted and skipped instead of aborting the whole
            // import with a 500. No wrapping transaction, so good rows persist.
            try {
                $data = array_combine($cols, $line);

                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    $skipped++;
                    continue;
                }

                $categoryId = null;
                if (! empty($data['category'])) {
                    $key = strtolower(trim($data['category']));
                    $categoryId = $categories->get($key)?->id;
                    if (! $categoryId) {
                        $cat = Category::create(['name' => trim($data['category']), 'is_active' => true]);
                        $categories->put($key, $cat);
                        $categoryId = $cat->id;
                    }
                }

                $product = Product::create([
                    'name' => $name,
                    'sku' => $data['sku'] ?? null,
                    'category_id' => $categoryId,
                    'short_description' => $data['short_description'] ?? null,
                    'description' => $data['description'] ?? null,
                    'meta_description' => $data['meta_description'] ?? null,
                    'price' => is_numeric($data['price'] ?? null) ? (float) $data['price'] : 0,
                    'manage_stock' => isset($data['stock']) && $data['stock'] !== '',
                    'stock_quantity' => (int) ($data['stock'] ?? 0),
                    'in_stock' => true,
                    'status' => in_array(strtolower($data['status'] ?? 'published'), ['draft', 'published']) ? strtolower($data['status'] ?? 'published') : 'published',
                    'tags' => $data['tags'] ?? null,
                ]);
                if ($categoryId) {
                    $product->categories()->sync([$categoryId]);
                }
                $created++;
            } catch (\Throwable $e) {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = "Row {$row}: ".Str::limit($this->cleanImportError($e->getMessage()), 140);
                }
            }
        }
        fclose($handle);

        $message = "Imported {$created} product(s)".($skipped ? ", skipped {$skipped} row(s)." : '.');

        return redirect()->route('admin.products.index')
            ->with('success', $message)
            ->with('import_errors', $errors);
    }

    /** Turn a raw DB/exception message into something an admin can act on. */
    protected function cleanImportError(string $message): string
    {
        if (Str::contains($message, ['Duplicate entry', '1062'])) {
            return 'Duplicate value (e.g. a SKU that already exists).';
        }
        if (Str::contains($message, ['Data too long', '1406'])) {
            return 'A value is too long for its column.';
        }
        if (Str::contains($message, ['Incorrect string value', '1366'])) {
            return 'Invalid characters (save the CSV as UTF-8).';
        }

        return $message;
    }

    public function create()
    {
        return view('admin.products.form', [
            'product' => new Product(['status' => 'published', 'manage_stock' => true, 'in_stock' => true]),
            'categories' => Category::orderBy('name')->get(),
            'allProducts' => Product::with('primaryImage')->orderBy('name')->get(['id', 'name']),
            'contentTemplates' => \App\Models\ContentTemplate::orderBy('name')->get(['id', 'name', 'sections']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $product = Product::create($data);

        $this->syncCategories($data, $product);
        $this->syncImages($request, $product);
        $this->syncVideos($request, $product);
        $this->syncVariants($request, $product);
        $this->syncContentSections($request, $product);

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'Product created.');
    }

    public function edit(Product $product)
    {
        $product->load('images', 'variants', 'categories', 'metaSyncStates');

        return view('admin.products.form', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'allProducts' => Product::with('primaryImage')->where('id', '!=', $product->id)->orderBy('name')->get(['id', 'name']),
            'contentTemplates' => \App\Models\ContentTemplate::orderBy('name')->get(['id', 'name', 'sections']),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateData($request, $product);
        $product->update($data);

        $this->syncCategories($data, $product);
        $this->syncImages($request, $product);
        $this->applyImageOrder($request, $product);
        $this->syncVideos($request, $product);
        $this->syncVariants($request, $product);
        $this->syncContentSections($request, $product);

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'Product updated.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Product deleted.');
    }

    /** Persist the product's editorial "story sections" from the JSON builder. */
    protected function syncContentSections(Request $request, Product $product): void
    {
        if (! $request->has('content_sections_json')) {
            return;
        }

        $raw = json_decode((string) $request->input('content_sections_json'), true);
        $product->content_sections = \App\Models\ContentTemplate::cleanSections($raw);
        $product->save();
    }

    /** AJAX: upload one section image, return its stored URL (used by the builder). */
    public function uploadSectionImage(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'max:8192']]);

        $path = app(\App\Services\ImageOptimizer::class)->storeWebp($request->file('image'), 'sections', 1600, 82);

        return response()->json(['url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path)]);
    }

    /** Save a product's current sections as a reusable content template. */
    public function saveAsTemplate(Request $request, Product $product)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $sections = \App\Models\ContentTemplate::cleanSections(
            json_decode((string) $request->input('content_sections_json'), true)
        );

        if (empty($sections)) {
            return back()->with('error', 'Add at least one section before saving it as a template.');
        }

        \App\Models\ContentTemplate::create(['name' => $data['name'], 'sections' => $sections]);

        return back()->with('success', 'Saved as template “'.$data['name'].'”.');
    }

    /** Clone a product (with images & variants) as a new draft. */
    public function duplicate(Product $product)
    {
        $product->load('images', 'variants');

        $copy = $product->replicate(['slug', 'sku', 'views']);
        $copy->name = $product->name.' (copy)';
        $copy->slug = Product::uniqueSlug($copy->name);
        $copy->sku = $product->sku ? $product->sku.'-COPY' : null;
        $copy->status = 'draft';
        $copy->is_featured = false;
        $copy->views = 0;
        $copy->save();

        foreach ($product->images as $img) {
            $copy->images()->create([
                'path' => $img->path,
                'alt' => $img->alt,
                'position' => $img->position,
                'is_primary' => $img->is_primary,
            ]);
        }
        foreach ($product->variants as $v) {
            $copy->variants()->create([
                'attributes' => $v->attributes,
                'sku' => $v->sku,
                'price' => $v->price,
                'stock_quantity' => $v->stock_quantity,
                'is_active' => $v->is_active,
            ]);
        }

        return redirect()->route('admin.products.edit', $copy)->with('success', 'Product duplicated — now editing the copy (saved as draft).');
    }

    /** Inline quick-edit of price and/or stock from the list. */
    public function quickUpdate(Request $request, Product $product)
    {
        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('price', $data) && $data['price'] !== null) {
            $product->price = $data['price'];
        }
        if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null) {
            $product->stock_quantity = $data['stock_quantity'];
            if ($product->manage_stock) {
                $product->in_stock = $data['stock_quantity'] > 0;
            }
        }
        $product->save();

        return back()->with('success', $product->name.' updated.');
    }

    /**
     * Inline quick-edit from the product list: price, stock, append images & video
     * links — all without opening the full product form.
     */
    public function quickMedia(Request $request, Product $product)
    {
        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'tags' => ['nullable', 'string', 'max:255'],
            'colors' => ['nullable', 'string', 'max:255'],
            'upsell_ids' => ['nullable', 'array'],
            'upsell_ids.*' => ['integer', 'exists:products,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:8192'],
            'video_urls' => ['nullable', 'array'],
            'video_urls.*' => ['nullable', 'string', 'max:255'],
            'video_files' => ['nullable', 'array'],
            'video_files.*' => ['nullable', 'file', 'mimes:mp4,webm,mov,m4v,ogg', 'max:51200'],
        ]);

        if (filled($data['price'] ?? null)) {
            $product->price = $data['price'];
        }
        if ($request->has('cost_price')) {
            $product->cost_price = $data['cost_price'];
        }
        if ($request->has('transport_cost')) {
            $product->transport_cost = $data['transport_cost'];
        }
        if ($request->has('meta_description')) {
            $product->meta_description = $data['meta_description'] ?: null;
        }
        if ($request->has('tags')) {
            $product->tags = $data['tags'] ?: null;
        }
        if ($request->has('colors')) {
            $product->colors = collect(explode(',', (string) ($data['colors'] ?? '')))
                ->map(fn ($c) => trim($c))->filter()->unique()->values()->all();
        }
        if ($request->has('upsell_ids')) {
            $product->upsell_ids = collect($data['upsell_ids'] ?? [])->map(fn ($i) => (int) $i)
                ->reject(fn ($i) => $i === $product->id)->values()->all();
        }
        if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null) {
            $product->stock_quantity = $data['stock_quantity'];
            if ($product->manage_stock) {
                $product->in_stock = $data['stock_quantity'] > 0;
            }
        }

        // Append any new video links to the existing gallery videos.
        $newVideos = collect($data['video_urls'] ?? [])->map(fn ($u) => trim((string) $u))->filter()->values();
        if ($newVideos->isNotEmpty()) {
            $product->video_urls = collect($product->video_urls ?? [])->merge($newVideos)->filter()->unique()->values()->all();
        }

        $product->save();

        // Append uploaded images & videos to the gallery (reuses the full-form logic).
        $this->syncImages($request, $product);
        $this->syncVideos($request, $product);

        return back()->with('success', $product->name.' updated.');
    }

    /** Bulk actions on selected products. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,draft,feature,unfeature,bestseller,unbestseller,delete,category'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'category_ids' => ['nullable', 'array', 'required_if:action,category'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $catIds = array_values(array_unique(array_map('intval', $data['category_ids'] ?? [])));
        $query = Product::whereIn('id', $data['ids']);
        $count = (clone $query)->count();

        match ($data['action']) {
            'publish' => $query->update(['status' => 'published']),
            'draft' => $query->update(['status' => 'draft']),
            'feature' => $query->update(['is_featured' => true]),
            'unfeature' => $query->update(['is_featured' => false]),
            'bestseller' => $query->update(['is_bestseller' => true]),
            'unbestseller' => $query->update(['is_bestseller' => false]),
            'delete' => $query->get()->each->delete(),
            // Replace all: set the product's categories to exactly the chosen set,
            // primary = the first selected category.
            'category' => $query->get()->each(function ($p) use ($catIds) {
                $p->update(['category_id' => $catIds[0] ?? null]);
                $p->categories()->sync($catIds);
            }),
        };

        $msg = $data['action'] === 'category'
            ? "$count product(s) set to ".count($catIds)." selected category(ies)."
            : "$count product(s) updated.";

        return back()->with('success', $msg);
    }

    /** Delete several selected images of a product at once. */
    public function bulkDeleteImages(Request $request, Product $product)
    {
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer'],
        ]);

        $images = $product->images()->whereIn('id', $data['image_ids'])->get();
        foreach ($images as $image) {
            if (! str_starts_with($image->path, 'http')) {
                Storage::disk('public')->delete($image->path);
            }
            $image->delete();
        }

        // Make sure the product still has a primary image.
        if (! $product->images()->where('is_primary', true)->exists()) {
            $product->images()->orderBy('position')->first()?->update(['is_primary' => true]);
        }

        return back()->with('success', $images->count().' image(s) removed.');
    }

    public function deleteImage(ProductImage $image)
    {
        if (! str_starts_with($image->path, 'http')) {
            Storage::disk('public')->delete($image->path);
        }
        $image->delete();

        return back()->with('success', 'Image removed.');
    }

    public function setPrimaryImage(ProductImage $image)
    {
        ProductImage::where('product_id', $image->product_id)->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return back()->with('success', 'Primary image set.');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    protected function validateData(Request $request, ?Product $product = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'product_type' => ['nullable', 'in:simple,variable'],
            'price' => ['nullable', 'numeric', 'min:0', 'required_if:product_type,simple'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.name' => ['nullable', 'string', 'max:60'],
            'attributes.*.values' => ['nullable', 'string', 'max:300'],
            'variants' => ['nullable', 'array'],
            'variants.*.attrs' => ['nullable', 'array'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'is_preorder' => ['nullable', 'boolean'],
            'preorder_release_date' => ['nullable', 'date'],
            'preorder_note' => ['nullable', 'string', 'max:200'],
            'manage_stock' => ['nullable', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:draft,published'],
            'tags' => ['nullable', 'string', 'max:255'],
            'colors' => ['nullable', 'string', 'max:255'],
            'custom_label' => ['nullable', 'string', 'max:60'],
            'custom_value' => ['nullable', 'string', 'max:255'],
            'custom_show' => ['nullable', 'boolean'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*.label' => ['nullable', 'string', 'max:60'],
            'custom_fields.*.value' => ['nullable', 'string', 'max:255'],
            'custom_fields.*.show' => ['nullable'],
            'is_featured' => ['nullable', 'boolean'],
            'is_bestseller' => ['nullable', 'boolean'],
            'video_urls' => ['nullable', 'array'],
            'video_urls.*' => ['nullable', 'string', 'max:255'],
            'video_files' => ['nullable', 'array'],
            'video_files.*' => ['nullable', 'file', 'mimes:mp4,webm,mov,m4v,ogg', 'max:51200'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'quantity_offers' => ['nullable', 'array'],
            'quantity_offers.*.min_qty' => ['nullable', 'integer', 'min:2', 'max:999'],
            'quantity_offers.*.percent' => ['nullable', 'numeric', 'min:0.1', 'max:90'],
            'upsell_ids' => ['nullable', 'array'],
            'upsell_ids.*' => ['integer', 'exists:products,id'],
            'cross_sell_ids' => ['nullable', 'array'],
            'cross_sell_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $validated['manage_stock'] = $request->boolean('manage_stock');
        $validated['is_featured'] = $request->boolean('is_featured');
        $validated['is_bestseller'] = $request->boolean('is_bestseller');
        $validated['is_preorder'] = $request->boolean('is_preorder');
        $validated['custom_show'] = $request->boolean('custom_show');
        $validated['stock_quantity'] = $validated['stock_quantity'] ?? 0;

        // Colours: comma-separated string → clean unique array (stored as JSON).
        $validated['colors'] = collect(explode(',', (string) ($validated['colors'] ?? '')))
            ->map(fn ($c) => trim($c))->filter()->unique()->values()->all();

        // Gallery video references (YouTube/Vimeo URLs + previously-stored file paths).
        $validated['video_urls'] = collect($validated['video_urls'] ?? [])
            ->map(fn ($u) => trim((string) $u))->filter()->values()->all();
        unset($validated['video_files']); // handled separately (uploads)

        // Normalise the repeatable custom fields: keep only complete rows.
        $validated['custom_fields'] = collect($validated['custom_fields'] ?? [])
            ->map(fn ($f) => [
                'label' => trim((string) ($f['label'] ?? '')),
                'value' => trim((string) ($f['value'] ?? '')),
                'show' => filled($f['show'] ?? null),
            ])
            ->filter(fn ($f) => $f['label'] !== '' && $f['value'] !== '')
            ->values()->all();

        // ── Product type: simple vs variable ────────────────────────────────
        $isVariable = ($request->input('product_type') === 'variable');
        $validated['has_variants'] = $isVariable;

        if ($isVariable) {
            // Attribute definitions → options json: [{name, values:[]}]
            $validated['options'] = collect($request->input('attributes', []))
                ->map(fn ($a) => [
                    'name' => trim((string) ($a['name'] ?? '')),
                    'values' => collect(explode(',', (string) ($a['values'] ?? '')))->map(fn ($v) => trim($v))->filter()->values()->all(),
                ])
                ->filter(fn ($a) => $a['name'] !== '' && ! empty($a['values']))
                ->values()->all();

            // Price = lowest variation price (for cards, sorting, "from" display).
            $prices = collect($request->input('variants', []))
                ->map(fn ($v) => (float) ($v['price'] ?? 0))->filter(fn ($p) => $p > 0);
            $validated['price'] = $prices->min() ?? 0;
            $validated['manage_stock'] = true;
            $validated['stock_quantity'] = collect($request->input('variants', []))->sum(fn ($v) => (int) ($v['stock'] ?? 0));
            $validated['in_stock'] = $validated['stock_quantity'] > 0;
        } else {
            $validated['options'] = null;
            $validated['in_stock'] = ! $validated['manage_stock'] || $validated['stock_quantity'] > 0;
        }

        // Normalise quantity offers: keep only complete rows, sorted by min_qty.
        $validated['quantity_offers'] = collect($validated['quantity_offers'] ?? [])
            ->filter(fn ($t) => filled($t['min_qty'] ?? null) && filled($t['percent'] ?? null))
            ->map(fn ($t) => ['min_qty' => (int) $t['min_qty'], 'percent' => (float) $t['percent']])
            ->sortBy('min_qty')->values()->all();

        $validated['upsell_ids'] = array_values(array_unique(array_map('intval', $validated['upsell_ids'] ?? [])));
        $validated['cross_sell_ids'] = array_values(array_unique(array_map('intval', $validated['cross_sell_ids'] ?? [])));

        // Primary category = the chosen primary, else the first ticked category.
        $catIds = array_values(array_unique(array_map('intval', $validated['category_ids'] ?? [])));
        if (empty($validated['category_id']) && ! empty($catIds)) {
            $validated['category_id'] = $catIds[0];
        }
        // Make sure the primary is part of the set.
        if (! empty($validated['category_id']) && ! in_array((int) $validated['category_id'], $catIds, true)) {
            $catIds[] = (int) $validated['category_id'];
        }
        $validated['_category_ids'] = $catIds;          // consumed by syncCategories
        unset($validated['category_ids']);

        // Slug: sanitise whatever was typed into a clean, unique slug so editing it
        // never fails on odd characters or a collision with another product.
        if (blank($validated['slug'] ?? null)) {
            unset($validated['slug']);                  // let the model auto-generate
        } else {
            $validated['slug'] = Product::uniqueSlug($validated['slug'], $product?->id);
        }

        return $validated;
    }

    /** Sync the many-to-many categories pivot from validated data. */
    protected function syncCategories(array $data, Product $product): void
    {
        $product->categories()->sync($data['_category_ids'] ?? []);
    }

    /** Apply a drag-reordered image sequence (posted as image_order[] of ids). */
    protected function applyImageOrder(Request $request, Product $product): void
    {
        $order = collect($request->input('image_order', []))->map(fn ($id) => (int) $id)->filter();
        if ($order->isEmpty()) {
            return;
        }

        $owned = $product->images()->pluck('id')->all();
        $position = 0;
        foreach ($order as $id) {
            if (in_array($id, $owned, true)) {
                ProductImage::where('id', $id)->update(['position' => $position++]);
            }
        }
    }

    /** Store uploaded MP4/WebM gallery videos and append their paths to video_urls. */
    protected function syncVideos(Request $request, Product $product): void
    {
        if (! $request->hasFile('video_files')) {
            return;
        }

        $urls = collect($product->video_urls ?? []);
        foreach ($request->file('video_files') as $file) {
            if ($file && $file->isValid()) {
                $urls->push($file->store('product-videos', 'public'));
            }
        }
        $product->update(['video_urls' => $urls->filter()->values()->all()]);
    }

    protected function syncImages(Request $request, Product $product): void
    {
        // Paths from device uploads and from media-library / remote picks.
        $paths = [];

        if ($request->hasFile('images')) {
            $optimizer = app(ImageOptimizer::class);
            $watermark = app(\App\Services\WatermarkService::class);
            $autoStamp = ! empty($watermark->settings()['auto_products']) && $watermark->isReady();
            foreach ($request->file('images') as $file) {
                $stored = $optimizer->storeWebp($file, 'products');
                if ($autoStamp) {
                    $watermark->applyToPath($stored);   // only new device uploads, not library picks
                }
                $paths[] = $stored;
            }
        }

        foreach ((array) $request->input('image_urls', []) as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            // Reuse an existing library file in place; download+optimise a remote one.
            $paths[] = public_url_to_path($url) ?: app(ImageOptimizer::class)->storeWebpFromUrl($url, 'products');
        }

        if (empty($paths)) {
            return;
        }

        $hasPrimary = $product->images()->where('is_primary', true)->exists();
        $position = (int) $product->images()->max('position');

        foreach ($paths as $path) {
            $product->images()->create([
                'path' => $path,
                'alt' => $product->name,
                'position' => ++$position,
                'is_primary' => ! $hasPrimary,
            ]);
            $hasPrimary = true;
        }
    }

    /**
     * Variants posted as variants[] = ['label' => 'Size 6 / Gold', 'sku', 'price', 'stock'].
     * Stored fresh each save (simple + predictable for a small catalog).
     */
    protected function syncVariants(Request $request, Product $product): void
    {
        // Simple product → no variants.
        if ($request->input('product_type') !== 'variable') {
            $product->variants()->delete();
            return;
        }

        $rows = collect($request->input('variants', []))
            ->filter(fn ($row) => ! empty($row['attrs']) && is_array($row['attrs']));

        // Rebuild fresh each save (small catalog, keeps it predictable).
        $product->variants()->delete();

        foreach ($rows as $row) {
            $attrs = collect($row['attrs'])->map(fn ($v) => (string) $v)->filter(fn ($v) => $v !== '')->all();
            if (empty($attrs)) {
                continue;
            }
            $product->variants()->create([
                'attributes' => $attrs,                                   // {"Size":"7","Color":"Gold"}
                'sku' => $row['sku'] ?? null,
                'price' => filled($row['price'] ?? null) ? (float) $row['price'] : null,
                'stock_quantity' => (int) ($row['stock'] ?? 0),
                'is_active' => true,
            ]);
        }
    }
}
