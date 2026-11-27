<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'categories@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    /** Exactly what the "Add category" box on the Categories list posts: no google_category. */
    public function test_the_quick_add_form_on_the_list_page_creates_a_category(): void
    {
        $this->actingAs($this->admin())->post(route('admin.categories.store'), [
            'name' => 'Anklets',
            'parent_id' => '',
            'description' => '',
            'position' => '0',
            'is_active' => '1',
        ])->assertRedirect(route('admin.categories.index'))->assertSessionHasNoErrors();

        $category = Category::where('name', 'Anklets')->firstOrFail();
        $this->assertNull($category->google_category);
        $this->assertNull($category->parent_id);
        $this->assertTrue($category->is_active);
    }

    public function test_a_post_with_only_a_name_still_creates_a_category(): void
    {
        $this->actingAs($this->admin())->post(route('admin.categories.store'), ['name' => 'Brooches'])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', ['name' => 'Brooches', 'parent_id' => null, 'google_category' => null]);
    }

    public function test_the_full_form_still_saves_parent_and_google_category(): void
    {
        $parent = Category::create(['name' => 'Jewellery']);

        $this->actingAs($this->admin())->post(route('admin.categories.store'), [
            'name' => 'Toe rings',
            'parent_id' => $parent->id,
            'google_category' => 'Apparel & Accessories > Jewelry > Rings',
            'is_active' => '1',
        ])->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => 'Toe rings',
            'parent_id' => $parent->id,
            'google_category' => 'Apparel & Accessories > Jewelry > Rings',
        ]);
    }
}
