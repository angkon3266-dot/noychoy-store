<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CollectionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(string $name, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
        ], $attrs));
    }

    protected function giftCollection(): Collection
    {
        return Collection::create([
            'name' => 'Gifts under 2000',
            'slug' => 'gifts-under-2000',
            'description' => 'Everything giftable, under two thousand.',
            'type' => 'smart',
            'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'is', 'value' => 'gift']],
            'is_active' => true,
        ]);
    }

    public function test_a_collection_page_renders_the_catalog_with_its_own_title_and_copy(): void
    {
        $this->product('Eid Ring', ['tags' => 'gift']);
        $this->product('Hidden thing', ['tags' => 'plain']);
        $this->giftCollection();

        $this->get('/collection/gifts-under-2000')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Catalog')
                ->where('title', 'Gifts under 2000')
                ->where('description', 'Everything giftable, under two thousand.')
                ->where('products.total', 1)
                ->where('products.data.0.name', 'Eid Ring'));
    }

    public function test_an_inactive_collection_is_a_404(): void
    {
        $this->giftCollection()->update(['is_active' => false]);

        $this->get('/collection/gifts-under-2000')->assertNotFound();
    }

    public function test_storefront_filters_narrow_a_collection_further(): void
    {
        $this->product('Cheap gift', ['tags' => 'gift', 'price' => 500]);
        $this->product('Dear gift', ['tags' => 'gift', 'price' => 5000]);
        $this->giftCollection();

        $this->get('/collection/gifts-under-2000?price_max=1000')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('products.total', 1));
    }

    public function test_renaming_a_collection_keeps_its_url(): void
    {
        $c = $this->giftCollection();
        $c->update(['name' => 'Something else entirely']);

        $this->assertSame('gifts-under-2000', $c->fresh()->slug);
        $this->get('/collection/gifts-under-2000')->assertOk();
    }

    public function test_searching_gift_now_finds_products_tagged_gift(): void
    {
        // The reported dead end: "gift" returned nothing because search only
        // covered name, sku and short_description.
        $this->product('Rose Band', ['tags' => 'gift, eid']);

        $this->get('/shop?q=gift')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('products.total', 1));
    }

    public function test_search_on_a_category_page_is_no_longer_silently_dropped(): void
    {
        $rings = Category::create(['name' => 'Rings', 'is_active' => true]);
        $this->product('Gift ring', ['category_id' => $rings->id, 'tags' => 'gift']);
        $this->product('Plain ring', ['category_id' => $rings->id, 'tags' => 'plain']);

        // Previously category() never called ->search(), so this returned both
        // while the page still rendered a "search results" state.
        $this->get('/category/rings?q=gift')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('products.total', 1)
                ->where('searchQuery', 'gift'));
    }

    public function test_the_admin_can_create_a_smart_collection(): void
    {
        $this->product('Eid Ring', ['tags' => 'gift']);

        $this->actingAs($this->admin())->post('/admin/collections', [
            'name' => 'Eid Gifts',
            'type' => 'smart',
            'match' => 'all',
            'is_active' => '1',
            'show_in_menu' => '1',
            'rules' => [['field' => 'tag', 'operator' => 'is', 'value' => 'gift']],
        ])->assertRedirect(route('admin.collections.index'));

        $c = Collection::firstWhere('name', 'Eid Gifts');
        $this->assertNotNull($c);
        $this->assertSame('eid-gifts', $c->slug);
        $this->assertSame([['field' => 'tag', 'operator' => 'is', 'value' => 'gift']], $c->rules);
        $this->get('/collection/eid-gifts')->assertOk();
    }

    public function test_malformed_rules_are_dropped_at_save_not_at_query_time(): void
    {
        $this->actingAs($this->admin())->post('/admin/collections', [
            'name' => 'Half built',
            'type' => 'smart',
            'match' => 'all',
            'rules' => [
                ['field' => 'tag', 'operator' => 'is', 'value' => 'gift'],
                ['field' => 'price', 'operator' => 'gte', 'value' => ''],
            ],
        ])->assertRedirect();

        // What the admin sees saved is exactly what the storefront will run.
        $this->assertCount(1, Collection::firstWhere('name', 'Half built')->rules);
    }

    public function test_an_unknown_rule_field_is_rejected_rather_than_stored(): void
    {
        $this->actingAs($this->admin())->post('/admin/collections', [
            'name' => 'Bad',
            'type' => 'smart',
            'match' => 'all',
            'rules' => [['field' => 'drop_table', 'operator' => 'is', 'value' => 'x']],
        ])->assertSessionHasErrors('rules.0.field');
    }

    public function test_the_rule_builder_preview_counts_without_saving(): void
    {
        $this->product('A gift', ['tags' => 'gift']);
        $this->product('Not a gift', ['tags' => 'plain']);

        $this->actingAs($this->admin())->postJson('/admin/collections/preview', [
            'match' => 'all',
            'rules' => [
                ['field' => 'tag', 'operator' => 'is', 'value' => 'gift'],
                ['field' => 'price', 'operator' => 'gte', 'value' => ''],
            ],
        ])->assertOk()->assertJson([
            'count' => 1,
            'usable_rules' => 1,
            'submitted_rules' => 2,
        ]);

        $this->assertSame(0, Collection::count());
    }

    public function test_admin_index_and_form_screens_render(): void
    {
        $this->giftCollection();

        $this->actingAs($this->admin())->get('/admin/collections')->assertOk()->assertSee('Gifts under 2000');
        $this->actingAs($this->admin())->get('/admin/collections/create')->assertOk()->assertSee('Add condition');
        $this->actingAs($this->admin())->get('/admin/collections/'.Collection::first()->id.'/edit')->assertOk();
    }

    public function test_a_manager_can_reach_collections(): void
    {
        $manager = User::create(['name' => 'M', 'email' => 'm@b.test', 'password' => bcrypt('x'), 'role' => 'manager']);

        // The section gate is derived from the route name, so 'collections'
        // has to be in the manager list or this 403s.
        $this->actingAs($manager)->get('/admin/collections')->assertOk();
    }
}
