<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Names, descriptions and specs that promised something the photographs do not
 * show (owner, 3 Oct 2026: "fix them" — the list the story-row copywriting
 * turned up, each one re-checked against the product's own photos).
 *
 *  - Wrong colour in the name: #266 "Emerald" is aqua blue, #242 "Blue" is
 *    violet, #305 and #314 "Crimson" are magenta.
 *  - "Tassel" on pieces with no tassel: #241 is a chandelier of green drops,
 *    #244 red flowers over a cluster of red drops, #250 a short two-stone drop,
 *    #324 and #325 fringes of teardrops. #244 and #325 promised silk.
 *  - #278 "Layered" holds one oval stone, and its finish claimed gold as well as
 *    platinum on a ring photographed white.
 *  - Set contents, as the owner confirmed: #266 and #271 are necklace, earrings
 *    and ring (not bracelet); #322 is a bracelet only (not earrings too).
 *  - #208, #216, #276: the Details table said "Silver Plated" while their own
 *    descriptions say rhodium — the wording the owner settled on 12 Sep 2026.
 *
 * Slugs are left alone, so every link, ad and catalogue URL keeps working.
 *
 * Every change is guarded on what is there now: a whole field (name, short and
 * meta descriptions) changes only while it is still exactly the old text; the
 * description changes line by line, only where the old line is still there; a
 * spec row only while it still says the old value. Whatever the owner has
 * rewritten since wins, and re-running changes nothing. Saved through the
 * model, so the Meta catalogue, the assistant's knowledge and the filter cache
 * all follow, as they would for an edit in the admin.
 */
return new class extends Migration
{
    /** Product id => field => [now, fixed]. */
    private const FIELDS = [
        266 => [
            'name' => ['Emerald Radiance Necklace Set', 'Aqua Radiance Necklace Set'],
            'short_description' => ['Luxurious green zircon jewelry set for women.', 'Luxurious aqua-blue zircon jewelry set for women.'],
            'meta_description' => [
                'Discover our exquisite emerald zircon jewelry set. Brilliant sparkle, timeless elegance, complete coordinated ensemble for sophisticated women.',
                'Discover our exquisite aqua-blue zircon jewelry set. Brilliant sparkle, timeless elegance, complete coordinated ensemble for sophisticated women.',
            ],
        ],
        242 => [
            'name' => ['Bohemian Blue Zircon Flower Earrings', 'Bohemian Violet Zircon Flower Earrings'],
            'short_description' => ['Radiant bohemian florals adorned with stunning blue zircon stones.', 'Radiant bohemian florals adorned with stunning violet zircon stones.'],
            'meta_description' => [
                'Discover our exquisite bohemian flower earrings featuring radiant blue zircon stones. Perfect for elegant, artistic women seeking sophisticated statement jewelry.',
                'Discover our exquisite bohemian flower earrings featuring radiant violet zircon stones. Perfect for elegant, artistic women seeking sophisticated statement jewelry.',
            ],
        ],
        305 => [
            'name' => ['Crimson Cascade Bridal Earrings', 'Magenta Cascade Bridal Earrings'],
            'short_description' => ['Radiant red cubic zirconia statement drops for elegant occasions.', 'Radiant magenta cubic zirconia statement drops for elegant occasions.'],
            'meta_description' => [
                'Discover radiant crimson cubic zirconia bridal earrings with sophisticated drops. Premium sparkle and elegance for your most memorable occasions.',
                'Discover radiant magenta cubic zirconia bridal earrings with sophisticated drops. Premium sparkle and elegance for your most memorable occasions.',
            ],
        ],
        314 => [
            'name' => ['Crimson Blossom Cubic Zirconia Studs', 'Magenta Blossom Cubic Zirconia Studs'],
            'short_description' => ['Radiant rose red florals, exquisite cubic zirconia brilliance.', 'Radiant magenta florals, exquisite cubic zirconia brilliance.'],
            'meta_description' => [
                'Discover elegant rose red cubic zirconia flower studs—brilliant sparkle, sophisticated design, versatile elegance for every occasion.',
                'Discover elegant magenta cubic zirconia flower studs—brilliant sparkle, sophisticated design, versatile elegance for every occasion.',
            ],
        ],
        241 => [
            'name' => ['Vintage Tassel Zircon Drop Earrings', 'Vintage Chandelier Zircon Drop Earrings'],
            'short_description' => ['Elegant vintage-inspired earrings with cascading zircon tassels.', 'Elegant vintage-inspired chandelier earrings with cascading zircon drops.'],
            'meta_description' => [
                'Discover elegant vintage tassel drop earrings with sparkling zircon stones. Timeless design meets modern sophistication—perfect for refined taste.',
                'Discover elegant vintage chandelier drop earrings with sparkling zircon stones. Timeless design meets modern sophistication—perfect for refined taste.',
            ],
        ],
        244 => [
            'name' => ['Crimson Zircon Tassel Statement Earrings', 'Crimson Zircon Cluster Statement Earrings'],
            'short_description' => ['Radiant red zircon drops with elegant silk tassels.', 'Radiant red zircon flowers above a cluster of red drops.'],
            'meta_description' => [
                'Discover crimson zircon tassel earrings—exquisite sparkle meets refined elegance. Handcrafted luxury for the discerning woman. Shop premium statement jewelry.',
                'Discover crimson zircon cluster earrings—exquisite sparkle meets refined elegance. Handcrafted luxury for the discerning woman. Shop premium statement jewelry.',
            ],
        ],
        250 => [
            'name' => ['Multicolor Zircon Tassel Earrings', 'Multicolor Zircon Drop Earrings'],
            // Also stops calling gold plating "18k gold".
            'meta_description' => [
                'Discover our exquisite multicolor zircon tassel earrings in 18k gold. Radiant, elegant, and perfect for those who appreciate refined luxury jewelry.',
                'Discover our exquisite multicolor zircon drop earrings with 18k gold plating. Radiant, elegant, and perfect for those who appreciate refined luxury jewelry.',
            ],
        ],
        324 => [
            'name' => ['Purple Tassel Necklace and Earrings Set', 'Purple Teardrop Necklace and Earrings Set'],
            'short_description' => [
                'This stunning jewelry set pairs sparkling cubic zirconia stones with an elegant long tassel design',
                'This stunning jewelry set pairs sparkling cubic zirconia stones with an elegant fringe of purple teardrops',
            ],
        ],
        325 => [
            'name' => ['Marquise Leaf Pink Tassel Earrings', 'Marquise Leaf Pink Chandelier Earrings'],
            'short_description' => ['Elegant cubic zirconia drop earrings with luxurious pink tassel detailing.', 'Elegant cubic zirconia chandelier earrings with a fringe of pink teardrops.'],
            'meta_description' => [
                'Discover exquisite marquise leaf earrings with brilliant cubic zirconia and luxurious pink tassel detailing. Elegant, timeless sophistication.',
                'Discover exquisite marquise leaf earrings with brilliant cubic zirconia and a fringe of pink teardrops. Elegant, timeless sophistication.',
            ],
        ],
        278 => [
            'name' => ['Platinum Layered Statement Cocktail Ring', 'Platinum Oval Statement Cocktail Ring'],
            'short_description' => ['Luxurious multi-layer oval CZ statement ring.', 'Luxurious oval CZ statement ring on an open band.'],
            'meta_description' => [
                'Elevate your look with our Platinum Layered Statement Cocktail Ring—luxury brass with brilliant CZ stones and sophisticated multi-layer design.',
                'Elevate your look with our Platinum Oval Statement Cocktail Ring—luxury brass with a brilliant oval CZ stone on a sleek open band.',
            ],
        ],
        322 => [
            'name' => ['Radiant Citrine Cubic Zirconia Ensemble', 'Radiant Citrine Cubic Zirconia Bracelet'],
            'short_description' => ['Luminous yellow crystal jewelry for refined elegance.', 'Luminous yellow crystal bracelet for refined elegance.'],
            'meta_description' => [
                'Radiant yellow cubic zirconia earrings and bracelet set. Lab-created brilliance for bridal elegance. Premium quality, hypoallergenic metals. Shop luxury jewelry today.',
                'Radiant yellow cubic zirconia bracelet. Lab-created brilliance for bridal elegance. Premium quality, hypoallergenic metals. Shop luxury jewelry today.',
            ],
        ],
    ];

    /** Product id => description line now => the line fixed. */
    private const DESCRIPTION = [
        266 => [
            '"Green fire at the throat, the wrist, the ear — one color, told three ways."' => '"Aqua fire at the throat, the hand, the ear — one colour, told three ways."',
            '**Emerald Radiance — a coordinated ensemble in deep zircon green.**' => '**Aqua Radiance — a coordinated ensemble in bright zircon aqua.**',
            '- A complete coordinated set — necklace, earrings and bracelet — in a flattering emerald-green hue that complements all skin tones.' => '- A complete coordinated set — necklace, earrings and ring — in a fresh aqua-blue hue that complements all skin tones.',
        ],
        242 => [
            '"Flowers this blue only grow in imaginations — and now on you."' => '"Flowers this violet only grow in imaginations — and now on you."',
            "**The Bohemian Bloom — blue zircon petals with an artist's temperament.**" => "**The Bohemian Bloom — clear zircon petals and violet drops with an artist's temperament.**",
            '- Vibrant blue zircon stones radiating brilliant sparkle and depth.' => '- Vibrant violet zircon drops beneath clear zircon petals, radiating brilliant sparkle and depth.',
        ],
        305 => [
            '"Red was never meant to be subtle — let it fall the full length of the evening."' => '"Magenta was never meant to be subtle — let it fall the full length of the evening."',
            '**The Crimson Cascade — bridal-length drops in the boldest colour of all.**' => '**The Magenta Cascade — bridal-length drops in the boldest colour of all.**',
            '- Elongated drops that cascade in rich crimson, creating graceful movement and a refined silhouette.' => '- Elongated drops that cascade in rich magenta, creating graceful movement and a refined silhouette.',
            '- A bridal statement for weddings and special celebrations — in a red that complements every skin tone beautifully.' => '- A bridal statement for weddings and special celebrations — in a magenta that complements every skin tone beautifully.',
            '- Lab-created cubic zirconia in deep red, with diamond-like sparkle and exceptional clarity.' => '- Lab-created cubic zirconia in deep magenta, with diamond-like sparkle and exceptional clarity.',
        ],
        314 => [
            '**The Crimson Blossom — botanical romance in rose red brilliance.**' => '**The Magenta Blossom — botanical romance in magenta brilliance.**',
            '- Ornate floral studs in a rich rose red that flatters warm and cool skin tones alike' => '- Ornate floral studs in a rich magenta that flatters warm and cool skin tones alike',
        ],
        241 => [
            '**The Vintage Tassel — movement, memory and zircon shimmer.**' => '**The Vintage Chandelier — movement, memory and zircon shimmer.**',
            '- Cascading tassel drops on a vintage line that adds movement and dimension to any ensemble.' => '- Cascading green pear drops on a vintage chandelier line that adds movement and dimension to any ensemble.',
        ],
        244 => [
            '**The Crimson Tassel — silk movement crowned in deep red fire.**' => '**The Crimson Cluster — swaying drops crowned in deep red fire.**',
            '- Statement drops of brilliant crimson zircon finished with handcrafted silk tassels that move the moment you do.' => '- Statement drops of brilliant crimson zircon: haloed red flowers above a cluster of red teardrops that move the moment you do.',
        ],
        250 => [
            '**The Golden Tassel — 3A brilliance set swinging in 18k warmth.**' => '**The Golden Drop — 3A brilliance set swinging in 18k warmth.**',
            '- Graceful tassels arranging multicolor stones into one striking, sophisticated statement with real movement and dimension.' => '- A red trillion stone above a deep purple teardrop, two colours in one striking, sophisticated drop with real movement and dimension.',
        ],
        324 => [
            '"When you turn, the tassels turn a half-beat later — that is the drama."' => '"When you turn, the teardrops turn a half-beat later — that is the drama."',
            '**The Purple Tassel — regal colour, moving light, one complete look.**' => '**The Purple Teardrop — regal colour, moving light, one complete look.**',
            '- A coordinated necklace-and-earrings pairing where long tassels bring movement and contemporary flair to a classic silhouette' => '- A coordinated necklace-and-earrings pairing where a fringe of purple teardrops brings movement and contemporary flair to a classic silhouette',
        ],
        325 => [
            '"Leaves of light, with a whisper of pink silk trailing after."' => '"Leaves of light, with a fringe of pink trailing after."',
            '**The Marquise Leaf — sparkle above, soft pink tassels below.**' => '**The Marquise Leaf — sparkle above, soft pink teardrops below.**',
            '- Graceful marquise leaf drops finished with a cascading pink tassel for movement and contemporary elegance' => '- Graceful marquise leaf drops finished with a cascading fringe of pink teardrops for movement and contemporary elegance',
        ],
        278 => [
            '**The Layered Cocktail — tiers of oval fire stacked into one bold statement.**' => '**The Oval Cocktail — one oval of fire, raised into one bold statement.**',
            '- A multi-layer cocktail ring whose stacked construction adds dramatic dimension and sophistication to any ensemble.' => '- A cocktail ring with one large oval stone held high in four claws on an open band, adding dramatic dimension and sophistication to any ensemble.',
            '- Premium brass beneath 18K gold and platinum plating — luxurious finishes built for durability and lasting brilliance.' => '- Premium brass beneath platinum plating — a luxurious finish built for durability and lasting brilliance.',
            '- Brilliant oval-cut cubic zirconia stones with diamond-like sparkle at every layer.' => '- A brilliant oval-cut cubic zirconia stone with diamond-like sparkle from every angle.',
        ],
        271 => [
            '- A complete bridal collection — matching necklace, earrings and bracelet — in ocean blue, for cohesive wedding-day elegance.' => '- A complete bridal collection — matching necklace, earrings and ring — in ocean blue, for cohesive wedding-day elegance.',
        ],
        322 => [
            '"Wear a little bottled sunshine — at your ears and at your wrist."' => '"Wear a little bottled sunshine — right at your wrist."',
            '**The Radiant Citrine — a matched set glowing golden-yellow.**' => '**The Radiant Citrine — a bracelet glowing golden-yellow.**',
            '- A coordinated earrings-and-bracelet ensemble in a warm golden-yellow that flatters every complexion' => '- Clear pear-cut stones around one square golden-yellow centre, a warm glow that flatters every complexion',
        ],
    ];

    /** Product id => [spec label, value now, value fixed]. */
    private const SPECS = [
        208 => ['Finish', 'Silver Plated', 'Rhodium Plated'],
        216 => ['Finish', 'Silver Plated', 'Rhodium Plated'],
        276 => ['Finish', 'Silver Plated', 'Rhodium Plated'],
        278 => ['Finish', '18K Gold & Platinum Plated', 'Platinum Plated'],
    ];

    /** Product id => [colour filter now, fixed]. */
    private const COLOURS = [
        314 => [['Red'], ['Magenta']],
    ];

    /** Product id => [story row now, fixed] — the 3 Oct rows follow the rename. */
    private const STORY = [
        314 => [
            ['heading' => 'Rose-Red Depth', 'body' => 'Deep rose-red cubic zirconia cover every petal, with pear-cut stones laid in a ring, and the dark setting behind them makes the colour look even richer.'],
            ['heading' => 'Deep Magenta', 'body' => 'Deep magenta cubic zirconia cover every petal, with pear-cut stones laid in a ring, and the dark setting behind them makes the colour look even richer.'],
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

            if (isset(self::STORY[$id])) {
                [$was, $becomes] = [self::STORY[$id][$from], self::STORY[$id][$to]];
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
