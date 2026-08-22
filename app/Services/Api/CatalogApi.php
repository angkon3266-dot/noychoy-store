<?php

namespace App\Services\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use App\Services\WatermarkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The catalogue operations an outside agent can perform, in one place.
 *
 * Both surfaces call this: the REST controllers and the MCP tools. They are
 * two ways of dialling the same number — if the logic lived in the controllers
 * the MCP tools would slowly grow their own subtly different rules about
 * watermarking, variants and what counts as the primary image.
 */
class CatalogApi
{
    public function __construct(
        private readonly ImageOptimizer $optimizer,
        private readonly WatermarkService $watermark,
    ) {}

    /** @return array<int,array> */
    public function search(?string $query = null, int $limit = 20, bool $publishedOnly = false): array
    {
        return Product::query()
            ->with('images')
            ->when($publishedOnly, fn ($q) => $q->published())
            ->when(filled($query), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$query}%")
                ->orWhere('sku', 'like', "%{$query}%")
                ->orWhere('slug', 'like', "%{$query}%")))
            ->latest('id')
            ->take(max(1, min(100, $limit)))
            ->get()
            ->map(fn (Product $p) => $this->present($p))
            ->all();
    }

    /** Accepts a numeric id, a slug or an exact SKU — an agent rarely knows the id. */
    public function find(string|int $ref): ?Product
    {
        return Product::with('images')
            ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref))
            ->when(! is_numeric($ref), fn ($q) => $q->where('slug', $ref)->orWhere('sku', $ref))
            ->first();
    }

    /**
     * Attach an image from an upload or a URL.
     *
     * Runs the same pipeline the admin panel does — WebP conversion, optional
     * watermark, srcset variant — so an API-added photo is indistinguishable
     * from one added by hand.
     */
    public function addImage(Product $product, UploadedFile|string $source, bool $makePrimary = false, ?string $alt = null): ProductImage
    {
        $path = $source instanceof UploadedFile
            ? $this->optimizer->storeWebp($source, 'products')
            : $this->optimizer->storeWebpFromUrl($source, 'products');

        if (! str_starts_with($path, 'http')) {
            if (! empty($this->watermark->settings()['auto_products']) && $this->watermark->isReady()) {
                $this->watermark->applyToPath($path);
            }
            $this->optimizer->variant($path, 450);
        }

        // First image of a product is its primary whether asked for or not —
        // a product whose every image is non-primary has no thumbnail.
        $isFirst = $product->images()->count() === 0;

        $image = $product->images()->create([
            'path' => $path,
            'alt' => $alt,
            'position' => (int) $product->images()->max('position') + 1,
            'is_primary' => $makePrimary || $isFirst,
        ]);

        if ($image->is_primary) {
            $this->demoteOthers($product, $image->id);
        }

        return $image;
    }

    public function setPrimary(Product $product, ProductImage $image): void
    {
        $image->update(['is_primary' => true]);
        $this->demoteOthers($product, $image->id);
    }

    public function deleteImage(Product $product, ProductImage $image): void
    {
        $wasPrimary = $image->is_primary;

        if (! str_starts_with($image->path, 'http')) {
            Storage::disk('public')->delete([
                $image->path,
                $this->optimizer->variantPath($image->path, 450),
            ]);
        }

        $image->delete();

        // Never leave a product with images but no primary.
        if ($wasPrimary && $next = $product->images()->orderBy('position')->first()) {
            $next->update(['is_primary' => true]);
        }
    }

    /** Fields an agent may set on create or update. */
    public const WRITABLE = [
        'name', 'short_description', 'description', 'price', 'compare_at_price',
        'stock_quantity', 'manage_stock', 'status', 'is_featured', 'is_bestseller', 'sku',
        'meta_title', 'meta_description', 'category_id', 'tags', 'weight',
        'is_preorder', 'preorder_note',
    ];

    /** @param array<string,mixed> $fields */
    public function updateProduct(Product $product, array $fields): Product
    {
        $allowed = $this->normalise(array_intersect_key($fields, array_flip(self::WRITABLE)));

        if ($allowed) {
            $product->update($allowed);
        }

        return $product->fresh('images');
    }

    /**
     * Create a product the way the admin form does: unique slug + display
     * serial, draft unless told otherwise, stock tracked when a quantity is
     * given. Images are attached afterwards with addImage().
     *
     * @param array<string,mixed> $fields
     */
    public function createProduct(array $fields): Product
    {
        $data = $this->normalise(array_intersect_key($fields, array_flip(self::WRITABLE)));

        $data['slug'] = Product::uniqueSlug((string) ($fields['slug'] ?? $data['name']));
        $data['status'] = $data['status'] ?? 'draft';
        $data['stock_quantity'] = (int) ($data['stock_quantity'] ?? 0);
        $data['manage_stock'] = $data['manage_stock'] ?? ($data['stock_quantity'] > 0);
        $data['in_stock'] = ! $data['manage_stock'] || $data['stock_quantity'] > 0;

        return Product::createUnique($data)->fresh('images');
    }

    /**
     * Archive (soft-delete) a product. Its files and order history stay, so
     * past orders keep their line items and the product can be restored from
     * the admin panel — an agent mistake should never be unrecoverable.
     */
    public function deleteProduct(Product $product): void
    {
        $product->delete();
    }

    /** @return array<int,array{id:int,name:string,slug:string,parent_id:?int,is_active:bool}> */
    public function categories(): array
    {
        return Category::query()->orderBy('position')->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'is_active'])
            ->map(fn (Category $c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
                'parent_id' => $c->parent_id, 'is_active' => (bool) $c->is_active,
            ])->all();
    }

    /** Resolve a category by id, slug or exact name — agents rarely know ids. */
    public function findCategory(string|int $ref): ?Category
    {
        return Category::query()
            ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref))
            ->when(! is_numeric($ref), fn ($q) => $q->where('slug', $ref)->orWhere('name', $ref))
            ->first();
    }

    /**
     * Accept the loose shapes an agent sends: tags as an array, booleans as
     * strings.
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    private function normalise(array $fields): array
    {
        if (isset($fields['tags']) && is_array($fields['tags'])) {
            $fields['tags'] = implode(',', array_map('trim', $fields['tags']));
        }
        foreach (['is_featured', 'is_bestseller', 'is_preorder', 'manage_stock'] as $flag) {
            if (array_key_exists($flag, $fields)) {
                $fields[$flag] = filter_var($fields[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    public function present(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'price' => (float) $product->price,
            'compare_at_price' => $product->compare_at_price !== null ? (float) $product->compare_at_price : null,
            'currency' => config('store.currency', 'BDT'),
            'status' => $product->status,
            'stock_quantity' => (int) $product->stock_quantity,
            'in_stock' => (bool) $product->in_stock,
            'is_featured' => (bool) $product->is_featured,
            'is_bestseller' => (bool) $product->is_bestseller,
            'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name, 'slug' => $product->category->slug] : null,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'tags' => $product->tags,
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'url' => route('product.show', $product),
            'images' => $product->images->sortBy('position')->values()->map(fn (ProductImage $i) => [
                'id' => $i->id,
                'url' => $i->url,
                'alt' => $i->alt,
                'position' => (int) $i->position,
                'is_primary' => (bool) $i->is_primary,
            ])->all(),
        ];
    }

    private function demoteOthers(Product $product, int $keepId): void
    {
        $product->images()->where('id', '!=', $keepId)->update(['is_primary' => false]);
    }
}
