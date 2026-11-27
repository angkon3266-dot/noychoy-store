<?php

namespace Tests\Feature;

use App\Jobs\Meta\SyncCollectionToMeta;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Meta\MetaProductSetSync;
use App\Services\Meta\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Collections become Meta product sets so ads can pick them, the way Shopify's
 * Facebook channel does it (owner, 2026-11-27).
 */
class MetaCollectionProductSetTest extends TestCase
{
    use RefreshDatabase;

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

    /** Connect Meta after the products exist, so their own catalogue sync stays out of these tests. */
    protected function connect(): void
    {
        $settings = app(MetaSettings::class);
        $settings->setToken('test-token');
        $settings->update(['enabled' => true, 'business_id' => 'biz-1', 'catalog_id' => 'cat-1']);
    }

    protected function manual(array $products, array $attrs = []): Collection
    {
        $c = Collection::create(array_merge([
            'name' => 'Moissanite Edit', 'type' => 'manual', 'match' => 'all', 'rules' => [], 'is_active' => true,
        ], $attrs));
        $c->products()->sync(collect($products)->mapWithKeys(fn ($p, $i) => [$p->id => ['position' => $i]])->all());

        return $c;
    }

    protected function sync(Collection $c): string
    {
        return app(MetaProductSetSync::class)->sync($c);
    }

    /** @return list<Request> */
    protected function setCalls(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => ! str_contains($r->url(), 'items_batch'))
            ->values()->all();
    }

    public function test_a_collection_is_created_as_a_set_of_its_products(): void
    {
        $a = $this->product('Halo Ring');
        $b = $this->product('Tennis Bracelet');
        $this->product('Not In It');
        $c = $this->manual([$a, $b]);
        $this->connect();
        Http::fake(['*/cat-1/product_sets*' => Http::response(['id' => 'set-9'])]);

        $this->assertSame('created', $this->sync($c));

        $call = $this->setCalls()[0];
        $this->assertSame('POST', $call->method());
        $this->assertSame('Moissanite Edit', $call['name']);
        $this->assertSame(
            ['retailer_id' => ['is_any' => collect([meta_content_id($a), meta_content_id($b)])->sort()->values()->all()]],
            json_decode($call['filter'], true),
        );

        $c->refresh();
        $this->assertSame('set-9', $c->meta_product_set_id);
        $this->assertSame(2, $c->meta_set_count);
        $this->assertNull($c->meta_sync_error);
    }

    public function test_a_product_with_variants_goes_in_as_its_variant_rows(): void
    {
        // The feed has no parent row for these, only one row per variant.
        $p = $this->product('Solitaire', ['has_variants' => true]);
        $v1 = ProductVariant::create(['product_id' => $p->id, 'sku' => 'S-6', 'attributes' => ['Size' => '6'], 'price' => 1000, 'stock_quantity' => 2, 'is_active' => true]);
        $v2 = ProductVariant::create(['product_id' => $p->id, 'sku' => 'S-7', 'attributes' => ['Size' => '7'], 'price' => 1000, 'stock_quantity' => 2, 'is_active' => true]);
        $c = $this->manual([$p]);
        $this->connect();

        $ids = app(MetaProductSetSync::class)->retailerIds($c);

        $this->assertEqualsCanonicalizing([meta_content_id($p, $v1), meta_content_id($p, $v2)], $ids);
    }

    public function test_an_unchanged_set_does_not_reach_meta_and_a_changed_one_is_updated_in_place(): void
    {
        $a = $this->product('Halo Ring');
        $b = $this->product('Tennis Bracelet');
        $c = $this->manual([$a]);
        $this->connect();
        Http::fake([
            '*/cat-1/product_sets*' => Http::response(['id' => 'set-9']),
            '*/set-9*' => Http::response(['success' => true]),
        ]);

        $this->sync($c);
        $this->assertSame('unchanged', $this->sync($c));
        $this->assertCount(1, $this->setCalls());

        $c->products()->attach($b->id, ['position' => 1]);
        $this->assertSame('updated', $this->sync($c));

        $last = $this->setCalls()[1];
        $this->assertStringContainsString('/set-9', $last->url());
        $this->assertContains(meta_content_id($b), json_decode($last['filter'], true)['retailer_id']['is_any']);
        $this->assertSame(2, $c->fresh()->meta_set_count);
    }

    public function test_switching_it_off_or_deactivating_deletes_the_set(): void
    {
        $c = $this->manual([$this->product('Halo Ring')], ['meta_product_set_id' => 'set-9', 'meta_set_hash' => 'x']);
        $this->connect();
        Http::fake(['*/set-9*' => Http::response(['success' => true])]);

        $c->update(['is_active' => false]);
        $this->assertSame('removed', $this->sync($c));

        $call = $this->setCalls()[0];
        $this->assertSame('DELETE', $call->method());
        $this->assertStringContainsString('/set-9', $call->url());
        $this->assertNull($c->fresh()->meta_product_set_id);
    }

    public function test_an_empty_collection_gets_no_set(): void
    {
        $c = $this->manual([]);
        $this->connect();
        Http::fake();

        $this->assertSame('absent', $this->sync($c));
        $this->assertSame([], $this->setCalls());
    }

    public function test_a_set_deleted_in_commerce_manager_is_made_again(): void
    {
        $c = $this->manual([$this->product('Halo Ring')], ['meta_product_set_id' => 'gone-1', 'meta_set_hash' => 'stale']);
        $this->connect();
        Http::fake([
            '*/gone-1*' => Http::response(['error' => ['message' => 'Unsupported post request. Object does not exist', 'code' => 100, 'error_subcode' => 33]], 400),
            '*/cat-1/product_sets*' => Http::response(['id' => 'set-new']),
        ]);

        $this->assertSame('created', $this->sync($c));
        $this->assertSame('set-new', $c->fresh()->meta_product_set_id);
    }

    public function test_a_refusal_is_kept_for_the_admin_form(): void
    {
        $c = $this->manual([$this->product('Halo Ring')]);
        $this->connect();
        Http::fake(['*/cat-1/product_sets*' => Http::response(['error' => ['message' => '(#200) Missing catalog_management', 'code' => 200]], 403)]);

        $this->assertSame('failed', $this->sync($c));
        $this->assertStringContainsString('catalog_management', $c->fresh()->meta_sync_error);
    }

    public function test_nothing_is_sent_while_meta_is_not_connected(): void
    {
        $c = $this->manual([$this->product('Halo Ring')]);
        Http::fake();

        $this->assertSame('skipped', $this->sync($c));
        Http::assertNothingSent();
    }

    public function test_saving_or_deleting_a_collection_in_the_admin_syncs_its_set(): void
    {
        Bus::fake([SyncCollectionToMeta::class]);
        $admin = User::firstOrCreate(['email' => 'a@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
        $c = $this->manual([$this->product('Halo Ring')], ['meta_product_set_id' => 'set-9']);

        $this->actingAs($admin)->put(route('admin.collections.update', $c), [
            'name' => 'Moissanite Edit', 'type' => 'manual', 'match' => 'all', 'is_active' => 1,
        ])->assertRedirect();

        Bus::assertDispatchedAfterResponse(SyncCollectionToMeta::class, fn ($job) => $job->collectionId === $c->id);
        $this->assertFalse($c->fresh()->meta_sync, 'an unticked box switches it off');

        $this->actingAs($admin)->delete(route('admin.collections.destroy', $c))->assertRedirect();
        Bus::assertDispatchedAfterResponse(SyncCollectionToMeta::class, fn ($job) => $job->deletedSetId === 'set-9');
    }
}
