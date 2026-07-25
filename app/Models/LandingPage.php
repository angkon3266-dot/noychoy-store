<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LandingPage extends Model
{
    protected $fillable = [
        'title', 'slug', 'is_published', 'product_ids', 'blocks',
        'show_header', 'show_footer', 'meta_title', 'meta_description', 'og_image', 'views',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'show_header' => 'boolean',
        'show_footer' => 'boolean',
        'product_ids' => 'array',
        'blocks' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (LandingPage $page) {
            if (blank($page->slug)) {
                $page->slug = static::uniqueSlug($page->title, $page->id);
            }
        });
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'landing';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Products attached to this page, in the chosen order. */
    public function products(): \Illuminate\Support\Collection
    {
        $ids = collect($this->product_ids ?? [])->map(fn ($i) => (int) $i)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Product::published()->whereIn('id', $ids)
            ->with('images', 'approvedReviews', 'category')->get()
            ->sortBy(fn ($p) => $ids->search($p->id))->values();
    }

    public function url(): string
    {
        return route('landing.show', $this->slug);
    }
}
