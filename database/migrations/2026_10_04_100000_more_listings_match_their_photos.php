<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * The second round of listings brought in line with their photos (owner,
 * 4 Oct 2026: "yes fix those"), after 2026_10_03_120000.
 *
 *  - "Crimson" on fuchsia stones: #193 and #212 become Magenta; #329 becomes
 *    Magenta Geometric — its drop is a square over a soft-cornered diamond,
 *    not a cascade, and "Magenta Cascade" is already #305. Their colour
 *    filters and story rows follow.
 *  - #185 "Butterfly" is a bow (its stones are crimson, so that stays).
 *  - #253 is a silver-tone ring with a pavé rose and one yellow stone: not
 *    three stones, not yellow gold.
 *  - #215 is gold-plated, as photos 1–2 show (owner-confirmed), not white gold.
 *  - #214's turquoise charm is in no photo; its meta also named it "Azure".
 *
 * Slugs stay. Every change is guarded on the exact current text, exactly as in
 * the first round, so anything rewritten since wins and re-running changes
 * nothing; saved through the model so the catalogue and filters follow.
 */
return new class extends Migration
{
    /** Product id => field => [now, fixed]. */
    private const FIELDS = [
        193 => [
            'name' => ['Crimson Blossom Pearl Drops', 'Magenta Blossom Pearl Drops'],
            'meta_description' => [
                'Discover our Crimson Blossom Pearl Drop Earrings—exquisite red cubic zirconia flowers with lustrous pearls. Perfect for weddings and special occasions.',
                'Discover our Magenta Blossom Pearl Drop Earrings—exquisite magenta cubic zirconia flowers with lustrous pearls. Perfect for weddings and special occasions.',
            ],
        ],
        212 => [
            'name' => ['Crimson Botanica Statement Earrings', 'Magenta Botanica Statement Earrings'],
            'short_description' => ['Elegant red flower drop earrings for women.', 'Elegant magenta flower drop earrings for women.'],
            // Also: it is not gold, and the old text stopped mid-word.
            'meta_description' => [
                'Sophisticated gold drop earrings with brilliant micro-pavé cubic zirconia. Perfect for weddings and special occasions. Radiant, elegant jewelry that catches eve',
                'Magenta flower drop earrings with brilliant micro-pavé cubic zirconia and green-stone leaves on a dark stem. Perfect for weddings and special occasions.',
            ],
        ],
        329 => [
            'name' => ['Crimson Cascade Rhodium Drop Earrings', 'Magenta Geometric Rhodium Drop Earrings'],
            'short_description' => ['Luxurious red cubic zirconia statement earrings for elegant occasions.', 'Luxurious magenta cubic zirconia statement earrings for elegant occasions.'],
            'meta_description' => [
                'Discover luxurious Crimson Cascade Earrings—rhodium-plated cubic zirconia drops that capture light beautifully. Perfect for weddings and elegant occasions.',
                'Discover luxurious Magenta Geometric Earrings—rhodium-plated cubic zirconia drops that capture light beautifully. Perfect for weddings and elegant occasions.',
            ],
        ],
        185 => [
            'name' => ['Crimson Butterfly Pearl Drop Earrings', 'Crimson Bow Pearl Drop Earrings'],
            'short_description' => ['Elegant rhodium-plated zirconia butterfly drop earrings for women.', 'Elegant rhodium-plated zirconia bow drop earrings for women.'],
            'meta_description' => [
                'Discover exquisite rose red butterfly pearl drop earrings with brilliant zirconia. Premium rhodium-plated luxury jewelry for sophisticated elegance.',
                'Discover exquisite rose red bow pearl drop earrings with brilliant zirconia. Premium rhodium-plated luxury jewelry for sophisticated elegance.',
            ],
        ],
        253 => [
            'short_description' => ['Radiant three-stone zircon ring in elegant yellow gold.', 'Radiant zircon rose ring with a yellow stone, in a bright silver tone.'],
            'meta_description' => [
                'Discover our Celestial Zircon Rose Ring—stunning three-stone design with brilliant sparkle, adjustable fit, and timeless elegance. Shop now.',
                'Discover our Celestial Zircon Rose Ring—a pavé rose beside a yellow stone, with brilliant sparkle, adjustable fit, and timeless elegance. Shop now.',
            ],
        ],
        215 => [
            'meta_description' => [
                'Elegant white gold plated cubic zirconia bridal earrings. Luminous, sophisticated drops perfect for weddings. Luxury sparkle, accessible price.',
                'Elegant gold plated cubic zirconia bridal earrings. Luminous, sophisticated drops perfect for weddings. Luxury sparkle, accessible price.',
            ],
        ],
        214 => [
            'meta_description' => [
                'Discover Celestial Azure Drop Earrings—handcrafted cubic zirconia statement pieces in radiant sky blue and gold plating.',
                'Discover Celestial Turquoise Drop Earrings—handcrafted cubic zirconia statement pieces in radiant sky blue and gold plating.',
            ],
        ],
    ];

    /** Product id => description line now => the line fixed. */
    private const DESCRIPTION = [
        193 => [
            '"Red petals, white pearls — the kind of contrast a room remembers."' => '"Magenta petals, white pearls — the kind of contrast a room remembers."',
            '**The Crimson Blossom — a red flower letting one pearl fall.**' => '**The Magenta Blossom — a magenta flower letting one pearl fall.**',
            '- Delicate red flowers in sparkling stones, each finished with a dangling pearl accent — feminine, and light in motion.' => '- Delicate magenta flowers in sparkling stones, each finished with a dangling pearl accent — feminine, and light in motion.',
            '- Sparkling red cubic zirconia petals, elevated by soft pearl accents.' => '- Sparkling magenta cubic zirconia petals, elevated by soft pearl accents.',
        ],
        212 => [
            '**The Crimson Botanica — a red bloom in full statement.**' => '**The Magenta Botanica — a magenta bloom in full statement.**',
            '- Red flower drops in a long dangle silhouette — with a removable design you can restyle to mix your collection.' => '- Magenta flower drops in a long dangle silhouette — with a removable design you can restyle to mix your collection.',
        ],
        329 => [
            '"Red that arrives before you do, and lingers after you leave."' => '"Magenta that arrives before you do, and lingers after you leave."',
            '**The Crimson Cascade — bold scarlet light on cool rhodium.**' => '**The Magenta Geometric — bold magenta light on cool rhodium.**',
            '- Generously long drops whose graceful movement flatters every face shape' => '- A square top above a soft-cornered diamond with a round cut-out, a graphic drop whose movement flatters every face shape',
            '- A commanding red made for weddings, galas and sophisticated evening celebrations' => '- A commanding magenta made for weddings, galas and sophisticated evening celebrations',
            '- Brilliant red cubic zirconia with diamond-like clarity and radiance' => '- Brilliant magenta cubic zirconia with diamond-like clarity and radiance',
        ],
        185 => [
            '"A butterfly lands, a pearl falls — and the whole outfit follows."' => '"A bow is tied, a pearl falls — and the whole outfit follows."',
            '**The Crimson Butterfly — rose-red wings with a single pearl in tow.**' => '**The Crimson Bow — rose-red loops with a single pearl in tow.**',
            '- Rose-red butterflies in glittering stones, each releasing a single pearl drop below — feminine, sophisticated, quietly unmissable.' => '- Rose-red bows in glittering stones, each releasing a single pearl drop below — feminine, sophisticated, quietly unmissable.',
            '- Dazzling cubic zirconia across the wings, finished with a luminous pearl drop.' => '- Dazzling cubic zirconia across the bow, finished with a luminous pearl drop.',
        ],
        253 => [
            '"Past, present, and future — worn as three points of light."' => '"A rose in full bloom, with one drop of sunshine beside it."',
            '**The Celestial Rose — three stones opening into one warm-gold bloom.**' => '**The Celestial Rose — a pavé bloom beside one golden-yellow stone.**',
            '- A rose-shaped, three-stone silhouette in a warm yellow gold finish — romantic, distinctive, unlike any traditional cut.' => '- A pavé rose beside a cushion-cut yellow stone on an open band, in a bright silver-tone finish — romantic, distinctive, unlike any traditional cut.',
            '- The trio stands for past, present and future — made for marking meaningful moments.' => '- Two ends that meet on your finger — made for marking meaningful moments.',
        ],
        215 => [
            '- White gold plating over hypoallergenic materials — irritation-free through the longest special day.' => '- Gold plating over hypoallergenic materials — irritation-free through the longest special day.',
        ],
        214 => [
            '- A bold water-drop silhouette finished with a turquoise charm — eye-catching without ever trying too hard.' => '- A bold water-drop silhouette paved edge to edge in turquoise-blue stones — eye-catching without ever trying too hard.',
            '- Sky-blue cubic zirconia with a diamond-like sparkle, crowned by a turquoise charm.' => '- Sky-blue cubic zirconia with a diamond-like sparkle, set stone to stone across a gold-plated dome.',
        ],
    ];

    /** Product id => [spec label, value now, value fixed]. */
    private const SPECS = [
        253 => ['Finish', 'Yellow Gold', 'Silver Tone'],
        215 => ['Finish', 'White Gold Plated', 'Gold Plated'],
    ];

    /** Product id => [colour filter now, fixed]. */
    private const COLOURS = [
        193 => [['Multi', 'White', 'Gold'], ['Magenta', 'White', 'Gold']],
        212 => [['Rose', 'Green'], ['Magenta', 'Green']],
        329 => [['Red', 'Multi', 'Silver'], ['Magenta', 'Silver']],
    ];

    /** Product id => list of [story row now, fixed], matched on heading + body. */
    private const STORY = [
        193 => [
            [
                ['heading' => 'Curling Tendrils', 'body' => 'Pointed red-speckled petals open around curling gold-tone tendrils, and a round white pearl tucks in just below, so the flower seems to cradle it at your lobe.'],
                ['heading' => 'Curling Tendrils', 'body' => 'Pointed magenta-speckled petals open around curling gold-tone tendrils, and a round white pearl tucks in just below, so the flower seems to cradle it at your lobe.'],
            ],
            [
                ['heading' => 'Rosy on Gold', 'body' => 'Red and clear cubic zirconia trace each petal in a gold-tone setting, and the warm metal turns the red rosier while the pearl adds a soft, milky finish.'],
                ['heading' => 'Rosy on Gold', 'body' => 'Magenta and clear cubic zirconia trace each petal in a gold-tone setting, and the warm metal turns the magenta rosier while the pearl adds a soft, milky finish.'],
            ],
        ],
        329 => [
            [
                ['heading' => 'Polka Dot Sparkle', 'body' => 'Round berry-red cubic zirconia are scattered like polka dots across clear pavé on rhodium plating, and the cool white base makes each red dot look sharper.'],
                ['heading' => 'Polka Dot Sparkle', 'body' => 'Round magenta cubic zirconia are scattered like polka dots across clear pavé on rhodium plating, and the cool white base makes each magenta dot look sharper.'],
            ],
        ],
        185 => [
            [
                ['heading' => 'Wings Over Pearl', 'body' => 'Four red pear-shaped stones open like wings at the lobe, outlined in clear sparkle, and a short line of round stones lets one white pearl swing beneath.'],
                ['heading' => 'Bow Over Pearl', 'body' => 'Four red pear-shaped stones fan out into a bow at the lobe, outlined in clear sparkle, and a short line of round stones lets one white pearl swing beneath.'],
            ],
        ],
    ];

    public function up(): void
    {
        $this->fix(from: 0, to: 1);
    }

    public function down(): void
    {
        $this->fix(from: 1, to: 0);
    }

    private function fix(int $from, int $to): void
    {
        $ids = array_unique([
            ...array_keys(self::FIELDS), ...array_keys(self::DESCRIPTION),
            ...array_keys(self::SPECS), ...array_keys(self::COLOURS), ...array_keys(self::STORY),
        ]);

        foreach (Product::whereIn('id', $ids)->get() as $product) {
            $id = $product->id;
            $name = $product->name;

            foreach (self::FIELDS[$id] ?? [] as $field => $pair) {
                if ($product->{$field} === $pair[$from]) {
                    $product->{$field} = $pair[$to];
                }
            }

            if (isset(self::DESCRIPTION[$id]) && filled($product->description)) {
                $lines = self::DESCRIPTION[$id];
                $product->description = strtr($product->description, $from === 0 ? $lines : array_flip($lines));
            }

            if (isset(self::SPECS[$id])) {
                [$label, $now, $fixed] = self::SPECS[$id];
                $values = $from === 0 ? [$now, $fixed] : [$fixed, $now];
                $product->custom_fields = collect($product->custom_fields ?? [])
                    ->map(fn ($f) => ($f['label'] ?? null) === $label && ($f['value'] ?? null) === $values[0]
                        ? array_merge($f, ['value' => $values[1]])
                        : $f)
                    ->all();
            }

            if (isset(self::COLOURS[$id]) && $product->colors === self::COLOURS[$id][$from]) {
                $product->colors = self::COLOURS[$id][$to];
            }

            foreach (self::STORY[$id] ?? [] as $pair) {
                [$was, $becomes] = [$pair[$from], $pair[$to]];
                $product->content_sections = collect($product->content_sections ?? [])
                    ->map(fn ($s) => ($s['heading'] ?? null) === $was['heading'] && ($s['body'] ?? null) === $was['body']
                        ? array_merge($s, $becomes)
                        : $s)
                    ->all();
            }

            if ($product->isDirty()) {
                $product->save();
            }

            // Alt text that was the old name follows the new one.
            if ($product->name !== $name) {
                $product->images()->where('alt', $name)->update(['alt' => $product->name]);
            }
        }
    }
};
