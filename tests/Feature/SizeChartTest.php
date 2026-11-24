<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\SpinWheel;
use App\Support\Storefront\SizeChart;
use App\Support\Storefront\SizeGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The owner's own ring and bangle size charts (Nov 2026), which "Find my
 * size" answers from — on the owner's chart a 60 mm finger is a US 7.
 */
class SizeChartTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@sizes.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    public function test_the_standard_charts_apply_until_the_owner_saves_one(): void
    {
        $ring = SizeChart::rows('ring');
        $seven = collect($ring)->firstWhere('size', '7');
        $this->assertEqualsWithDelta(54.4, $seven['around'], 0.1);
        $this->assertFalse(SizeChart::isCustom('ring'));
        $this->assertSame('2.2', SizeChart::rows('bangle')[0]['size']);
    }

    public function test_the_owner_saves_a_chart_and_the_product_page_sends_it(): void
    {
        $this->actingAs($this->admin())->get(route('admin.products.size-chart'))->assertOk()->assertSee('Find my size');

        $this->actingAs($this->admin())->post(route('admin.products.size-chart.update'), [
            'ring' => [
                ['size' => '8', 'around' => '62.5'],
                ['size' => '7', 'around' => '60'],
                ['size' => '7', 'around' => '61'],   // the same size twice: kept once
                ['size' => '9', 'around' => '5.4'],  // centimetres: out of range, dropped
                ['size' => '', 'around' => ''],      // a blank row
            ],
            'bangle' => [],
        ])->assertRedirect(route('admin.products.size-chart'))->assertSessionHas('success');

        Setting::flushMemo();
        $this->assertSame([['size' => '7', 'around' => 60.0], ['size' => '8', 'around' => 62.5]], SizeChart::rows('ring'));
        $this->assertTrue(SizeChart::isCustom('ring'));
        // An empty bangle chart means the standard one, not no sizes.
        $this->assertFalse(SizeChart::isCustom('bangle'));
        $this->assertCount(7, SizeChart::rows('bangle'));

        $product = Product::create([
            'name' => 'Halo Ring', 'slug' => 'halo-ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => false, 'in_stock' => true,
            'category_id' => Category::create(['name' => 'Rings'])->id,
            'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['7', '8']]],
        ]);

        $this->get(route('product.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sizeGuide.kind', 'ring')
            ->where('sizeGuide.chart.0.size', '7')
            ->where('sizeGuide.chart.0.around', 60));
    }

    public function test_a_bangle_size_on_the_owners_chart_counts_as_a_size(): void
    {
        $category = Category::create(['name' => 'Bangles']);
        $product = Product::create([
            'name' => 'Churi', 'slug' => 'churi', 'status' => 'published', 'price' => 900,
            'manage_stock' => false, 'in_stock' => true, 'category_id' => $category->id,
            'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['2.5', '2.7']]],
        ]);

        // Not standard bangle sizes, so no finder…
        $this->assertNull(SizeGuide::for($product));

        // …until they are on the owner's chart.
        Setting::put(SizeChart::KEY, ['bangle' => [['size' => '2.5', 'around' => 178], ['size' => '2.7', 'around' => 190]]]);
        $this->assertSame('Size', SizeGuide::for($product)['attribute']);
    }

    public function test_the_owner_adds_and_removes_pictures(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post(route('admin.products.size-chart.update'), [
            'ring_measure_image' => UploadedFile::fake()->image('tape.jpg', 900, 700),
            'bangle_chart_image' => UploadedFile::fake()->image('chart.png', 800, 1000),
        ])->assertRedirect()->assertSessionHasNoErrors();

        Setting::flushMemo();
        $paths = Setting::get(SizeChart::IMAGES_KEY);
        Storage::disk('public')->assertExists($paths['ring']['measure']);
        Storage::disk('public')->assertExists($paths['bangle']['chart']);
        $this->assertNotNull(SizeChart::images('ring')['measure']);
        $this->assertNull(SizeChart::images('ring')['chart']);

        // A piece's finder gets the pictures of its kind.
        $product = Product::create([
            'name' => 'Band', 'slug' => 'band', 'status' => 'published', 'price' => 900,
            'manage_stock' => false, 'in_stock' => true,
            'category_id' => Category::create(['name' => 'Rings'])->id,
            'has_variants' => true, 'options' => [['name' => 'Size', 'values' => ['6', '7']]],
        ]);
        $this->assertSame(SizeChart::images('ring'), SizeGuide::for($product)['images']);

        // Saving again without a file keeps it; "remove" deletes the file.
        $this->actingAs($this->admin())->post(route('admin.products.size-chart.update'), [])->assertRedirect();
        Setting::flushMemo();
        Storage::disk('public')->assertExists($paths['ring']['measure']);

        $this->actingAs($this->admin())->post(route('admin.products.size-chart.update'), ['remove_ring_measure_image' => '1'])->assertRedirect();
        Setting::flushMemo();
        Storage::disk('public')->assertMissing($paths['ring']['measure']);
        $this->assertNull(SizeChart::images('ring')['measure']);

        // Not a picture: refused, with words.
        $this->actingAs($this->admin())->post(route('admin.products.size-chart.update'), [
            'ring_chart_image' => UploadedFile::fake()->create('chart.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors(['ring_chart_image' => 'A picture must be a JPG, PNG or WebP image.']);
    }

    public function test_staff_without_product_access_cannot_edit_it(): void
    {
        $staff = User::create(['name' => 'Packer', 'email' => 'packer@sizes.test', 'password' => bcrypt('x'), 'role' => 'staff']);
        if ($staff->canAccess('products')) {
            $this->markTestSkipped('Staff can manage products on this install.');
        }
        $this->actingAs($staff)->get(route('admin.products.size-chart'))->assertForbidden();
    }
}
