<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Five hundred reviews in one newest-first list, and no way to ask what one
 * piece has been told. The owner searches for a product and the screen becomes
 * that product's reviews: its rating as shoppers see it, every review in every
 * state, and the add / edit / moderate options scoped to it.
 */
class AdminReviewProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@review-search.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(string $name, string $slug, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => $name, 'slug' => $slug, 'status' => 'published',
            'price' => 2400, 'manage_stock' => false,
        ], $attrs));
    }

    protected function review(Product $product, string $author, string $status = 'approved', int $rating = 5): Review
    {
        return Review::create([
            'product_id' => $product->id, 'author_name' => $author,
            'rating' => $rating, 'status' => $status,
        ]);
    }

    public function test_a_search_that_names_one_product_opens_its_reviews(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->product('Pearl Drop Necklace', 'pearl-drop-necklace');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => 'kundan']))
            ->assertRedirect(route('admin.reviews.index', ['product' => $kundan->id]));
    }

    public function test_a_product_shows_only_its_own_reviews_in_every_state(): void
    {
        // The store-wide queue opens on pending; one product's page opens on
        // all of them, because its approved reviews are what the owner wants.
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $pearl = $this->product('Pearl Drop Necklace', 'pearl-drop-necklace');

        $this->review($kundan, 'Munia', 'approved');
        $this->review($kundan, 'Shayla Rahman', 'pending');
        $this->review($kundan, 'Nadia', 'hidden');
        $this->review($pearl, 'Someone Else', 'approved');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $kundan->id]))
            ->assertOk()
            ->assertSee('Munia')
            ->assertSee('Shayla Rahman')
            ->assertSee('Nadia')
            ->assertDontSee('Someone Else')
            ->assertViewHas('current', 'all')
            ->assertViewHas('counts', ['pending' => 1, 'approved' => 1, 'hidden' => 1]);
    }

    public function test_the_status_tabs_stay_on_the_product(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->review($kundan, 'Munia', 'approved');
        $this->review($kundan, 'Shayla Rahman', 'pending');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $kundan->id, 'status' => 'pending']))
            ->assertOk()
            ->assertSee('Shayla Rahman')
            ->assertDontSee('Munia')
            // Escaped: in the HTML the query string's & is written &amp;.
            ->assertSee(route('admin.reviews.index', ['product' => $kundan->id, 'status' => 'approved']));
    }

    public function test_the_product_header_shows_the_rating_shoppers_see(): void
    {
        // Approved reviews only — a pending one-star is not on the product page.
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->review($kundan, 'A', 'approved', 5);
        $this->review($kundan, 'B', 'approved', 4);
        $this->review($kundan, 'C', 'pending', 1);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $kundan->id]));

        $response->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['avg'] === 4.5 && $s['total'] === 2 && $s['dist'][1] === 0)
            ->assertSee('from 2 approved reviews', false);
    }

    public function test_a_search_matching_several_products_lists_them_to_pick_from(): void
    {
        $bangle = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $choker = $this->product('Kundan Choker', 'kundan-choker');
        $this->review($bangle, 'Munia', 'pending');
        $this->review($this->product('Pearl Drop Necklace', 'pearl-drop-necklace'), 'Unrelated Reviewer', 'pending');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => 'kundan']))
            ->assertOk()
            ->assertSee('2 products match', false)
            ->assertSee(route('admin.reviews.index', ['product' => $bangle->id]), false)
            ->assertSee(route('admin.reviews.index', ['product' => $choker->id]), false)
            // The store-wide queue steps aside while picking, so the list on
            // screen is never a different list from the one searched.
            ->assertDontSee('Unrelated Reviewer');
    }

    public function test_the_product_id_off_a_courier_label_finds_the_piece(): void
    {
        $this->product('Pearl Drop Necklace', 'pearl-drop-necklace', ['serial' => 41]);
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set', ['serial' => 12]);

        foreach (['#12', '12'] as $typed) {
            $this->actingAs($this->admin())
                ->get(route('admin.reviews.index', ['q' => $typed]))
                ->assertRedirect(route('admin.reviews.index', ['product' => $kundan->id]));
        }
    }

    public function test_a_search_with_no_match_says_so(): void
    {
        $this->product('Kundan Bangle Set', 'kundan-bangle-set');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => 'zzzzqqq']))
            ->assertOk()
            ->assertSee('No product matches', false);
    }

    public function test_adding_reviews_from_a_product_starts_on_that_product(): void
    {
        $this->product('Pearl Drop Necklace', 'pearl-drop-necklace');
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $kundan->id]))
            ->assertOk()
            ->assertSee('Add reviews to this product')
            ->assertSee('<option value="'.$kundan->id.'" selected', false);
    }

    public function test_moderating_a_review_returns_to_the_product_it_was_on(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $review = $this->review($kundan, 'Shayla Rahman', 'pending');
        $page = route('admin.reviews.index', ['product' => $kundan->id]);

        $this->actingAs($this->admin())
            ->from($page)
            ->patch(route('admin.reviews.status', $review), ['status' => 'approved'])
            ->assertRedirect($page);
    }

    public function test_the_store_wide_queue_still_opens_on_pending(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->review($kundan, 'Approved Already', 'approved');
        $this->review($kundan, 'Waiting Reviewer', 'pending');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index'))
            ->assertOk()
            ->assertViewHas('current', 'pending')
            ->assertSee('Waiting Reviewer')
            ->assertDontSee('Approved Already');
    }

    public function test_the_picker_knows_every_product_and_its_counts(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set', ['serial' => 12, 'sku' => 'KB-1']);
        $this->review($kundan, 'A', 'approved');
        $this->review($kundan, 'B', 'pending');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index'))
            ->assertViewHas('picker', fn ($picker) => collect($picker)->contains(fn ($p) => $p['id'] === $kundan->id
                && $p['serial'] === 12 && $p['sku'] === 'KB-1' && $p['total'] === 2 && $p['pending'] === 1));
    }

    public function test_the_product_page_opens_its_reviews(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->review($kundan, 'A', 'approved');
        $this->review($kundan, 'B', 'pending');

        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $kundan))
            ->assertOk()
            ->assertSee(route('admin.reviews.index', ['product' => $kundan->id]), false)
            ->assertSee('(2)', false)
            ->assertSee('1 pending');
    }

    public function test_a_deleted_products_reviews_can_still_be_opened(): void
    {
        // Products soft-delete and their reviews stay behind; the link from an
        // old review must not 500 or land on the store-wide list.
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->review($kundan, 'Munia', 'approved');
        $kundan->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $kundan->id]))
            ->assertOk()
            ->assertSee('Munia')
            ->assertSee('deleted product');
    }

    // ── What the adversarial review of this screen turned up ────────────────

    public function test_editing_a_review_of_a_deleted_product_keeps_it_on_that_product(): void
    {
        // The edit form lists live products only. With no option of its own,
        // the browser submitted the first product in the list, and one save to
        // fix a typo moved the review — and its stars — onto an unrelated piece.
        $first = $this->product('Aaa First Product', 'aaa-first-product');
        $gone = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $review = $this->review($gone, 'Munia', 'approved');
        $gone->delete();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $gone->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<option value="'.$gone->id.'" selected>Kundan Bangle Set (deleted', $html);
        $this->assertStringNotContainsString('<option value="'.$first->id.'" selected', $html);
    }

    public function test_a_deleted_product_does_not_offer_to_take_new_reviews(): void
    {
        $gone = $this->product('Kundan Bangle Set', 'kundan-bangle-set');
        $this->review($gone, 'Munia', 'approved');
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['product' => $gone->id]))
            ->assertOk()
            ->assertDontSee('Add reviews to this product');
    }

    public function test_a_number_is_never_read_as_a_fragment_of_a_description(): void
    {
        // "12" is a Product ID. It used to fall through to the word search when
        // no live product carried it, and land on the one piece whose copy said
        // "a set of 12 bangles" — as though that were the answer.
        $this->product('Glass Bangles', 'glass-bangles', ['serial' => 3, 'description' => '<p>A set of 12 bangles</p>']);
        $this->product('Pearl Drop Necklace', 'pearl-drop-necklace', ['serial' => 4]);

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => '12']))
            ->assertOk()
            ->assertSee('No product matches', false);
    }

    public function test_a_deleted_products_id_still_finds_it(): void
    {
        // Its reviews outlive it, so its Product ID has to keep working.
        $gone = $this->product('Old Choker', 'old-choker', ['serial' => 12]);
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => '#12']))
            ->assertRedirect(route('admin.reviews.index', ['product' => $gone->id]));
    }

    public function test_a_numeric_sku_finds_its_product(): void
    {
        $piece = $this->product('Kundan Bangle Set', 'kundan-bangle-set', ['serial' => 7, 'sku' => '20455']);

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => '20455']))
            ->assertRedirect(route('admin.reviews.index', ['product' => $piece->id]));
    }

    public function test_a_lone_hash_matches_nothing_rather_than_every_colour_code(): void
    {
        $this->product('Kundan Bangle Set', 'kundan-bangle-set', ['description' => '<p style="color:#b8860b">Gold</p>']);

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['q' => '#']))
            ->assertOk()
            ->assertSee('No product matches', false);
    }

    public function test_a_hand_edited_url_with_array_parameters_does_not_crash_the_page(): void
    {
        $kundan = $this->product('Kundan Bangle Set', 'kundan-bangle-set');

        foreach ([
            '/admin/reviews?q[]=kundan',
            '/admin/reviews?status[]=pending',
            '/admin/reviews?product[]='.$kundan->id,
            '/admin/reviews?product='.$kundan->id.'&status[]=approved',
        ] as $url) {
            $this->actingAs($this->admin())->get($url)->assertOk();
        }
    }
}
