<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inline editing from the product list, and the gallery actions in the editor.
 *
 * All of these answer JSON so the page never reloads — which is the point: a
 * reload throws away the admin's filters, page number and scroll position, and
 * in the editor it throws away every unsaved field on the form.
 */
class ProductQuickEditTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => 'Gold Ring',
            'slug' => 'gold-ring',
            'status' => 'published',
            'price' => 550,
        ]);
    }

    protected function image(Product $product, string $seed, bool $primary = false): ProductImage
    {
        return $product->images()->create([
            'path' => "https://example.test/{$seed}.jpg",
            'position' => 0,
            'is_primary' => $primary,
        ]);
    }

    // ── Publish / draft ──────────────────────────────────────────────────────

    public function test_a_product_can_be_drafted_from_the_list(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.products.quick', $product), ['status' => 'draft'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'draft']);

        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_publishing_and_pricing_save_together(): void
    {
        $product = $this->product(['status' => 'draft']);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.products.quick', $product), ['status' => 'published', 'price' => 700])
            ->assertOk();

        $product->refresh();
        $this->assertSame('published', $product->status);
        $this->assertSame(700.0, (float) $product->price);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.products.quick', $product), ['status' => 'archived'])
            ->assertStatus(422);

        $this->assertSame('published', $product->fresh()->status);
    }

    public function test_omitting_the_status_leaves_it_alone(): void
    {
        // The price-only save from the row must not silently publish a draft.
        $product = $this->product(['status' => 'draft']);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.products.quick', $product), ['price' => 600])
            ->assertOk();

        $this->assertSame('draft', $product->fresh()->status);
    }

    // ── Primary image ────────────────────────────────────────────────────────

    public function test_the_primary_image_can_be_chosen_from_the_quick_edit(): void
    {
        $product = $this->product();
        $first = $this->image($product, 'one', true);
        $second = $this->image($product, 'two');

        $this->actingAs($this->admin())
            ->patchJson(route('admin.products.quick', $product), ['primary_image_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('primary_image', $second->url);

        $this->assertFalse((bool) $first->fresh()->is_primary);
        $this->assertTrue((bool) $second->fresh()->is_primary);
    }

    public function test_one_product_cannot_repoint_another_products_gallery(): void
    {
        // The id arrives from the browser, so it has to be scoped to the
        // product being edited rather than trusted.
        $mine = $this->product();
        $theirs = $this->product(['slug' => 'other-ring']);
        $foreign = $this->image($theirs, 'theirs', true);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.products.quick', $mine), ['primary_image_id' => $foreign->id])
            ->assertOk();

        $this->assertTrue((bool) $foreign->fresh()->is_primary, 'the other product was modified');
    }

    public function test_setting_a_primary_image_answers_json_for_the_editor(): void
    {
        $product = $this->product();
        $a = $this->image($product, 'a', true);
        $b = $this->image($product, 'b');

        $this->actingAs($this->admin())
            ->postJson(route('admin.products.images.primary', $b))
            ->assertOk()
            ->assertJson(['ok' => true, 'primary_id' => $b->id]);

        $this->assertFalse((bool) $a->fresh()->is_primary);
        $this->assertTrue((bool) $b->fresh()->is_primary);
    }

    public function test_deleting_an_image_answers_json(): void
    {
        $product = $this->product();
        $image = $this->image($product, 'gone');

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.products.images.delete', $image))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertModelMissing($image);
    }

    public function test_deleting_the_primary_promotes_the_next_image(): void
    {
        // A gallery with no primary shows nothing on the storefront.
        $product = $this->product();
        $primary = $this->image($product, 'first', true);
        $next = $this->image($product, 'second');

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.products.images.delete', $primary))
            ->assertOk()
            ->assertJsonPath('primary_id', $next->id);

        $this->assertTrue((bool) $next->fresh()->is_primary);
    }

    public function test_deleting_the_last_image_leaves_no_primary_to_promote(): void
    {
        $product = $this->product();
        $only = $this->image($product, 'only', true);

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.products.images.delete', $only))
            ->assertOk()
            ->assertJsonPath('primary_id', null);

        $this->assertSame(0, $product->images()->count());
    }

    public function test_the_browser_form_still_redirects(): void
    {
        // Only AJAX callers get JSON; a plain form post keeps its redirect.
        $product = $this->product();
        $image = $this->image($product, 'x');

        $this->actingAs($this->admin())
            ->post(route('admin.products.images.primary', $image))
            ->assertRedirect();
    }
}
