<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A caller that asks for JSON must get JSON back when something fails —
 * otherwise the UI can only say "not saved" instead of naming the problem.
 * Equally important: ordinary browser form posts must keep redirecting with
 * errors, which is what every non-AJAX page in the app relies on.
 */
class AjaxErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    protected function product(): Product
    {
        return Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 1000,
            'manage_stock' => true, 'stock_quantity' => 5, 'in_stock' => true,
        ]);
    }

    public function test_a_json_request_gets_a_readable_validation_error(): void
    {
        $product = $this->product();

        $res = $this->actingAs($this->admin())
            ->patchJson('/admin/products/'.$product->slug.'/quick', ['price' => -5]);

        $res->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['price']]);
    }

    public function test_a_browser_form_post_still_redirects_with_errors(): void
    {
        $product = $this->product();

        // No Accept: application/json — the ordinary form path.
        $res = $this->actingAs($this->admin())
            ->patch('/admin/products/'.$product->slug.'/quick', ['price' => -5]);

        $res->assertRedirect();
        $res->assertSessionHasErrors('price');
    }

    public function test_the_cart_add_endpoint_keeps_working_for_ajax(): void
    {
        $product = $this->product();

        $res = $this->postJson('/cart/add/'.$product->slug, ['qty' => 1]);

        $res->assertOk()->assertJsonStructure(['count']);
    }

    public function test_a_failed_ajax_cart_add_is_json_not_html(): void
    {
        $product = $this->product();

        // Asking for more than exists must fail in a form the JS can read.
        $res = $this->postJson('/cart/add/'.$product->slug, ['qty' => 9999]);

        $this->assertTrue(
            $res->headers->get('content-type') === null
                || str_contains((string) $res->headers->get('content-type'), 'json'),
            'an AJAX cart failure should not return HTML',
        );
    }
}
