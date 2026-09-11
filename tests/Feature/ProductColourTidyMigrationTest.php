<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colour tidy-up migration. It runs against the LIVE catalogue, so the
 * things worth pinning are its rules, not its results: fold spelling drift into
 * the established colour, fill only genuine blanks, and never overwrite a
 * colour the owner has set.
 */
class ProductColourTidyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_12_100000_tidy_product_colours.php';

    /**
     * RefreshDatabase has already run every migration by the time a test body
     * starts, so `artisan migrate` would be a no-op. Invoke up() directly, the
     * way TrustBadgeBanglaMigrationTest does.
     */
    private function migrate(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    private function product(string $slug, array $attrs = []): Product
    {
        $cat = Category::firstOrCreate(['slug' => 'c'], ['name' => 'C', 'is_active' => true]);

        return Product::create(array_merge([
            'name' => $slug, 'slug' => $slug, 'price' => 100,
            'status' => 'published', 'category_id' => $cat->id, 'in_stock' => true,
        ], $attrs));
    }

    public function test_spelling_drift_folds_into_the_established_colour(): void
    {
        $p = $this->product('drifted', ['colors' => ['Green', 'Golden']]);

        $this->migrate();

        $this->assertSame(['Green', 'Gold'], $p->fresh()->color_list);
    }

    public function test_casing_alone_is_drift_too(): void
    {
        $lower = $this->product('lower-orange', ['colors' => ['orange']]);
        $silver = $this->product('lower-silver', ['colors' => ['silver']]);

        $this->migrate();

        $this->assertSame(['Orange'], $lower->fresh()->color_list);
        $this->assertSame(['Silver'], $silver->fresh()->color_list);
    }

    public function test_a_product_holding_both_spellings_ends_with_one(): void
    {
        $p = $this->product('both', ['colors' => ['Gold', 'Golden']]);

        $this->migrate();

        $this->assertSame(['Gold'], $p->fresh()->color_list, 'the merge must not leave a duplicate');
    }

    public function test_an_uncoloured_product_gets_the_colour_its_photo_shows(): void
    {
        // Named "Sterling Silver", photographed on a gold band — the case that
        // rules out deriving colour from the product name.
        $opal = $this->product('925-sterling-silver-opal');
        $ring = $this->product('kyra-luminous-zircon-flower-ring');

        $this->migrate();

        $this->assertSame(['Gold', 'White'], $opal->fresh()->color_list);
        $this->assertSame(['Silver'], $ring->fresh()->color_list);
    }

    public function test_a_colour_the_owner_already_set_is_never_overwritten(): void
    {
        $p = $this->product('925-sterling-silver-opal', ['colors' => ['Purple']]);

        $this->migrate();

        $this->assertSame(['Purple'], $p->fresh()->color_list);
    }

    public function test_products_that_are_neither_drifted_nor_blank_are_left_alone(): void
    {
        $p = $this->product('fine', ['colors' => ['Turquoise', 'Multi']]);

        $this->migrate();

        $this->assertSame(['Turquoise', 'Multi'], $p->fresh()->color_list);
    }

    public function test_running_twice_changes_nothing_more(): void
    {
        $p = $this->product('drifted-twice', ['colors' => ['Golden']]);

        $this->migrate();
        $once = $p->fresh()->color_list;
        $this->migrate();

        $this->assertSame($once, $p->fresh()->color_list);
    }
}
