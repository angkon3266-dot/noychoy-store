<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product IDs save one at a time as they're typed. The number is unique in the
 * database, so taking one that's in use has to swap rather than fail —
 * otherwise renumbering by hand deadlocks on the first collision.
 */
class ProductSerialTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    protected function product(string $name, ?int $serial): Product
    {
        $product = Product::create([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'published',
            'price' => 100, 'serial' => $serial,
        ]);

        // Product::creating assigns the next free number, so a test that wants
        // "no ID yet" has to clear it after the fact.
        if ($serial === null) {
            $product->forceFill(['serial' => null])->save();
        }

        return $product->fresh();
    }

    protected function setSerial(Product $product, $serial)
    {
        return $this->actingAs($this->admin())
            ->patchJson('/admin/products/'.$product->slug.'/serial', ['serial' => $serial]);
    }

    public function test_a_free_number_is_saved(): void
    {
        $p = $this->product('Ring', null);

        $this->setSerial($p, 7)->assertOk()->assertJson(['ok' => true, 'serial' => 7]);
        $this->assertSame(7, $p->fresh()->serial);
    }

    public function test_taking_a_used_number_swaps_the_two_products(): void
    {
        $a = $this->product('Ring', 1);
        $b = $this->product('Necklace', 2);

        $res = $this->setSerial($a, 2);

        $res->assertOk()->assertJson(['ok' => true, 'serial' => 2, 'swapped_with' => 'Necklace']);
        $this->assertSame(2, $a->fresh()->serial);
        $this->assertSame(1, $b->fresh()->serial);   // took A's old number
    }

    public function test_swapping_with_a_product_that_had_no_number_clears_it(): void
    {
        $a = $this->product('Ring', null);
        $b = $this->product('Necklace', 5);

        $this->setSerial($a, 5)->assertOk();

        $this->assertSame(5, $a->fresh()->serial);
        $this->assertNull($b->fresh()->serial);
    }

    public function test_an_empty_value_clears_the_number(): void
    {
        $p = $this->product('Ring', 3);

        $this->setSerial($p, null)->assertOk();
        $this->assertNull($p->fresh()->serial);
    }

    public function test_saving_the_same_number_is_a_no_op(): void
    {
        $p = $this->product('Ring', 4);

        $this->setSerial($p, 4)->assertOk()->assertJsonMissing(['swapped_with' => 'Ring']);
        $this->assertSame(4, $p->fresh()->serial);
    }

    public function test_a_number_below_one_is_rejected(): void
    {
        $p = $this->product('Ring', 3);

        $this->setSerial($p, 0)->assertStatus(422);
        $this->assertSame(3, $p->fresh()->serial);
    }

    public function test_a_deleted_product_still_releases_its_number(): void
    {
        $a = $this->product('Ring', null);
        $b = $this->product('Necklace', 9);
        $b->delete();

        $this->setSerial($a, 9)->assertOk();

        $this->assertSame(9, $a->fresh()->serial);
        $this->assertNull(Product::withTrashed()->find($b->id)->serial);
    }
}
