<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'serial', 'name', 'slug', 'sku', 'category_id', 'short_description', 'description',
        'price', 'compare_at_price', 'cost_price', 'transport_cost', 'manage_stock', 'stock_quantity',
        'in_stock', 'weight', 'has_variants', 'options', 'status', 'is_featured',
        'views', 'meta_title', 'meta_description', 'woo_id',
        'quantity_offers', 'upsell_ids', 'cross_sell_ids',
        'is_preorder', 'preorder_release_date', 'preorder_note', 'tags', 'colors',
        'custom_label', 'custom_value', 'custom_show', 'custom_fields', 'loves_count',
        'is_bestseller', 'video_urls',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'weight' => 'decimal:2',
        'manage_stock' => 'boolean',
        'in_stock' => 'boolean',
        'has_variants' => 'boolean',
        'is_featured' => 'boolean',
        'options' => 'array',
        'stock_quantity' => 'integer',
        'quantity_offers' => 'array',
        'upsell_ids' => 'array',
        'cross_sell_ids' => 'array',
        'is_preorder' => 'boolean',
        'preorder_release_date' => 'date',
        'colors' => 'array',
        'custom_show' => 'boolean',
        'custom_fields' => 'array',
        'loves_count' => 'integer',
        'is_bestseller' => 'boolean',
        'video_urls' => 'array',
    ];

    public function scopeBestsellers(Builder $query): Builder
    {
        return $query->where('is_bestseller', true);
    }

    /** Normalised gallery videos: [{type:'youtube'|'file', embed, thumb, src}]. */
    public function galleryVideos(): array
    {
        return collect($this->video_urls ?? [])
            ->filter(fn ($u) => filled($u))
            ->map(fn ($u) => video_meta((string) $u))
            ->filter()
            ->values()->all();
    }

    /** Custom fields as a clean list: [{label, value, show}]. Includes the
     *  legacy single custom_label/value/show as the first entry for back-compat. */
    public function customFieldList(): array
    {
        $list = collect($this->custom_fields ?? [])
            ->map(fn ($f) => [
                'label' => trim((string) ($f['label'] ?? '')),
                'value' => trim((string) ($f['value'] ?? '')),
                'show' => (bool) ($f['show'] ?? false),
            ])
            ->filter(fn ($f) => $f['label'] !== '' && $f['value'] !== '')
            ->values();

        if (trim((string) $this->custom_label) !== '' && trim((string) $this->custom_value) !== '') {
            $list->prepend([
                'label' => trim((string) $this->custom_label),
                'value' => trim((string) $this->custom_value),
                'show' => (bool) $this->custom_show,
            ]);
        }

        return $list->all();
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug($product->name, $product->id);
            }
        });

        // Assign the next sequential serial on creation (1,2,3…). Internal — never shown on the storefront.
        static::creating(function (Product $product) {
            if (blank($product->serial)) {
                $product->serial = (int) static::withTrashed()->max('serial') + 1;
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 1;
        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.(++$i);
        }
        return $slug;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** All categories this product belongs to (for filtering & Meta catalog). */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('status', 'approved')->latest();
    }

    /** Resolved pre-order state: product flag OR its category default. */
    public function isPreorder(): bool
    {
        return (bool) $this->is_preorder || (bool) ($this->category?->is_preorder);
    }

    public function getAverageRatingAttribute(): ?float
    {
        $reviews = $this->relationLoaded('approvedReviews')
            ? $this->approvedReviews
            : $this->approvedReviews()->get(['rating']);

        return $reviews->isEmpty() ? null : round($reviews->avg('rating'), 1);
    }

    public function getReviewCountAttribute(): int
    {
        return $this->relationLoaded('approvedReviews')
            ? $this->approvedReviews->count()
            : $this->approvedReviews()->count();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('sku', 'like', "%{$term}%")
              ->orWhere('short_description', 'like', "%{$term}%");
        });
    }

    public function getThumbnailAttribute(): ?string
    {
        $image = $this->relationLoaded('images')
            ? ($this->images->firstWhere('is_primary', true) ?? $this->images->first())
            : ($this->primaryImage ?? $this->images()->first());

        return $image?->url;
    }

    public function getIsOnSaleAttribute(): bool
    {
        return $this->compare_at_price !== null && (float) $this->compare_at_price > (float) $this->price;
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if (! $this->is_on_sale) {
            return null;
        }
        return (int) round(100 - ($this->price / $this->compare_at_price * 100));
    }

    public function isAvailable(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }
        if (! $this->manage_stock) {
            return $this->in_stock;
        }
        return $this->stock_quantity > 0;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Tags as a clean array. */
    public function getTagListAttribute(): array
    {
        return collect(explode(',', (string) $this->tags))->map(fn ($t) => trim($t))->filter()->values()->all();
    }

    /** Colours as a clean array (used by the storefront colour filter). */
    public function getColorListAttribute(): array
    {
        return collect($this->colors ?? [])->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
    }

    /** Human product type. */
    public function getTypeLabelAttribute(): string
    {
        return $this->has_variants ? 'Variable' : 'Simple';
    }

    // ── Margin / profitability ──────────────────────────────────────────────

    /** Total landed unit cost = product cost + transportation/packaging. */
    public function getLandedCostAttribute(): float
    {
        return (float) $this->cost_price + (float) $this->transport_cost;
    }

    /** Profit per unit at the current selling price (price − landed cost). */
    public function getMarginAmountAttribute(): ?float
    {
        if ($this->cost_price === null && $this->transport_cost === null) {
            return null;
        }

        return round((float) $this->price - $this->landed_cost, 2);
    }

    /** Margin as a percent of the selling price (gross margin). Null if unknown. */
    public function getMarginPercentAttribute(): ?float
    {
        $margin = $this->margin_amount;
        if ($margin === null || (float) $this->price <= 0) {
            return null;
        }

        return round($margin / (float) $this->price * 100, 1);
    }

    // ── Quantity / bundle offers ────────────────────────────────────────────

    /**
     * Normalised, validated offer tiers, sorted by min_qty ascending.
     * Each tier: ['min_qty' => int>=2, 'percent' => float 0..90].
     *
     * @return array<int, array{min_qty:int, percent:float}>
     */
    public function offerTiers(): array
    {
        return collect($this->quantity_offers ?? [])
            ->map(fn ($t) => [
                'min_qty' => (int) ($t['min_qty'] ?? 0),
                'percent' => round((float) ($t['percent'] ?? 0), 2),
            ])
            ->filter(fn ($t) => $t['min_qty'] >= 2 && $t['percent'] > 0 && $t['percent'] <= 90)
            ->sortBy('min_qty')
            ->values()
            ->all();
    }

    /** Best discount percent that applies at a given quantity (0 if none). */
    public function offerPercentForQty(int $qty): float
    {
        $best = 0.0;
        foreach ($this->offerTiers() as $tier) {
            if ($qty >= $tier['min_qty']) {
                $best = max($best, $tier['percent']);
            }
        }

        return $best;
    }

    /** Effective unit price for a quantity after applying the best offer tier. */
    public function unitPriceForQty(int $qty, ?float $base = null): float
    {
        $base ??= (float) $this->price;
        $percent = $this->offerPercentForQty($qty);

        return round($base * (1 - $percent / 100), 2);
    }

    // ── Manual relationships (upsell / cross-sell) ──────────────────────────

    /** Published products listed as upsells ("You may also like"). */
    public function upsells(): \Illuminate\Support\Collection
    {
        return $this->loadRelatedByIds($this->upsell_ids);
    }

    /** Published products listed as cross-sells ("Frequently bought together"). */
    public function crossSells(): \Illuminate\Support\Collection
    {
        return $this->loadRelatedByIds($this->cross_sell_ids);
    }

    protected function loadRelatedByIds($ids): \Illuminate\Support\Collection
    {
        $ids = collect($ids ?? [])->filter()->map(fn ($i) => (int) $i)->reject(fn ($i) => $i === $this->id)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return static::published()->whereIn('id', $ids)->with('images')->get()
            ->sortBy(fn ($p) => $ids->search($p->id))->values();
    }
}
