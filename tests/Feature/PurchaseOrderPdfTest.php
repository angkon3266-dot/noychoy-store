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

    /**
     * Forwarders send their shipping instructions half in Chinese. dompdf's
     * DejaVu has no CJK glyphs (they printed as boxes), so text containing any
     * is set in the bundled Noto Sans SC. "Ship to" is the owner's own text —
     * the store's name is not put there.
     */
    public function test_chinese_text_gets_the_cjk_font_and_ship_to_is_the_owners_text(): void
    {
        $admin = User::create(['name' => 'Owner', 'email' => 'owner@test.local', 'password' => bcrypt('x'), 'role' => 'admin']);
        $po = PurchaseOrder::create([
            'supplier_id' => Supplier::create(['name' => 'Naishide', 'country' => 'China'])->id,
            'status' => 'ordered', 'currency' => 'USD',
            'ship_to' => "FRK Cargo\n广州市白云区 28号 D仓",
            'courier_tracking' => "Shipping Mark 唛头 : FRK/Shamim\nCTN count: 6",
        ]);
        $po->items()->create(['product_name' => 'Pearl drop', 'qty' => 10, 'unit_cost' => 2]);

        $html = view('admin.purchase-orders.pdf', [
            'order' => $po->load('supplier', 'items'),
            'images' => [],
            'cjk' => true,
            'store' => ['name' => 'NoyChoy Store', 'address' => null, 'phone' => null, 'email' => null, 'logo' => null],
        ])->render();

        $this->assertStringContainsString('<span class="cjk">FRK Cargo'."\n".'广州市白云区 28号 D仓</span>', $html);
        $this->assertStringContainsString('<span class="cjk">Shipping Mark 唛头', $html);
        $this->assertStringNotContainsString('<span class="cjk">Pearl drop', $html);
        $shipTo = \Illuminate\Support\Str::betweenFirst($html, '<h3>Ship to</h3>', '</td>');
        $this->assertStringNotContainsString('NoyChoy Store', $shipTo);

        $res = $this->actingAs($admin)->get(route('admin.purchase-orders.pdf', $po))->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_a_new_po_starts_with_the_last_ship_to_address(): void
    {
        $admin = User::create(['name' => 'Owner', 'email' => 'owner@test.local', 'password' => bcrypt('x'), 'role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Naishide']);
        PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => 'received', 'ship_to' => 'FRK Cargo, Guangzhou']);

        $this->actingAs($admin)->get(route('admin.purchase-orders.create'))
            ->assertOk()
            ->assertSee('FRK Cargo, Guangzhou');

        $this->actingAs($admin)->postJson(route('admin.purchase-orders.store'), [
            'supplier_id' => $supplier->id, 'status' => 'pending', 'ship_to' => 'New forwarder, Yiwu',
        ])->assertOk();

        $this->assertSame('New forwarder, Yiwu', PurchaseOrder::latest('id')->value('ship_to'));
    }
}
