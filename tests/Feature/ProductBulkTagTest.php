<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Tag these 20 at once" — the missing half of step 04.
 *
 * Collections were already shipped and already match on tags, but there was no
 * way to tag more than one product at a time, so the collections had nothing to
 * match and the occasion tiles dead-ended on the full catalogue.
 *
 * The assertion that actually matters is the last one: a tag string written by
 * this action must be matched by a CollectionService `tag is` rule. Everything
 * else is bookkeeping around that.
 */
class ProductBulkTagTest extends TestCase
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
            'slug' => Str::slug($name),
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
        ], $attrs));
    }

    protected function bulk(string $action, array $ids, ?string $tags = null)
    {
        return $this->actingAs($this->admin())->post(route('admin.products.bulk'), array_filter([
            'action' => $action,
            'ids' => $ids,
            'tags' => $tags,
        ], fn ($v) => $v !== null));
    }

    public function test_it_adds_a_tag_to_several_products_at_once(): void
    {
        $a = $this->product('Ring A');
        $b = $this->product('Ring B');

        $this->bulk('tag_add', [$a->id, $b->id], 'gift')->assertRedirect();

        $this->assertSame('gift', $a->fresh()->tags);
        $this->assertSame('gift', $b->fresh()->tags);
    }

    public function test_adding_keeps_the_tags_a_product_already_had(): void
    {
        $p = $this->product('Ring', ['tags' => 'bestseller']);

        $this->bulk('tag_add', [$p->id], 'gift, eid');

        $this->assertSame('bestseller, gift, eid', $p->fresh()->tags);
    }

    public function test_adding_a_tag_a_product_already_has_changes_nothing(): void
    {
        // Case-insensitively: the live catalogue holds both "Gift" and "gift",
        // and the collection match is case-insensitive, so re-adding one over
        // the other would only make the string longer.
        $p = $this->product('Ring', ['tags' => 'Gift, bestseller']);

        $this->bulk('tag_add', [$p->id], 'gift');

        $this->assertSame('Gift, bestseller', $p->fresh()->tags);
    }

    public function test_it_removes_a_tag_without_touching_the_others(): void
    {
        $p = $this->product('Ring', ['tags' => 'gift, eid, bestseller']);

        $this->bulk('tag_remove', [$p->id], 'eid');

        $this->assertSame('gift, bestseller', $p->fresh()->tags);
    }

    public function test_removing_the_last_tag_leaves_null_not_an_empty_string(): void
    {
        $p = $this->product('Ring', ['tags' => 'gift']);

        $this->bulk('tag_remove', [$p->id], 'gift');

        $this->assertNull($p->fresh()->tags);
    }

    public function test_replace_overwrites_every_existing_tag(): void
    {
        $p = $this->product('Ring', ['tags' => 'gift, eid, bestseller']);

        $this->bulk('tag_replace', [$p->id], 'wedding');

        $this->assertSame('wedding', $p->fresh()->tags);
    }

    public function test_an_empty_tag_box_cannot_wipe_the_catalogue(): void
    {
        $p = $this->product('Ring', ['tags' => 'gift, eid']);

        // The one that would hurt: tag_replace with nothing typed.
        $this->bulk('tag_replace', [$p->id], '')->assertSessionHasErrors('tags');

        $this->assertSame('gift, eid', $p->fresh()->tags);
    }

    public function test_messy_input_is_cleaned_up(): void
    {
        $p = $this->product('Ring');

        // A column pasted out of a spreadsheet, with duplicates and stray space.
        $this->bulk('tag_add', [$p->id], "  gift ,, \n eid\t,GIFT ,  new   arrival ");

        // Deduped case-insensitively, whitespace collapsed, empties dropped.
        $this->assertSame('gift, eid, new arrival', $p->fresh()->tags);
    }

    public function test_an_absurdly_long_tag_is_refused(): void
    {
        $p = $this->product('Ring', ['tags' => 'gift']);

        $this->bulk('tag_add', [$p->id], str_repeat('x', 60))
            ->assertSessionHas('error');

        $this->assertSame('gift', $p->fresh()->tags);
    }

    public function test_a_tag_written_here_is_matched_by_a_collection_rule(): void
    {
        // The whole point of the feature. If this fails, products get tagged
        // and the collection still shows nothing.
        $gift = $this->product('Gift Ring');
        $other = $this->product('Plain Ring');
        $decoy = $this->product('Gift Card Holder', ['tags' => 'gift-card']);

        $this->bulk('tag_add', [$gift->id], 'gift');

        $collection = Collection::create([
            'name' => 'Eid Gifts',
            'slug' => 'eid-gifts',
            'type' => 'smart',
            'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'is', 'value' => 'gift']],
            'status' => 'published',
        ]);

        $matched = app(CollectionService::class)->query($collection)->pluck('id')->all();

        $this->assertContains($gift->id, $matched);
        $this->assertNotContains($other->id, $matched);
        // Whole-tag matching: "gift" must not drag in "gift-card".
        $this->assertNotContains($decoy->id, $matched);
    }

    public function test_a_tag_added_to_a_product_that_already_has_it_is_reported_as_unchanged(): void
    {
        $already = $this->product('Ring A', ['tags' => 'gift']);
        $fresh = $this->product('Ring B');

        $this->bulk('tag_add', [$already->id, $fresh->id], 'gift')
            ->assertSessionHas('success');

        $this->assertSame('gift', $already->fresh()->tags);
        $this->assertSame('gift', $fresh->fresh()->tags);
    }
}
