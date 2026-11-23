<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductLove;
use App\Models\ProductVariant;
use App\Models\WishlistShare;
use App\Support\Storefront\WishlistPieces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The shareable wishlist (Nov 2026): /wishlist for everyone, a share link,
 * and "Add all to bag" with sizes.
 */
class WishlistTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, array $attrs = []): Product
    {
        return Product::create($attrs + [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
        ]);
    }

    protected function sizedRing(): array
    {
        $ring = $this->product('Halo Ring', [
            'category_id' => Category::create(['name' => 'Rings'])->id,
            'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['6', '7']]],
        ]);
        $six = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H6', 'attributes' => ['Size' => '6'], 'price' => 1000, 'stock_quantity' => 0, 'is_active' => true]);
        $seven = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H7', 'attributes' => ['Size' => '7'], 'price' => 1100, 'stock_quantity' => 3, 'is_active' => true]);

        return [$ring, $six, $seven];
    }

    public function test_a_piece_lists_its_sizes_with_stock_and_variant(): void
    {
        [$ring, $six, $seven] = $this->sizedRing();

        $piece = WishlistPieces::make($ring->fresh());

        $this->assertSame('Size', $piece['sizes']['name']);
        $this->assertSame([$six->id, $seven->id], array_column($piece['sizes']['values'], 'variant_id'));
        $this->assertSame([false, true], array_column($piece['sizes']['values'], 'in_stock'));
        $this->assertFalse($piece['needs_page']);
    }

    public function test_add_many_adds_a_sized_piece_with_its_variant_and_answers_json(): void
    {
        [$ring, , $seven] = $this->sizedRing();
        $plain = $this->product('Slim Bangle');

        $this->postJson(route('cart.add-many'), [
            'product_ids' => [$ring->id, $plain->id],
            'variants' => [$ring->id => $seven->id],
        ])->assertOk()->assertJsonPath('count', 2)->assertJsonPath('added', '2 pieces');

        // Without a variant the sized piece is still skipped, as before.
        $this->postJson(route('cart.add-many'), ['product_ids' => [$ring->id]])->assertStatus(422);
    }

    public function test_wishlist_shows_a_guests_loves_and_shares_them_by_code(): void
    {
        $a = $this->product('Pearl Studs');
        $b = $this->product('Opal Ring');
        ProductLove::create(['product_id' => $a->id, 'visitor_token' => 'tok-1']);
        ProductLove::create(['product_id' => $b->id, 'visitor_token' => 'tok-2']);

        $this->withCookie('visitor_token', 'tok-1')->get(route('wishlist'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Wishlist')->has('cards', 1)->where('cards.0.id', $a->id)->where('shared', null));

        $url = $this->withCredentials()->withCookie('visitor_token', 'tok-1')
            ->postJson(route('wishlist.share'), ['name' => 'Nusrat Jahan'])
            ->assertOk()->json('url');

        $share = WishlistShare::firstOrFail();
        $this->assertStringEndsWith('/wishlist/'.$share->code, $url);
        $this->assertStringNotContainsString('tok-1', $url);

        // Sharing again reuses the same link.
        $this->withCredentials()->withCookie('visitor_token', 'tok-1')->postJson(route('wishlist.share'))->assertOk();
        $this->assertSame(1, WishlistShare::count());

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Wishlist')->where('shared.owner', 'Nusrat')->has('cards', 1));
    }

    public function test_an_empty_wishlist_cannot_be_shared(): void
    {
        $this->withCredentials()->withCookie('visitor_token', 'nobody')->postJson(route('wishlist.share'))->assertStatus(422);
        $this->postJson(route('wishlist.share'))->assertStatus(422);
    }

    public function test_the_removed_style_tools_are_gone(): void
    {
        foreach (['/gift-finder', '/stack', '/try-on', '/looks'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.looks.index'));
        $this->assertArrayNotHasKey('looks', config('home.sections'));
    }
}
