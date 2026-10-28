<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A purchase order exports as a PDF to send the supplier (2026-10-29). Item
 * photos are fetched by us and embedded; one that will not load leaves a blank
 * square rather than failing the export.
 */
class PurchaseOrderPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_purchase_order_downloads_as_a_pdf(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'img.example.com/ok.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'img.example.com/dead.jpg' => Http::response('', 404),
        ]);

        $admin = User::create(['name' => 'Owner', 'email' => 'owner@test.local', 'password' => bcrypt('x'), 'role' => 'admin']);
        $po = PurchaseOrder::create([
            'supplier_id' => Supplier::create(['name' => 'Yiwu Jewels', 'wechat' => 'yiwu88'])->id,
            'status' => 'ordered', 'currency' => 'USD', 'courier_cost' => 20, 'processing_pct' => 2.99,
            'courier_tracking' => "Shenzhen Air Cargo\nSF1234567890123",
        ]);
        $po->items()->create([
            'product_name' => 'Pearl drop earrings', 'qty' => 60, 'unit_cost' => 2.5, 'image_url' => 'https://img.example.com/ok.png',
            'attribute_names' => ['Color'], 'variants' => [['attrs' => ['Color' => 'Gold'], 'qty' => 40], ['attrs' => ['Color' => 'Silver'], 'qty' => 20]],
        ]);
        $po->items()->create(['product_name' => 'Hoop', 'qty' => 10, 'unit_cost' => 1, 'image_url' => 'https://img.example.com/dead.jpg']);
        $po->load('items')->recalculateTotal();

        $res = $this->actingAs($admin)->get(route('admin.purchase-orders.pdf', $po))->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString($po->po_number.'.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
