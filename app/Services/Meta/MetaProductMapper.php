<?php

namespace App\Services\Meta;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Maps our Product / ProductVariant models to Meta Catalog "product item" data.
 *
 * A simple product yields one item; a variable product yields one item per
 * active variant (each with its own retailer_id, price, stock, image and
 * attributes) plus, optionally, the parent as an item group. Retailer IDs are
 * stable and deterministic so re-syncs update rather than duplicate.
 */
class MetaProductMapper
{
    public function __construct(private readonly MetaSettings $settings) {}

    /** Stable external id for a product (or a variant of it). */
    public function retailerId(Product $product, ?ProductVariant $variant = null): string
    {
        return $variant
            ? "prod-{$product->id}-var-{$variant->id}"
            : "prod-{$product->id}";
    }

    /**
     * Build every catalog item for a product.
     *
     * @return array<int, array{retailer_id:string, data:array}>
     */
    public function items(Product $product): array
    {
        $product->loadMissing(['images', 'category', 'variants']);

        if ($product->has_variants && $this->settings->toggle('sync_variations') && $product->variants->isNotEmpty()) {
            return $product->variants
                ->where('is_active', true)
                ->map(fn (ProductVariant $v) => [
                    'retailer_id' => $this->retailerId($product, $v),
                    'data' => $this->variantData($product, $v),
                ])
                ->values()->all();
        }

        return [[
            'retailer_id' => $this->retailerId($product),
            'data' => $this->simpleData($product),
        ]];
    }

    // ── Field mapping ──────────────────────────────────────────────────────

