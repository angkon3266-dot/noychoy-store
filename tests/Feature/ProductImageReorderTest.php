<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dragging product images into a new order.
 *
 * The behaviour was implemented in an inline <script> at the bottom of the
 * product form. That markup renders inside <main>, admin-ajax.js replaces
 * <main> with `innerHTML` after every background save, and a <script> inserted
 * that way never executes — so the drag listeners were destroyed by the first
 * save and never came back. Reordering worked once per full page load and was
 * dead from then on, which is why it read as "sometimes it works".
 *
 * The behaviour now lives in the imageGrid Alpine component, which Alpine
 * re-initialises inside swapped-in markup. These tests hold the contract that
 * makes that possible: no inline script, and markup the component can drive.
 */
class ProductImageReorderTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'reorder@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    /** @return array{0: Product, 1: list<ProductImage>} */
    protected function productWithImages(int $count = 4): array
    {
        $product = Product::create([
            'name' => 'Gold Ring',
            'slug' => 'gold-ring',
            'status' => 'published',
            'price' => 550,
        ]);

        $images = [];
        foreach (range(1, $count) as $i) {
            $images[] = ProductImage::create([
                'product_id' => $product->id,
                'path' => "products/img-{$i}.webp",
                'position' => $i - 1,
                'is_primary' => $i === 1,
            ]);
        }

        return [$product, $images];
    }

    public function test_a_posted_order_is_applied(): void
    {
        [$product, $images] = $this->productWithImages();
        [$a, $b, $c, $d] = $images;

        $this->actingAs($this->admin())
            ->put(route('admin.products.update', $product), [
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => $product->price,
                'status' => 'published',
                'image_order' => [$d->id, $b->id, $a->id, $c->id],
            ])->assertRedirect();

        $this->assertSame(
            [$d->id, $b->id, $a->id, $c->id],
            $product->images()->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_an_image_from_another_product_cannot_be_reordered_in(): void
    {
        [$product, $images] = $this->productWithImages(2);

        $other = Product::create([
            'name' => 'Silver Ring', 'slug' => 'silver-ring',
            'status' => 'published', 'price' => 400,
        ]);
        $foreign = ProductImage::create([
            'product_id' => $other->id, 'path' => 'products/other.webp', 'position' => 0,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.products.update', $product), [
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => $product->price,
                'status' => 'published',
                'image_order' => [$foreign->id, $images[1]->id, $images[0]->id],
            ])->assertRedirect();

        $this->assertSame($other->id, $foreign->fresh()->product_id);
        $this->assertSame(
            [$images[1]->id, $images[0]->id],
            $product->images()->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_saving_without_dragging_leaves_the_order_alone(): void
    {
        [$product, $images] = $this->productWithImages(3);

        $this->actingAs($this->admin())
            ->put(route('admin.products.update', $product), [
                'name' => 'Renamed',
                'slug' => $product->slug,
                'price' => $product->price,
                'status' => 'published',
            ])->assertRedirect();

        $this->assertSame(
            collect($images)->pluck('id')->all(),
            $product->images()->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_the_editor_ships_markup_the_component_can_drive(): void
    {
        [$product] = $this->productWithImages(3);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x-data="imageGrid()"', $html);
        $this->assertStringContainsString('id="imgOrderInputs"', $html, 'nowhere to write the dragged order');
        // touch-action: none, or a phone hands the gesture to the scroller and
        // the drag never begins.
        $this->assertStringContainsString('touch-none', $html);
        // Focusable, so the arrow-key fallback has something to move.
        $this->assertStringContainsString('tabindex="0"', $html);
    }

    public function test_the_editor_carries_no_inline_reorder_script(): void
    {
        // The regression this whole file exists for. A <script> in this markup
        // is silently discarded by admin-ajax.js's innerHTML swap, so anything
        // that lives there works exactly once and then stops.
        [$product] = $this->productWithImages(3);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->getContent();

        $body = str($html)->after('</head>')->toString();

        foreach (['dragstart', 'getElementById(\'imgGrid\')', "getElementById('imgBulkDelBtn')"] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "image behaviour is back in an inline script; it will not survive a background save",
            );
        }
    }
}
