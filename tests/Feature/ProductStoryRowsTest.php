<?php

namespace Tests\Feature;

use App\Models\ContentTemplate;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The story rows under the buy box (3 Oct 2026), modelled on the .com store's
 * "Product story rows": a row set to `auto` shows the product's own media —
 * its uploaded video in the first row, then its 2nd and 3rd photos — so only
 * the copy is written per product.
 */
class ProductStoryRowsTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $sections, array $photos = ['a.webp', 'b.webp', 'c.webp'], array $videos = []): Product
    {
        $product = Product::create([
            'name' => 'Crimson Botanica Statement Earrings',
            'slug' => 'crimson-botanica',
            'status' => 'published',
            'price' => 1450,
            'manage_stock' => false,
            'in_stock' => true,
            'video_urls' => $videos,
            'content_sections' => $sections,
        ]);

        foreach ($photos as $i => $path) {
            $product->images()->create(['path' => 'products/'.$path, 'position' => $i, 'is_primary' => $i === 0]);
        }

        return $product;
    }

    private function rows(string $heading = 'Row'): array
    {
        return collect(range(1, 3))->map(fn ($n) => [
            'media' => 'auto', 'image' => '', 'heading' => "$heading $n", 'body' => "Body $n", 'layout' => $n % 2 ? 'left' : 'right',
        ])->all();
    }

    private function photo(string $path): string
    {
        return Storage::disk('public')->url('products/'.$path);
    }

    public function test_auto_rows_play_the_video_first_then_the_second_and_third_photos(): void
    {
        $product = $this->product($this->rows(), videos: ['product-videos/clip.mp4']);

        $this->get(route('product.show', $product))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('product.sections', 3)
                ->where('product.sections.0.heading', 'Row 1')
                ->where('product.sections.0.layout', 'left')
                ->where('product.sections.0.media.type', 'video')
                ->where('product.sections.0.media.src', Storage::disk('public')->url('product-videos/clip.mp4'))
                // The first photo stands in until the clip plays.
                ->where('product.sections.0.media.poster.src', $this->photo('a.webp'))
                ->where('product.sections.1.layout', 'right')
                ->where('product.sections.1.media.type', 'image')
                ->where('product.sections.1.media.src', $this->photo('b.webp'))
                ->where('product.sections.2.media.src', $this->photo('c.webp')),
            );
    }

    public function test_without_an_uploaded_video_the_first_row_shows_the_first_photo(): void
    {
        // A YouTube link cannot loop muted in the background, so it is not used.
        $product = $this->product($this->rows(), videos: ['https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sections.0.media.type', 'image')
                ->where('product.sections.0.media.src', $this->photo('a.webp')),
            );
    }

    public function test_rows_past_the_last_photo_fall_back_to_the_first(): void
    {
        $product = $this->product($this->rows(), photos: ['only.webp']);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sections.1.media.src', $this->photo('only.webp'))
                ->where('product.sections.2.media.src', $this->photo('only.webp')),
            );
    }

    public function test_a_custom_image_or_no_picture_is_still_honoured(): void
    {
        $product = $this->product([
            ['media' => 'image', 'image' => 'https://cdn.example.com/look.jpg', 'heading' => 'Custom', 'body' => '', 'layout' => 'left'],
            ['media' => 'none', 'image' => 'https://cdn.example.com/ignored.jpg', 'heading' => 'Words only', 'body' => '', 'layout' => 'left'],
            ['media' => 'image', 'image' => '', 'heading' => 'Image never chosen', 'body' => '', 'layout' => 'left'],
        ]);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sections.0.media.src', 'https://cdn.example.com/look.jpg')
                ->where('product.sections.1.media', null)
                ->where('product.sections.2.media', null),
            );
    }

    public function test_a_video_dropped_on_a_row_plays_with_that_rows_photo_as_poster(): void
    {
        $product = $this->product([
            ['media' => 'auto', 'image' => '', 'heading' => 'One', 'body' => '', 'layout' => 'left'],
            ['media' => 'image', 'image' => 'https://noychoy.test/storage/sections/clip.mp4', 'heading' => 'Two', 'body' => '', 'layout' => 'right'],
        ]);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sections.1.media.type', 'video')
                ->where('product.sections.1.media.src', 'https://noychoy.test/storage/sections/clip.mp4')
                ->where('product.sections.1.media.poster.src', $this->photo('b.webp')),
            );
    }

    public function test_the_builder_takes_a_video_and_refuses_anything_else(): void
    {
        Storage::fake('public');
        $admin = \App\Models\User::create(['name' => 'Owner', 'email' => 'o@t.local', 'password' => bcrypt('x'), 'role' => 'admin']);

        $url = $this->actingAs($admin)
            ->postJson(route('admin.products.section-video'), ['video' => \Illuminate\Http\UploadedFile::fake()->create('clip.mp4', 900, 'video/mp4')])
            ->assertOk()->json('url');
        $this->assertStringContainsString('/sections/', $url);

        $this->actingAs($admin)
            ->postJson(route('admin.products.section-video'), ['video' => \Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertUnprocessable();
    }

    public function test_the_product_form_hands_the_builder_its_own_photos_and_video(): void
    {
        $product = $this->product($this->rows(), videos: ['product-videos/clip.mp4']);
        $admin = \App\Models\User::create(['name' => 'Owner', 'email' => 'o@t.local', 'password' => bcrypt('x'), 'role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk()->getContent();

        // The builder's own x-data, not the gallery further down the page.
        $start = strpos($html, 'sectionBuilder(');
        $builder = substr($html, $start, strpos($html, 'saveUrl', $start) - $start);

        $this->assertStringContainsString(route('admin.products.section-video'), $builder);
        $this->assertStringContainsString('clip.mp4', $builder);
        $this->assertStringContainsString('b.webp', $builder);
    }

    public function test_sections_saved_before_the_media_choice_keep_their_old_meaning(): void
    {
        // Stored by the old builder: no `media` key at all.
        $product = $this->product([
            ['image' => 'https://cdn.example.com/look.jpg', 'heading' => 'Had a picture', 'body' => '', 'layout' => 'right'],
            ['image' => '', 'heading' => 'Had none', 'body' => 'Text only', 'layout' => 'left'],
        ]);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sections.0.media.src', 'https://cdn.example.com/look.jpg')
                ->where('product.sections.1.media', null),
            );
    }

    public function test_clean_sections_normalises_the_media_choice(): void
    {
        $clean = ContentTemplate::cleanSections([
            ['media' => 'auto', 'heading' => ' Botanical Sway ', 'body' => 'Leaves climb.'],
            ['media' => 'bogus', 'image' => 'x.jpg', 'heading' => 'Odd'],
            ['media' => 'auto', 'heading' => '', 'body' => ''],     // empty — dropped
        ]);

        $this->assertCount(2, $clean);
        $this->assertSame(['media' => 'auto', 'image' => '', 'heading' => 'Botanical Sway', 'body' => 'Leaves climb.', 'layout' => 'right'], $clean[0]);
        $this->assertSame('image', $clean[1]['media']);
    }

    public function test_the_crawlable_shell_carries_the_story_copy(): void
    {
        $product = $this->product($this->rows('Botanical'));

        $html = $this->get(route('product.show', $product))->getContent();
        $shell = substr($html, strpos($html, 'id="seo-shell"'));

        $this->assertStringContainsString('Botanical 2', $shell);
        $this->assertStringContainsString('Body 3', $shell);
    }

    public function test_a_product_without_story_rows_sends_none(): void
    {
        $product = $this->product([]);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page->where('product.sections', []));
    }

    // ── The 3 Oct 2026 copy for every product ───────────────────────────────

    private function migration(): object
    {
        return require database_path('migrations/2026_10_03_100000_write_story_rows_for_every_product.php');
    }

    /** A catalogue product at its production id — the migration's addressing scheme. */
    private function catalogued(int $id, ?string $name = null, ?array $sections = null): Product
    {
        $story = collect(json_decode(file_get_contents(database_path('data/product-story-rows.json')), true))
            ->firstWhere('id', $id);

        $p = Product::create([
            'name' => $name ?? $story['name'], 'slug' => 'piece-'.$id, 'status' => 'published',
            'price' => 1000, 'in_stock' => true, 'content_sections' => $sections,
        ]);
        Product::whereKey($p->id)->update(['id' => $id]);

        return Product::find($id);
    }

    public function test_the_data_file_keeps_to_the_format(): void
    {
        $stories = json_decode(file_get_contents(database_path('data/product-story-rows.json')), true);

        $this->assertCount(110, $stories);
        foreach ($stories as $story) {
            $this->assertCount(3, $story['rows'], "#{$story['id']}");
            foreach ($story['rows'] as $row) {
                $this->assertLessThanOrEqual(3, str_word_count($row['heading'], 0, '-\''), "#{$story['id']} {$row['heading']}");
                $this->assertLessThanOrEqual(30, count(preg_split('/\s+/', trim($row['body']))), "#{$story['id']} {$row['heading']}");
                $this->assertDoesNotMatchRegularExpression('/meridian|\bprom\b|!/i', $row['heading'].' '.$row['body']);
            }
        }
    }

    public function test_every_product_gets_three_auto_rows_alternating_sides(): void
    {
        $this->catalogued(212);

        $this->migration()->up();

        $sections = Product::find(212)->content_sections;
        $this->assertCount(3, $sections);
        $this->assertSame(['auto', 'auto', 'auto'], array_column($sections, 'media'));
        $this->assertSame(['left', 'right', 'left'], array_column($sections, 'layout'));
        $this->assertNotSame('', $sections[0]['heading']);
    }

    public function test_it_leaves_a_renamed_piece_and_one_with_its_own_story_alone(): void
    {
        $own = [['media' => 'none', 'image' => '', 'heading' => 'Hers', 'body' => 'Written in the builder.', 'layout' => 'left']];
        $this->catalogued(179, name: 'Something Else Now');
        $this->catalogued(180, sections: $own);

        $this->migration()->up();
        $this->migration()->up();   // and twice changes nothing

        $this->assertNull(Product::find(179)->content_sections);
        $this->assertSame($own, Product::find(180)->content_sections);
    }

    public function test_down_removes_only_what_it_wrote(): void
    {
        $this->catalogued(181);
        $this->catalogued(182);
        $this->migration()->up();

        $edited = Product::find(182)->content_sections;
        $edited[0]['heading'] = 'Edited by the owner';
        Product::find(182)->update(['content_sections' => $edited]);

        $this->migration()->down();

        $this->assertNull(Product::find(181)->content_sections);
        $this->assertSame('Edited by the owner', Product::find(182)->content_sections[0]['heading']);
    }
}
