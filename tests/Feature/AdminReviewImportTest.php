<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The backlog, not the trickle: months of Messenger and WhatsApp praise across
 * the whole catalogue, already sitting in one spreadsheet. Typed a product at a
 * time that is an evening's work, so a sheet goes in at once — and a row that
 * cannot be placed is reported back rather than quietly dropped or guessed at.
 */
class AdminReviewImportTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@import.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(string $name, string $slug, ?string $sku = null): Product
    {
        return Product::create([
            'name' => $name, 'slug' => $slug, 'sku' => $sku, 'status' => 'published',
            'price' => 2400, 'manage_stock' => false,
        ]);
    }

    protected function importCsv(string $csv, string $status = 'approved')
    {
        return $this->actingAs($this->admin())->post(route('admin.reviews.import.store'), [
            'status' => $status,
            'file' => UploadedFile::fake()->createWithContent('reviews.csv', $csv),
        ]);
    }

    protected function importPaste(string $text, string $status = 'approved')
    {
        return $this->actingAs($this->admin())->post(route('admin.reviews.import.store'), [
            'status' => $status,
            'paste' => $text,
        ]);
    }

    public function test_one_sheet_carries_reviews_for_several_products(): void
    {
        $kundan = $this->product('Kundan Set', 'kundan-set');
        $chain = $this->product('Gold Chain Bracelet', 'gold-chain-bracelet');

        $this->importCsv(<<<'CSV'
        product,name,rating,date,title,review,phone,verified
        Kundan Set,Munia,5,14/07/2026,Onek shundor!,Etao ekdom perfect,01712345678,yes
        Gold Chain Bracelet,Shayla Rahman,4,2026-09-01,,Onnek shundor lagse,,
        CSV)->assertRedirect();

        $this->assertSame(2, Review::count());

        $munia = Review::where('author_name', 'Munia')->firstOrFail();
        $this->assertSame($kundan->id, $munia->product_id);
        $this->assertSame(5, $munia->rating);
        $this->assertSame('Onek shundor!', $munia->title);
        $this->assertTrue($munia->is_verified_buyer);
        $this->assertSame('14 Jul 2026', store_time($munia->created_at)->format('d M Y'));

        $shayla = Review::where('author_name', 'Shayla Rahman')->firstOrFail();
        $this->assertSame($chain->id, $shayla->product_id);
        $this->assertSame(4, $shayla->rating);
        $this->assertFalse($shayla->is_verified_buyer);
        $this->assertSame('01 Sep 2026', store_time($shayla->created_at)->format('d M Y'));
    }

    public function test_a_slash_date_is_read_day_first(): void
    {
        // 01/09/2026 is 1 September here, not 9 January. Read the American way
        // round, a whole sheet of dates lands in the wrong months.
        $this->product('Kundan Set', 'kundan-set');

        $this->importCsv("product,name,rating,date\nKundan Set,Munia,5,01/09/2026\n");

        $this->assertSame('01 Sep 2026', store_time(Review::firstOrFail()->created_at)->format('d M Y'));
    }

    public function test_cells_pasted_straight_out_of_excel_are_read_as_they_come(): void
    {
        $this->product('Kundan Set', 'kundan-set');

        $this->importPaste("product\tname\trating\tdate\treview\nKundan Set\tMunia\t5\t14/07/2026\tEtto shundor")
            ->assertRedirect();

        $review = Review::firstOrFail();
        $this->assertSame('Munia', $review->author_name);
        $this->assertSame('Etto shundor', $review->body);
    }

    public function test_a_product_the_sheet_names_wrongly_is_reported_not_guessed(): void
    {
        $this->product('Kundan Set', 'kundan-set');

        $this->importCsv(<<<'CSV'
        product,name,rating
        Kundan Set,Munia,5
        Kundan Necklace,Ayesha,5
        CSV)->assertSessionHas('import_errors');

        $this->assertSame(1, Review::count());
        $this->assertSame('Munia', Review::firstOrFail()->author_name);
        $this->assertStringContainsString('Kundan Necklace', implode(' ', session('import_errors')));
    }

    public function test_a_product_is_found_by_id_slug_sku_or_a_loosely_spelled_name(): void
    {
        $p = $this->product('Kundan Set', 'kundan-set', 'KS-01');

        $this->importCsv(<<<CSV
        product,name,rating
        {$p->id},By id,5
        kundan-set,By slug,5
        KS-01,By sku,5
        "kundan  set",By name,5
        CSV);

        $this->assertSame(4, Review::where('product_id', $p->id)->count());
    }

    public function test_importing_the_same_sheet_twice_does_not_double_the_reviews(): void
    {
        $this->product('Kundan Set', 'kundan-set');
        $csv = "product,name,rating,review\nKundan Set,Munia,5,Etto shundor\n";

        $this->importCsv($csv);
        $this->importCsv($csv)->assertSessionHas('import_errors');

        $this->assertSame(1, Review::count());
    }

    public function test_a_blank_rating_reads_as_five_and_a_bad_one_is_dropped(): void
    {
        $this->product('Kundan Set', 'kundan-set');

        $this->importCsv(<<<'CSV'
        product,name,rating
        Kundan Set,Blank,
        Kundan Set,Nonsense,9
        CSV);

        $this->assertSame(1, Review::count());
        $this->assertSame(5, Review::firstOrFail()->rating);
        $this->assertStringContainsString('1–5', implode(' ', session('import_errors')));
    }

    public function test_the_heading_a_sheet_already_uses_is_understood(): void
    {
        $this->product('Kundan Set', 'kundan-set');

        $this->importCsv("item,customer,stars,review_date,feedback\nKundan Set,Munia,5,2026-07-14,Khub valo\n");

        $review = Review::firstOrFail();
        $this->assertSame('Munia', $review->author_name);
        $this->assertSame('Khub valo', $review->body);
        $this->assertSame('14 Jul 2026', store_time($review->created_at)->format('d M Y'));
    }

    public function test_rows_sharing_a_date_keep_the_order_the_sheet_listed_them(): void
    {
        $product = $this->product('Kundan Set', 'kundan-set');

        $this->importCsv(<<<'CSV'
        product,name,rating,date
        Kundan Set,First,5,2026-08-20
        Kundan Set,Second,5,2026-08-20
        CSV);

        $this->assertSame(
            ['First', 'Second'],
            $product->approvedReviews()->get()->pluck('author_name')->all(),
        );
    }

    public function test_the_import_screen_lists_the_products_to_copy_names_from(): void
    {
        $this->product('Kundan Set', 'kundan-set');

        $this->actingAs($this->admin())
            ->get(route('admin.reviews.import'))
            ->assertOk()
            ->assertSee('Import reviews')
            ->assertSee('Kundan Set');
    }

    public function test_an_empty_submission_says_so_rather_than_importing_nothing(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reviews.import.store'), ['status' => 'approved'])
            ->assertSessionHas('error');

        $this->assertSame(0, Review::count());
    }
}
