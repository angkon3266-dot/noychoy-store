<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The purchase-order form saves itself while the owner edits (2026-10-28): the
 * page posts the whole form as JSON to the ordinary store/update routes. The
 * first autosave of a new PO creates it; later ones update that same record.
 * Shipping details are free text, no longer capped at 120 characters.
 */
class PurchaseOrderAutosaveTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function payload(Supplier $supplier, array $extra = []): array
    {
        return array_merge([
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'currency' => 'USD',
            'items' => [[
                'product_name' => 'Pearl drop earrings',
                'unit_cost' => '2.50',
                'attribute_names_json' => '["Color"]',
                'variants_json' => '[{"attrs":{"Color":"Gold"},"qty":40}]',
            ]],
        ], $extra);
    }

    public function test_first_autosave_creates_the_po_and_later_ones_update_it(): void
    {
        $supplier = Supplier::create(['name' => 'Yiwu Jewels']);

        $created = $this->actingAs($this->admin())
            ->postJson(route('admin.purchase-orders.store'), $this->payload($supplier))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $po = PurchaseOrder::sole();
        $created->assertJson([
            'id' => $po->id,
            'po_number' => $po->po_number,
            'update_url' => route('admin.purchase-orders.update', $po),
            'edit_url' => route('admin.purchase-orders.edit', $po),
        ]);
        $this->assertSame(100.0, (float) $po->total_cost);

        $this->actingAs($this->admin())
            ->putJson(route('admin.purchase-orders.update', $po), $this->payload($supplier, [
                'po_number' => $po->po_number,
                'courier_cost' => '20',
            ]))
            ->assertOk();

        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(120.0, (float) $po->fresh()->total_cost);
        $this->assertSame(1, $po->items()->count());
    }

    public function test_autosave_validation_failure_comes_back_as_json(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.purchase-orders.store'), ['status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_shipping_details_and_long_links_are_not_cut_short(): void
    {
        $supplier = Supplier::create(['name' => 'Yiwu Jewels']);
        $details = "Forwarder: Shenzhen Air Cargo, warehouse 3B\n"
            .rtrim(str_repeat('Tracking SF1234567890123 · carton 1 of 6 · ', 20));
        $link = 'https://www.alibaba.com/product-detail/Pearl-Drop-Earrings_1600000000000.html?'.str_repeat('spm=a2700.galleryofferlist.p_offer.d_title.', 8);

        $this->actingAs($this->admin())
            ->post(route('admin.purchase-orders.store'), $this->payload($supplier, [
                'courier_name' => 'Shenzhen Air Cargo (door-to-door, Dhaka customs cleared by agent)',
                'courier_tracking' => $details,
                'items' => [[
                    'product_name' => 'Pearl drop earrings',
                    'qty' => 10,
                    'unit_cost' => '2.50',
                    'product_link' => $link,
                ]],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $po = PurchaseOrder::sole();
        $this->assertGreaterThan(120, mb_strlen($details));
        $this->assertSame($details, $po->courier_tracking);
        $this->assertSame($link, $po->items()->sole()->product_link);

        $this->actingAs($this->admin())
            ->get(route('admin.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('Forwarder: Shenzhen Air Cargo, warehouse 3B');
    }
}
