<?php

namespace App\Support\Storefront;

use App\Models\Setting;

/**
 * The ring and bangle size charts the size finder reads (Nov 2026).
 *
 * The owner's own charts, edited in Admin → Products → Size chart. Suppliers
 * do not agree on sizes — on the owner's chart a 60 mm finger is a US 7,
 * where the textbook scale says US 9 — and a finder that answers from the
 * textbook sends the wrong ring. So each row is a size as it is sold here and
 * the measurement round the finger (or hand) it fits, in millimetres; the
 * finder works out the inside width from it.
 *
 * Until the owner saves a chart, the standard one applies: US ring sizes
 * (size 7 = 17.3 mm across) and the subcontinent's bangle sizes (2.2 = 2⅛″
 * across, each step ⅛″).
 */
class SizeChart
{
    public const KEY = 'size_charts';

    /** The owner's pictures: [kind => [slot => storage path]] (Setting). */
    public const IMAGES_KEY = 'size_chart_images';

    /**
     * Picture slots per kind: `measure` replaces the store's how-to-measure
     * photo in the finder's Measure tab; `chart` is the owner's own printed
     * chart, shown above the table in the Size chart tab.
     */
    public const IMAGE_SLOTS = ['measure', 'chart'];

    /** What a row's measurement may be, in mm round the finger / hand. */
    public const LIMITS = ['ring' => [30, 90], 'bangle' => [120, 300]];

    /** @return list<array{size: string, around: float}> */
    public static function rows(string $kind): array
    {
        $saved = (array) (Setting::get(self::KEY)[$kind] ?? []);
        $rows = static::clean($kind, $saved);

        return $rows ?: static::standard($kind);
    }

    public static function isCustom(string $kind): bool
    {
        return static::clean($kind, (array) (Setting::get(self::KEY)[$kind] ?? [])) !== [];
    }

    /** Storage paths of the owner's pictures for a kind, by slot (missing = none). */
    public static function imagePaths(string $kind): array
    {
        return array_filter((array) ((Setting::get(self::IMAGES_KEY) ?? [])[$kind] ?? []), 'filled');
    }

    /** @return array{measure: ?string, chart: ?string} public URLs */
    public static function images(string $kind): array
    {
        $paths = static::imagePaths($kind);

        return collect(self::IMAGE_SLOTS)->mapWithKeys(fn ($slot) => [$slot => theme_asset($paths[$slot] ?? null)])->all();
    }

    /** Every size on the chart, as typed ("7", "2.10"). */
    public static function sizes(string $kind): array
    {
        return array_column(static::rows($kind), 'size');
    }

    /**
     * Rows as the admin form posted them: blanks dropped, a size listed twice
     * kept once, numbers in range, smallest first.
     *
     * @return list<array{size: string, around: float}>
     */
    public static function clean(string $kind, array $rows): array
    {
        [$min, $max] = self::LIMITS[$kind] ?? [0, 0];

        return collect($rows)
            ->map(fn ($r) => [
                'size' => trim((string) ($r['size'] ?? '')),
                'around' => round((float) ($r['around'] ?? 0), 1),
            ])
            ->filter(fn ($r) => $r['size'] !== '' && $r['around'] >= $min && $r['around'] <= $max)
            ->unique('size')
            ->sortBy('around')
            ->values()
            ->all();
    }

    /** @return list<array{size: string, around: float}> */
    public static function standard(string $kind): array
    {
        if ($kind === 'bangle') {
            $across = ['2.2' => 54.0, '2.4' => 57.2, '2.6' => 60.3, '2.8' => 63.5, '2.10' => 66.7, '2.12' => 69.9, '2.14' => 73.0];

            return collect($across)
                ->map(fn ($mm, $size) => ['size' => (string) $size, 'around' => round($mm * M_PI, 1)])
                ->values()->all();
        }

        // US ring sizes: inside diameter = 11.63 + 0.8128 × size.
        return collect([5, 5.5, 6, 6.5, 7, 7.5, 8, 8.5, 9, 9.5, 10, 10.5, 11, 12])
            ->map(fn ($us) => ['size' => (string) $us, 'around' => round((11.63 + 0.8128 * $us) * M_PI, 1)])
            ->all();
    }
}
