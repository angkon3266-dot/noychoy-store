<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Names, descriptions and specs brought in line with the photographs
 * (3 Oct 2026): wrong colours in names, tassels that are not tassels, set
 * contents, and "Silver Plated" where the piece is rhodium. Every change is
 * guarded, so whatever the owner has rewritten since wins.
 */
class ListingsMatchTheirPhotosTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_03_120000_listings_match_their_photos.php');
    }

    /** A product at its production id — the migration's addressing scheme. */
    private function product(int $id, array $attrs, array $alts = []): Product
    {
        $p = Product::create(array_merge([
            'slug' => 'piece-'.$id, 'price' => 1000, 'status' => 'published', 'in_stock' => true,
        ], $attrs));
        Product::whereKey($p->id)->update(['id' => $id]);

        foreach ($alts as $i => $alt) {
            Product::find($id)->images()->create(['path' => "products/$id-$i.webp", 'alt' => $alt, 'position' => $i]);
        }

        return Product::find($id);
    }

    private function emerald(array $overrides = []): Product
    {
        return $this->product(266, array_merge([
            'name' => 'Emerald Radiance Necklace Set',
            'short_description' => 'Luxurious green zircon jewelry set for women.',
            'description' => "\"Green fire at the throat, the wrist, the ear — one color, told three ways.\"\r\n\r\n"
                ."## Design\r\n\r\n"
                ."- A complete coordinated set — necklace, earrings and bracelet — in a flattering emerald-green hue that complements all skin tones.\r\n"
                .'- Reach for it on special occasions, or let it carry elevated everyday sophistication.',
        ], $overrides), ['Emerald Radiance Necklace Set', 'A custom alt']);
    }

    public function test_a_wrong_colour_in_the_name_is_corrected_everywhere_it_is_written(): void
    {
        $this->emerald();

        $this->migration()->up();

        $p = Product::with('images')->find(266);
        $this->assertSame('Aqua Radiance Necklace Set', $p->name);
        $this->assertSame('Luxurious aqua-blue zircon jewelry set for women.', $p->short_description);
        // The set the owner confirmed: a ring, not a bracelet.
        $this->assertStringContainsString('necklace, earrings and ring — in a fresh aqua-blue hue', $p->description);
        $this->assertStringContainsString('"Aqua fire at the throat, the hand, the ear', $p->description);
        // Lines it does not know about are left exactly as they were.
        $this->assertStringContainsString('- Reach for it on special occasions', $p->description);
        // Only the alt that was the old name follows it.
        $this->assertSame(['Aqua Radiance Necklace Set', 'A custom alt'], $p->images->pluck('alt')->all());
        // The URL does not move.
        $this->assertSame('piece-266', $p->slug);
    }

    public function test_whatever_the_owner_rewrote_since_wins(): void
    {
        $this->emerald([
            'name' => 'Sea Glass Necklace Set',   // hers
            'description' => '- A complete coordinated set — necklace, earrings and bracelet — in a flattering emerald-green hue that complements all skin tones.',
        ]);
        Product::find(266)->update(['description' => 'Her own words.']);

        $this->migration()->up();

        $p = Product::find(266);
        $this->assertSame('Sea Glass Necklace Set', $p->name);
        $this->assertSame('Her own words.', $p->description);
        // A field she has not touched is still corrected.
        $this->assertSame('Luxurious aqua-blue zircon jewelry set for women.', $p->short_description);
    }

    public function test_specs_say_rhodium_and_platinum_where_the_photos_do(): void
    {
        $this->product(208, ['name' => 'Pearl Scallop CZ Stud Earrings', 'custom_fields' => [
            ['label' => 'Stone', 'value' => 'Cubic Zirconia & Pearl', 'show' => true],
            ['label' => 'Finish', 'value' => 'Silver Plated', 'show' => true],
        ]]);
        $this->product(278, ['name' => 'Platinum Layered Statement Cocktail Ring', 'custom_fields' => [
            ['label' => 'Metal', 'value' => 'Brass', 'show' => true],
            ['label' => 'Finish', 'value' => '18K Gold & Platinum Plated', 'show' => true],
        ]]);

        $this->migration()->up();

        $this->assertSame(
            ['Cubic Zirconia & Pearl', 'Rhodium Plated'],
            array_column(Product::find(208)->custom_fields, 'value'),
        );
        $this->assertSame(['Brass', 'Platinum Plated'], array_column(Product::find(278)->custom_fields, 'value'));
        $this->assertSame('Platinum Oval Statement Cocktail Ring', Product::find(278)->name);
    }

    public function test_a_renamed_colour_also_moves_its_filter_and_story_row(): void
    {
        $other = ['media' => 'auto', 'image' => '', 'heading' => 'Full Bloom', 'body' => 'Untouched.', 'layout' => 'left'];
        $this->product(314, [
            'name' => 'Crimson Blossom Cubic Zirconia Studs',
            'colors' => ['Red'],
            'content_sections' => [$other, [
                'media' => 'auto', 'image' => '', 'heading' => 'Rose-Red Depth', 'layout' => 'right',
                'body' => 'Deep rose-red cubic zirconia cover every petal, with pear-cut stones laid in a ring, and the dark setting behind them makes the colour look even richer.',
            ]],
        ]);

        $this->migration()->up();

        $p = Product::find(314);
        $this->assertSame('Magenta Blossom Cubic Zirconia Studs', $p->name);
        $this->assertSame(['Magenta'], $p->colors);
        $this->assertSame($other, $p->content_sections[0]);
        $this->assertSame('Deep Magenta', $p->content_sections[1]['heading']);
        $this->assertStringStartsWith('Deep magenta cubic zirconia', $p->content_sections[1]['body']);
    }

    public function test_running_twice_changes_nothing_and_down_puts_it_back(): void
    {
        $this->product(322, [
            'name' => 'Radiant Citrine Cubic Zirconia Ensemble',
            'description' => '"Wear a little bottled sunshine — at your ears and at your wrist."',
        ], ['Radiant Citrine Cubic Zirconia Ensemble']);

        $this->migration()->up();
        $this->migration()->up();

        $p = Product::with('images')->find(322);
        $this->assertSame('Radiant Citrine Cubic Zirconia Bracelet', $p->name);
        $this->assertSame('"Wear a little bottled sunshine — right at your wrist."', $p->description);

        $this->migration()->down();

        $p = Product::with('images')->find(322);
        $this->assertSame('Radiant Citrine Cubic Zirconia Ensemble', $p->name);
        $this->assertSame('"Wear a little bottled sunshine — at your ears and at your wrist."', $p->description);
        $this->assertSame(['Radiant Citrine Cubic Zirconia Ensemble'], $p->images->pluck('alt')->all());
    }
}
