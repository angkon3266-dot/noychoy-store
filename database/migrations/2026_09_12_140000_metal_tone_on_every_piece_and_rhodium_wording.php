<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Two fixes to the colour filter, both asked for by the owner.
 *
 * 1. HALF THE CATALOGUE HAD NO METAL TONE. 52 of 106 published products carried
 *    a stone colour and nothing else, so "Gold" returned 20 pieces and "Silver"
 *    35 out of a catalogue where almost every piece is one or the other. The
 *    filter was not broken — the query matches the data exactly — the data was
 *    simply missing the two facets shoppers actually reach for.
 *
 *    The rule is the one the earlier tidy-up set: the metal tone always, read
 *    off the PHOTOGRAPH rather than the name. That distinction does real work
 *    here — "Golden Zircon Bloom Radiance Ring" (#330) is a rhodium-toned ring
 *    holding a golden stone, and a name-derived rule would have filed it under
 *    Gold where nobody would find it. Where the name states the finish and the
 *    photo cannot settle it, the name is used: #329 is missing its image file
 *    and is called "Crimson Cascade Rhodium Drop Earrings".
 *
 *    Four pieces are deliberately left alone. #212's metal is hidden behind
 *    enamel, and #256, #281 and #314 are blackened/gunmetal settings — calling
 *    any of those Gold or Silver would put a black ring in front of someone
 *    filtering for gold.
 *
 * 2. "SILVER PLATING" IS RHODIUM PLATING. Three products described their finish
 *    as silver when the pieces are rhodium-plated. Only the finish claims move;
 *    the genuinely sterling silver pieces (#273, #274, #275, #336) are left
 *    exactly as they are, because calling 925 sterling silver "rhodium" would
 *    be a false claim, and imagery like "a whisper in silver and pearl" is
 *    describing a colour, which rhodium plating still is.
 *
 * Only ever ADDS a metal tone, and only when the product has none: a colour the
 * owner has set herself always wins, and re-running changes nothing.
 */
return new class extends Migration
{
    /** Product id => metal tone, each read off that product's own photograph. */
    private const METAL = [
        179 => 'Silver', 180 => 'Silver', 181 => 'Silver', 183 => 'Gold',
        184 => 'Silver', 185 => 'Silver', 186 => 'Silver', 189 => 'Silver',
        190 => 'Gold',   192 => 'Gold',   193 => 'Gold',   197 => 'Silver',
        202 => 'Silver', 203 => 'Gold',   204 => 'Silver', 206 => 'Silver',
        209 => 'Gold',   210 => 'Silver', 213 => 'Gold',   214 => 'Gold',
        215 => 'Gold',   219 => 'Gold',   220 => 'Silver', 221 => 'Silver',
        247 => 'Gold',   252 => 'Gold',   262 => 'Silver', 264 => 'Gold',
        266 => 'Silver', 279 => 'Silver', 280 => 'Silver', 303 => 'Gold',
        305 => 'Silver', 306 => 'Gold',   308 => 'Gold',   309 => 'Silver',
        310 => 'Gold',   312 => 'Silver', 313 => 'Gold',   321 => 'Silver',
        322 => 'Silver', 324 => 'Silver', 325 => 'Silver', 326 => 'Gold',
        327 => 'Gold',   328 => 'Gold',   329 => 'Silver', 330 => 'Silver',
    ];

    /** Finish claims, longest first so "silver-plated" wins over "silver". */
    private const WORDING = [
        'Silver-plated' => 'Rhodium-plated',
        'silver-plated' => 'rhodium-plated',
        'Silver plating' => 'Rhodium plating',
        'silver plating' => 'rhodium plating',
    ];

    /** Really is sterling silver — never reworded. */
    private const STERLING = [273, 274, 275, 336];

    public function up(): void
    {
        $this->addMetalTones();
        $this->rewordPlating();
        $this->bumpFacetCache();
    }

    private function addMetalTones(): void
    {
        foreach (self::METAL as $id => $metal) {
            $product = Product::find($id);

            // Hers wins, and a piece that already has a tone is left alone.
            if (! $product || array_intersect($product->color_list, ['Gold', 'Silver'])) {
                continue;
            }

            $product->forceFill([
                'colors' => array_values(array_unique([...$product->color_list, $metal])),
            ])->saveQuietly();
        }
    }

    private function rewordPlating(): void
    {
        $products = Product::whereNotIn('id', self::STERLING)
            ->where(fn ($q) => $q->where('description', 'like', '%silver%')
                ->orWhere('short_description', 'like', '%silver%'))
            ->get();

        foreach ($products as $product) {
            $changed = [];

            foreach (['description', 'short_description'] as $field) {
                $before = (string) $product->{$field};
                if ($before === '') {
                    continue;
                }
                $after = strtr($before, self::WORDING);
                if ($after !== $before) {
                    $changed[$field] = $after;
                }
            }

            if ($changed) {
                $product->forceFill($changed)->saveQuietly();
            }
        }
    }

    /**
     * The facet list is cached per scope; without this the shop keeps offering
     * the old, thinner Gold and Silver lists until those entries expire.
     */
    private function bumpFacetCache(): void
    {
        try {
            \App\Services\StorefrontFilters::bumpVersion();
        } catch (\Throwable $e) {
            // A cache store that is unreachable mid-deploy is not a reason to
            // fail the migration — the entries expire on their own.
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. A metal tone added here is indistinguishable
        // from one the owner set herself, so removing them on a rollback would
        // strip work this migration never did.
    }
};
