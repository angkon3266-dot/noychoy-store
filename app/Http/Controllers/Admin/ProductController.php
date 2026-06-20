<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with('primaryImage', 'category')
            ->search($request->query('q'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.products.index', compact('products'));
    }

    public function create()
    {
        return view('admin.products.form', [
            'product' => new Product(['status' => 'published', 'manage_stock' => true, 'in_stock' => true]),
            'categories' => Category::orderBy('name')->get(),
            'allProducts' => Product::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $product = Product::create($data);

        $this->syncImages($request, $product);
        $this->syncVariants($request, $product);

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'Product created.');
    }

    public function edit(Product $product)
    {
        $product->load('images', 'variants');

        return view('admin.products.form', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'allProducts' => Product::where('id', '!=', $product->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateData($request, $product);
        $product->update($data);

        $this->syncImages($request, $product);
        $this->applyImageOrder($request, $product);
        $this->syncVariants($request, $product);

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'Product updated.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Product deleted.');
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

    /** Bulk actions on selected products. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,draft,feature,unfeature,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $query = Product::whereIn('id', $data['ids']);
        $count = (clone $query)->count();

        match ($data['action']) {
            'publish' => $query->update(['status' => 'published']),
            'draft' => $query->update(['status' => 'draft']),
            'feature' => $query->update(['is_featured' => true]),
            'unfeature' => $query->update(['is_featured' => false]),
            'delete' => $query->get()->each->delete(),
        };

        return back()->with('success', "$count product(s) updated.");
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
            'sku' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'is_preorder' => ['nullable', 'boolean'],
            'preorder_release_date' => ['nullable', 'date'],
            'preorder_note' => ['nullable', 'string', 'max:200'],
            'manage_stock' => ['nullable', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:draft,published'],
            'is_featured' => ['nullable', 'boolean'],
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
        $validated['is_preorder'] = $request->boolean('is_preorder');
        $validated['stock_quantity'] = $validated['stock_quantity'] ?? 0;
        $validated['in_stock'] = ! $validated['manage_stock'] || $validated['stock_quantity'] > 0;
        $validated['has_variants'] = filled($request->input('variants'));

        // Normalise quantity offers: keep only complete rows, sorted by min_qty.
        $validated['quantity_offers'] = collect($validated['quantity_offers'] ?? [])
            ->filter(fn ($t) => filled($t['min_qty'] ?? null) && filled($t['percent'] ?? null))
            ->map(fn ($t) => ['min_qty' => (int) $t['min_qty'], 'percent' => (float) $t['percent']])
            ->sortBy('min_qty')->values()->all();

        $validated['upsell_ids'] = array_values(array_unique(array_map('intval', $validated['upsell_ids'] ?? [])));
        $validated['cross_sell_ids'] = array_values(array_unique(array_map('intval', $validated['cross_sell_ids'] ?? [])));

        return $validated;
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

    protected function syncImages(Request $request, Product $product): void
    {
        if (! $request->hasFile('images')) {
            return;
        }

        $optimizer = app(ImageOptimizer::class);
        $hasPrimary = $product->images()->where('is_primary', true)->exists();
        $position = (int) $product->images()->max('position');

        foreach ($request->file('images') as $file) {
            $path = $optimizer->storeWebp($file, 'products');
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
        $rows = collect($request->input('variants', []))
            ->filter(fn ($row) => filled($row['label'] ?? null));

        if ($rows->isEmpty()) {
            $product->variants()->delete();
            $product->update(['has_variants' => false]);
            return;
        }

        $product->variants()->delete();

        foreach ($rows as $row) {
            $product->variants()->create([
                'attributes' => ['Option' => $row['label']],
                'sku' => $row['sku'] ?? null,
                'price' => ($row['price'] ?? null) !== null && $row['price'] !== '' ? $row['price'] : null,
                'stock_quantity' => (int) ($row['stock'] ?? 0),
                'is_active' => true,
            ]);
        }

        $product->update(['has_variants' => true]);
    }
}
