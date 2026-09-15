<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Most of this shop's praise never reaches the review form — it arrives in
 * Messenger or WhatsApp, weeks after delivery, and the owner catches up on a
 * whole product's worth in one sitting. Typing them in was only half the job:
 * every such review used to carry today's date, so eight customers writing
 * over two months all appeared to have written on the same afternoon.
 */
class AdminReviewEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@reviews.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(string $slug = 'kundan-set'): Product
    {
        return Product::create([
            'name' => 'Kundan Set', 'slug' => $slug, 'status' => 'published',
            'price' => 2400, 'manage_stock' => false,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function addReviews(Product $product, array $rows, string $status = 'approved')
    {
        return $this->actingAs($this->admin())->post(route('admin.reviews.store'), [
            'product_id' => $product->id,
            'status' => $status,
            'reviews' => collect($rows)->mapWithKeys(fn ($r, $i) => ['r'.$i => $r])->all(),
        ]);
    }

    public function test_the_reviews_screen_carries_the_form_and_a_date_on_every_row(): void
    {
        $product = $this->product();
        Review::create([
            'product_id' => $product->id, 'author_name' => 'Munia',
            'rating' => 5, 'status' => 'approved',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Add reviews yourself')
            ->assertSee('reviewed_on', false)
            ->assertSee(route('admin.reviews.store'), false)
            ->assertSee('Kundan Set');
    }

    public function test_the_owner_can_write_down_a_review_on_the_date_it_was_given(): void
    {
        $product = $this->product();

        $this->addReviews($product, [[
            'author_name' => 'Munia',
            'rating' => 5,
            'body' => 'Ekhaner prottekta kaner dul etto shundor!!',
            'reviewed_on' => '2026-07-14',
            'is_verified_buyer' => '1',
        ]])->assertRedirect();

        $review = Review::firstOrFail();

        $this->assertSame('Munia', $review->author_name);
        $this->assertSame('approved', $review->status);
        $this->assertTrue($review->is_verified_buyer);
        $this->assertSame('14 Jul 2026', store_time($review->created_at)->format('d M Y'));
    }

    public function test_a_whole_conversation_of_feedback_goes_in_at_once(): void
    {
        // The ask this form exists for: one product, several customers, each
        // with their own date, typed in one sitting.
        $product = $this->product();

        $this->addReviews($product, [
            ['author_name' => 'Munia', 'rating' => 5, 'body' => 'Etao ekdom perfect', 'reviewed_on' => '2026-09-01'],
            ['author_name' => 'Shayla Rahman', 'rating' => 5, 'body' => 'Onnek shundor lagse', 'reviewed_on' => '2026-07-14'],
            ['author_name' => 'Ayesha', 'rating' => 4, 'body' => 'Valo, delivery deri hoise', 'reviewed_on' => '2026-05-04'],
        ])->assertRedirect();

        $this->assertSame(3, Review::count());
        $this->assertSame(3, Review::where('status', 'approved')->count());
        $this->assertSame(
            ['01 Sep 2026', '14 Jul 2026', '04 May 2026'],
            $product->approvedReviews()->get()
                ->map(fn ($r) => store_time($r->created_at)->format('d M Y'))->all(),
        );
    }

    public function test_two_reviews_on_one_date_keep_the_order_they_were_typed(): void
    {
        $product = $this->product();

        $this->addReviews($product, [
            ['author_name' => 'First', 'rating' => 5, 'reviewed_on' => '2026-08-20'],
            ['author_name' => 'Second', 'rating' => 5, 'reviewed_on' => '2026-08-20'],
        ]);

        $this->assertSame(
            ['First', 'Second'],
            $product->approvedReviews()->get()->pluck('author_name')->all(),
        );
    }

    public function test_a_row_the_owner_left_empty_is_skipped_rather_than_refused(): void
    {
        $product = $this->product();

        $this->addReviews($product, [
            ['author_name' => 'Munia', 'rating' => 5, 'body' => 'Shundor', 'reviewed_on' => '2026-08-01'],
            ['author_name' => '', 'rating' => 5, 'body' => '', 'reviewed_on' => '2026-08-01'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Review::count());
    }

    public function test_a_date_is_read_as_a_dhaka_date_not_a_utc_one(): void
    {
        // Stored UTC, shown Dhaka: an entry that slipped six hours would put a
        // 1 September review on 31 August for every shopper reading it.
        $product = $this->product();

        $this->addReviews($product, [
            ['author_name' => 'Shayla Rahman', 'rating' => 5, 'reviewed_on' => '2026-09-01'],
        ]);

        $this->assertSame('01 Sep 2026', store_time(Review::firstOrFail()->created_at)->format('d M Y'));
    }

    public function test_the_owner_can_correct_the_date_and_wording_of_an_existing_review(): void
    {
        $product = $this->product();
        $review = Review::create([
            'product_id' => $product->id, 'author_name' => 'Shayla',
            'rating' => 4, 'body' => 'Valo', 'status' => 'pending',
        ]);

        $this->actingAs($this->admin())
            ->patch(route('admin.reviews.update', $review), [
                'product_id' => $product->id,
                'author_name' => 'Shayla Rahman',
                'rating' => 5,
                'body' => 'Onnek shundor lagse, pochondo hoise khub.',
                'reviewed_on' => '2026-08-02',
                'status' => 'approved',
            ])->assertRedirect();

        $review->refresh();

        $this->assertSame(5, $review->rating);
        $this->assertSame('Shayla Rahman', $review->author_name);
        $this->assertSame('approved', $review->status);
        $this->assertSame('02 Aug 2026', store_time($review->created_at)->format('d M Y'));
    }

    public function test_a_review_cannot_be_dated_in_the_future(): void
    {
        // A future date is never a real one — nobody has written the review
        // yet. It also sorts above every genuine review, so one slip puts a
        // review dated next winter at the top of the product page.
        $product = $this->product();
        $ahead = now(config('store.timezone'))->addMonth()->toDateString();

        $this->addReviews($product, [
            ['author_name' => 'Shayla Rahman', 'rating' => 5, 'reviewed_on' => $ahead],
        ])->assertSessionHasErrors('reviews.r0.reviewed_on');

        $this->assertSame(0, Review::count());
    }

    public function test_correcting_a_review_cannot_push_its_date_into_the_future(): void
    {
        $product = $this->product();
        $review = Review::create([
            'product_id' => $product->id, 'author_name' => 'Shayla',
            'rating' => 4, 'body' => 'Valo', 'status' => 'approved',
        ]);
        $review->created_at = Carbon::parse('2026-08-02 12:00:00');
        $review->save();

        $this->actingAs($this->admin())
            ->patch(route('admin.reviews.update', $review), [
                'product_id' => $product->id,
                'author_name' => 'Shayla',
                'rating' => 4,
                'body' => 'Valo',
                'reviewed_on' => now(config('store.timezone'))->addMonth()->toDateString(),
                'status' => 'approved',
            ])->assertSessionHasErrors('reviewed_on');

        // Refused means unchanged, not half-applied.
        $this->assertSame('02 Aug 2026', store_time($review->refresh()->created_at)->format('d M Y'));
    }

    public function test_today_is_measured_in_dhaka_so_a_morning_entry_is_not_refused(): void
    {
        // 2am in Dhaka is still yesterday on a UTC server. Measuring "today"
        // there would refuse the owner the one date they most want to type.
        $this->travelTo(Carbon::parse('2026-09-16 20:30:00', 'UTC')); // 02:30 on the 17th in Dhaka
        $product = $this->product();

        $this->addReviews($product, [
            ['author_name' => 'Shayla Rahman', 'rating' => 5, 'reviewed_on' => '2026-09-17'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('17 Sep 2026', store_time(Review::firstOrFail()->created_at)->format('d M Y'));
    }
}
