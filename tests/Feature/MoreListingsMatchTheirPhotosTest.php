<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The second round of listings brought in line with their photos (4 Oct 2026). */
class MoreListingsMatchTheirPhotosTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_04_100000_more_listings_match_their_photos.php');
    }

    private function product(int $id, array $attrs): Product
    {
        $p = Product::create(array_merge(['slug' => 'piece-'.$id, 'price' => 1000, 'status' => 'published', 'in_stock' => true], $attrs));
        Product::whereKey($p->id)->update(['id' => $id]);

        return Product::find($id);
    }

    public function test_the_ring_says_silver_tone_and_two_stones(): void
    {
        $this->product(253, [
            'name' => 'Celestial Zircon Rose Statement Ring',
            'description' => "**The Celestial Rose — three stones opening into one warm-gold bloom.**\r\n\r\n- The trio stands for past, present and future — made for marking meaningful moments.",
            'custom_fields' => [['label' => 'Finish', 'value' => 'Yellow Gold', 'show' => true]],
        ]);

        $this->migration()->up();

        $p = Product::find(253);
        $this->assertSame('Silver Tone', $p->custom_fields[0]['value']);
        $this->assertStringContainsString('a pavé bloom beside one golden-yellow stone', $p->description);
        $this->assertStringNotContainsString('trio', $p->description);
    }

    public function test_a_renamed_colour_moves_its_filter_and_story_row_and_rolls_back(): void
    {
        $row = ['media' => 'auto', 'image' => '', 'heading' => 'Polka Dot Sparkle', 'layout' => 'right',
            'body' => 'Round berry-red cubic zirconia are scattered like polka dots across clear pavé on rhodium plating, and the cool white base makes each red dot look sharper.'];
        $this->product(329, [
            'name' => 'Crimson Cascade Rhodium Drop Earrings',
            'colors' => ['Red', 'Multi', 'Silver'],
            'content_sections' => [$row],
        ]);

        $this->migration()->up();

        $p = Product::find(329);
        $this->assertSame('Magenta Geometric Rhodium Drop Earrings', $p->name);
        $this->assertSame(['Magenta', 'Silver'], $p->colors);
        $this->assertStringStartsWith('Round magenta cubic zirconia', $p->content_sections[0]['body']);

        $this->migration()->down();

        $p = Product::find(329);
        $this->assertSame('Crimson Cascade Rhodium Drop Earrings', $p->name);
        $this->assertSame(['Red', 'Multi', 'Silver'], $p->colors);
        $this->assertSame($row, $p->content_sections[0]);
    }
}
