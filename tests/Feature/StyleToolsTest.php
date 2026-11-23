<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Look;
use App\Models\Product;
use App\Models\ProductLove;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WishlistShare;
use App\Support\Storefront\StylePieces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The style tools (Nov 2026): shareable wishlist, gift quiz, stacker,
 * earring try-on, Shop the look, and sized pieces added as a set.
 */
class StyleToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name, ?Category $category = null, array $attrs = []): Product
    {
        return Product::create($attrs + [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
            'category_id' => $category?->id,
        ]);
    }

    protected function sizedRing(Category $rings): array
    {
        $ring = $this->product('Halo Ring', $rings, ['has_variants' => true, 'options' => [['name' => 'Size', 'values' => ['6', '7']]]]);
        $six = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H6', 'attributes' => ['Size' => '6'], 'price' => 1000, 'stock_quantity' => 0, 'is_active' => true]);
        $seven = ProductVariant::create(['product_id' => $ring->id, 'sku' => 'H7', 'attributes' => ['Size' => '7'], 'price' => 1100, 'stock_quantity' => 3, 'is_active' => true]);

        return [$ring, $six, $seven];
    }

    public function test_kinds_come_from_category_words_and_earring_is_not_ring(): void
    {
        $parent = Category::create(['name' => 'Earrings']);
        $child = Category::create(['name' => 'Premium Statement', 'parent_id' => $parent->id]);

        $this->assertSame('earring', StylePieces::kind($this->product('Leaf drops', $child)));
        $this->assertSame('ring', StylePieces::kind($this->product('Band', Category::create(['name' => 'Fashion Ring']))));
        $this->assertSame('bangle', StylePieces::kind($this->product('Kada', Category::create(['name' => 'Bangles']))));
        $this->assertSame('necklace', StylePieces::kind($this->product('Pear Drop Necklace')));
    }

    public function test_a_piece_lists_its_sizes_with_stock_and_variant(): void
    {
        [$ring, $six, $seven] = $this->sizedRing(Category::create(['name' => 'Rings']));

        $piece = StylePieces::make($ring->fresh());

        $this->assertSame('Size', $piece['sizes']['name']);
        $this->assertSame([$six->id, $seven->id], array_column($piece['sizes']['values'], 'variant_id'));
        $this->assertSame([false, true], array_column($piece['sizes']['values'], 'in_stock'));
        $this->assertFalse($piece['needs_page']);
    }

    public function test_add_many_adds_a_sized_piece_with_its_variant_and_answers_json(): void
    {
        [$ring, , $seven] = $this->sizedRing(Category::create(['name' => 'Rings']));
        $plain = $this->product('Slim Bangle', Category::create(['name' => 'Bangles']));

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

    public function test_the_gift_quiz_ranks_within_budget_and_remembers_the_answers(): void
    {
        $earrings = Category::create(['name' => 'Earrings']);
        $rings = Category::create(['name' => 'Rings']);
        $match = $this->product('Ruby Drops', $earrings, ['price' => 900, 'tags' => 'Birthday, Statement']);
        $this->product('Plain Drops', $earrings, ['price' => 800]);
        $this->product('Gold Hoops', $earrings, ['price' => 950]);
        $this->product('Too Dear Drops', $earrings, ['price' => 5000, 'tags' => 'Birthday']);
        $this->product('A Ring', $rings, ['price' => 700]);

        $res = $this->getJson(route('gift-quiz.results', ['for' => 'her', 'occasion' => 'birthday', 'type' => 'earring', 'style' => 'statement', 'price_max' => 1000]))
            ->assertOk();

        $ids = array_column($res->json('cards'), 'id');
        $this->assertSame($match->id, $ids[0]);
        $this->assertCount(3, $ids);
        $this->assertFalse($res->json('relaxed'));
        $this->assertSame('birthday', session('gift_profile.occasion'));
        $this->assertSame('her', session('gift_profile.for'));

        $this->get(route('gift-quiz'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('GiftQuiz')->has('occasions'));
    }

    public function test_too_few_of_a_kind_lets_the_kind_go_but_never_the_budget(): void
    {
        $this->product('Only Ring', Category::create(['name' => 'Rings']), ['price' => 500]);
        $this->product('Drops', Category::create(['name' => 'Earrings']), ['price' => 600]);
        $this->product('Dear', Category::create(['name' => 'Necklaces']), ['price' => 9000]);

        $res = $this->getJson(route('gift-quiz.results', ['type' => 'ring', 'price_max' => 1000]))->assertOk();

        $this->assertTrue($res->json('relaxed'));
        $this->assertEqualsCanonicalizing(['Only Ring', 'Drops'], array_column($res->json('cards'), 'name'));
    }

    public function test_stacker_and_try_on_list_their_kinds(): void
    {
        $ring = $this->product('Stack Ring', Category::create(['name' => 'Rings']));
        $bangle = $this->product('Stack Bangle', Category::create(['name' => 'Bangles']));
        $earring = $this->product('Try Drops', Category::create(['name' => 'Earrings']));

        $this->get(route('stacker', ['add' => $bangle->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stacker')->has('pieces', 2)->where('start', $bangle->id));

        $this->get(route('try-on'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('TryOn')->has('pieces', 1)->where('pieces.0.id', $earring->id));

        $this->get(route('product.show', $earring))->assertInertia(fn (Assert $page) => $page->where('styleTool.label', 'Try it on'));
        $this->get(route('product.show', $ring))->assertInertia(fn (Assert $page) => $page->where('styleTool.label', 'Stack it'));
    }

    public function test_looks_drop_dead_pins_and_show_on_the_page_and_homepage(): void
    {
        $a = $this->product('Look Earrings', Category::create(['name' => 'Earrings']));
        $draft = $this->product('Draft piece', null, ['status' => 'draft']);
        Look::create(['title' => 'Evening', 'image' => 'looks/x.webp', 'hotspots' => [
            ['x' => 40, 'y' => 30, 'product_id' => $a->id],
            ['x' => 140, 'y' => -5, 'product_id' => $a->id],      // clamped
            ['x' => 50, 'y' => 50, 'product_id' => $draft->id],    // not published: dropped
            ['x' => 'oops', 'y' => 1, 'product_id' => $a->id],     // not numeric: dropped
        ]]);
        Look::create(['title' => 'No pins', 'image' => 'looks/y.webp', 'hotspots' => []]);

        $this->get(route('looks'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Looks')
            ->has('looks', 1)
            ->has('looks.0.hotspots', 2)
            ->where('looks.0.hotspots.1.x', 100)
            ->where('looks.0.hotspots.1.y', 0));
    }

    public function test_admin_makes_a_look_and_uploads_a_cutout(): void
    {
        Storage::fake('public');
        $admin = User::create(['name' => 'A', 'email' => 'looks@b.test', 'password' => bcrypt('x'), 'role' => 'admin']);
        $piece = $this->product('Cut Drops', Category::create(['name' => 'Earrings']));

        $this->actingAs($admin)->get(route('admin.looks.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.looks.create'))->assertOk();

        $this->actingAs($admin)->post(route('admin.looks.store'), [
            'title' => 'Garden party',
            'image' => UploadedFile::fake()->image('look.jpg', 800, 1000),
            'hotspots' => json_encode([['x' => 20, 'y' => 30, 'product_id' => $piece->id], ['x' => 5, 'y' => 5, 'product_id' => '']]),
            'is_active' => 1,
        ])->assertRedirect();

        $look = Look::firstOrFail();
        $this->assertSame([['x' => 20, 'y' => 30, 'product_id' => $piece->id]], $look->hotspots);
        $this->actingAs($admin)->get(route('admin.looks.edit', $look))->assertOk();

        $this->actingAs($admin)->get(route('admin.looks.cutouts'))->assertOk()->assertSee('Cut Drops');
        $this->actingAs($admin)->post(route('admin.looks.cutouts.save', $piece), [
            'cutout' => UploadedFile::fake()->image('drop.png', 200, 400),
        ])->assertRedirect();
        $this->assertNotNull($piece->fresh()->cutout_image);
        $this->assertTrue(StylePieces::make($piece->fresh())['cutout']);

        // A JPEG has no transparency to draw with.
        $this->actingAs($admin)->post(route('admin.looks.cutouts.save', $piece), [
            'cutout' => UploadedFile::fake()->image('drop.jpg'),
        ])->assertSessionHasErrors('cutout');
    }
}
