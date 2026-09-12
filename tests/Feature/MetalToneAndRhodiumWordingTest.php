<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colour filter's two fixes.
 *
 * Half the catalogue carried a stone colour and no metal tone, so Gold and
 * Silver — the two facets shoppers actually reach for — returned a fraction of
 * the pieces that are visibly one or the other. And three products described a
 * rhodium finish as silver.
 */
class MetalToneAndRhodiumWordingTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_09_12_140000_metal_tone_on_every_piece_and_rhodium_wording.php'
        );
    }

    private function product(int $id, array $attrs = []): Product
    {
        $cat = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings', 'is_active' => true]);

        $p = Product::create(array_merge([
            'name' => 'Piece '.$id, 'slug' => 'piece-'.$id, 'sku' => 'SKU-'.$id,
            'price' => 1000, 'status' => 'published', 'category_id' => $cat->id, 'in_stock' => true,
        ], $attrs));

        // The ids are the migration's whole addressing scheme, so they have to
        // be exact rather than whatever autoincrement hands out.
        Product::whereKey($p->id)->update(['id' => $id]);

        return Product::find($id);
    }

    // ── Metal tones ──────────────────────────────────────────────────────────

    public function test_a_stone_only_product_gains_the_metal_tone_from_its_photo(): void
    {
        $this->product(180, ['colors' => ['Blue']]);      // rhodium ring
        $this->product(183, ['colors' => ['Olive']]);     // gold bridal set

        $this->migration()->up();

        $this->assertEqualsCanonicalizing(['Blue', 'Silver'], Product::find(180)->color_list);
        $this->assertEqualsCanonicalizing(['Olive', 'Gold'], Product::find(183)->color_list);
    }

    public function test_a_product_that_already_has_a_tone_is_left_alone(): void
    {
        // The owner has since called it Gold; the migration must not argue.
        $this->product(180, ['colors' => ['Blue', 'Gold']]);

        $this->migration()->up();

        $this->assertEqualsCanonicalizing(['Blue', 'Gold'], Product::find(180)->color_list);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->product(180, ['colors' => ['Blue']]);

        $this->migration()->up();
        $first = Product::find(180)->color_list;
        $this->migration()->up();

        $this->assertSame($first, Product::find(180)->color_list);
    }

    public function test_a_blackened_setting_is_not_claimed_as_gold_or_silver(): void
    {
        // #314's setting is gunmetal; #256 is an antique blackened band. Putting
        // either in front of someone filtering for gold would be a lie.
        $this->product(314, ['colors' => ['Red']]);
        $this->product(256, ['colors' => ['Black', 'Red']]);

        $this->migration()->up();

        $this->assertSame(['Red'], Product::find(314)->color_list);
        $this->assertEqualsCanonicalizing(['Black', 'Red'], Product::find(256)->color_list);
    }

    // ── Rhodium wording ──────────────────────────────────────────────────────

    public function test_a_silver_plating_claim_becomes_rhodium(): void
    {
        $this->product(216, [
            'colors' => ['White'],
            'description' => '- Hypoallergenic silver plating on a lightweight build — kind to sensitive ears.',
        ]);
        $this->product(276, [
            'colors' => ['White'],
            'description' => '- Silver-plated brass construction pairs durability with a luxurious aesthetic.',
        ]);

        $this->migration()->up();

        $this->assertStringContainsString('rhodium plating', Product::find(216)->description);
        $this->assertStringNotContainsString('silver plating', Product::find(216)->description);
        $this->assertStringContainsString('Rhodium-plated brass', Product::find(276)->description);
    }

    public function test_the_short_description_is_reworded_too(): void
    {
        $this->product(208, [
            'colors' => ['White'],
            'short_description' => 'Sparkling silver-plated cubic zirconia pearl studs',
        ]);

        $this->migration()->up();

        $this->assertSame(
            'Sparkling rhodium-plated cubic zirconia pearl studs',
            Product::find(208)->short_description,
        );
    }

    public function test_a_genuine_sterling_silver_piece_keeps_its_wording(): void
    {
        // 925 sterling silver really is silver. Calling it rhodium would be a
        // false claim about what the customer is buying.
        $this->product(274, [
            'colors' => ['Green'],
            'description' => '- Authentic 925 sterling silver ensures lasting durability.',
        ]);

        $this->migration()->up();

        $this->assertStringContainsString('925 sterling silver', Product::find(274)->description);
    }

    public function test_silver_used_as_imagery_is_left_alone(): void
    {
        // Rhodium plating is silver in colour, so the picture the copy paints is
        // still true — and "a whisper in rhodium and pearl" is not English.
        $this->product(196, [
            'colors' => ['White'],
            'description' => '"A bride\'s whisper in silver and pearl — soft, certain, unforgettable."',
        ]);

        $this->migration()->up();

        $this->assertStringContainsString('whisper in silver and pearl', Product::find(196)->description);
    }
}
