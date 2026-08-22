<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function collection(array $attrs = []): Collection
    {
        return Collection::create(array_merge([
            'name' => 'Eid Gifts',
            'type' => 'smart',
            'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'is', 'value' => 'gift']],
            'is_active' => true,
            'show_in_menu' => true,
        ], $attrs));
    }

    public function test_a_menu_item_can_point_at_a_collection_and_the_url_is_resolved_on_save(): void
    {
        $c = $this->collection();

        $this->actingAs($this->admin())->post('/admin/menu', [
            'menu_desktop_trigger' => 'hover',
            'menu_json' => json_encode([[
                'label' => 'Gifts', 'type' => 'link',
                'target' => 'collection', 'collection_id' => $c->id,
                'url' => '#',
            ]]),
        ])->assertRedirect();

        $stored = Setting::get('menu');

        // Resolved once, at save time — site_menu() runs on every request, so
        // resolving there would be a query per menu item per page.
        $this->assertSame(route('collection.show', 'eid-gifts'), $stored[0]['url']);
        $this->assertSame('collection', $stored[0]['target']);
        $this->assertSame($c->id, $stored[0]['collection_id']);

        $this->assertSame(route('collection.show', 'eid-gifts'), site_menu()[0]['url']);
    }

    public function test_dropdown_children_and_mega_links_can_target_collections_too(): void
    {
        $c = $this->collection();

        $this->actingAs($this->admin())->post('/admin/menu', [
            'menu_desktop_trigger' => 'hover',
            'menu_json' => json_encode([
                ['label' => 'Shop', 'type' => 'dropdown', 'url' => '/shop', 'children' => [
                    ['label' => 'Gifts', 'target' => 'collection', 'collection_id' => $c->id],
                ]],
                ['label' => 'Big', 'type' => 'mega', 'url' => '/shop', 'columns' => [
                    ['heading' => 'By occasion', 'links' => [
                        ['label' => 'Eid', 'target' => 'collection', 'collection_id' => $c->id],
                    ]],
                ]],
            ]),
        ])->assertRedirect();

        $menu = site_menu();
        $this->assertSame(route('collection.show', 'eid-gifts'), $menu[0]['children'][0]['url']);
        $this->assertSame(route('collection.show', 'eid-gifts'), $menu[1]['columns'][0]['links'][0]['url']);
    }

    public function test_only_collections_offered_to_the_menu_are_pickable(): void
    {
        $this->collection(['name' => 'Offered', 'show_in_menu' => true]);
        $this->collection(['name' => 'Hidden', 'show_in_menu' => false]);

        $this->actingAs($this->admin())->get('/admin/menu')
            ->assertOk()
            ->assertSee('Offered')
            ->assertDontSee('"name":"Hidden"', false);
    }

    public function test_a_custom_target_never_keeps_a_stale_collection_id(): void
    {
        $c = $this->collection();

        $this->actingAs($this->admin())->post('/admin/menu', [
            'menu_desktop_trigger' => 'hover',
            'menu_json' => json_encode([[
                'label' => 'Manual', 'type' => 'link', 'url' => '/shop',
                'target' => 'custom', 'collection_id' => $c->id,
            ]]),
        ])->assertRedirect();

        $stored = Setting::get('menu');
        $this->assertNull($stored[0]['collection_id']);
        $this->assertSame('/shop', $stored[0]['url']);
    }

    public function test_collections_appear_in_the_sitemap(): void
    {
        $this->collection();

        $this->get('/sitemap.xml')->assertOk()->assertSee(route('collection.show', 'eid-gifts'), false);
    }

    public function test_an_inactive_collection_stays_out_of_the_sitemap(): void
    {
        $this->collection(['is_active' => false]);

        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('collection.show', 'eid-gifts'), false);
    }

    public function test_the_meta_feed_category_filter_no_longer_leaks_drafts(): void
    {
        $rings = Category::create(['name' => 'Rings', 'slug' => 'rings', 'is_active' => true]);

        foreach ([['Live ring', 'live-ring', 'published'], ['Draft ring', 'draft-ring', 'draft']] as [$name, $slug, $status]) {
            $p = Product::create(['name' => $name, 'slug' => $slug, 'status' => $status, 'price' => 100, 'category_id' => $rings->id, 'in_stock' => true, 'manage_stock' => false]);
            // Meta requires an image_link, so a product with no image is skipped.
            $p->images()->create(['path' => 'products/'.$slug.'.webp', 'is_primary' => true, 'position' => 0]);
        }

        // The orWhereHas used to escape published(), so the filtered feed
        // compiled to "(published AND pivot) OR primary" and shipped drafts.
        $csv = $this->get('/feed/meta.csv?category=rings')->assertOk()->streamedContent();

        $this->assertStringContainsString('Live ring', $csv);
        $this->assertStringNotContainsString('Draft ring', $csv);
    }
}
