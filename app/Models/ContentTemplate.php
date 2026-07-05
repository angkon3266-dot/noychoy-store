<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable, named arrangement of product-page "story sections"
 * (image + heading + text blocks). Snapshots — applying one copies its sections
 * onto a product; saving one copies a product's sections here.
 */
class ContentTemplate extends Model
{
    protected $fillable = ['name', 'sections'];

    protected $casts = ['sections' => 'array'];

    /** Normalise a raw sections array to clean blocks. */
    public static function cleanSections($raw): array
    {
        return collect(is_array($raw) ? $raw : [])
            ->map(fn ($s) => [
                'image' => trim((string) ($s['image'] ?? '')),
                'heading' => trim((string) ($s['heading'] ?? '')),
                'body' => trim((string) ($s['body'] ?? '')),
                'layout' => ($s['layout'] ?? 'right') === 'left' ? 'left' : 'right',
            ])
            ->filter(fn ($s) => $s['image'] !== '' || $s['heading'] !== '' || $s['body'] !== '')
            ->values()->all();
    }
}
