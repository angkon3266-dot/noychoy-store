<?php

namespace App\Models;

use App\Jobs\SyncProductKnowledge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'serial', 'name', 'slug', 'sku', 'category_id', 'short_description', 'description',
        'price', 'compare_at_price', 'cost_price', 'transport_cost', 'manage_stock', 'stock_quantity',
        'in_stock', 'weight', 'has_variants', 'options', 'status', 'is_featured',
        'views', 'meta_title', 'meta_description',
        'quantity_offers', 'upsell_ids', 'cross_sell_ids',
        'is_preorder', 'preorder_release_date', 'preorder_note', 'tags', 'colors',
        'custom_label', 'custom_value', 'custom_show', 'custom_fields', 'loves_count',
        'is_bestseller', 'video_urls', 'content_sections',
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
        'price_drop_notified_at' => 'datetime',
        'colors' => 'array',
        'custom_show' => 'boolean',
        'custom_fields' => 'array',
        'loves_count' => 'integer',
        'is_bestseller' => 'boolean',
        'video_urls' => 'array',
        'content_sections' => 'array',
        'announced_at' => 'datetime',
        'preorder_announced_at' => 'datetime',
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
                $product->serial = static::nextSerial();
            }
        });

        // Keep the product's knowledge markdown in step with every save
        // (queued; skipped in tests so suites don't write files).
        static::saved(function (Product $product) {
            if (! app()->environment('testing')) {
                SyncProductKnowledge::dispatch($product->id);
            }

            // The catalogue filter sidebar is derived from every published
            // product, so it is cached — this is what keeps it honest.
            \App\Services\StorefrontFilters::bumpVersion();
        });

        static::deleted(fn () => \App\Services\StorefrontFilters::bumpVersion());
    }

    /**
     * The next free product serial.
     *
     * `$attempt` steps past a number a concurrent create already claimed. The
     * same pattern as Order::generateNumber(), and for the same reason: max()+1
     * is a read-then-write race, and production logged two
     * "Duplicate entry … for key products_serial_unique" 500s because of it.
     */
    public static function nextSerial(int $attempt = 0): int
    {
        return (int) static::withTrashed()->max('serial') + 1 + max(0, $attempt);
    }

    /**
     * Save a NEW product, stepping the serial forward if another request took
     * it between our max() and our insert. Retrying the same number would just
     * reproduce the same duplicate-key error, so each attempt moves on.
     */
    public function saveWithUniqueSerial(int $attempts = 5): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->save();
            } catch (UniqueConstraintViolationException $e) {
                // Only the serial index is ours to resolve — a duplicate SKU or
                // slug is the caller's problem and must still surface.
                if ($attempt >= $attempts - 1 || ! str_contains($e->getMessage(), 'serial')) {
                    throw $e;
                }
                $this->serial = static::nextSerial($attempt + 1);
                $this->exists = false;      // the failed insert left it half-set
            }
        }
    }

    /** Product::create(), but resilient to a concurrent serial claim. */
    public static function createUnique(array $attributes): static
    {
        $product = new static($attributes);
        $product->saveWithUniqueSerial();

        return $product;
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

    /**
     * Collections this product has been hand-pinned into.
     *
     * Fully qualified on purpose: this file imports Illuminate\Support\Collection,
     * so a bare `Collection::class` here would silently resolve to the support
     * collection and fail at runtime rather than at parse time.
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Collection::class);
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

    /** Meta catalog sync state rows (one per synced item — product + variants). */
    public function metaSyncStates(): HasMany
    {
        return $this->hasMany(MetaSyncState::class);
    }

    /**
     * Aggregate Meta status for the product page badge:
     * synced (all items synced) | pending | failed | never.
     */
    public function metaStatus(): string
    {
        $states = $this->relationLoaded('metaSyncStates') ? $this->metaSyncStates : $this->metaSyncStates()->get();

        if ($states->isEmpty()) {
            return MetaSyncState::STATUS_NEVER;
        }
        if ($states->contains('status', MetaSyncState::STATUS_FAILED)) {
            return MetaSyncState::STATUS_FAILED;
        }
        if ($states->contains('status', MetaSyncState::STATUS_PENDING)) {
            return MetaSyncState::STATUS_PENDING;
        }
        if ($states->every(fn ($s) => $s->status === MetaSyncState::STATUS_SYNCED)) {
            return MetaSyncState::STATUS_SYNCED;
        }

        return MetaSyncState::STATUS_PENDING;
    }

    /** Most recent Meta sync timestamp, or null. */
    public function metaLastSyncedAt(): ?Carbon
    {
        $states = $this->relationLoaded('metaSyncStates') ? $this->metaSyncStates : $this->metaSyncStates()->get();

        return $states->max('last_synced_at') ? Carbon::parse($states->max('last_synced_at')) : null;
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('status', 'approved')->latest();
    }

    /**
     * Google product taxonomy for the catalogue feeds: this product's category,
     * then any parent category, then the store-wide default. Blank is valid —
     * Meta just shows "Google product category: Missing" for the item.
     */
    public function googleCategory(): ?string
    {
        for ($cat = $this->category; $cat; $cat = $cat->parent) {
            if (filled($cat->google_category)) {
                return $cat->google_category;
            }
        }

        return config('meta.defaults.google_product_category')
            ?: (Setting::get('google_product_category') ?: null);
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
                ->orWhere('short_description', 'like', "%{$term}%")
                // Searching "gift" used to return nothing, because the word
                // lives in the tags and the long description, not the name.
                ->orWhere('tags', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%"));
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

    /** Offer types an admin can pick per tier. */
    public const OFFER_TYPES = ['percent', 'amount', 'unit_price'];

    /**
     * Normalised, validated offer tiers, sorted by min_qty ascending.
     *
     * Every tier resolves to a `percent` off the unit price whatever the admin
     * chose — a ৳-amount or a fixed unit price is converted against the product's
     * price — so the cart, checkout and product-page maths all stay percent-based.
     * The display fields (title/badge/highlight) ride along for the storefront.
     *
     * A tier with min_qty 1 is simply a discount on this product — it needs no
     * bundle. A pre-order-only tier disappears the moment the product stops
     * being a pre-order, so a "prebook and save" offer expires by itself.
     *
     * @return array<int, array{min_qty:int, percent:float, type:string, value:float, title:string, badge:string, highlight:bool, preorder_only:bool, label:string, save_each:float}>
     */
    public function offerTiers(): array
    {
        $price = (float) $this->price;
        $isPreorder = $this->isPreorder();

        return collect($this->quantity_offers ?? [])
            ->map(function ($t) use ($price) {
                $type = in_array($t['type'] ?? null, self::OFFER_TYPES, true) ? $t['type'] : 'percent';
                // Rows saved before offer types existed carry `percent` only.
                $value = (float) ($t['value'] ?? $t['percent'] ?? 0);

                $percent = match ($type) {
                    'amount' => $price > 0 ? $value / $price * 100 : 0,
                    'unit_price' => $price > 0 ? (1 - $value / $price) * 100 : 0,
                    default => $value,
                };
                $percent = round(max(0, min(90, $percent)), 2);

                $tier = [
                    'min_qty' => max(1, (int) ($t['min_qty'] ?? 1)),
                    'type' => $type,
                    'value' => round($value, 2),
                    'percent' => $percent,
                    'title' => trim((string) ($t['title'] ?? '')),
                    'badge' => trim((string) ($t['badge'] ?? '')),
                    'highlight' => (bool) ($t['highlight'] ?? false),
                    'preorder_only' => (bool) ($t['preorder_only'] ?? false),
                    'save_each' => round($price * $percent / 100, 2),
                ];
                $tier['label'] = $tier['title'] !== '' ? $tier['title'] : self::autoOfferLabel($tier);

                return $tier;
            })
            ->filter(fn ($t) => $t['percent'] > 0 && (! $t['preorder_only'] || $isPreorder))
            ->sortBy('min_qty')
            ->values()
            ->all();
    }

    /** Fallback headline when the admin didn't write a title. */
    protected static function autoOfferLabel(array $tier): string
    {
        $trim = fn (float $n) => rtrim(rtrim(number_format($n, 2), '0'), '.');
        $pre = ! empty($tier['preorder_only']);

        // min_qty 1 means "no bundle needed" — phrase it as a plain discount.
        if ($tier['min_qty'] <= 1) {
            return match ($tier['type']) {
                'amount' => ($pre ? 'Pre-order and save ' : 'Save ').money($tier['value']).' each',
                'unit_price' => ($pre ? 'Pre-order price: ' : 'Yours at ').money($tier['value']).' each',
                default => ($pre ? 'Pre-order now and get ' : 'Get ').$trim($tier['percent']).'% off',
            };
        }

        $buy = $pre ? 'Pre-order '.$tier['min_qty'].'+' : 'Buy '.$tier['min_qty'].'+';

        return match ($tier['type']) {
            'amount' => $buy.' & save '.money($tier['value']).' each',
            'unit_price' => $buy.' at '.money($tier['value']).' each',
            default => $buy.' & get '.$trim($tier['percent']).'% off',
        };
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
    public function upsells(): Collection
    {
        return $this->loadRelatedByIds($this->upsell_ids);
    }

    /** Published products listed as cross-sells ("Frequently bought together"). */
    public function crossSells(): Collection
    {
        return $this->loadRelatedByIds($this->cross_sell_ids);
    }

    protected function loadRelatedByIds($ids): Collection
    {
        $ids = collect($ids ?? [])->filter()->map(fn ($i) => (int) $i)->reject(fn ($i) => $i === $this->id)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return static::published()->whereIn('id', $ids)->with('images', 'approvedReviews', 'category')->get()
            ->sortBy(fn ($p) => $ids->search($p->id))->values();
    }
}
