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
        return match ($section['media']) {
            'none' => null,
            'image' => $section['image'] !== '' ? static::photo($section['image']) : null,
            default => $row === 0 && $video
                ? [
                    'type' => 'video',
                    'src' => $video['src'],
                    // Shown until the clip is in view and playing — and for
                    // good on Save-Data or a 2G connection, where it waits for
                    // a tap.
                    'poster' => $photos->isNotEmpty() ? static::photo($photos->first()) : null,
                ]
                : (($url = $photos->get($row) ?? $photos->first()) ? static::photo($url) : null),
        };
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
