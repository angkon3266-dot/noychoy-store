<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Shop the look: one photo with products pinned on it (Nov 2026).
 *
 * @property array|null $hotspots [{x: float, y: float, product_id: int}]
 */
class Look extends Model
{
    protected $fillable = ['title', 'caption', 'image', 'hotspots', 'is_active', 'position'];

    protected $casts = [
        'hotspots' => 'array',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('position')->orderByDesc('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http') ? $this->image : Storage::disk('public')->url($this->image);
    }

    /** Hotspots cleaned up: numeric, inside the photo, one product each. */
    public function cleanHotspots(): array
    {
        return collect($this->hotspots ?? [])
            ->filter(fn ($h) => is_array($h) && is_numeric($h['x'] ?? null) && is_numeric($h['y'] ?? null) && (int) ($h['product_id'] ?? 0) > 0)
            ->map(fn ($h) => [
                'x' => round(max(0, min(100, (float) $h['x'])), 2),
                'y' => round(max(0, min(100, (float) $h['y'])), 2),
                'product_id' => (int) $h['product_id'],
            ])
            ->values()->all();
    }
}
