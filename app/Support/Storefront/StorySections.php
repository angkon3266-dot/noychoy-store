<?php

namespace App\Support\Storefront;

use App\Models\ContentTemplate;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The product page's story rows — large heading, short paragraph, a picture or
 * the product video beside it, alternating sides — with each row's picture
 * resolved for React.
 *
 * Mirrors the "Product story rows" section on the .com Shopify store
 * (sections/jewelry-product-story.liquid, 17 Sep 2026): a row set to `auto`
 * shows the product's own media, so the copy is all that has to be written
 * per product —
 *
 *   row 1  the product's first uploaded video (muted, looped, plays in view),
 *          else its first photo
 *   row N  its Nth photo, else its first
 *
 * Photos follow the gallery order (`position`), so reordering the gallery
 * reorders the story too. YouTube / Vimeo links are not used: an iframe cannot
 * play muted in the background the way the row needs.
 *
 * A row set to its own media (`image`) can hold a picture or a video file
 * dropped in the builder (4 Oct 2026); a video plays like the product's own,
 * with the row's product photo as its poster.
 */
class StorySections
{
    public static function for(Product $product): array
    {
        $sections = ContentTemplate::cleanSections($product->content_sections ?? []);
        if (! $sections) {
            return [];
        }

        $photos = $product->images->pluck('url')->filter()->values();
        $video = collect($product->galleryVideos())->firstWhere('type', 'file');

        return collect($sections)->map(fn ($s, $i) => [
            'heading' => $s['heading'],
            'body' => $s['body'],
            'layout' => $s['layout'],
            'media' => static::media($s, $i, $photos, $video),
        ])->all();
    }

    private static function media(array $section, int $row, Collection $photos, ?array $video): ?array
    {
        // The row's own product photo: what an `auto` row shows, and the
        // poster for a video dropped onto the row.
        $rowPhoto = $photos->get($row) ?? $photos->first();

        return match ($section['media']) {
            'none' => null,
            'image' => match (true) {
                $section['image'] === '' => null,
                static::isVideo($section['image']) => [
                    'type' => 'video',
                    'src' => $section['image'],
                    'poster' => $rowPhoto ? static::photo($rowPhoto) : null,
                ],
                default => static::photo($section['image']),
            },
            default => $row === 0 && $video
                ? [
                    'type' => 'video',
                    'src' => $video['src'],
                    // Shown until the clip is in view and playing — and for
                    // good on Save-Data or a 2G connection, where it waits for
                    // a tap.
                    'poster' => $photos->isNotEmpty() ? static::photo($photos->first()) : null,
                ]
                : ($rowPhoto ? static::photo($rowPhoto) : null),
        };
    }

    /** A section's own media can be a video file dropped in the builder. */
    public static function isVideo(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?.*)?$/i', $url);
    }

    /** Story images sit in a half-width column; the 900 variant is plenty. */
    private static function photo(string $url): array
    {
        return [
            'type' => 'image',
            'src' => image_variant($url, 900) ?: $url,
            'srcset' => image_srcset($url),
        ];
    }
}