    private function simpleData(Product $product): array
    {
        $images = $this->images($product);
        [$price, $salePrice] = $this->prices(offer_pricing()->quote($product));

        return array_filter([
            'retailer_id' => $this->retailerId($product),
            'title' => $this->truncate($product->name, 200),
            'description' => $this->description($product),
            'availability' => $this->availability($product),
            'condition' => config('meta.defaults.condition', 'new'),
            'price' => $price,
            'sale_price' => $salePrice,
            'inventory' => $this->inventory($product),
            'brand' => $this->brand($product),
            'link' => $this->link($product),
            'image_link' => $images['primary'],
            'additional_image_link' => $images['additional'] ?: null,
            'video' => $this->videos($product),
            'google_product_category' => $product->googleCategory(),
            'product_type' => $this->productType($product),
            'color' => $this->firstColor($product),
            'custom_label_0' => $product->is_bestseller ? 'bestseller' : null,
            'gtin' => null,
            'mpn' => $product->sku ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function variantData(Product $product, ProductVariant $variant): array
    {
        $attrs = collect($variant->attributes ?? []);
        $variantImage = $variant->image?->url;
        $images = $this->images($product);
        [$regular, $sale] = $this->prices(offer_pricing()->quote($product, $variant));

        return array_filter([
            'retailer_id' => $this->retailerId($product, $variant),
            'item_group_id' => $this->retailerId($product),
            'title' => $this->truncate($product->name.' '.$variant->label, 200),
            'description' => $this->description($product),
            'availability' => $this->availabilityFromStock($variant->stock_quantity, $product),
            'condition' => config('meta.defaults.condition', 'new'),
            'price' => $regular,
            'sale_price' => $sale,
            'inventory' => $this->settings->toggle('sync_inventory') ? max(0, (int) $variant->stock_quantity) : null,
            'brand' => $this->brand($product),
            'link' => $this->link($product),
            'image_link' => $variantImage ?: $images['primary'],
            'additional_image_link' => $images['additional'] ?: null,
            // Variants inherit the parent's videos — the clip shows the piece,
            // not the size, so every variant item should carry it.
            'video' => $this->videos($product),
            'google_product_category' => $product->googleCategory(),
            'product_type' => $this->productType($product),
            'color' => $this->attr($attrs, ['color', 'colour']),
            'size' => $this->attr($attrs, ['size']),
            'material' => $this->attr($attrs, ['material']),
            'pattern' => $this->attr($attrs, ['pattern']),
            'gender' => $this->attr($attrs, ['gender']),
            'mpn' => $variant->sku ?: $product->sku ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Meta wants price as "12.00 BDT" (amount + ISO currency). */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '').' '.config('meta.defaults.currency', 'BDT');
    }

    /**
     * Meta expects `price` = regular and `sale_price` = discounted. The quote
     * (App\Support\OfferPricing) carries both: while a live offer covers the
     * piece, its regular price and what it lists at; otherwise a higher
     * compare-at price and the selling price — the figures the product page
     * and the scheduled CSV feed use, so the two ways into the catalogue agree.
     *
     * @param  array{price: float, was: ?float, offer: ?\App\Models\Offer}  $quote
     * @return array{0:?string, 1:?string} [price, sale_price]
     */
    private function prices(array $quote): array
    {
        // With price sync off only the piece's own price goes up — never a
        // sale price.
        if (! $this->settings->toggle('sync_price')) {
            return [$this->money($quote['offer'] ? $quote['was'] : $quote['price']), null];
        }

        if ($quote['was'] !== null) {
            return [$this->money($quote['was']), $this->money($quote['price'])];
        }

        return [$this->money($quote['price']), null];
    }

    private function availability(Product $product): string
    {
        if ($product->is_preorder) {
            return 'available for order';
        }

        if ($product->manage_stock) {
            return $product->stock_quantity > 0 ? 'in stock' : 'out of stock';
        }

        return $product->in_stock ? 'in stock' : 'out of stock';
    }

    private function availabilityFromStock(?int $stock, Product $product): string
    {
        if (! $product->manage_stock) {
            return $product->in_stock ? 'in stock' : 'out of stock';
        }

        return (int) $stock > 0 ? 'in stock' : 'out of stock';
    }

    private function inventory(Product $product): ?int
    {
        if (! $this->settings->toggle('sync_inventory') || ! $product->manage_stock) {
            return null;
        }

        return max(0, (int) $product->stock_quantity);
    }

    private function description(Product $product): string
    {
        return feed_description($product, 5000);
    }

    private function brand(Product $product): ?string
    {
        foreach ($product->customFieldList() as $field) {
            if (str_contains(strtolower($field['label']), 'brand')) {
                return $field['value'];
            }
        }

        // Same fallback as the CSV feed, so both describe the same brand.
        return config('meta.defaults.brand') ?: (store_name() ?: null);
    }

    private function link(Product $product): string
    {
        return route('product.show', $product);
    }

    /** @return array{primary:?string, additional:array<int,string>} */
    private function images(Product $product): array
    {
        if (! $this->settings->toggle('sync_images')) {
            return ['primary' => null, 'additional' => []];
        }

        $urls = $product->images
            ->sortByDesc('is_primary')
            ->map(fn ($img) => $img->url)
            ->filter()
            ->values();

        return [
            'primary' => $urls->first(),
            // Meta allows up to 20 additional images.
            'additional' => $urls->slice(1, 20)->implode(','),
        ];
    }

    /**
     * Gallery videos as Meta catalog `video` entries.
     *
     * Meta fetches and re-hosts the file itself, so the URL has to be a direct
     * link to the video (.mp4, .mov …). A YouTube or Vimeo watch page is a
     * player, not a file, and Meta rejects it — those entries are dropped here
     * rather than sent and left to fail silently in Commerce Manager.
     *
     * @return array<int, array{url:string}>|null
     */
    private function videos(Product $product): ?array
    {
        // `sync_images` is the media toggle: with it off an item has no
        // image_link and Meta rejects it outright, so sending a video would be
        // pointless anyway.
        if (! $this->settings->toggle('sync_images')) {
            return null;
        }

        $videos = collect($product->galleryVideos())
            ->filter(fn ($v) => ($v['type'] ?? null) === 'file' && filled($v['src'] ?? null))
            // Meta accepts up to 20 videos per item.
            ->take(20)
            ->map(fn ($v) => ['url' => $v['src']])
            ->values()
            ->all();

        // array_filter() on the payload drops nulls but keeps an empty array,
        // and Meta reads `"video": []` as "delete the videos on this item".
        return $videos ?: null;
    }

    private function productType(Product $product): ?string
    {
        if (! $this->settings->toggle('sync_categories')) {
            return null;
        }

        $cat = $product->category;
        if (! $cat) {
            return null;
        }

        // Build a "Parent > Child" path when the category is nested.
        return $cat->parent
            ? $cat->parent->name.' > '.$cat->name
            : $cat->name;
    }

    private function firstColor(Product $product): ?string
    {
        return collect($product->colors ?? [])->filter()->first();
    }

    private function attr(Collection $attrs, array $names): ?string
    {
        foreach ($attrs as $key => $value) {
            if (in_array(strtolower((string) $key), $names, true)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
