<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue only ever travelled one way — in, through the CSV importer.
 * Getting a product's details back out meant reading them off the screen and
 * retyping, whether they were bound for a photographer, a marketplace listing
 * or a spreadsheet the owner actually likes working in.
 */
class AdminProductExportTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@export.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Kundan Set', 'slug' => 'kundan-set', 'sku' => 'KS-01',
            'status' => 'published', 'price' => 2400, 'compare_at_price' => 3000,
            'manage_stock' => true, 'stock_quantity' => 7, 'in_stock' => true,
            'short_description' => 'A short line', 'tags' => 'eid, kundan',
        ], $attrs));
    }

    /** The downloaded file as text. */
    protected function csv(string $url): string
    {
        $response = $this->actingAs($this->admin())->get($url);
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    public function test_one_product_exports_its_details_from_its_own_page(): void
    {
        $category = Category::create(['name' => 'Necklaces', 'is_active' => true]);
        $product = $this->product(['category_id' => $category->id]);

        $csv = $this->csv(route('admin.products.export-one', $product));

        $this->assertStringContainsString('Kundan Set', $csv);
        $this->assertStringContainsString('KS-01', $csv);
        $this->assertStringContainsString('2400.00', $csv);
        $this->assertStringContainsString('Necklaces', $csv);
        $this->assertStringContainsString('A short line', $csv);
        $this->assertStringContainsString(route('product.show', $product), $csv);

        // One product means one row under the header.
        $this->assertCount(2, array_filter(explode("\n", trim($csv))));
    }

    public function test_the_file_opens_in_excel_as_the_import_template(): void
    {
        // The first eleven columns are the importer's own, in its order, so a
        // file can go out, be edited, and come back in without rearranging.
        $this->product();

        $csv = $this->csv(route('admin.products.export'));
        $header = str_getcsv(strtok(ltrim($csv, "\xEF\xBB\xBF"), "\n"));

        $this->assertSame(
            ['name', 'product_id', 'price', 'sku', 'category', 'stock', 'status',
                'short_description', 'description', 'meta_description', 'tags'],
            array_slice($header, 0, 11),
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv); // Excel needs the BOM for Bangla
    }

    public function test_an_exported_file_imports_straight_back(): void
    {
        $this->product(['name' => 'Round Trip Ring', 'slug' => 'round-trip-ring', 'sku' => 'RT-01']);

        $csv = ltrim($this->csv(route('admin.products.export')), "\xEF\xBB\xBF");
        Product::query()->forceDelete();

        $this->actingAs($this->admin())->post(route('admin.products.import.store'), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('products.csv', $csv),
        ])->assertRedirect();

        $back = Product::firstOrFail();
        $this->assertSame('Round Trip Ring', $back->name);
        $this->assertSame('RT-01', $back->sku);
        $this->assertSame('2400.00', (string) $back->price);
        $this->assertSame(7, $back->stock_quantity);
    }

    public function test_the_export_is_the_list_on_screen_not_the_whole_catalogue(): void
    {
        // Exporting "everything" while looking at a filtered list is a quiet
        // way to hand someone the wrong file.
        $this->product(['name' => 'Published Piece', 'slug' => 'published-piece', 'sku' => 'P-1']);
        $this->product(['name' => 'Draft Piece', 'slug' => 'draft-piece', 'sku' => 'D-1', 'status' => 'draft']);

        $csv = $this->csv(route('admin.products.export', ['status' => 'draft']));

        $this->assertStringContainsString('Draft Piece', $csv);
        $this->assertStringNotContainsString('Published Piece', $csv);
    }

    public function test_a_search_narrows_the_export_the_same_way_it_narrows_the_list(): void
    {
        $this->product(['name' => 'Pearl Drop Necklace', 'slug' => 'pearl-drop', 'sku' => 'PD-1']);
        $this->product(['name' => 'Silver Anklet Pair', 'slug' => 'silver-anklet', 'sku' => 'SA-1']);

        $csv = $this->csv(route('admin.products.export', ['q' => 'Pearl']));

        $this->assertStringContainsString('Pearl Drop Necklace', $csv);
        $this->assertStringNotContainsString('Silver Anklet Pair', $csv);
    }

    public function test_the_product_page_offers_the_export(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Export details')
            ->assertSee(route('admin.products.export-one', $product), false);
    }

    public function test_staff_cannot_export_the_catalogue(): void
    {
        // Products are a manager-and-up section, and cost price is in the file.
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@export.test',
            'password' => bcrypt('secret'), 'role' => 'staff',
        ]);

        $this->actingAs($staff)->get(route('admin.products.export'))->assertForbidden();
    }
}
