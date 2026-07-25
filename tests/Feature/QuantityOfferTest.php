<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quantity offer tiers. Whatever type the admin picks, the tier must resolve to
 * a percent off the unit price — that's what the cart, the buy box and checkout
 * all run on — and the display fields must survive the round trip.
 */
class QuantityOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $offers): Product
    {
        $category = Category::create(['name' => 'Rings', 'slug' => 'rings']);

        return Product::create([
            'name' => 'Test Ring', 'slug' => 'test-ring', 'sku' => 'TR-1',
            'price' => 1000, 'category_id' => $category->id, 'is_published' => true,
            'stock_quantity' => 100, 'quantity_offers' => $offers,
        ]);
    }

    public function test_percent_amount_and_fixed_price_all_resolve_to_a_percent(): void
    {
        $tiers = $this->product([
            ['min_qty' => 2, 'type' => 'percent', 'value' => 5],
            ['min_qty' => 3, 'type' => 'amount', 'value' => 150],      // ৳150 off ৳1000
            ['min_qty' => 5, 'type' => 'unit_price', 'value' => 750],  // ৳750 each
        ])->offerTiers();

        $this->assertSame(5.0, $tiers[0]['percent']);
        $this->assertSame(15.0, $tiers[1]['percent']);
        $this->assertSame(25.0, $tiers[2]['percent']);
        $this->assertSame(250.0, $tiers[2]['save_each']);
    }

    public function test_titles_badges_and_auto_labels(): void
    {
        $tiers = $this->product([
            ['min_qty' => 2, 'type' => 'percent', 'value' => 10, 'title' => 'Grab a spare', 'badge' => 'POPULAR', 'highlight' => true],
            ['min_qty' => 4, 'type' => 'unit_price', 'value' => 800],
        ])->offerTiers();

        $this->assertSame('Grab a spare', $tiers[0]['label']);
        $this->assertSame('POPULAR', $tiers[0]['badge']);
        $this->assertTrue($tiers[0]['highlight']);
        // No title → an auto label describing the tier in the admin's own terms.
        $this->assertStringContainsString('Buy 4+ at', $tiers[1]['label']);
    }

    public function test_legacy_percent_only_rows_still_work(): void
    {
        // Tiers saved before offer types existed carry `percent` and nothing else.
        $tiers = $this->product([['min_qty' => 2, 'percent' => 12]])->offerTiers();

        $this->assertSame(12.0, $tiers[0]['percent']);
        $this->assertSame('percent', $tiers[0]['type']);
    }

    public function test_best_tier_wins_and_invalid_rows_are_dropped(): void
    {
        $product = $this->product([
            ['min_qty' => 2, 'type' => 'percent', 'value' => 5],
            ['min_qty' => 3, 'type' => 'percent', 'value' => 12],
            ['min_qty' => 1, 'type' => 'percent', 'value' => 50],   // min_qty < 2 → dropped
            ['min_qty' => 4, 'type' => 'unit_price', 'value' => 1200], // above price → no discount
        ]);

        $this->assertCount(2, $product->offerTiers());
        $this->assertSame(0.0, $product->offerPercentForQty(1));
        $this->assertSame(5.0, $product->offerPercentForQty(2));
        $this->assertSame(12.0, $product->offerPercentForQty(9));
        $this->assertSame(880.0, $product->unitPriceForQty(3));
    }
}
