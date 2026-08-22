<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A curated group of products with its own page, image and menu slot.
 *
 * `type` mirrors CustomerSegment: 'smart' builds itself from `rules`, 'manual'
 * uses the picked list. A smart collection can still pin products — pinned
 * products are merged on top of the rule matches, which is how you force a
 * hero piece into an otherwise automatic collection.
 */
class Collection extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'image', 'type', 'match', 'rules', 'sort',
        'position', 'is_active', 'show_in_menu', 'meta_title', 'meta_description',
    ];

    protected $casts = [
        'rules' => 'array',
        'is_active' => 'boolean',
        'show_in_menu' => 'boolean',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        // Same contract as Category: a slug is generated only when blank, so
        // renaming a collection never breaks the URL people have already shared.
        static::saving(function (Collection $collection) {
            if (blank($collection->slug)) {
                $collection->slug = static::uniqueSlug($collection->name, $collection->id);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    /** Hand-picked products (the whole list for manual, pinned extras for smart). */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position')->orderBy('collection_product.position');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInMenu($query)
    {
        return $query->where('is_active', true)->where('show_in_menu', true);
    }

    public function isSmart(): bool
    {
        return $this->type !== 'manual';
    }

    // NOTE: deliberately no getRouteKeyName() override. The storefront route
    // declares {collection:slug} explicitly, while the admin binds by id like
    // every other admin screen — overriding it globally 404s /admin/…/{id}/edit.

    public function url(): string
    {
        return route('collection.show', $this->slug);
    }
}
