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

    /**
     * What a section shows beside its copy:
     *  - auto  : the product's own media for that row — its video in the first
     *            row, then its 2nd, 3rd… photo (App\Support\Storefront\StorySections)
     *  - image : the uploaded / pasted `image`
     *  - none  : nothing, the copy runs centred on its own
     */
    public const MEDIA = ['auto', 'image', 'none'];

    /** Normalise a raw sections array to clean blocks. */
    public static function cleanSections($raw): array
    {
        return collect(is_array($raw) ? $raw : [])
            ->map(function ($s) {
                $image = trim((string) ($s['image'] ?? ''));
                $media = $s['media'] ?? null;

                // Sections saved before the choice existed: a picture meant a
                // custom image, no picture meant text only.
                if (! in_array($media, self::MEDIA, true)) {
                    $media = $image !== '' ? 'image' : 'none';
                }

                return [
                    'media' => $media,
                    'image' => $image,
                    'heading' => trim((string) ($s['heading'] ?? '')),
                    'body' => trim((string) ($s['body'] ?? '')),
                    'layout' => ($s['layout'] ?? 'right') === 'left' ? 'left' : 'right',
                ];
            })
            ->filter(fn ($s) => $s['image'] !== '' || $s['heading'] !== '' || $s['body'] !== '')
            ->values()->all();
    }
}
