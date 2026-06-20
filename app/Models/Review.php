<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Review extends Model
{
    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'hidden' => 'Hidden'];

    protected $fillable = [
        'product_id', 'customer_id', 'author_name', 'phone', 'rating',
        'title', 'body', 'photos', 'is_verified_buyer', 'status',
    ];

    protected $casts = [
        'photos' => 'array',
        'rating' => 'integer',
        'is_verified_buyer' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** Public URLs for any attached photos. */
    public function getPhotoUrlsAttribute(): array
    {
        return collect($this->photos ?? [])
            ->map(fn ($p) => Str::startsWith($p, ['http://', 'https://']) ? $p : Storage::disk('public')->url($p))
            ->all();
    }
}
