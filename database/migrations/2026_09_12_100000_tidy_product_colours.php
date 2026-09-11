<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Two tidy-ups to the storefront colour filter, both asked for by the owner.
 *
 * 1. SPELLING DRIFT. "Golden", "orange" and "silver" each sat on exactly one
 *    product and fragmented a filter that already had Gold, Orange and Silver.
 *    Folded into the existing value, matched case-insensitively so a later
 *    "GOLDEN" would be caught too.
 *
 * 2. EIGHT PRODUCTS WITH NO COLOUR AT ALL, so they were invisible to every
 *    colour filter. Each one below was decided by LOOKING AT ITS PHOTO, not by
 *    reading its name — which matters, because "925 Sterling Silver Opal" is
 *    photographed on a gold band, and a name-derived rule would have filed it
 *    under Silver where no shopper would find it.
 *
 *    The rule applied: the metal tone always, plus a stone colour only when the
 *    stones are not the clear/white CZ that most of this catalogue uses. Tagging
 *    every clear stone "White" would make that filter meaningless.
 *
 * Keyed by slug rather than id so the intent is readable, and only written when
 * the product still has no colour — if the owner has set one by the time this
 * runs, hers wins. Re-running changes nothing.
 */
return new class extends Migration
{
    /** Drifted value (lowercased) => the established colour it belongs to. */
    private const SYNONYMS = [
        'golden' => 'Gold',
        'gold plated' => 'Gold',
        'gold-plated' => 'Gold',
        'silver' => 'Silver',
        'sterling silver' => 'Silver',
        'orange' => 'Orange',
        'multicolor' => 'Multi',
        'multicolour' => 'Multi',
        'multi-color' => 'Multi',
    ];

    /** The eight uncoloured products, read off their own photographs. */
    private const ASSIGN = [
        // Gold setting, white pavé flowers.
        'golden-blossom-cubic-zirconia-earrings-2' => ['Gold'],
        // Red and purple stones in a gold setting.
        'multicolor-zircon-tassel-earrings-5' => ['Multi', 'Gold'],
        // Pink heart stone, silver-tone band.
        'rose-quartz-heart-promise-ring' => ['Pink', 'Silver'],
        'geometric-platinum-zircon-statement-ring-2' => ['Silver'],
        'luminous-zircon-platinum-cluster-ring' => ['Silver'],
        // Name says nothing; the photo is a silver-tone band with clear stones.
        'kyra-luminous-zircon-flower-ring' => ['Silver'],
        'luminous-gold-cubic-zircon-tennis-bracelet' => ['Gold'],
        // Named "Sterling Silver", photographed gold-plated with a white opal.
        '925-sterling-silver-opal' => ['Gold', 'White'],
    ];

    public function up(): void
    {
        $this->normaliseDrift();
        $this->fillMissing();
        $this->bumpFacetCache();
    }

    /** Fold spelling variants into the colour they are a variant of. */
    private function normaliseDrift(): void
    {
        Product::whereNotNull('colors')->chunkById(100, function ($products) {
            foreach ($products as $product) {
                $before = $product->color_list;
                if (empty($before)) {
                    continue;
                }

                $after = collect($before)
                    ->map(fn ($c) => self::SYNONYMS[mb_strtolower(trim((string) $c))] ?? trim((string) $c))
                    ->filter()
                    // A product carrying both "Gold" and "Golden" must end up
                    // with one Gold, not two.
                    ->unique()
                    ->values()
                    ->all();

                if ($after !== $before) {
                    $product->forceFill(['colors' => $after])->saveQuietly();
                }
            }
        });
    }

    /** Give the uncoloured eight the colour their photograph shows. */
    private function fillMissing(): void
    {
        foreach (self::ASSIGN as $slug => $colors) {
            $product = Product::where('slug', $slug)->first();

            // Hers wins: this only fills a genuine blank.
            if (! $product || ! empty($product->color_list)) {
                continue;
            }

            $product->forceFill(['colors' => $colors])->saveQuietly();
        }
    }

    /**
     * The facet list is cached for ten minutes per scope; without this the shop
     * keeps offering "Golden" until every one of those entries expires.
     */
    private function bumpFacetCache(): void
    {
        try {
            \App\Services\StorefrontFilters::bumpVersion();
        } catch (\Throwable $e) {
            // A cache store that is not reachable during a deploy is not a
            // reason to fail the migration — the entries expire on their own.
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. "Golden" and "Gold" are indistinguishable
        // once merged, and guessing which of the two a product started with
        // would corrupt rows this migration never touched.
    }
};
