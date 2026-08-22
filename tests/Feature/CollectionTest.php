<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionTest extends TestCase
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

    protected function smart(array $rules, string $match = 'all', array $attrs = []): Collection
    {
        return Collection::create(array_merge([
            'name' => 'Test collection',
            'type' => 'smart',
            'match' => $match,
            'rules' => $rules,
            'is_active' => true,
        ], $attrs));
    }

    protected function svc(): CollectionService
    {
        return app(CollectionService::class);
    }

    public function test_tag_is_matches_a_whole_tag_not_a_prefix(): void
    {
        $this->product('Eid Ring', ['tags' => 'eid, gift']);
        $this->product('Gift Card', ['tags' => 'gift-card']);
        $this->product('Plain Band', ['tags' => 'everyday']);

        $c = $this->smart([['field' => 'tag', 'operator' => 'is', 'value' => 'gift']]);

        // "gift-card" must NOT match "gift" — the whole point of whole-tag
        // matching over a naive LIKE %gift%.
        $this->assertSame(['Eid Ring'], $this->svc()->query($c)->pluck('name')->all());
    }

    public function test_tag_contains_is_the_looser_match(): void
    {
        $this->product('Eid Ring', ['tags' => 'eid, gift']);
        $this->product('Gift Card', ['tags' => 'gift-card']);

        $c = $this->smart([['field' => 'tag', 'operator' => 'contains', 'value' => 'gift']]);

        $this->assertCount(2, $this->svc()->query($c)->get());
    }

    public function test_tag_matching_ignores_case(): void
    {
        // The live catalogue stores both "Gift" (56 products) and "gift" (42).
        $this->product('Upper', ['tags' => 'Gift, Statement']);
        $this->product('Lower', ['tags' => 'gift']);

        $c = $this->smart([['field' => 'tag', 'operator' => 'is', 'value' => 'gift']]);

        $this->assertCount(2, $this->svc()->query($c)->get());
    }

    public function test_match_all_ands_and_match_any_ors(): void
    {
        $this->product('Cheap gift', ['tags' => 'gift', 'price' => 900]);
        $this->product('Dear gift', ['tags' => 'gift', 'price' => 5000]);
        $this->product('Cheap other', ['tags' => 'plain', 'price' => 800]);

        $rules = [
            ['field' => 'tag', 'operator' => 'is', 'value' => 'gift'],
            ['field' => 'price', 'operator' => 'lte', 'value' => '1000'],
        ];

        $this->assertSame(['Cheap gift'], $this->svc()->query($this->smart($rules))->pluck('name')->all());
        $this->assertCount(3, $this->svc()->query($this->smart($rules, 'any'))->get());
    }

    public function test_on_sale_rule_compiles_to_sql_not_a_php_filter(): void
    {
        $this->product('Discounted', ['price' => 800, 'compare_at_price' => 1200]);
        $this->product('Full price', ['price' => 800, 'compare_at_price' => null]);
        $this->product('Fake sale', ['price' => 800, 'compare_at_price' => 700]);

        $c = $this->smart([['field' => 'on_sale', 'operator' => 'is_true', 'value' => null]]);

        // Paginated totals depend on this being SQL, not a filtered collection.
        $this->assertSame(1, $this->svc()->query($c)->count());
        $this->assertSame(['Discounted'], $this->svc()->query($c)->pluck('name')->all());
    }

    public function test_category_rule_matches_primary_or_pivot(): void
    {
        $rings = Category::create(['name' => 'Rings', 'is_active' => true]);

        $this->product('Primary ring', ['category_id' => $rings->id]);
        $pivot = $this->product('Pivot ring');
        $pivot->categories()->attach($rings->id);
        $this->product('Unrelated');

        $c = $this->smart([['field' => 'category', 'operator' => 'is', 'value' => (string) $rings->id]]);

        $this->assertEqualsCanonicalizing(
            ['Primary ring', 'Pivot ring'],
            $this->svc()->query($c)->pluck('name')->all()
        );
    }

    public function test_a_smart_collection_with_no_usable_rules_is_empty_not_everything(): void
    {
        $this->product('A');
        $this->product('B');

        // Blank value, unknown field, unknown operator — all dropped.
        $c = $this->smart([
            ['field' => 'tag', 'operator' => 'is', 'value' => ''],
            ['field' => 'nonsense', 'operator' => 'is', 'value' => 'x'],
            ['field' => 'price', 'operator' => 'explodes', 'value' => '5'],
        ]);

        $this->assertSame(0, $this->svc()->query($c)->count());
    }

    public function test_pinned_products_are_added_on_top_of_the_rule_matches(): void
    {
        $this->product('Tagged gift', ['tags' => 'gift']);
        $hero = $this->product('Hero piece', ['tags' => 'plain']);

        $c = $this->smart([['field' => 'tag', 'operator' => 'is', 'value' => 'gift']]);
        $c->products()->attach($hero->id, ['position' => 0]);

        $this->assertEqualsCanonicalizing(
            ['Tagged gift', 'Hero piece'],
            $this->svc()->query($c)->pluck('name')->all()
        );
    }

    public function test_a_manual_collection_uses_only_its_picked_list(): void
    {
        $a = $this->product('Picked');
        $this->product('Not picked');

        $c = Collection::create(['name' => 'Manual', 'type' => 'manual', 'is_active' => true]);
        $c->products()->attach($a->id, ['position' => 0]);

        $this->assertSame(['Picked'], $this->svc()->query($c)->pluck('name')->all());
    }

    public function test_an_empty_manual_collection_shows_nothing(): void
    {
        $this->product('Something');

        $c = Collection::create(['name' => 'Empty', 'type' => 'manual', 'is_active' => true]);

        $this->assertSame(0, $this->svc()->query($c)->count());
    }

    public function test_drafts_never_leak_into_a_collection(): void
    {
        $this->product('Live gift', ['tags' => 'gift']);
        $this->product('Draft gift', ['tags' => 'gift', 'status' => 'draft']);

        $c = $this->smart([['field' => 'tag', 'operator' => 'is', 'value' => 'gift']]);

        $this->assertSame(['Live gift'], $this->svc()->query($c)->pluck('name')->all());
    }
}
