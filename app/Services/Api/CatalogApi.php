<?php

namespace App\Services\Api;

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

    /** @param array<string,mixed> $fields */
    public function updateProduct(Product $product, array $fields): Product
    {
        $allowed = array_intersect_key($fields, array_flip([
            'name', 'short_description', 'description', 'price', 'compare_at_price',
            'stock_quantity', 'status', 'is_featured', 'sku', 'meta_title', 'meta_description',
        ]));

        if ($allowed) {
            $product->update($allowed);
        }

        return $product->fresh('images');
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
